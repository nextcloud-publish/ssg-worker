# ssg-worker

Nextcloud publish worker building static sites from Nextcloud Collectives markdown pages.
It uses the ssg-library to render the sites and is based on Symfony and Symfony Messenger.
No HTTP server, no database, no templating — it connects to RabbitMQ via [Symfony Messenger](https://symfony.com/doc/current/messenger.html)
and consumes build jobs from the `q.builds` queue (the same queue `publish`
enqueues onto).

For each job it downloads the content archive, extracts it, renders the pages,
**publishes the result to `PUBLISHED_DIR/<slug>/`** and **reports the outcome to the
job's `callback_status_url`**. A build that fails for a transient reason is retried once;
one that fails for good is quarantined to `FAILED_DIR/<build_id>/` and reported as
`failed`. Progress and failures are both logged via PHP's `error_log()` and captured by
`docker logs`.

[docs/build-pipeline.md](docs/build-pipeline.md) is the design of record — the directory
contract, the atomic swap, the retry semantics, the callback contract and what the test
suite cannot reach. [docs/roadmap.md](docs/roadmap.md) records the known limitations and
what a database would close.

## Queueing

Consumption goes through Symfony Messenger: `config/packages/messenger.yaml`
wires a `builds` transport to the broker, and `BuildJobHandler` is registered
against it in `config/services.yaml` for `App\Message\BuildJob` — the same
class name and property set as `publish`'s message, which is what lets
Messenger's `type`-header-driven decoding resolve to a real class without any
name-mapping config. `config/packages/property_info.yaml` enables the
constructor extractor the serializer needs to build that readonly class.

The transport does not declare any topology (`auto_setup: false`): the broker
imports the queue, user and permissions from
`publish/docker/rabbitmq-config/definitions.json` at boot and owns them, same
as on the `publish` side.

The handler takes an already-decoded `BuildJob`, so a body that is not valid
JSON or does not resolve to that class never reaches it — the serializer
rejects it first. Of the fields it does read:

| Field                  | Used for                                                             |
| ---------------------- | -------------------------------------------------------------------- |
| `build_id`             | names the job's folder and the quarantine folder; traces the build    |
| `static_site_id`       | names the job's folder under `JOB_STORAGE_DIR`                       |
| `slug`                 | names the published site under `PUBLISHED_DIR`                       |
| `title`                | titles the rendered site, as the header link on every page           |
| `content_download_url` | the archive to fetch; only `http`/`https` are accepted               |
| `callback_status_url`  | where the outcome is POSTed; only `http`/`https`, no private networks |

`static_site_id`, `build_id` and `slug` all arrive from an HTTP payload and become
directory names, so each has to match `/^[A-Za-z0-9_-]{1,128}$/` — the same allow-list
`publish` applies, so changing it means updating both repos together. Dots are excluded
outright, which keeps a bare `..` from passing.

`slug` and `title` are separate fields because the slug is a path now and that
allow-list excludes spaces; a message sent before `title` existed falls back to using
the slug as the heading.

Each attempt starts from an **empty** workdir. `SsgLab\SiteBuilder` never clears its
output directory, so reusing one would republish pages that were deleted from the
collective.

### Outcomes

- **Success** → the rendered tree is swapped into `PUBLISHED_DIR/<slug>/`, the build
  tree is cleared, and `success` is POSTed to `callback_status_url`.
- **Transient failure** → rethrown, and the transport redelivers after 15s.
  `retry_strategy.max_retries: 2` gives **two build attempts**; the third delivery does
  not build, it quarantines to `FAILED_DIR/<build_id>/` and POSTs `failed`.
- **Permanent failure** (an unsafe id or slug, a URL we will not fetch, an archive with
  no markdown) → reported `failed` on the first delivery, never retried.

Callback delivery is **at-least-once**: receivers must dedupe on `(build_id, status)` and
treat a terminal status as final. The payload and the retry semantics are specified in
[docs/build-pipeline.md](docs/build-pipeline.md).

> Retries need a `delays` direct exchange on the broker. It is declared in
> `publish/docker/rabbitmq-config/definitions.json`; without it the consumer dies on its
> first retry.

## Requirements

- PHP >= 8.5 and [Composer](https://getcomposer.org/) for local runs — the same
  version the runtime image ships, so there is only one supported PHP
- [Docker](https://www.docker.com/) with Compose v2 for the containerized dev stack
- A running `publish` RabbitMQ broker (see [Docker](#docker) below) — this
  repo does not run its own broker, and the broker must declare the `delays`
  exchange or retries kill the consumer



## Environment variables


| Variable          | Required | Default | Description                                                                                          |
| ----------------- | -------- | ------- | ---------------------------------------------------------------------------------------------------- |
| `AMQP_DSN`        | yes      | none    | RabbitMQ connection string, e.g. `amqp://app:secret@rabbitmq:5672/%2f`                               |
| `AMQP_HEARTBEAT`  | no       | `10`    | AMQP heartbeat interval in seconds; must match the broker's setting                                  |
| `JOB_STORAGE_DIR` | yes      | none    | Build scratch root; the worker creates `<static_site_id>/<build_id>/input` + `/output` under it       |
| `PUBLISHED_DIR`   | yes      | none    | Where finished sites are published, as `<slug>/`. An external nginx serves this                       |
| `FAILED_DIR`      | yes      | none    | Where a terminally failed build's tree is quarantined, as `<build_id>/`                              |
| `MAX_DOWNLOAD_MB` | yes      | none    | Ceiling on a single content download, in megabytes (e.g. `256`); minimum `1`                         |

Everything except `AMQP_HEARTBEAT` has no fallback — an unset variable is a broken
deployment, and guessing a value would hide a misconfiguration rather than surface it.
A wrong `PUBLISHED_DIR` in particular means builds succeed and are published where
nothing serves them.

Two things about the layout matter beyond naming a path:

- **`FAILED_DIR` belongs on the same mount as `JOB_STORAGE_DIR`.** Quarantining copies a
  failed build's whole tree — the archive and its fully extracted duplicate — and the
  message stays unacked for the entire copy. On one mount it is an instant rename.
- **`PUBLISHED_DIR` must be readable by whatever uid serves it.** The worker runs as root
  and creates `output/` at `0750`; the promoter widens the published root to `0755` for
  exactly this reason, and it is the one thing no unit test can verify.

## Local development

```bash
composer install
mkdir -p /tmp/ssg/{build_temp,published,build_failed}
AMQP_DSN="amqp://app:secret@localhost:5672/%2f" \
JOB_STORAGE_DIR=/tmp/ssg/build_temp \
PUBLISHED_DIR=/tmp/ssg/published \
FAILED_DIR=/tmp/ssg/build_failed \
MAX_DOWNLOAD_MB=256 \
php bin/console messenger:consume builds -vv
```

This assumes a RabbitMQ broker is reachable at that DSN (`publish`'s dev stack
exposes `5672` on `127.0.0.1`) and that the host has `ext-amqp` available (see
[Requirements](#requirements)). The directories are created if missing, along with each
job's folders underneath them.

## Docker

This repo ships a single `Dockerfile` at its root and **no compose file of its own** —
the full stack lives in `publish` and builds this repo from a sibling checkout:

```bash
docker compose -f ../publish/docker/dev/compose.yaml up --build
```

To build the image on its own:

```bash
docker build -t ssg-worker .
```

The image installs `ext-amqp` and `pcntl` and defaults to `messenger:consume builds`
with `--time-limit=3600 --memory-limit=512M`, so it **exits every hour by design** and
must be run under a restart policy. `pcntl` is what lets Symfony install its `SIGTERM`
handler; without it `docker stop` hard-kills the worker mid-build and the message comes
back redelivered, spending a build attempt. `vendor/` is installed at image build time;
compose keeps it behind an anonymous volume so the `/app` bind mount does not shadow it
— rebuild with `--build` after changing `composer.json`.

## Tests

```bash
php bin/phpunit
```

Real collaborators rather than mocks throughout (the classes are `final`, so PHP could
not mock them anyway): `MockHttpClient` replaces the network and a temp directory
replaces the volumes, so status codes, oversized bodies, broken transfers, traversal
attempts, the atomic swap, cross-mount copies, quarantining, the retry gate and error
redaction all run offline.

**Passing tests say nothing about whether `messenger:consume builds` survives its first
retry.** `InMemoryTransportFactory` discards every transport option, so nothing under
`options:` in `messenger.yaml` is exercised — including the `delays` exchange the broker
must declare. [docs/build-pipeline.md](docs/build-pipeline.md#what-the-test-suite-cannot-reach)
lists what that leaves uncovered and which dev-stack checks cover it instead.

## Layout

```text
Dockerfile                   php:8.5-cli-alpine + ext-amqp + pcntl, builds vendor/ at image build time
config/
  packages/messenger.yaml    builds transport, retry_strategy, RetryCountMiddleware
  packages/property_info.yaml constructor extractor, so BuildJob can be built
  services.yaml              the three storage roots, %build.max_retries%, SSRF-guarded http client
src/
  Job/                       JobLayout: every path, and the allow-list guarding them
                             JobWorkspace: creates <static_site_id>/<build_id>/input + /output, wiping any previous attempt
                             ContentDownloader: streams content_download_url to disk, scheme-checked and size-capped
                             ArchiveExtractor: unpacks the archive into input/content_unarchived
                             SiteRenderer: renders the extracted pages into output/
                             BuildPromoter: swaps output/ into PUBLISHED_DIR/<slug> atomically, clears the build tree
                             BuildQuarantine: moves a failed build to FAILED_DIR/<build_id>
                             Filesystem: the primitives, each failing with the OS's own reason
  Callback/                  StatusNotifier: POSTs the outcome to callback_status_url
                             ErrorRedactor: keeps volume paths out of what the client is sent
  Message/                   BuildJob: the message shape for q.builds, mirroring publish's message class
                             BuildJobHandler: registered in services.yaml, owns the build and its outcome
  Messenger/                 RetryCountMiddleware: lifts the retry count and previous error off the envelope
docs/                        build-pipeline.md (design of record), roadmap.md (limitations)
tests/                       PHPUnit tests for the above
```

