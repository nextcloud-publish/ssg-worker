# ssg-worker

Nextcloud publish worker building static sites from Nextcloud Collectives markdown pages. It uses the ssg-library to render the sites.
using the ssg-library and is based on Symfony and Symfony Messenger.
No HTTP server, no database, no templating — it connects to RabbitMQ via [Symfony Messenger](https://symfony.com/doc/current/messenger.html)
and consumes build jobs from the `q.builds` queue (the same queue `publish`
enqueues onto). For each job it creates an `input/` + `output/` folder pair on
the volume it shares with `publish`, downloads the job's content archive into
`input/`, extracts it and renders the pages into `output/`. It then reports the
outcome on `q.build-results` or `q.build-failures`, where `publish`'s result
worker picks it up, publishes the site and calls the job's
`callback_status_url`. Progress and failures are both logged via PHP's
`error_log()` and captured by `docker logs`.

See [docs/build-pipeline.md](docs/build-pipeline.md) for the whole system, what changed
to build it, and the AMQP behaviour that will bite you if you touch it.

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


| Field                  | Used for                                                   |
| ---------------------- | ---------------------------------------------------------- |
| `build_id`             | names the job's folder, and identifies it end to end        |
| `static_site_id`       | names the job's folder under `JOB_STORAGE_DIR`, and the published site |
| `content_download_url` | the archive to fetch; only `http`/`https` are accepted     |
| `slug`                 | titles the rendered site, as the header link on every page |
| `callback_status_url`  | passed through to the outcome message; called by `publish`  |


`static_site_id` and `build_id` both arrive from an HTTP payload and both become
directory names on a shared volume, so each has to match
`/^[A-Za-z0-9_-]{1,128}$/` — the same allow-list `publish` applies, so changing
it means updating both repos together. Dots are excluded outright, which keeps a
bare `..` from passing.

Creating the folders is idempotent — a redelivery of the same build reuses them
and leaves their contents alone.

## Reporting the outcome

Every job ends with exactly one message: `BuildSucceeded` on `q.build-results`,
or `BuildFailed` on `q.build-failures`. `publish`'s result worker consumes both,
publishes or quarantines the build, and calls the job's `callback_status_url`.

**A failed build is not an exception any more.** `BuildJobHandler` catches,
dispatches a `BuildFailed` carrying the real reason, and returns — so the
`BuildJob` is acknowledged, which is what `retry_strategy.max_retries: 0` always
asked for, except the outcome now leaves a trace instead of vanishing. Carrying
the cause in the message body is the whole reason these are explicit messages
rather than a broker dead-letter: RabbitMQ's `x-first-death-reason` only ever
says `rejected`, `delivery_limit` or `expired`, never `tar: unexpected EOF`.

**A failed broker still is an exception.** The `BuildSucceeded` dispatch sits
outside that `try`, so an outage while reporting surfaces as a transport failure
rather than being misreported as a broken build. `builds` therefore has
`max_retries: 2`: at `0` a broker hiccup at that moment would discard a
*completed* build, because `RejectRedeliveredMessageMiddleware` republishes a
redelivered message "as retries with a retry limit" and there was no retry to
hand off to. That retry also needs the transport's `exchange: { name: '' }`
block — without it Messenger republishes to a derived `messages` exchange that
does not exist, and the 404 kills the worker inside `Worker::ack()`.

Retries that run out land on `q.build-dead` via the `x.build-dead` fanout. The
fanout is not decoration: `AmqpSender` leaks the *original* routing key onto a
message sent to a failure transport, so the default exchange would put it
straight back on `q.builds` in a loop.

## Requirements

- PHP >= 8.5 and [Composer](https://getcomposer.org/) for local runs — the same
  version the runtime image ships, so there is only one supported PHP
- [Docker](https://www.docker.com/) with Compose v2 for the containerized dev stack
- A running `publish` RabbitMQ broker (see [Docker](#docker-dev-stack) below) — this
  repo does not run its own broker



## Environment variables


| Variable          | Required | Default | Description                                                                                                    |
| ----------------- | -------- | ------- | -------------------------------------------------------------------------------------------------------------- |
| `AMQP_DSN`        | yes      | none    | RabbitMQ connection string, e.g. `amqp://app:secret@rabbitmq:5672/%2f`                                         |
| `AMQP_HEARTBEAT`  | no       | `10`    | AMQP heartbeat interval in seconds; must match the broker's setting                                            |
| `JOB_STORAGE_DIR` | yes      | none    | Root of the job volume shared with `publish`; the worker creates `<static_site_id>/<build_id>/input` + `/output` under it |
| `MAX_DOWNLOAD_MB` | yes      | none    | Ceiling on a single content download, in megabytes (e.g. `256`); minimum `1`                                   |


`AMQP_DSN`, `JOB_STORAGE_DIR` and `MAX_DOWNLOAD_MB` have no fallback — an unset
variable is a broken deployment. `JOB_STORAGE_DIR` has to name the same
directory `publish` uses, and the download cap guards that shared volume, so
guessing a value for either would hide a misconfiguration rather than surface
it.

## Local development

```bash
composer install
mkdir -p /tmp/ssg-jobs
AMQP_DSN="amqp://app:secret@localhost:5672/%2f" \
JOB_STORAGE_DIR=/tmp/ssg-jobs \
MAX_DOWNLOAD_MB=256 \
php bin/console messenger:consume builds -vv
```

This assumes a RabbitMQ broker is reachable at that DSN (`publish`'s dev stack
exposes `5672` on `127.0.0.1`) and that the host has `ext-amqp` available (see
[Requirements](#requirements)). `JOB_STORAGE_DIR` itself is created if missing,
along with each job's folders underneath it.

## Docker

This repo ships a single `Dockerfile` at its root and **no compose file of its
own**. The full stack lives in `publish/docker/dev/compose.yaml`, which builds
this repo from a sibling checkout — so clone both into the same parent
directory. Checkout [publish](https://github.com/nextcloud-publish/publish) for
a system overview.

```bash
docker build -t ssg-worker .
```

The image installs `ext-amqp` with PIE and `ext-pcntl` with
`docker-php-ext-install`, and defaults to `messenger:consume builds`, so the
built image is ready to consume immediately.
`ext-pcntl` is what lets Messenger install its `SIGTERM` handler: without it
`docker stop` kills the worker mid-build, the message stays unacked until
`consumer_timeout`, and `RejectRedeliveredMessageMiddleware` rejects the
redelivery before the handler ever runs.

`vendor/` is installed at image build time; the compose file keeps it behind an
anonymous volume so the `/app` bind mount does not shadow it — rebuild with
`--build` after changing `composer.json`.

## Tests

```bash
php bin/phpunit
```

`JobWorkspace`, `ContentDownloader`, `ArchiveExtractor`, `SiteRenderer` and
`BuildJobHandler` are covered in full — `MockHttpClient` replaces real network
calls and a temp directory replaces the volume, so status codes, oversized
bodies, broken transfers, the traversal cases, idempotency across redeliveries
and a download that is not a valid archive all run offline. `BuildJobHandlerTest`
uses a recording `MessageBusInterface` rather than a mock, and asserts on the
outcome message that reached it.

`tests/Message/MessageContractTest.php` pins the exact `type` header and JSON
body of all three messages against hard-coded fixtures, and **the same file with
the same fixtures exists in `publish`**. Those classes are duplicated by hand
across the two repos, so renaming a property on one side is otherwise a decode
failure in production that nothing catches first. If that test needs editing,
the other repo needs the same edit in the same commit.

**What no test here can catch.** `InMemoryTransportFactory` discards its options
entirely, so every AMQP detail is invisible offline: a wrong queue name, a queue
or exchange missing from `definitions.json`, the `exchange:` block whose absence
kills a retrying worker, the delay exchange, the quorum-queue redeclare, and the
routing-key loop on the failure transport. The suite passes without the AMQP
stack installed at all, which is worth knowing: passing tests say nothing about
whether `messenger:consume builds` can connect. Only `publish/docker/dev/`
exercises any of that.

## Layout

```
Dockerfile                   php:8.5-cli-alpine, builds vendor/ at image build time
config/
  packages/messenger.yaml    builds transport + the three outcome transports
  packages/property_info.yaml constructor extractor, so BuildJob can be built
  services.yaml              JOB_STORAGE_DIR and MAX_DOWNLOAD_MB wiring
src/
  Job/                       JobWorkspace: creates <static_site_id>/<build_id>/input + /output under JOB_STORAGE_DIR
                             ContentDownloader: streams content_download_url to disk, scheme-checked and size-capped
                             ArchiveExtractor: unpacks the archive into input/content_unarchived
                             SiteRenderer: renders the extracted pages into output/
                             Helper: creates a directory or throws with the filesystem's own reason
  Message/                   BuildJob: the message shape for q.builds, mirroring publish's message class
                             BuildSucceeded / BuildFailed: the outcome messages, also mirrored
                             BuildJobHandler: registered in services.yaml, runs the pipeline and reports
docs/build-pipeline.md      the system, what changed here, and the AMQP gotchas
tests/                       PHPUnit tests for the above
  Message/MessageContractTest.php   the cross-repo wire contract (mirrored in publish)
  Support/RecordingBus.php          a real MessageBusInterface that keeps what it was handed
```

