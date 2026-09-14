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

- PHP >= 8.4 and [Composer](https://getcomposer.org/) for local runs
- [Docker](https://www.docker.com/) with Compose v2 if you want to run the
containerized stack
- `ext-amqp`, which `symfony/amqp-messenger` builds on. It is a *transitive*
platform requirement, so a host without it fails `composer install` itself,
not just `messenger:consume builds`. Install it with
[PIE](https://github.com/php/pie) (the image does the same), or skip it with
`composer install --ignore-platform-req=ext-amqp` — the test suite runs
without the extension, only the consumer needs it.
- A running `publish` RabbitMQ broker — this repo does not run its own broker



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

This repo ships a single `Dockerfile` at its root and **no compose file of its
own**. Checkout [publish](https://github.com/nextcloud-publish/publish) for a system overview.

```bash
docker build -t ssg-worker .
```

The image installs `ext-amqp` with PIE and defaults to `messenger:consume builds -vv`, so the built image is ready to consume immediately. `vendor/` is
installed at image build time; the compose file keeps it behind an anonymous
volume so the `/app` bind mount does not shadow it — rebuild with `--build`
after changing `composer.json`.

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
Dockerfile                   php:8.5-cli-alpine, builds vendor/ at image build time
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

