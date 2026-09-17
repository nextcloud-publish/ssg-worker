# The build pipeline

How a build request becomes a published site, what changed in this repo to make that
happen, and the handful of things that will bite you if you touch it.

`publish` has a companion document, `docs/build-pipeline.md`, with the same system
overview and its own repo-specific half. It also owns the result worker, the callback
contract and the broker topology, so it is the one to read for those.

## What it looked like before

The pipeline had no return path. `publish` accepted `POST /build` and enqueued a
`BuildJob`; this repo rendered the site into `$JOB_STORAGE_DIR/<static_site_id>/output`
and stopped there.

- `BuildJobHandler` reported only through `error_log()`. On failure it threw, and with
  `retry_strategy: max_retries: 0` and no dead-letter queue the message was acknowledged
  and the job vanished.
- `callback_status_url` was carried on the message and read by nothing — this repo's own
  test passed `''` for it, which would have been impossible if anything consumed it.
- The job directory was keyed on `static_site_id` alone, and `SiteBuilder` never clears its
  output directory, so a rebuild inherited every page that had been deleted from the
  collective.
- Nothing was runnable end to end: `JOB_STORAGE_DIR` and `MAX_DOWNLOAD_MB` were set
  nowhere, and `config/services.yaml` pointed at an `integration-test/docker-compose.yml`
  that had never existed — so this repo could not even compile its container from a
  checked-in stack.

## What it looks like now

```
POST /build ──► q.builds ──► ssg-worker renders the site      ← this repo
                              ├─ ok   ──► BuildSucceeded ──► q.build-results ─┐
                              └─ fail ──► BuildFailed    ──► q.build-failures ┤
                                                                              ▼
                                        publish result-worker (messenger:consume)
                                          success: promote the site,   then POST success
                                          failure: quarantine the job, then POST failed
                                                                              │
                                              retries exhausted ──► x.build-dead (fanout)
                                                                          ──► q.build-dead
```

This repo's job ends the moment it dispatches an outcome. It never touches the published
tree, never calls the client, and never reads the result queues.

### Queues and exchanges

| Object | Kind | Written by | Drained by |
| --- | --- | --- | --- |
| `q.builds` | quorum queue | `publish`'s API | **this repo** |
| `q.build-results` | quorum queue | **this repo**, on success | `publish`'s result worker |
| `q.build-failures` | quorum queue | **this repo**, on failure | `publish`'s result worker |
| `q.build-dead` | quorum queue | Messenger, once retries are spent | nobody — a parking lot |
| `delays` | direct exchange | Messenger's retry machinery | — |
| `x.build-dead` | fanout exchange | the failure transport | bound to `q.build-dead` |

All of it is declared in `publish/docker/rabbitmq-config/definitions.json`, which the
broker imports at boot. This repo declares nothing: `auto_setup: false` everywhere.

### Directories

```
/opt/ssg/build_temp/<static_site_id>/<build_id>/{input,output}   JOB_STORAGE_DIR  ← this repo writes here
/opt/ssg/published/<static_site_id>/                             PUBLISHED_DIR    ← publish only
/opt/ssg/build_failed/<build_id>/                                FAILED_DIR       ← publish only
```

## Decisions taken

Recorded in full on the `publish` side; the short version of the two that shape this repo:

**The result worker lives in `publish`, not here.** This repo already had the HTTP client,
the filesystem layout and a consume-shaped image, which argued for putting it here. It lost
to the fact that `publish` owns the client relationship — `callback_status_url` arrives
through its API and the callback payload is a published contract. The price is that
`BuildSucceeded` and `BuildFailed` join `BuildJob` as classes duplicated by hand across two
repos, and `tests/Message/MessageContractTest.php` is what keeps them honest.

**Outcomes are explicit messages, not broker dead-lettering.** `BuildFailed` carries the
real reason in a readable JSON body. RabbitMQ's `x-first-death-reason` only ever says
`rejected`, `delivery_limit` or `expired` — never `tar: unexpected EOF`. Explicit messages
also survive a swap to a Doctrine/Postgres transport, which has no dead-letter concept at
all.

## What changed in this repo

### The handler now reports an outcome

`BuildJobHandler` takes a `MessageBusInterface`, wraps the pipeline in a `try`, and
dispatches `BuildFailed` on the way out of the catch without rethrowing. That is what
`max_retries: 0` ("log and ack, never redeliver") always asked for — except the outcome now
leaves a trace instead of vanishing.

**The `BuildSucceeded` dispatch sits outside that `try`, deliberately.** Inside it, a
broker outage while reporting would be caught and reported as a *build* failure — telling
the client their site is broken when it rendered perfectly. Neither dispatch is wrapped:
a transport failure has to surface so the message is retried.

A failed build is therefore no longer an exception, and four tests changed meaning
accordingly — they assert on the `BuildFailed` that reached a recording bus rather than on
a thrown exception. `tests/Support/RecordingBus.php` is a real `MessageBusInterface` rather
than a mock, matching this suite's existing preference for real collaborators.

### The job directory is keyed on `build_id` too

`JobWorkspace::createJobDirectories()` now takes `($staticSiteId, $buildId)` and produces
`<base>/<site>/<build>/{input,output}`. Both ids are validated against the same allow-list,
`/^[A-Za-z0-9_-]{1,128}$/` — `build_id` is `bin2hex(random_bytes(8))` on `publish`'s side
and passes unchanged, but nothing in the message itself guarantees that.

Two independent reasons, and either alone would justify it:

- The result worker uses "the temp directory is gone" as its duplicate guard. Keyed on the
  site alone, that cannot distinguish *already promoted* from *the next build of this site
  is mid-flight*.
- `SiteBuilder::build()` never clears its output directory, so a shared per-site `output/`
  kept pages deleted from the collective — and this design would then have published them.

### Three new transports

`build_results`, `build_failures` and `build_dead`, all publish-only: `queues: false`, an
`exchange` block, and `confirm_timeout: 5` so a broker crash between accepting the result
and flushing it cannot lose the only record that the build finished.

`queues: false` matters. Without the key the connection defaults to `['messages' => []]` —
inert for publishing, but `messenger:stats` would then `declareQueue()` a `messages` queue
on the broker, which is exactly the topology-declaring this stack forbids.

### `builds` gained retries, and had to

`max_retries: 2`, plus an `exchange: { name: '' }` block it did not previously need.

This is a consequence of the design rather than a nicety. With `max_retries: 0`, a broker
failure during the outcome dispatch discarded a **completed** build — nobody ever heard
about it. `RejectRedeliveredMessageMiddleware`'s whole purpose is to republish a redelivered
message "as retries with a retry limit", and `max_retries: 0` removed the thing it hands
off to, turning "log and ack" into "silently drop". Verified:
`RejectRedeliveredMessageException` does **not** implement `UnrecoverableExceptionInterface`,
so raising the limit genuinely restores that recovery path.

The `exchange` block is mandatory once retries exist — see *Things that will bite you*.

### Infrastructure

- `Dockerfile` installs `ext-pcntl` alongside `ext-amqp`, in **one** `RUN`, because the
  build toolchain is deleted at the end of it. Symfony registers its `SIGTERM` handler only
  when `function_exists('pcntl_signal')`, and the official images do not enable it; without
  it `docker stop` hard-kills the worker mid-build.
- The default command gained `--time-limit=3600 --memory-limit=512M`. Long-running PHP
  workers are expected to exit periodically and be restarted; compose's `restart:` policy
  is the restarter.
- `.env` gained `AMQP_HEARTBEAT=10`. The README had always documented that default, but
  nothing supplied it — so any run without the variable set failed. It only became visible
  once this repo gained its first kernel-booting test.
- `config/packages/framework.yaml` sets `test: true` under `when@test`, so
  `static::getContainer()` can reach non-public services. This repo had no kernel-booting
  test before.
- The dangling `integration-test/docker-compose.yml` references now point at
  `publish/docker/dev/compose.yaml`, which builds this repo from a **sibling checkout** —
  clone both into the same parent directory.

## Things that will bite you

Verified against the vendored Symfony 8.1, not recalled. The full list lives on the
`publish` side; these are the ones that apply here.

**A transport with `max_retries > 0` needs `exchange: { name: '' }`.** Retries republish
through the transport's own sender; with no exchange block the name is derived from the DSN
path and falls back to a literal `messages` exchange that does not exist. The 404 is raised
inside `Worker::ack()` where nothing catches it, so the worker dies and the message sits
unacknowledged until `consumer_timeout`. `q.builds` got away without one only because
`max_retries` was 0.

**Every `queues:` entry needs `arguments: { x-queue-type: quorum }`.**
`countMessagesInQueues()` calls `declareQueue()`, so `messenger:stats` and
`messenger:failed:*` redeclare the queue as classic and get `PRECONDITION_FAILED` (406).
Inert on the consume path, which never declares.

**`retry_strategy.delay > 0` requires the `delays` exchange** to exist in
`definitions.json`. With `auto_setup: false`, `setupDelay()` skips declaring it but still
runs `declareQueue()` and `bind()` — a bind to a missing exchange is a 404 inside
`Worker::ack()`, which kills the consumer.

**`jitter` must stay 0.** The jittered value is embedded in the delay *queue name*, so any
jitter creates a fresh classic queue per message per attempt.

**Never add `--keepalive`.** No transport ships an implementation of
`KeepaliveReceiverInterface`, and `Worker::keepalive()` throws for receivers that lack one.
It is the obvious-looking fix for a build outrunning `consumer_timeout`, and it is not
available.

**`consumer_timeout` is a maximum build duration**, not just a tuning value — 30s in dev,
300s planned for production. A site that legitimately renders for longer is
indistinguishable from a hung worker.

## What the test suite cannot catch

`InMemoryTransportFactory::createTransport()` **discards its options entirely**, so every
AMQP detail above is invisible offline: wrong queue names, a queue or exchange missing from
`definitions.json`, the `exchange:` block whose absence kills a retrying worker, and the
delay exchange. `tests/Messenger/RoutingTest.php` catches a typo in the `routing:` map and
nothing more. Passing tests say nothing about whether `messenger:consume builds` can
connect — `publish/docker/dev/` is the only thing that exercises that.

What the suite *does* pin is the cross-repo contract.
`tests/Message/MessageContractTest.php` asserts the exact `type` header and JSON body of
all three messages against hard-coded fixtures, and **the same file with the same fixtures
exists in `publish`**. Those classes are duplicated by hand; renaming a property on one
side is otherwise a `MessageDecodingFailedException` in production that nothing catches
first. If that test needs editing, the other repo needs the same edit in the same commit.

## Still open

- A build killed mid-flight — `consumer_timeout`, OOM, `SIGKILL` — emits no outcome at all,
  and nothing on disk records `callback_status_url`, so nothing can notify the client.
  Closing that needs a job record written before the work starts, plus a sweeper. The
  pragmatic backstop is a client-side timeout.
- `BuildFailed.error` forwards the raw exception message, which includes absolute server
  paths. The prototype notes say a notification should be "a pointer, not a diagnosis"; a
  stable error code plus a truncated message would stop leaking internal layout to a
  client-supplied URL.
