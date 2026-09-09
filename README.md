# ssg-worker

A minimal Symfony 8.1 console worker. No HTTP, no database, no templating —
it connects to RabbitMQ, listens for build jobs on the `q.builds` queue (the
same queue `publish` enqueues onto), and creates each job's `input/` + `output/`
folder pair on the volume it shares with `publish`. Progress and failures are
logged via PHP's `error_log()` (captured by `docker logs` either way).

It does not build sites yet: the folders it creates stay empty.

## Build job messages

The worker reads one field from each `q.builds` message and ignores the rest:

| Field            | Used for                                                        |
| ---------------- | --------------------------------------------------------------- |
| `static_site_id` | names the job's folder under `JOB_STORAGE_DIR`                  |

`static_site_id` reaches this service from an HTTP payload by way of the queue
and becomes a directory name on a shared volume, so it has to match
`/^[A-Za-z0-9_-]{1,128}$/` — the same allow-list `publish` applies. Dots are
excluded outright rather than filtered out, which keeps a bare `..` from
passing as a valid name. Deliberately wider than the UUID `publish` actually
sends: the two sides have to agree on which jobs are buildable, so this
allow-list only changes in both repos at once.

Creating the folders is idempotent — a rebuild of the same site reuses them,
and leaves their contents alone.

A message that is malformed, or whose folders cannot be created, is logged and
**acked**, not requeued: there is no dead-letter queue or retry counter yet, so
requeuing a job that can never succeed would redeliver it forever.

## Requirements

- PHP >= 8.4 and [Composer](https://getcomposer.org/) for local runs
- [Docker](https://www.docker.com/) with Compose v2 for the containerized dev stack
- A running `publish` RabbitMQ broker (see [Docker](#docker-dev-stack) below) — this
  repo does not run its own broker

## Environment variables

| Variable         | Required | Default | Description                                                        |
| ----------------- | -------- | ------- | -------------------------------------------------------------------- |
| `AMQP_DSN`         | yes      | none    | RabbitMQ connection string, e.g. `amqp://app:secret@rabbitmq:5672/%2f` |
| `AMQP_HEARTBEAT`   | no       | `10`    | AMQP heartbeat interval in seconds; must match the broker's setting  |
| `JOB_STORAGE_DIR`  | yes      | none    | Root of the job volume shared with `publish`; the worker creates `<static_site_id>/input` + `/output` under it |

`AMQP_DSN` and `JOB_STORAGE_DIR` have no fallback — an unset variable is a
broken deployment. `JOB_STORAGE_DIR` in particular has to name the same
directory `publish` uses, so guessing a path would send builds somewhere
nothing else looks at rather than surfacing the misconfiguration.

## Local development

```bash
composer install
mkdir -p /tmp/ssg-jobs
AMQP_DSN="amqp://app:secret@localhost:5672/%2f" \
JOB_STORAGE_DIR=/tmp/ssg-jobs \
php bin/console app:listen-for-build-jobs
```

This assumes a RabbitMQ broker is reachable at that DSN (see `publish`'s dev
stack, which exposes `5672` on `127.0.0.1`). `JOB_STORAGE_DIR` itself is
created if missing, along with each job's folders underneath it.

## Docker (dev stack)

The worker does not start its own RabbitMQ — it joins the network that
`publish`'s dev stack creates. Start that stack first, then this one:

```bash
# 1. start publish's stack (creates the RabbitMQ broker + shared network)
docker compose -f ../publish/docker/compose.dev.yaml up -d --build

# 2. start the worker, joining that network
docker compose -f docker/compose.dev.yaml up --build
```

The project directory is bind-mounted into the container, so code changes are
picked up on restart. `vendor/` is installed at image build time and kept via
an anonymous volume, so it survives the bind mount — rebuild the image
(`--build`) after changing `composer.json`.

Watch the logs:

```bash
docker compose -f docker/compose.dev.yaml logs -f worker
```

## Tests

```bash
php bin/phpunit
```

`JobWorkspace` and `BuildJobHandler` are covered in full over a temp directory
— every rejected message shape, the traversal cases, and idempotency across
rebuilds all run offline.

Tests also cover `AmqpBuildJobListener`'s fail-fast behaviour on a
missing/invalid `AMQP_DSN` — it connects lazily, so this is testable without a
broker. The actual listen loop isn't unit tested, same as `publish`'s
`AmqpBuildQueue`: it needs a real broker.

## Layout

```
config/                    Symfony configuration (no routing — no HTTP)
src/Command/                ListenForBuildJobsCommand: app:listen-for-build-jobs
src/Messaging/               AmqpBuildJobListener: connects, listens on q.builds, hands each message to the handler
                            BuildJobHandler: validates one message and prepares its workspace
src/Storage/                JobWorkspace: creates <static_site_id>/input + /output under JOB_STORAGE_DIR
tests/                      PHPUnit tests for the above
docker/
  Dockerfile                php:8.5-cli-alpine, runs app:listen-for-build-jobs
  compose.dev.yaml          single-service dev stack, joins publish's network
```
