# The build pipeline

How `ssg-worker` turns a `BuildJob` into a published site.

```text
q.builds ─► reset ─► download ─► extract ─► render ─► publish ─► callback
```

[BuildJobHandler](../src/Message/BuildJobHandler.php) runs the sequence and reports success.
[BuildFailureHandler](../src/Message/BuildFailureHandler.php) reports failure. There is no
result queue.

## Directories

```text
JOB_STORAGE_DIR/<build_id>/{input,output}       build scratch, 0750
PUBLISHED_DIR/<static_site_id>/<slug>/          the live site, served at PUBLISH_BASE_URL
PUBLISHED_DIR/.staging/<build_id>/<slug>/       the build before it goes live
```

[JobWorkspace](../src/Job/JobWorkspace.php) builds every path.

- Scratch is keyed on `build_id`, so concurrent builds of one site never share `output/`.
  The renderer does not clear `output/`, so a shared one would republish deleted pages.
- The two roots may be on different mounts. `rename()` fails across mounts with `EXDEV`, so
  [Filesystem::moveDir()](../src/Job/Filesystem.php) falls back to a recursive copy.
- `.staging` lives inside `PUBLISHED_DIR`, so the final swap is a rename on one mount. nginx's
  `location ~ /\.` answers 404 for it.
- A failed build's directory is deleted. The callback error and the `Build failed` log line are
  its only record.

## Publishing

The handler calls `reset()` at the start of every attempt, `publish()` on success, and
`clear()` once the job is finished either way. `publish()` runs three steps:

1. Moves `output/` to `.staging/<build_id>/<slug>/`. This is the slow step, and it does not
   touch the live site.
2. Sets the staged tree to `0755` for directories and `0644` for files, so nginx can read it.
   `output/` is `0750`, and files from the archive keep their tarball modes.
3. Deletes `PUBLISHED_DIR/<static_site_id>/` and renames the staged directory in its place.
   `rename()` onto a non-empty directory fails with `ENOTEMPTY`.

Replacing the whole site directory means an old slug stops being served. `publish()` refuses
an empty `output/`, so a build with no pages never replaces a live site.

Known limitation: the site answers 404 between the delete and the rename. Nothing records how
far an attempt got, so a retry rebuilds from the start.

## Retries and failures

`max_retries: 1` gives two build attempts, 15 s apart. Every delivery builds, so the retry
count is the build budget.

| Thrown | Means | Result |
| --- | --- | --- |
| `\RuntimeException` | may succeed next time: disk, network, broker | retried |
| `\InvalidArgumentException` | cannot succeed: archive with no pages, nothing rendered, callback rejected | the handler wraps it in `UnrecoverableMessageHandlingException`, reported on the first attempt |

Classes in `src/Job` throw only these two SPL exceptions. Messenger's exception is used only in
`BuildJobHandler`. A permanent error thrown as `\RuntimeException` uses up both attempts and
the delay before the client hears about it.

`BuildFailureHandler` subscribes to `WorkerMessageFailedEvent` instead of catching in the
handler, because some failures never reach the handler: a message requeued after
`consumer_timeout` arrives redelivered, and `RejectRedeliveredMessageMiddleware` throws before
the handler runs. The subscriber has priority 0, so it runs after
`SendFailedMessageForRetryListener` (100) has set `willRetry()`.

That requeue uses up an attempt, so a build that runs longer than `consumer_timeout` (120 s
dev, 300 s prod) is reported `failed` even if it would have succeeded.

The retry strategy has `jitter: 0`: the delay is part of the delay queue's name, so jitter
would declare a new queue per message and attempt.

The broker must declare the `delays` exchange. With `auto_setup: false`, Messenger still binds
its delay queues to it, and a missing exchange stops the consumer on its first retry. publish
declares it in `docker/dev/rabbitmq-config/definitions.json`.

## Callback

A `PUT` to `callback_status_url` with one of two bodies:

```json
{ "status": "published", "data": { "publish_url": "https://sites.example.org/<static_site_id>/<slug>/" } }
{ "status": "failed",    "data": { "error_message": "Could not download ..." } }
```

- Delivery is at-least-once: a crash between the PUT and the ack sends it again. Receivers
  identify the build by `callback_status_url`, dedupe on `(callback_status_url, status)`, and
  treat a final status as final.
- A failed callback fails the build. 408, 429, 5xx and transport errors are retried, so the
  whole build runs again. Other 4xx and 3xx are permanent. If the callback still fails on the
  last attempt, the site is live but reported `failed`.
- The log gets the full error message. The client gets it redacted by
  `BuildFailureHandler::redact()`: the storage roots become `<build>` and `<published>`, other
  paths are shortened, URL credentials removed, and the message truncated to 500 characters.

## Not covered by the tests

`InMemoryTransportFactory` ignores its options, so nothing under `options:` in
`messenger.yaml` is tested: the queue, the quorum argument, the `exchange:` block and the
`delays` exchange. SIGTERM handling, `consumer_timeout`, readability across uids and a real
`EXDEV` are not tested either; `EXDEV` is approximated with `/dev/shm`.

Whether `messenger:consume builds` survives its first retry is only tested by running
publish's dev stack.

[MessengerConfigTest](../tests/Messenger/MessengerConfigTest.php) covers the retry budget and
the subscriber wiring. [BuildJobContractTest](../tests/Message/BuildJobContractTest.php) covers
the message format shared with publish.
