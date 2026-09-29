# ssg-worker

SSG stands for **static site generator**. This worker is the generator half of the
Nextcloud publish service: it turns Nextcloud Collectives markdown pages into a static
HTML site and writes it where a web server can serve it.

Rendering is done by [ssg-library](https://github.com/nextcloud-publish/ssg-library).
There is no HTTP server, no database and no templating in this repo.

For each job the worker downloads the content archive, extracts it, renders the pages,
publishes the result to `PUBLISHED_DIR/<static_site_id>/<slug>/` and PUTs the outcome to the job's
`callback_status_url`. Progress and failures go to PHP's `error_log()` and are captured
by `docker logs`.

[docs/build-pipeline.md](docs/build-pipeline.md) specifies the directory contract,
staging and the swap, the retry semantics, the callback payload, and what the test suite
does not cover. [docs/roadmap.md](docs/roadmap.md) lists the known limitations.

## Works together with the publish component

This worker does not run on its own. `publish` is the other half of the service and owns
everything this repo does not:

- the HTTP API users publish through, which enqueues one `BuildJob` per build
- the RabbitMQ broker and its whole topology — the `q.builds` queue, the `delays`
  exchange, the user and the permissions — imported from
  `publish/docker/rabbitmq-config/definitions.json` at boot
- the compose stack for the whole service, which builds this repo from a sibling checkout

Without a running `publish` there is no broker to connect to and no job to build.

Two things have to stay in step across the two repos:

- **The message class.** `App\Message\BuildJob` declares the same namespace, class name
  and property names as publish's message class. Messenger resolves the target class
  from the message's `type` header and the serializer maps JSON keys onto constructor
  parameter names, so a mismatch on either breaks decoding.
- **The id allow-list.** `/^[A-Za-z0-9_-]{1,128}$/`, applied on both sides. Changing it
  means changing both repos together.

## Queueing

`config/packages/messenger.yaml` wires the `builds` transport to the broker.
`config/services.yaml` registers `BuildJobHandler` against it for `App\Message\BuildJob`.
`config/packages/property_info.yaml` enables the constructor extractor the serializer
needs to build the readonly class.

The transport declares no topology (`auto_setup: false`), because the broker owns it.

A body that is not valid JSON, or that does not resolve to `BuildJob`, is rejected by the
serializer and never reaches the handler. The fields the handler reads:

| Field                  | Used for                                                                  |
| ---------------------- | ------------------------------------------------------------------------- |
| `build_id`             | UUID v7; names the job's folder under `JOB_STORAGE_DIR`                    |
| `static_site_id`       | names the site's folder under `PUBLISHED_DIR`                              |
| `slug`                 | names the published site under `PUBLISHED_DIR/<static_site_id>`            |
| `title`                | the heading on every rendered page                                         |
| `content_download_url` | the archive to fetch; `http` and `https` only                              |
| `callback_status_url`  | where the outcome is PUT;    `http` and `https` only, no private networks  |

`created_at` is decoded but never read.

`static_site_id`, `build_id` and `slug` become directory names, so each must match the
allow-list above. Dots are excluded, which stops a bare `..`. `slug` and `title` are
separate fields because that allow-list excludes spaces.

`BuildJobHandler::assertUsableJob()` checks the three ids and both URLs before any work
starts. Nothing downstream re-checks them.

Each attempt starts from an empty workdir. `SsgLab\SiteBuilder` never clears its output
directory, so reusing one would republish pages that were deleted from the collective.

### Outcomes

| Outcome           | What happens                                                                                                                                                                      |
| ----------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Success           | `PUBLISHED_DIR/<static_site_id>/` is replaced by one holding only `<slug>/`, the build tree is cleared, and `published` is PUT with    the `publish_url`                          |
| Transient failure | rethrown, and the transport redelivers after 15s. `max_retries: 1` gives two build attempts; on the second failure `BuildFailureHandler` deletes the build tree and PUTs `failed`  |
| Permanent failure | reported `failed` on the first delivery, never retried. Caused by an unsafe id or slug, a URL that is not http or https, or an archive with no markdown                            |

The callback body is `{"status": "published", "result": {"publish_url": ...}}` or
`{"status": "failed", "result": {"error_message": ...}}`, nothing else.

Callback delivery is at-least-once. Receivers must dedupe on `(callback_status_url, status)` and
treat a terminal status as final. The payload is specified in
[docs/build-pipeline.md](docs/build-pipeline.md).

> Retries need the `delays` direct exchange on the broker. Without it the consumer dies
> on its first retry.

## Requirements

- PHP >= 8.5 and [Composer](https://getcomposer.org/) for local runs. This is the
  version the runtime image ships, so there is only one supported PHP.
- [Docker](https://www.docker.com/) with Compose v2 for the containerized dev stack.
- A running `publish` RabbitMQ broker (see [Docker](#docker)). This repo does not run
  its own, and the broker must declare the `delays` exchange.

## Environment variables

| Variable          | Required | Default | Description                                                                                       |
| ----------------- | -------- | ------- | --------------------------------------------------------------------------------------------------- |
| `AMQP_DSN`        | yes      | none    | RabbitMQ connection string, e.g. `amqp://app:secret@rabbitmq:5672/%2f`                            |
| `AMQP_HEARTBEAT`  | no       | `10`    | AMQP heartbeat in seconds; must match the broker's setting                                        |
| `JOB_STORAGE_DIR` | yes      | none    | Build scratch root. The worker creates `<build_id>/input` and `/output` under it                  |
| `PUBLISHED_DIR`   | yes      | none    | Where finished sites are published, as `<static_site_id>/<slug>/`. An external nginx serves this  |
| `PUBLISH_BASE_URL`| yes      | none    | The public URL `PUBLISHED_DIR` is served at. `publish_url` is `<base>/<static_site_id>/<slug>/`   |
| `MAX_DOWNLOAD_MB` | yes      | none    | Ceiling on a single content download, in megabytes. Minimum `1`                                   |

Only `AMQP_HEARTBEAT` has a fallback. An unset variable stops the deployment rather than
being guessed. A wrong `PUBLISHED_DIR` lets builds succeed and publish where nothing
serves them. A wrong `PUBLISH_BASE_URL` reports a `publish_url` that does not resolve.

`PUBLISHED_DIR` must be readable by the uid that serves it. The worker runs as root and
creates `output/` at `0750`. `JobWorkspace::publish()` sets the published tree to `0755`
for directories and `0644` for files. No unit test can verify this.

## Local development

```bash
composer install
mkdir -p /tmp/ssg/{build_temp,published}
AMQP_DSN="amqp://app:secret@localhost:5672/%2f" \
JOB_STORAGE_DIR=/tmp/ssg/build_temp \
PUBLISHED_DIR=/tmp/ssg/published \
PUBLISH_BASE_URL=http://localhost:8081 \
MAX_DOWNLOAD_MB=256 \
php bin/console messenger:consume builds -vv
```

This needs a RabbitMQ broker at that DSN (publish's dev stack exposes `5672` on
`127.0.0.1`) and `ext-amqp` on the host. The two directories are created if missing,
along with each job's folders underneath them.

## Docker

This repo ships one Dockerfile, `docker/Dockerfile`, and no compose file. The full stack lives in
`publish` and builds this repo from a sibling checkout:

```bash
docker compose -f ../publish/docker/dev/compose.yaml up --build
```

To build the image on its own:

```bash
docker build -f docker/Dockerfile -t ssg-worker .
```

The build context is the repo root, so `.dockerignore` stays there.

The image installs `ext-amqp` and `pcntl` and defaults to
`messenger:consume builds --time-limit=3600 --memory-limit=512M`. It exits every hour by
design and must run under a restart policy.

`pcntl` is what lets Symfony install its `SIGTERM` handler. Without it `docker stop`
kills the worker mid-build, the message is redelivered, and a build attempt is spent.

`vendor/` is installed at image build time. Compose keeps it behind an anonymous volume
so the `/app` bind mount does not shadow it. Rebuild with `--build` after changing
`composer.json`.

## Tests

```bash
php bin/phpunit
```

The tests use real collaborators rather than mocks, because the classes are `final` and
PHP cannot mock them. `MockHttpClient` replaces the network and a temp directory replaces
the volumes. Status codes, oversized bodies, broken transfers, traversal attempts,
staging and the swap, cross-mount copies, cleanup, failure reporting and error redaction
all run offline.

Passing tests say nothing about whether `messenger:consume builds` survives its first
retry. `InMemoryTransportFactory` discards every transport option, so nothing under
`options:` in `messenger.yaml` is exercised, including the `delays` exchange the broker
must declare.
[docs/build-pipeline.md](docs/build-pipeline.md#what-the-test-suite-cannot-reach) lists
what that leaves uncovered and which dev-stack checks cover it instead.

## Layout

```text
docker/Dockerfile               php:8.5-cli-alpine + ext-amqp + pcntl, builds vendor/ at image build time
config/
  packages/messenger.yaml       builds transport and retry_strategy
  packages/property_info.yaml   constructor extractor, so BuildJob can be built
  services.yaml                 the two storage roots, SSRF-guarded http client
src/
  Job/
    JobWorkspace.php            every path, the id allow-list, and a build's lifecycle:
                                reset() before, publish() on success, clear() at the end
    ContentDownloader.php       streams content_download_url to disk, scheme-checked and size-capped
    ArchiveExtractor.php        unpacks the archive into input/content_unarchived
    SiteRenderer.php            renders the extracted pages into output/
    StatusNotifier.php          PUTs the outcome to callback_status_url
    Filesystem.php              filesystem helpers; every error carries the OS message
  Message/
    BuildJob.php                the message shape for q.builds, mirroring publish's message class
    BuildJobHandler.php         registered in services.yaml, runs the build and reports it published
    BuildFailureHandler.php     reports a build that ran out of attempts, deletes it, and keeps
                                volume paths out of what the client is sent
docs/                           build-pipeline.md (the pipeline), roadmap.md (limitations)
tests/                          PHPUnit tests for the above
```
