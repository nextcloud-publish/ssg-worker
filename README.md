# ssg-worker

A minimal Symfony 8.1 console worker. No HTTP, no database, no templating —
it connects to RabbitMQ, listens for build jobs on the `q.builds` queue (the
same queue `publish` enqueues onto), and logs each one via PHP's `error_log()`
(captured by `docker logs` either way).

This is the skeleton stage: it does not build sites yet, it only proves the
listen-and-log pipeline works end to end.

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

`AMQP_DSN` has no fallback — an unset variable is a broken deployment.

## Local development

```bash
composer install
AMQP_DSN="amqp://app:secret@localhost:5672/%2f" php bin/console app:listen-for-build-jobs
```

This assumes a RabbitMQ broker is reachable at that DSN (see `publish`'s dev
stack, which exposes `5672` on `127.0.0.1`).

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

Tests cover `AmqpBuildJobListener`'s fail-fast behaviour on a missing/invalid
`AMQP_DSN` — it connects lazily, so this is testable without a broker. The
actual listen loop and per-message logging aren't unit tested, same as
`publish`'s `AmqpBuildQueue`: they need a real broker.

## Layout

```
config/                    Symfony configuration (no routing — no HTTP)
src/Command/                ListenForBuildJobsCommand: app:listen-for-build-jobs
src/Messaging/               AmqpBuildJobListener: connects, listens on q.builds, logs each message via error_log()
tests/                      PHPUnit tests for the above
docker/
  Dockerfile                php:8.5-cli-alpine, runs app:listen-for-build-jobs
  compose.dev.yaml          single-service dev stack, joins publish's network
```
