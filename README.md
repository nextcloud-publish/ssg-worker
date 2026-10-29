# ssg-worker

Nextcloud publish worker building static sites from Nextcloud Collectives markdown pages. It uses the ssg-library to render the sites.
using the ssg-library and is based on Symfony and Symfony Messenger.
No HTTP server, no database, no templating — it connects to RabbitMQ via [Symfony Messenger](https://symfony.com/doc/current/messenger.html)
and consumes build jobs from the `q.builds` queue (the same queue `publish`
enqueues onto). For each job it creates an `input/` + `output/` folder pair on
the volume it shares with `publish`, downloads the job's content archive into
`input/`, extracts it and renders the pages into `output/`. Progress and
failures are both logged via PHP's `error_log()` and captured by `docker logs`.

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
| `static_site_id`       | names the job's folder under `JOB_STORAGE_DIR`             |
| `content_download_url` | the archive to fetch; only `http`/`https` are accepted     |
| `slug`                 | titles the rendered site, as the header link on every page |


`static_site_id` arrives from an HTTP payload and becomes a directory name on a
shared volume, so it has to match `/^[A-Za-z0-9_-]{1,128}$/` — the same
allow-list `publish` applies, so changing it means updating both repos
together. Dots are excluded outright, which keeps a bare `..` from passing.

Creating the folders is idempotent — a rebuild of the same site reuses them and
leaves their contents alone.

A message whose handler throws — an unsafe `static_site_id`, folders that
cannot be created, a failed download, an archive that will not extract — is not
retried (`retry_strategy.max_retries: 0`): log and move on, no dead-letter
queue yet.

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
| `JOB_STORAGE_DIR` | yes      | none    | Root of the job volume shared with `publish`; the worker creates `<static_site_id>/input` + `/output` under it |
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

This repo ships one Dockerfile, `docker/Dockerfile`, and no compose file. Its default stage
`release` holds the source. Stage `base` leaves it out for the dev compose, which mounts the
checkout at `/ssg-worker`. The full stack lives in `publish` and builds this repo from a
sibling checkout:

```bash
docker compose -f ../publish/docker/dev/compose.yaml up --build
```

To build the image on its own:

```bash
docker build --pull -f docker/Dockerfile -t ssg-worker .
```

The build context is the repo root, so `.dockerignore` stays there. `--pull` fetches the
current `alpine:latest`; a local copy older than Alpine 3.23 has no `php85` packages.

The image is Alpine with Alpine's PHP 8.5 packages, `ext-amqp` and `pcntl` included. It runs
`messenger:consume builds --time-limit=3600 --memory-limit=512M`, so it exits every hour and
must run under a restart policy. Other limits are set with `command:` in the compose file.

The worker runs as the user `ssg-worker`, uid and gid 10001; `--build-arg UID=… GID=…`
changes them. The host gives that uid the directories mounted at `JOB_STORAGE_DIR` and
`PUBLISHED_DIR`. The dev compose runs it with your host ids instead
(`SSG_WORKER_UID`/`SSG_WORKER_GID`, default 1000).

PHP reads its time zone from `TZ`, default UTC.

`pcntl` is what lets Symfony install its `SIGTERM` handler. Without it `docker stop`
kills the worker mid-build, the message is redelivered, and a build attempt is spent.

`vendor/` is installed at image build time. Compose keeps it behind an anonymous volume
so the `/ssg-worker` bind mount does not shadow it. Rebuild with `--build` after changing
`composer.json`.

## Tests

```bash
php bin/phpunit
```

`JobWorkspace`, `ContentDownloader`, `ArchiveExtractor`, `SiteRenderer` and
`BuildJobHandler` are covered in full — `MockHttpClient` replaces real network
calls and a temp directory replaces the volume, so status codes, oversized
bodies, broken transfers, the traversal cases, idempotency across rebuilds and
a download that is not a valid archive all run offline.

`BuildJobHandlerTest` constructs a `BuildJob` directly and invokes the handler,
so it covers the pipeline but nothing of the transport. Neither the decoding of
a raw message body nor the consume loop is unit tested — both belong to
Messenger, and testing them needs a real broker. The suite therefore passes
without the AMQP stack installed at all, which is worth knowing: passing tests
say nothing about whether `messenger:consume builds` can connect.

## Layout

```
docker/Dockerfile            alpine + php85 packages + GNU tar; stages base (no source) and release
config/
  packages/messenger.yaml    builds transport (AMQP_DSN, no auto_setup, no retry)
  packages/property_info.yaml constructor extractor, so BuildJob can be built
  services.yaml              JOB_STORAGE_DIR and MAX_DOWNLOAD_MB wiring
src/
  Job/                       JobWorkspace: creates <static_site_id>/input + /output under JOB_STORAGE_DIR
                             ContentDownloader: streams content_download_url to disk, scheme-checked and size-capped
                             ArchiveExtractor: unpacks the archive into input/content_unarchived
                             SiteRenderer: renders the extracted pages into output/
                             Helper: creates a directory or throws with the filesystem's own reason
  Message/                   BuildJob: the message shape for q.builds, mirroring publish's message class
                             BuildJobHandler: registered in services.yaml, runs the whole per-job pipeline
tests/                       PHPUnit tests for the above
```

