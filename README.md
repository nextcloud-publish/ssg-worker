# ssg-worker

A minimal Symfony 8.1 console worker. No HTTP server, no database, no
templating — it connects to RabbitMQ via [Symfony Messenger](https://symfony.com/doc/current/messenger.html)
and consumes build jobs from the `q.builds` queue (the same queue `publish`
enqueues onto). For each job it creates an `input/` + `output/` folder pair on
the volume it shares with `publish`, downloads the job's content archive into
`input/`, extracts it and renders the pages into `output/`. Progress and
failures are logged via PHP's `error_log()` (captured by `docker logs` either
way).

## Queueing

Consumption goes through Symfony Messenger: `config/packages/messenger.yaml`
wires a `builds` transport to the broker, and `BuildJobHandler` is registered
against it with `#[AsMessageHandler]` on `App\Message\BuildJob` — the same
class name and property set as `publish`'s message, which is what lets
Messenger's `type`-header-driven decoding resolve to a real class without any
name-mapping config. `config/packages/property_info.yaml` enables the
constructor extractor the serializer needs to hydrate that readonly class.

The transport does not declare any topology (`auto_setup: false`): the broker
imports the queue, user and permissions from
`publish/docker/rabbitmq-config/definitions.json` at boot and owns them, same
as on the `publish` side.

The handler takes an already-decoded `BuildJob`, so a body that is not valid
JSON or does not resolve to that class never reaches it — the serializer
rejects it first. Of the fields it does read:

| Field                  | Used for                                                        |
| ---------------------- | --------------------------------------------------------------- |
| `static_site_id`       | names the job's folder under `JOB_STORAGE_DIR`                  |
| `content_download_url` | the archive to fetch; only `http`/`https` are accepted          |
| `slug`                 | titles the rendered site, as the header link on every page      |

`static_site_id` arrives from an HTTP payload and becomes a directory name on a
shared volume, so it has to match `/^[A-Za-z0-9_-]{1,128}$/` — the same
allow-list `publish` applies, so it only changes in both repos at once. Dots are
excluded outright, which keeps a bare `..` from passing.

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

| Variable         | Required | Default | Description                                                        |
| ----------------- | -------- | ------- | -------------------------------------------------------------------- |
| `AMQP_DSN`         | yes      | none    | RabbitMQ connection string, e.g. `amqp://app:secret@rabbitmq:5672/%2f` |
| `AMQP_HEARTBEAT`   | no       | `10`    | AMQP heartbeat interval in seconds; must match the broker's setting  |
| `JOB_STORAGE_DIR`  | yes      | none    | Root of the job volume shared with `publish`; the worker creates `<static_site_id>/input` + `/output` under it |
| `MAX_DOWNLOAD_MB`  | yes      | none    | Ceiling on a single content download, in megabytes (e.g. `256`); minimum `1` |

`AMQP_DSN`, `JOB_STORAGE_DIR` and `MAX_DOWNLOAD_MB` have no fallback — an unset
variable is a broken deployment. `JOB_STORAGE_DIR` has to name the same
directory `publish` uses, and the download cap guards that shared volume, so
guessing either would hide a misconfiguration rather than surface it.

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
own** — the multi-service stack lives outside it, in the sibling
`integration-test/docker-compose.yml`, which brings up `publish`, RabbitMQ, a
content host and this worker against a shared `job-data` volume.

```bash
docker build -t ssg-worker .

# or, with the rest of the stack:
cd ../integration-test && docker compose up -d --build
```

The image installs `ext-amqp` with PIE and defaults to `messenger:consume
builds -vv`, so it consumes as built. `vendor/` is installed at image build
time; the compose file keeps it behind an anonymous volume so the `/app` bind
mount does not shadow it — rebuild with `--build` after changing
`composer.json`.

## Tests

```bash
php bin/phpunit
```

`JobWorkspace`, `ContentDownloader`, `ArchiveExtractor`, `SiteRenderer` and
`BuildJobHandler` are covered in full — `MockHttpClient` stands in for the
network and a temp directory for the volume, so status codes, oversized
bodies, broken transfers, the traversal cases, idempotency across rebuilds and
a download that is not a valid archive all run offline.

`BuildJobHandlerTest` constructs a `BuildJob` directly and invokes the handler,
so it covers the pipeline but nothing of the transport. Neither the decoding of
a raw message body nor the consume loop is unit tested — both belong to
Messenger, and exercising them needs a real broker. The suite therefore passes
without the AMQP stack installed at all, which is worth knowing: green tests
say nothing about whether `messenger:consume builds` can connect.

## Layout

```
Dockerfile                   php:8.5-cli-alpine, builds vendor/ at image build time
config/
  packages/messenger.yaml    builds transport (AMQP_DSN, no auto_setup, no retry)
  packages/property_info.yaml constructor extractor, so BuildJob can be hydrated
  services.yaml              JOB_STORAGE_DIR and MAX_DOWNLOAD_MB wiring
src/
  Content/                   ContentDownloader: streams content_download_url to disk, scheme-checked and size-capped
                             ArchiveExtractor: unpacks the archive into input/content_unarchived
  Message/BuildJob.php       the q.builds wire contract, mirroring publish's message class
  Messaging/                 BuildJobHandler: #[AsMessageHandler], runs the whole per-job pipeline
  Rendering/                 SiteRenderer: renders the extracted pages into output/
  Storage/                   JobWorkspace: creates <static_site_id>/input + /output under JOB_STORAGE_DIR
tests/                       PHPUnit tests for the above
```
