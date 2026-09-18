# The build pipeline

How `ssg-worker` turns a `BuildJob` into a published site, and why each part is shaped
the way it is. Written for whoever changes this next: most of the decisions here look
arbitrary until you know the failure they prevent.

## End to end

```
q.builds ─► reset ─► download ─► extract ─► render ─► promote ─► callback
```

One handler, [BuildJobHandler](../src/Message/BuildJobHandler.php), owns the whole
sequence and the outcome. There is no result queue and no second service: the handler
that builds is the handler that promotes, quarantines and reports.

### The directory contract

```text
JOB_STORAGE_DIR/<static_site_id>/<build_id>/{input,output}   named volume, worker-private
FAILED_DIR/<build_id>/                                       quarantined failures
PUBLISHED_DIR/<slug>/                                        bind mount, external nginx serves this
PUBLISHED_DIR/.staging/<build_id>[.partial|.old]             staging + retiring
```

Three things about this are deliberate:

- **The job directory is keyed on `build_id`, not on `static_site_id` alone.** Two
  concurrent builds of one site would otherwise share a directory, and
  `SsgLab\SiteBuilder::build()` never clears its output directory — so a reused
  `output/` republishes pages that were deleted from the collective.
- **`.staging` lives *inside* `PUBLISHED_DIR`.** That is what makes the final swap two
  renames on one mount. The leading dot keeps a half-promoted build unreachable behind
  nginx's `location ~ /\. { return 404; }`.
- **`FAILED_DIR` belongs on the same mount as `JOB_STORAGE_DIR`.** Quarantining copies a
  failed build's whole tree — the archive *and* its fully extracted duplicate — and the
  message stays unacked for the entire copy. Same mount makes it an instant `rename()`.
  Only `PUBLISHED_DIR` needs to be a host bind mount.

Everything that turns a queue payload into a path goes through
[JobLayout](../src/Job/JobLayout.php), which is also where the allow-list lives.

## The atomic swap

[BuildPromoter](../src/Job/BuildPromoter.php) never assembles the live site in place. The
obvious implementations are both wrong:

- **One rename.** `rename()` of a directory onto an existing *non-empty* directory fails
  with `ENOTEMPTY` and never merges. It works on a site's first build and breaks on every
  rebuild after it.
- **`rm -rf` then rename.** The site 404s for however long a recursive delete takes, and a
  crash mid-delete leaves it deleted.

Instead: rename the live site aside to `.staging/<build>.old`, rename the new tree in,
then delete the old one. The visible gap is two syscalls, and a crash inside it leaves
both trees intact.

The cross-mount copy that precedes it lands on `<build>.partial` and is renamed to
`<build>` only once complete, so a crash mid-copy leaves something nothing will publish.
`is_dir($staging)` is checked *first* on the next attempt, because a complete staged
release means the expensive copy already finished.

The promoter also **refuses to publish an empty output directory**. The swap removes the
live site, so publishing nothing would take a working site down and replace it with a
404 — strictly worse than failing.

### Modes

`JobWorkspace` creates `output/` at `0750` and the container runs as root. Renamed in
unchanged, that is a directory whatever serves the site cannot traverse: a 403 on every
page, invisible to every unit test. `promote()` chmods the published root to `0755`.
Everything below it is already fine — `SiteBuilder` mkdirs at `0755` and writes files at
`0644`.

## Retry semantics

`retry_strategy.max_retries: 2`, which means **two build attempts**:

| Delivery | `retryCount` | What happens |
| --- | --- | --- |
| 1 | 0 | builds; on a retryable failure, rethrows → redelivered after 15s |
| 2 | 1 | builds; on a retryable failure, rethrows → redelivered after 60s |
| 3 | 2 | **does not build.** Quarantines, POSTs `failed`, acks. |

The gate is the first thing in the handler. The third delivery is the last one Messenger
will make (`MultiplierRetryStrategy::isRetryable()` is `$retries < $maxRetries`), so a
build started there would have nowhere to report a failure: throwing would have the
message logged and dropped, and returning would claim a success that never happened.

The count reaches the handler through
[RetryCountMiddleware](../src/Messenger/RetryCountMiddleware.php), because a handler is
given the message and never the envelope. The same middleware carries the *previous
attempt's error*, so the reporting delivery can say what went wrong without having run
the build itself.

> **That error is best-effort, not a guarantee.** It rides in an `ErrorDetailsStamp`,
> which travels as an AMQP header and holds a `FlattenException`. It round-trips only
> because the container's serializer has `ProblemNormalizer` ahead of `ObjectNormalizer`;
> the standalone `Serializer::create()` chain cannot decode it at all. If that ever
> breaks, the whole envelope fails to decode and the build is dropped with no callback.
> [StampRoundTripTest](../tests/Messenger/StampRoundTripTest.php) pins it, and the handler
> falls back to a fixed sentence.

**The budget is also spent by things that are not build failures.** A `consumer_timeout`
requeue comes back with the AMQP redelivered flag set, and
`RejectRedeliveredMessageMiddleware` throws *before* the handler runs — so a site that
renders for longer than `consumer_timeout` burns both attempts and is reported as failed
without ever finishing. That is why the dev broker's `consumer_timeout` was raised to
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

- **The callback never fails the message.** Letting `StatusNotifier` throw would replay
  the whole handler — download, extract, render, promote — and spend the *build* retry
  budget on an HTTP problem, so a slow client endpoint would be reported as a failed
  build. The handler catches, logs `[ERROR]`, and acks.
- **An unreachable callback URL therefore means a successful build is never reported.**
  That is what this design trades away; see [roadmap.md](roadmap.md).

`StatusNotifier` still distinguishes retryable from permanent responses internally (408,
429, 5xx and transport failures are retryable; other 4xx and any 3xx are not), so the
policy lives in one visible `catch` in the handler and the class stays portable if the
callback ever moves to its own queue.

### Error redaction

Every `RuntimeException` in the pipeline embeds an absolute path on purpose — that is
what an operator needs. `callback_status_url` is chosen by whoever called the API, and
sending the raw string there hands them a map of the volume layout.

So the two audiences get different strings:
[ErrorRedactor](../src/Callback/ErrorRedactor.php) replaces the configured roots with
`<build>` / `<published>` / `<failed>`, collapses any other absolute path, strips URL
credentials and truncates. The handler logs the raw message and redacts only on the way
to the callback. The message stays diagnostic without becoming a disclosure.

## Single-service, and what it trades away

An earlier, unmerged design split this work across two services
(`ssg-worker:14-implement-result-worker` and `publish:4-implement-result-worker`): the
worker emitted `BuildSucceeded`/`BuildFailed` onto result queues and a separate result
worker in `publish` did the promotion, quarantine and callback.

This is the simpler one. Most of the lower-level machinery — `Filesystem`, `JobLayout`,
`BuildPromoter`, `BuildQuarantine`, `StatusNotifier` and their tests — was taken from
that branch close to verbatim, so a later migration is mostly a move of files plus
re-adding the queues, not a rewrite.

| | Split design | This one |
| --- | --- | --- |
| Queues | `q.builds`, `q.build-results`, `q.build-failures`, `q.build-dead` | `q.builds` |
| Message classes hand-synced across repos | 3 | 1 (`BuildJob`) |
| A failed callback replays | three `stat()` calls | the whole build — so the handler acks instead |
| A build that succeeds but cannot report | retried from its own queue | **never reported** |

That last row is the real cost. If it becomes unacceptable before a migration, the cheap
fix is a `.staging/<build_id>.done` marker written after promotion and removed after a
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
