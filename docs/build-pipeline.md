# The build pipeline

How `ssg-worker` turns a `BuildJob` into a published site, and why each part is shaped
the way it is. Written for whoever changes this next: most of the decisions here look
arbitrary until you know the failure they prevent.

## End to end

```
q.builds ─► reset ─► download ─► extract ─► render ─► publish ─► callback
```

One handler, [BuildJobHandler](../src/Message/BuildJobHandler.php), owns the whole
sequence and the outcome. There is no result queue and no second service: the handler
that builds is the handler that publishes, cleans up and reports.

### The directory contract

```text
JOB_STORAGE_DIR/<static_site_id>/<build_id>/{input,output}   build scratch, worker-private
PUBLISHED_DIR/<slug>/                                        the live site, served externally
PUBLISHED_DIR/.staging/<build_id>                            staging, always beside the live site
```

**How those two roots are mounted is the operator's choice, and the code assumes
nothing.** A deployment may bind-mount the published tree so an external nginx and a
backup job can reach it while build scratch stays on a named volume, or put both on one
filesystem. `rename(2)` rejects a cross-mount move with `EXDEV` and PHP has no directory
fallback, so [Filesystem::moveDir()](../src/Job/Filesystem.php) falls back to a recursive
copy — which is what keeps the mount layout a deployment decision instead of a code
constraint.

Three things about this are deliberate:

- **The job directory is keyed on `build_id`, not on `static_site_id` alone.** Two
  concurrent builds of one site would otherwise share a directory, and
  `SsgLab\SiteBuilder::build()` never clears its output directory — so a reused
  `output/` republishes pages that were deleted from the collective.
- **`.staging` lives *inside* `PUBLISHED_DIR`.** That is what makes the final swap a
  same-mount rename *however the roots are mounted*, rather than a copy into the
  directory being served. The leading dot keeps a build still being copied unreachable
  behind nginx's `location ~ /\. { return 404; }`.
- **A failed build keeps nothing.** Its directory is deleted, not moved aside. The error
  string in the callback and the log line are the whole record — see
  [roadmap.md](roadmap.md) for what a database would let us keep instead.

Everything that turns a queue payload into a path goes through
[JobWorkspace](../src/Job/JobWorkspace.php), which is also where the allow-list lives and
where a build's directories are created and removed.

## Staging and the swap

[JobWorkspace::publish()](../src/Job/JobWorkspace.php) does two things, in order:

1. **Stage** — clear `.staging/<build_id>`, then move `output/` into it. A rename when
   the two roots share a mount, a recursive copy when they do not; either way it is the
   slow part. It happens here rather than straight into the live path precisely so that
   the slow, possibly non-atomic step is never aimed at the directory nginx is serving.
2. **Swap** — delete the live site, rename the staged tree in. Staging is inside
   `PUBLISHED_DIR`, so this rename is always same-mount: atomic and instant.

Retiring what is left of the build tree is
`clear()`'s job, not `publish()`'s, because it has to happen whether the build succeeded
or not. The handler drives that lifecycle: `reset()` at the start of an attempt,
`publish()` only on success, `clear()` once the job reaches a terminal state either way.

The delete in step 2 is not optional: `rename()` onto an existing *non-empty* directory
fails with `ENOTEMPTY` and never merges, so without it a site would publish once and fail
on every rebuild after.

**What this deliberately does not do is track its own progress.** Nothing inspects the
filesystem to work out how far a previous attempt got — there is no `.partial`/`.old`
scheme encoding build state in directory names. A retry rebuilds from scratch. Build
state belongs in a database; see [roadmap.md](roadmap.md).

The cost is a short window: the live site is gone between the delete and the rename, so a
republish briefly 404s, and a crash inside that window leaves the site down until the next
build. That is the trade made for not keeping state on disk.

`publish()` also **refuses to publish an empty output directory**, since the swap removes
the live site first — publishing nothing would take a working site down and replace it
with a 404, which is strictly worse than failing.

### Modes

`JobWorkspace` creates `output/` at `0750` and the container runs as root. Renamed in
unchanged, that is a directory whatever serves the site cannot traverse: a 403 on every
page, invisible to every unit test. `publish()` chmods the published root to `0755`.
Everything below it is already fine — `SiteBuilder` mkdirs at `0755` and writes files at
`0644`.

## Retry semantics

`retry_strategy.max_retries: 1`, which means **two build attempts**:

| Delivery | What happens |
| --- | --- |
| 1 | builds; on a retryable failure, throws → redelivered after 15s |
| 2 | builds; on a retryable failure, throws → **terminal**, reported `failed` |

Every delivery builds — there is no gate in the handler — so the retry count *is* the
build budget. A failure always leaves the handler by throwing, and the type decides what
the transport does with it:

- `\RuntimeException` goes out untouched, so the retry strategy applies.
- `\InvalidArgumentException` is wrapped in `UnrecoverableMessageHandlingException`,
  which Messenger checks *before* the retry strategy. Nothing a retry could change is
  therefore reported immediately, without burning the attempt or the 15s of backoff.

**Reporting the failure is [BuildFailureHandler](../src/Message/BuildFailureHandler.php)'s
job**, a `WorkerMessageFailedEvent` subscriber, and that is deliberate. A handler only
sees failures it is running for, and some never reach it — the redelivery case below is
the obvious one. `Worker::ack()` dispatches the event for every failed delivery, so
subscribing is the only way to report *every* terminal failure rather than most of them.

The subscriber runs at priority 0, below `SendFailedMessageForRetryListener`'s 100,
because that listener is what calls `setForRetry()`: `willRetry()` only means anything
after it has run. It takes the reason straight from `$event->getThrowable()`, in-process,
so nothing depends on an error surviving a round trip through the transport serializer.

Success is still reported by the handler, which is the only thing that knows the page
count.

**The budget is also spent by things that are not build failures.** A `consumer_timeout`
requeue comes back with the AMQP redelivered flag set, and
`RejectRedeliveredMessageMiddleware` throws *before* the handler runs. The subscriber
still sees it, so the client is told — but a site that renders for longer than
`consumer_timeout` is reported as failed without ever finishing. That is why the dev broker's `consumer_timeout` was raised to
120s. See [roadmap.md](roadmap.md).

`jitter: 0` is not cosmetic: the jittered delay is interpolated into the delay *queue
name*, so any jitter declares a fresh classic queue per message per attempt.

### The `delays` exchange

The single most likely thing to take the worker down, and nothing in this repo's test
suite can catch it.

Once `retry_strategy.delay > 0`, Messenger's AMQP transport binds its runtime delay
queues to a `delays` exchange. With `auto_setup: false` it skips *declaring* that
exchange but still runs `declareQueue()` and `bind()` against it — and a bind to a
missing exchange is a 404 raised inside `Worker::ack()`, where nothing catches it. The
consumer dies and the message sits unacked until `consumer_timeout`.

It is declared in `publish/docker/rabbitmq-config/definitions.json` and guarded by a `jq`
check in that repo's CI.

## The exception taxonomy

Every class in `src/Job` and `src/Callback` is written against this, and the handler is a
`catch` on each:

| Type | Means | Handler does |
| --- | --- | --- |
| `\InvalidArgumentException` | **Nothing a retry could change** — an unsafe id or slug, a URL we will not fetch, an archive with no pages, a callback endpoint that rejects us | Reports `failed` immediately, on the first delivery |
| `\RuntimeException` | **The environment might differ next time** — disk, network, broker, a half-finished swap | Rethrows, so Messenger redelivers |

Getting a classification wrong is not cosmetic. A permanent content error typed as
retryable costs the client both attempts and ~75s of backoff before they are told what
was wrong the first time; that is why `SiteRenderer` checks for at least one `.md` itself
rather than letting `SiteBuilder`'s `RuntimeException` through.

These are plain SPL exceptions, not Messenger's `UnrecoverableMessageHandlingException`.
That class only has power over Messenger's retry decision, and here the handler never
lets an exception out except one deliberate rethrow — so the type would be inert, and it
would drag `symfony/messenger` into classes that are otherwise plain PHP.

## The callback contract

`POST` to the job's `callback_status_url`, as JSON:

```json
{
  "build_id": "16ef078ad37fd894",
  "static_site_id": "1234-5678",
  "slug": "my-team-handbook",
  "status": "success",
  "finished_at": "2026-09-17T10:00:00+00:00",
  "pages": 7
}
```

`status` is `success` or `failed`. `pages` is present only on success; `error` is present
only on failure.

**DELIVERY IS AT-LEAST-ONCE, and receivers must cope with that.** Messenger acks only
after the handler returns, so a crash between the POST and the ack redelivers the same
callback. A `consumer_timeout` requeue can do the same. Dedupe on `(build_id, status)`
and treat a terminal status as final.

Two policies worth knowing:

- **A failed callback fails the build.** The `notify()` call sits inside the handler's
  `try`, so an unreachable endpoint is a `\RuntimeException` like any other and the
  message is redelivered. That re-runs the *whole* build — download, extract, render,
  publish — because nothing records that the site was already live. Expensive, and
  deliberate: the alternative is a finished build the client is never told about.
- **A callback still failing on the last attempt is reported `failed`, for a site that
  is live and correct.** `BuildFailureHandler` cannot tell "the build broke" from "the
  build worked but the phone line was down". Closing that needs a job record; see
  [roadmap.md](roadmap.md).

`StatusNotifier` distinguishes retryable from permanent responses (408, 429, 5xx and
transport failures are retryable; other 4xx and any 3xx are not), and that split is load
bearing here: a permanent rejection surfaces as `\InvalidArgumentException`, which the
handler wraps as unrecoverable, so a 404 callback costs no rebuild at all.

### Error redaction

Every `RuntimeException` in the pipeline embeds an absolute path on purpose — that is
what an operator needs. `callback_status_url` is chosen by whoever called the API, and
sending the raw string there hands them a map of the volume layout.

So the two audiences get different strings.
[BuildFailureHandler](../src/Message/BuildFailureHandler.php) logs the message as thrown
and redacts only on the way to the callback: its `redact()` replaces the configured roots
with `<build>` / `<published>`, collapses any other absolute path, strips URL credentials
and truncates. The result stays diagnostic without becoming a disclosure —
`<build>/.../content.tar.gz` still says which stage failed.

It is private to that class because the failure callback is the only thing that sends an
error anywhere; success carries none.

## Single-service, and what it trades away

An earlier, unmerged design split this work across two services
(`ssg-worker:14-implement-result-worker` and `publish:4-implement-result-worker`): the
worker emitted `BuildSucceeded`/`BuildFailed` onto result queues and a separate result
worker in `publish` did the publishing, cleanup and callback.

This is the simpler one. Most of the lower-level machinery — `Filesystem`,
the publishing logic now in `JobWorkspace`, `StatusNotifier` and their tests — was taken from
that branch close to verbatim, so a later migration is mostly a move of files plus
re-adding the queues, not a rewrite.

| | Split design | This one |
| --- | --- | --- |
| Queues | `q.builds`, `q.build-results`, `q.build-failures`, `q.build-dead` | `q.builds` |
| Message classes hand-synced across repos | 3 | 1 (`BuildJob`) |
| A failed callback replays | three `stat()` calls | the whole build — so the handler acks instead |
| A build that succeeds but cannot report | the callback alone is retried | **the whole build is retried** |

That last row is the real cost. If it becomes unacceptable before a migration, the cheap
fix is a `.staging/<build_id>.done` marker written after publishing and removed after a
successful callback, letting a replay skip straight to the callback.

## What the test suite cannot reach

`InMemoryTransportFactory::createTransport()` takes an `array $options` and never reads
it. **Every key under `options:` in `messenger.yaml` is invisible to every test** — the
queue name, the quorum argument, the `exchange:` block, and whether the `delays` exchange
exists on the broker at all. A retry never round-trips through a real delay queue, so
nothing offline proves `RedeliveryStamp` survives in AMQP headers.

Also out of reach offline: `pcntl`/SIGTERM shutdown, `consumer_timeout`, cross-uid
readability of `PUBLISHED_DIR`, and real `EXDEV` between a named volume and a bind mount
(the unit tests approximate it with `/dev/shm` and *skip* where that is unavailable).

**Passing tests say nothing about whether `messenger:consume builds` survives its first
retry.** Only the dev stack reaches that — see the verification steps in the
[README](../README.md).

What *is* pinned offline:
[MessengerConfigTest](../tests/Messenger/MessengerConfigTest.php) asserts the retry budget
the container actually built (two redeliveries, 15s then 60s, middleware present and
ahead of `handle_message`), and
[BuildJobContractTest](../tests/Message/BuildJobContractTest.php) asserts the wire format
`publish` and this service must agree on.
