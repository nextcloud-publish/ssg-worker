# The build pipeline

How `ssg-worker` turns a `BuildJob` into a published site, and why. Several decisions look
arbitrary until you know the failure they prevent.

```
q.builds ─► reset ─► download ─► extract ─► render ─► publish ─► callback
```

[BuildJobHandler](../src/Message/BuildJobHandler.php) runs the whole sequence and reports
success. [BuildFailureHandler](../src/Message/BuildFailureHandler.php) reports failure. There
is no result queue and no second service.

## Directories

```text
JOB_STORAGE_DIR/<build_id>/{input,output}       build scratch, worker-private
PUBLISHED_DIR/<static_site_id>/<slug>/          the live site, served at PUBLISH_BASE_URL
PUBLISHED_DIR/.staging/<build_id>/<slug>/       staging, beside the live site
```

All paths come from [JobWorkspace](../src/Job/JobWorkspace.php), which also holds the id
allow-list.

- **Scratch is keyed on `build_id`.** Concurrent builds of one site would otherwise share
  `output/`, and `SiteBuilder` never clears it, so deleted pages would be republished.
- **The two roots may be on different mounts.** `rename(2)` fails across mounts with `EXDEV`,
  so [Filesystem::moveDir()](../src/Job/Filesystem.php) falls back to a recursive copy.
- **`.staging` lives inside `PUBLISHED_DIR`**, so the final swap is always a same-mount
  rename. The leading dot keeps a half-copied build behind nginx's `location ~ /\.` 404.
- **A failed build keeps nothing.** Its directory is deleted. The callback error and the log
  line are the whole record.

## Publishing

The handler calls `reset()` at the start of every attempt, `publish()` on success, and
`clear()` once the job is terminal either way. `publish()` does this:

1. **Stage.** Move `output/` to `.staging/<build_id>/<slug>/`. This is the slow step, and it
   never touches the directory being served.
2. **Fix modes.** Set the whole staged tree to `0755` for directories and `0644` for files.
   `output/` is created `0750` as root, and archive attachments keep their tarball modes.
   Either one would 403 behind nginx, and no unit test can see that.
3. **Swap.** Delete `PUBLISHED_DIR/<static_site_id>/`, then rename the staged directory in.
   The delete is required: `rename()` onto a non-empty directory fails with `ENOTEMPTY`.

Replacing the whole site directory means an old slug stops being served, and two sites
with the same slug never collide. `publish()` refuses an empty `output/`, since the swap
would otherwise replace a live site with nothing.

The cost is that the site 404s between the delete and the rename, and a crash in that
window leaves it down. Nothing records how far an attempt got, and a retry rebuilds from
scratch. That is deliberate: build state belongs in a database, not in directory names. See
[roadmap.md](roadmap.md).

## Retries and failures

`max_retries: 1` gives **two build attempts**, 15s apart. Every delivery builds, so the
retry count is the build budget.

| Thrown | Means | Result |
| --- | --- | --- |
| `\RuntimeException` | might differ next time: disk, network, broker | retried |
| `\InvalidArgumentException` | cannot change: unsafe id or slug, bad URL, archive with no pages, callback rejects us | wrapped in `UnrecoverableMessageHandlingException` by the handler, reported on the first delivery |

Classes in `src/Job` throw only these SPL types. Messenger's exception appears only in
`BuildJobHandler`. Getting a type wrong wastes both attempts and the backoff before the
client hears about a permanent error. That is why `SiteRenderer` checks for a `.md` file itself.

**Failure is reported by a `WorkerMessageFailedEvent` subscriber, not the handler.** Some
failures never reach the handler. A `consumer_timeout` requeue arrives redelivered, and
`RejectRedeliveredMessageMiddleware` throws before the handler runs. The subscriber runs at
priority 0, after `SendFailedMessageForRetryListener` (100) has set `willRetry()`.

That same requeue spends an attempt, so a render longer than `consumer_timeout` is
reported `failed` without having failed (120s dev, 300s prod).

**`jitter: 0`**, because the delay is part of the delay queue's name, and jitter would
declare a new queue per message per attempt.

**The broker must declare the `delays` exchange.** With `auto_setup: false` Messenger still
binds its delay queues to it. A missing exchange is a 404 inside `Worker::ack()` that kills
the consumer on its first retry. It is declared in publish's `definitions.json`.

## Callback

`PUT` to `callback_status_url`. These two shapes are the whole body:

```json
{ "status": "published", "result": { "publish_url": "https://sites.example.org/<static_site_id>/<slug>/" } }
{ "status": "failed",    "result": { "error_message": "Could not download ..." } }
```

- **At-least-once.** A crash between the PUT and the ack sends it again. Receivers identify
  the build by its `callback_status_url`, dedupe on `(callback_status_url, status)`, and
  treat a terminal status as final.
- **A failed callback fails the build.** 408, 429, 5xx and transport errors are retryable,
  so the whole build reruns. Other 4xx and 3xx are permanent and cost no rebuild. If the
  callback still fails on the last attempt, a live site is reported `failed`. See
  [roadmap.md](roadmap.md).
- **Errors are redacted for the client only.** The log gets the full message with absolute
  paths. `BuildFailureHandler::redact()` replaces the storage roots with `<build>` and
  `<published>`, collapses other paths, strips URL credentials and truncates.

## What the test suite cannot reach

`InMemoryTransportFactory` ignores its options, so nothing under `options:` in
`messenger.yaml` is tested: the queue, the quorum argument, the `exchange:` block, the
`delays` exchange. SIGTERM handling, `consumer_timeout`, cross-uid readability and real
`EXDEV` are also out of reach. `EXDEV` is approximated with `/dev/shm`.

**Passing tests do not show that `messenger:consume builds` survives its first retry.** Only
the dev stack in `publish` tests that.

What is tested offline:
[MessengerConfigTest](../tests/Messenger/MessengerConfigTest.php) covers the retry budget and
subscriber wiring. [BuildJobContractTest](../tests/Message/BuildJobContractTest.php) covers
the wire format shared with `publish`.
