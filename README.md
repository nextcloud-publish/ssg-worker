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

[docs/build-pipeline.md](docs/build-pipeline.md) describes the directories, publishing,
retries, the callback body, and what the tests do not cover.

## Works together with the publish component

This worker does not run on its own. `publish` is the other half of the service and owns
everything this repo does not:

- the HTTP API users publish through, which enqueues one `BuildJob` per build
- the RabbitMQ broker and its topology (the `q.builds` queue, the `delays` exchange, the
  user and the permissions), imported from
  `publish/docker/dev/rabbitmq-config/definitions.json` at boot
- the compose stack for the whole service, which builds this repo from a sibling checkout
- the validation of every `BuildJob` field

Without a running `publish` there is no broker to connect to and no job to build.

`App\Message\BuildJob` declares the same namespace, class name and property names as
publish's message class, so the two have to change together. Messenger resolves the target
class from the message's `type` header and the serializer maps JSON keys onto constructor
parameter names, so a mismatch on either breaks decoding.

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
| `content_download_url` | the archive to fetch                                                       |
| `callback_status_url`  | where the outcome is PUT; private network addresses are refused, except in dev |

`created_at` is decoded but never read.

publish checks every field before it enqueues a job: `static_site_id` and `slug` match
`[A-Za-z0-9_-]{1,128}`, both URLs are `http` or `https` with a host. The worker uses the
fields without checking them again, and `static_site_id`, `build_id` and `slug` become
directory names. `slug` and `title` are separate fields because the slug excludes spaces.

Each attempt starts from an empty workdir. `SsgLab\SiteBuilder` never clears its output
directory, so reusing one would republish pages that were deleted from the collective.

### Outcomes

| Outcome           | What happens                                                                                                                                                                      |
| ----------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Success           | `PUBLISHED_DIR/<static_site_id>/` is replaced by one holding only `<slug>/`, the build tree is cleared, and `published` is PUT with    the `publish_url`                          |
| Transient failure | rethrown, and the transport redelivers after 15s. `max_retries: 1` gives two build attempts; on the second failure `BuildFailureHandler` deletes the build tree and PUTs `failed`  |
| Permanent failure | reported `failed` on the first delivery, never retried. Caused by an archive with no Markdown page, or a callback answered with a 3xx or a 4xx other than 408 and 429 |

The callback body is `{"status": "published", "data": {"publish_url": ...}}` or
`{"status": "failed", "data": {"error_message": ...}}`, nothing else.

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
| `JOB_STORAGE_DIR` | no       | `/opt/ssg/build_temp` | Build scratch root. The worker creates `<build_id>/input` and `/output` under it    |
| `PUBLISHED_DIR`   | no       | `/opt/ssg/published`  | Where finished sites are published, as `<static_site_id>/<slug>/`. An external nginx serves this |
| `PUBLISH_BASE_URL`| yes      | none    | The public URL `PUBLISHED_DIR` is served at. `publish_url` is `<base>/<static_site_id>/<slug>/`   |
| `MAX_DOWNLOAD_MB` | yes      | none    | Ceiling on a single content download, in megabytes. Minimum `1`                                   |
| `DOWNLOAD_TIMEOUT_SECONDS`      | no | `30`  | Idle timeout of the content download                                                  |
| `DOWNLOAD_MAX_DURATION_SECONDS` | no | `300` | Maximum total duration of the content download                                        |
| `DOWNLOAD_MAX_REDIRECTS`        | no | `3`   | Redirects followed for the content download                                           |
| `CALLBACK_TIMEOUT_SECONDS`      | no | `5`   | Idle timeout of the status callback PUT                                               |
| `CALLBACK_MAX_DURATION_SECONDS` | no | `10`  | Maximum total duration of the status callback PUT; keep it well below `consumer_timeout` |
| `CALLBACK_MAX_REDIRECTS`        | no | `0`   | Redirects followed for the status callback PUT                                        |
| `LOG_LEVEL`                     | no | `info`, `debug` in dev | Lowest level logged: `debug`, `info`, `notice`, `warning`, `error`   |

The two directory defaults are their paths inside the container; set them for a deployment
outside Docker. `AMQP_DSN`, `PUBLISH_BASE_URL` and `MAX_DOWNLOAD_MB` have no fallback: an
unset one stops the deployment rather than being guessed. A wrong `PUBLISHED_DIR` lets
builds succeed and publish where nothing serves them. A wrong `PUBLISH_BASE_URL` reports a
`publish_url` that does not resolve.

`PUBLISHED_DIR` must be readable by the uid that serves it. The worker runs as `ssg-worker` and
creates `output/` at `0750`. `JobWorkspace::publish()` sets the published tree to `0755`
for directories and `0644` for files. No unit test can verify this.

## Logging

[Monolog](https://symfony.com/doc/current/logging.html) writes every line to
`var/log/ssg-worker.log` and to stderr, from `LOG_LEVEL` up. The file is not rotated; mount
`var/log/` from the host and rotate it there. The replicas share the file.

```text
[2026-10-31T14:03:13.102+00:00] messenger.WARNING: Error thrown while handling message App\Message\BuildJob. Sending for retry #1 … {…} {"hostname":"3f9a1c2b7d4e"}
```

`hostname` is the container id. In prod the `messenger` and `http_client` channels write from
`warning` up. Timestamps follow `TZ`.

## Local development

```bash
composer install
mkdir -p /tmp/ssg/{build_temp,published}
APP_ENV=dev \
AMQP_DSN="amqp://app:secret@localhost:5672/%2f" \
JOB_STORAGE_DIR=/tmp/ssg/build_temp \
PUBLISHED_DIR=/tmp/ssg/published \
PUBLISH_BASE_URL=http://localhost:8081 \
MAX_DOWNLOAD_MB=256 \
php bin/console messenger:consume builds
```

This needs a RabbitMQ broker at that DSN (publish's dev stack exposes `5672` on
`127.0.0.1`) and `ext-amqp` on the host. `.env` sets `APP_ENV=prod`, where the callback
client refuses private addresses, so a local callback receiver needs `APP_ENV=dev`.

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
so the `/ssg-worker` bind mount does not shadow it. After changing `composer.json`, rebuild
with `--build --renew-anon-volumes`; without the second flag compose keeps the old `vendor/`.

## Images

CI pushes the image to `ghcr.io/nextcloud-publish/ssg-worker`, built from stage `release`:

| Tag | Points to |
| --- | --- |
| `latest` | the newest release |
| `1`, `1.2` | the newest release of that major or minor version (no `0` during 0.x) |
| `1.2.3` | one release |
| `main` | the newest build of `main` |
| `<branch>` | the newest build of a pull request from that branch; `/` becomes `-` |
| `sha-<commit>` | the build of one commit |

A release is a git tag like `1.2.3`, without a leading `v`, pushed to `main`. It must match
the version in `CHANGELOG.md`.

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
[docs/build-pipeline.md](docs/build-pipeline.md#not-covered-by-the-tests) lists
what that leaves uncovered and which dev-stack checks cover it instead.

PHPStan checks `src/` and `tests/` at level 10. It reads the service container of the dev
environment, so warm that cache first:

```bash
php bin/console cache:warmup --env=dev && vendor/bin/phpstan analyse
```

[pre-commit](https://pre-commit.com/) runs the CI lint steps, PHPStan and the tests before each commit.
It needs PHP 8.5 and `composer install` on the host. Install the hook once:

```bash
pre-commit install
```

## Layout

```text
docker/Dockerfile               alpine + php85 packages + GNU tar; stages base (no source) and release
config/
  packages/messenger.yaml       builds transport and retry_strategy
  packages/property_info.yaml   constructor extractor, so BuildJob can be built
  services.yaml                 the two storage roots, SSRF-guarded http client
src/
  Job/
    JobWorkspace.php            every path, and a build's lifecycle:
                                reset() before, publish() on success, clear() at the end
    ContentDownloader.php       streams content_download_url to disk, size-capped
    ArchiveExtractor.php        unpacks the archive into input/content_unarchived
    SiteRenderer.php            renders the extracted pages into output/
    StatusNotifier.php          PUTs the outcome to callback_status_url
    Filesystem.php              filesystem helpers; every error carries the OS message
  Message/
    BuildJob.php                the message shape for q.builds, mirroring publish's message class
    BuildJobHandler.php         registered in services.yaml, runs the build and reports it published
    BuildFailureHandler.php     reports a build that ran out of attempts, deletes it, and keeps
                                volume paths out of what the client is sent
docs/build-pipeline.md          the pipeline in detail
tests/                          PHPUnit tests for the above
```
