# Result handling in ssg-worker — implementation plan

**Status:** plan only, nothing implemented. Written to hand over to a session working in this
repo (`ssg-worker`); the planning session ran with `publish` as its working directory and
could not make changes here.

**Target branch:** `16-add-result-handling-capabilities-to-ssg-worker` (currently identical to
`main`).

---

## 1. Context

`ssg-worker` consumes `BuildJob` from `q.builds`, downloads the content archive, extracts it,
renders HTML into `output/` — and stops. `BuildJobHandler::__invoke()` ends at
`src/Message/BuildJobHandler.php:66` with an `error_log()` of the page count. Nothing is
promoted, `callback_status_url` is carried on the message and read by nobody, and with
`retry_strategy.max_retries: 0` a failed build is logged, acked, and lost.

The goal: promote on success, retry twice on failure, report both outcomes to the callback
URL, quarantine the wreckage — **all inside this repo**.

### Relationship to branch `14-implement-result-worker`

A complete, unmerged design already exists across both repos —
`ssg-worker:14-implement-result-worker` and `publish:4-implement-result-worker`, ~3,800 lines
— that **splits** this work: the worker emits `BuildSucceeded`/`BuildFailed` onto result
queues and a separate result worker in `publish` does the promotion, quarantine and callback.

This plan is a deliberate **second, simpler implementation for comparison**: one service, no
result queues, no hand-synced message classes across repos.

That said, branch 14 was read in full rather than worked around, and **most of its
lower-level machinery is reusable as-is**. What changes is the orchestration, not the
primitives. Keeping the borrowed classes close to their originals is also what makes a later
migration to the isolated design cheap — a move of three files plus re-adding the queues,
rather than a rewrite.

### Decisions already taken (confirmed with the requester)

| Question | Choice |
| --- | --- |
| Where promotion + callback live | **ssg-worker**, not `publish` |
| Retry mechanism | **Symfony Messenger** `retry_strategy`, `max_retries: 2` |
| Published path | `PUBLISHED_DIR/<slug>/` |
| Failed builds | **Quarantined** to `FAILED_DIR/<build_id>/`, not deleted |

---

## 2. What to take from branch 14, and what to leave

Read with `git show <branch>:<path>`. Nothing below needs rewriting from scratch.

### Take almost verbatim — only the namespace changes (`App\Storage` → `App\Job`)

| Source (`publish:4-implement-result-worker`) | Lands as | Why it is worth taking |
| --- | --- | --- |
| `src/Storage/Filesystem.php` (202 l) | `src/Job/Filesystem.php` | `ensureDir(mode)`, `rename`, `moveDir`, `copyDir`, `removeDir`, `isEmptyDir`. Handles the EXDEV cross-mount fallback, and **skips symlinks on copy / unlinks rather than `rmdir`s them on delete** — which matters because `ArchiveExtractor` explicitly does *not* guard against symlinks in the tarball. Every failure carries the OS's own reason, matching `Helper`'s idiom. |
| `src/Storage/JobLayout.php` (139 l) | `src/Job/JobLayout.php` | Every path in one place, plus `assertSafeIds()`. Needs the slug change in §4.2. |
| `src/Storage/BuildPromoter.php` (192 l) | `src/Job/BuildPromoter.php` | The atomic swap, done properly — see below. |
| `src/Storage/BuildQuarantine.php` (84 l) | `src/Job/BuildQuarantine.php` | Copy-to-`.partial`-then-rename, idempotent, and **never throws**, so a filesystem problem cannot suppress the failure callback. |
| `src/Callback/StatusNotifier.php` (175 l) | `src/Callback/StatusNotifier.php` | The callback POST, with a considered retryable/unrecoverable split (3xx and non-408/429 4xx park; 408/429/5xx/transport retry) and an SSRF scheme+host guard. |
| `tests/Storage/{Filesystem,BuildPromoter,BuildQuarantine}Test.php`, `tests/Callback/StatusNotifierTest.php` (983 l total) | `tests/Job/`, `tests/Callback/` | Already cover the hard cases, in this repo's conventions. |

**`BuildPromoter`'s swap is better than the obvious implementation — keep it intact.** Not one
rename (a directory rename onto a non-empty directory fails `ENOTEMPTY`, so it would work on a
site's first build and break on every rebuild after it). Not `rm -rf` then rename (404s the
site for the length of a recursive delete, and a crash mid-delete leaves it deleted). Instead:
rename the live site aside to `.staging/<build>.old`, rename the new one in, then delete the
old tree — the visible gap is two syscalls, and a crash inside it leaves both trees intact.
It also **refuses to publish an empty output dir**, which would otherwise take a working site
down and replace it with a 404.

### Take the infrastructure too

| Source | Note |
| --- | --- |
| `publish:…/docker/dev/compose.yaml` | The only thing anywhere that runs this worker at all. Take it minus the `result-worker` service. |
| `publish:…/docker/dev/collectives-mock/` | Serves content archives on `:8082` **and the published tree read-only on `:8081`**. That second server block is how you verify the promoted tree is readable by another uid and that a republish replaced rather than merged — neither is reachable from a unit test. |
| `publish:…/docker/rabbitmq-config/definitions.json` | Take **only** the `delays` exchange (§3), not the result/dead queues. |
| `publish:…/docker/Dockerfile` `pcntl` hunk | Applies verbatim here: `docker-php-ext-install pcntl` **in the same `RUN`** as the amqp install, because `apk del .build-deps` at the end removes the compiler. Without `pcntl` Symfony never registers its `SIGTERM` handler and `docker stop` hard-kills the worker — now mid-promotion. |

### Leave

- `BuildSucceeded` / `BuildFailed` / their handlers / `MessageContractTest` — they exist only
  to carry an outcome across the repo boundary, which is what this design removes.
- `q.build-results`, `q.build-failures`, `q.build-dead`, the `x.build-dead` fanout, and
  `failure_transport`. The handler owns the terminal outcome and acks; nothing is parked.
- The `from_transport: build_dead` second handler tags — no parking queue, no replay path.

---

## 3. Two files outside this repo must change

The application code is all in `ssg-worker`. Two infrastructure files in `publish` are
unavoidable:

1. **`publish/docker/rabbitmq-config/definitions.json`** — add the `delays` direct exchange
   (durable, non-internal, no arguments).

   Verified in the vendored source, not recalled: `Connection.php:121` sets
   `autoSetupDelayExchange = $connectionOptions['auto_setup'] ?? true`, so with
   `auto_setup: false` the exchange is never declared, while `setupDelay()`
   (`Connection.php:378-385`) still runs `declareQueue()` **and** `bind()`. A bind to a missing
   exchange is a 404 raised inside `Worker::ack()`, uncaught — the consumer dies and the
   message sits unacked until `consumer_timeout`. Setting `delay: 0` would avoid this, at the
   cost of hot-looping retries against a transient failure; not worth it.

   - **Adding** an object needs only `docker compose restart rabbitmq`. Only changing an
     existing queue's immutable arguments needs `down -v`.
   - The import is **all-or-nothing per boot** — one typo and the node starts with *no*
     topology at all. Verify in the management UI, not with `compose ps`.
   - Do **not** set `default_queue_type=quorum` on the vhost to avoid listing arguments:
     Messenger declares its own delay queues at runtime and they must stay classic, because a
     quorum queue cannot be redeclared to renew its `x-expires` lease.

2. **`publish/docker/dev/`** — the dev stack. Nothing runs this worker today, and
   `config/services.yaml:35,42` in this repo points at an `integration-test/docker-compose.yml`
   that has never existed in either repo's history. Fix those comments while you are there.

---

## 4. Implementation

### Directory contract

```text
JOB_STORAGE_DIR/<static_site_id>/<build_id>/{input,output}   named volume, worker-private
PUBLISHED_DIR/<slug>/                                        bind mount, external nginx serves this
PUBLISHED_DIR/.staging/<build_id>[.partial|.old]             staging + retiring, same mount as above
FAILED_DIR/<build_id>/                                       bind mount, quarantined builds
```

`.staging` lives *inside* `PUBLISHED_DIR` so the swap is two same-mount renames, and starts
with a dot so nginx's `location ~ /\. { return 404; }` keeps a half-promoted build unreachable.

Keying the job dir on `build_id` (currently `static_site_id` alone) is needed because
concurrent builds of one site would otherwise share a directory, and
`SsgLab\SiteBuilder::build()` never clears its output dir — a reused `output/` republishes
pages that were deleted from the collective.

### 4.1 Retry gate — `max_retries: 2`, checked at the start

Take branch 14's `builds` transport block
(`git show 14-implement-result-worker:config/packages/messenger.yaml`) as-is. It already
carries the three non-obvious requirements with their reasoning:

- **`exchange: { name: '', default_publish_routing_key: q.builds }`** — mandatory once
  `max_retries > 0`. Retries republish through the transport's own sender; with no exchange
  block the name is derived from the DSN path and falls back to a literal `messages` exchange
  that does not exist.
- **`jitter: 0`** — the jittered value is embedded in the delay *queue name*, so any jitter
  creates a fresh queue per message per attempt.
- **`queues: { q.builds: { arguments: { x-queue-type: quorum } } }`** — not a declaration;
  `countMessagesInQueues()` calls `declareQueue()`, so `messenger:stats` would otherwise
  redeclare `q.builds` as classic and get `PRECONDITION_FAILED` (406).

Keep `delay: 15000, multiplier: 4, max_delay: 120000`. Add a `when@test` override to
`in-memory://`.

> ⚠️ **Rewrite the `retry_strategy` comment.** On branch 14 this budget exists *only* for
> transport-level problems, because build failures are reported rather than retried. Here it
> is the build retry budget — the opposite. Note in it that a `consumer_timeout` requeue
> arrives as `RejectRedeliveredMessageException` and **spends the same budget**, so a site that
> legitimately renders for longer than `consumer_timeout` (30s dev / 300s prod) burns its
> retries and is reported as failed.

**Getting the retry count into the handler.** A handler receives only the message, not the
envelope. Add `src/Messenger/RetryCountMiddleware.php` (new — branch 14 has no equivalent):
read `RedeliveryStamp::getRetryCountFromEnvelope($envelope)` (existing static helper,
`vendor/symfony/messenger/Stamp/RedeliveryStamp.php:31`) and attach
`new HandlerArgumentsStamp([$retryCount])`, which Messenger passes as an extra handler
argument. Register it in `messenger.buses` ahead of `send_message`.

`BuildJobHandler::__invoke(BuildJob $message, int $retryCount = 0)` then opens with the check.
`max_retries: 2` means deliveries 1, 2, 3 carry counts 0, 1, 2 — three build attempts, two
retries.

```php
// Delivery 3 is the last one Messenger will make. Knowing that up front is what
// lets a failure below be reported instead of thrown into a void.
$isFinalAttempt = $retryCount >= self::MAX_RETRIES;   // 2
```

- Success → promote, then notify `success`.
- `\InvalidArgumentException` (unsafe id, bad slug, bad URL — the caller's fault, per the split
  documented at `src/Job/JobWorkspace.php:37-39`) → **never retried.** Quarantine, notify
  `failed`, return.
- `\RuntimeException` and `!$isFinalAttempt` → rethrow; Messenger redelivers after the backoff.
- `\RuntimeException` and `$isFinalAttempt` → quarantine, notify `failed`, return normally so
  the message is acked. No `failure_transport` needed.

> **Open reading of the requirement.** "Retry 2 times" is taken as the binding number, so the
> start-of-handler check decides whether a failure is *terminal* and the third delivery still
> builds. If the intent was that the third delivery not rebuild at all — report failure
> immediately on seeing `$retryCount >= 2` — that is a one-line move of the check above the
> `try`, giving two build attempts instead of three.

### 4.2 Adapt `JobLayout` for the slug

`publishedSiteDir()` currently takes `$staticSiteId`; here it takes `$slug`.
`BuildPromoter::promote()` gains the slug alongside the two ids. Everything else in both
classes is unchanged.

> ⚠️ **`slug` has never been validated anywhere.** `publish`'s `BuildController` only checks it
> is `isset()`, and it is about to become a directory name. Validate it with the same
> `JobLayout::isValidId()` allow-list and throw `\InvalidArgumentException`.

> ⚠️ **`slug` is also the site title on every rendered page** (`BuildJobHandler.php:62-64`).
> The allow-list excludes spaces and dots, so a human-readable title like `"My Team Handbook"`
> starts failing the moment this lands. If real slugs are not already URL-safe, the fix is a
> separate `path_slug` field on `BuildJob` rather than loosening an allow-list that now guards
> a filesystem path. Worth mirroring into `publish`'s API validation — its `todo.md` §7
> already has "never pass request values into a filesystem path" open.

> ⚠️ **Slug collisions are newly possible.** Keying the published tree on `slug` rather than
> `static_site_id` means two different sites claiming one slug silently overwrite each other,
> and `BuildPromoter`'s `is_dir($published)` "already promoted" check cannot tell that apart
> from a replay. Branch 14 did not have this problem. Decide whether slugs are globally
> unique; if not, this needs a guard.

### 4.3 Reset the workspace on each attempt

Retries carry the same `build_id`, so attempt 2 would otherwise render on top of attempt 1's
half-written `output/`. Add `JobWorkspace::reset()` — remove the job dir if present, then
create `input/` and `output/` — using the borrowed `Filesystem::removeDir()`.

> ⚠️ This **changes the meaning of an existing test**: `JobWorkspaceTest`'s idempotency case
> asserts "a rebuild reuses the existing folders" and must become "a rebuild starts from an
> empty workdir". The docblock at `JobWorkspace.php:35-36` needs the same correction.

### 4.4 Wire the callback

Take `StatusNotifier` **unchanged**, including its exception taxonomy, and register the SSRF
decorator exactly as branch 14 does:

```yaml
App\Callback\StatusNotifier:
    arguments:
        $httpClient: '@app.http_client.no_private_network'
        $timeoutSeconds: 5
        $maxDurationSeconds: 10
        $maxRedirects: 0

app.http_client.no_private_network:
    class: Symfony\Component\HttpClient\NoPrivateNetworkHttpClient
    arguments: ['@http_client']
```

The decorator is a separate named service so `ContentDownloader` keeps the plain client.
`NoPrivateNetworkHttpClient` is already vendored.

Add `slug` and `pages` to the success payload — branch 14's `StatusNotifier` sends only
`build_id`, `static_site_id`, `status`, `finished_at`, and optional `error`.

> ⚠️ **Strip absolute paths out of `error` before sending.** Branch 14 flags this as an open
> item: the pipeline's messages deliberately embed real filesystem paths, and this POSTs them
> to a client-supplied URL. Replace the `JOB_STORAGE_DIR` prefix and cap the length.

**The one real divergence from branch 14 — callback failure policy.** Branch 14 lets
`StatusNotifier` throw and lets Messenger retry, which is cheap *there* because that handler
only promotes and calls back, and `BuildPromoter` is idempotent — the replay costs three
`stat()` calls. Here the same throw replays download, extract and render as well, and spends
the build retry budget.

So: **the handler catches what `StatusNotifier` throws, logs `[ERROR]`, and acks.** Keep the
throwing behaviour inside `StatusNotifier` itself so the policy lives in one visible `catch` in
the handler — migrating to the isolated design later is then just deleting that `catch`.

Record in `docs/build-pipeline.md` that this is the property the single-service design gives
up: a persistently unreachable callback URL means a successful build is never reported. If
that becomes unacceptable before a migration, the cheap fix is a `.staging/<build_id>.done`
marker written after promotion and removed after a successful callback, letting the handler
skip straight to the callback on replay — branch 14's idempotency trick applied one level up.

### 4.5 Remaining wiring

- `config/services.yaml` — `$buildTempDir: '%env(JOB_STORAGE_DIR)%'`,
  `$publishedDir: '%env(PUBLISHED_DIR)%'`, `$failedDir: '%env(FAILED_DIR)%'`; register the
  middleware; fix the two dangling `integration-test/docker-compose.yml` comments.
- `.env` — add `AMQP_HEARTBEAT=10`. The README documents this default but nothing supplies it,
  so any run without it set fails at container compile.
- `Dockerfile` — the `pcntl` hunk from §2, plus `--time-limit=3600 --memory-limit=512M` on the
  consume command, with compose's `restart:` as the restarter.
- `config/packages/framework.yaml` — `test: true` under `when@test`, needed for the first
  kernel-booting test in this repo (branch 14 hit the same thing).
- `.github/workflows/ci.yml` — add `PUBLISHED_DIR`, `FAILED_DIR`, `JOB_STORAGE_DIR` for
  `lint:container`, and swap the stale `sockets` extension for `amqp` (a leftover from the
  php-amqplib era).
- `README.md` env table, and a `docs/build-pipeline.md` recording this design **and its
  divergences from branch 14**, so the two are comparable side by side.

---

## 5. Tests

Borrowed suites (`FilesystemTest`, `BuildPromoterTest`, `BuildQuarantineTest`,
`StatusNotifierTest`) come over with their namespaces changed; `BuildPromoterTest` needs the
slug parameter threaded through. They already match this repo's conventions — real
collaborators over mocks (the classes are `final`), `MockHttpClient`, real `tar.gz` fixtures,
and `ini_set('error_log', …)` for log assertions.

New:

- `tests/Messenger/RetryCountMiddlewareTest.php` — no stamp → 0, `RedeliveryStamp` → its
  count, count reaches the handler.
- `tests/Message/BuildJobHandlerTest.php` — extend: success promotes and notifies;
  `RuntimeException` at counts 0 and 1 rethrows; the same at count 2 quarantines and notifies
  `failed` without throwing; `InvalidArgumentException` never rethrows at any count; a throwing
  `StatusNotifier` does not fail the handler.
- `tests/Job/JobWorkspaceTest.php` — update the idempotency case (§4.3), add `build_id` and
  slug validation cases.

**What the suite cannot catch** (branch 14 documents this; it applies unchanged):
`InMemoryTransportFactory::createTransport()` discards its options entirely, so every AMQP
detail in §4.1 is invisible offline — the missing `delays` exchange, the `exchange:` block, the
quorum argument. Passing tests say nothing about whether `messenger:consume builds` survives
its first retry. Only the dev stack reaches that.

---

## 6. Verification

```bash
php bin/phpunit

# The delays exchange must exist before any retry fires
docker compose -f ../publish/docker/dev/compose.yaml restart rabbitmq
curl -su app:secret http://127.0.0.1:15672/api/exchanges/%2f/delays

docker compose -f ../publish/docker/dev/compose.yaml up --build
curl -X POST localhost:8080/build -H 'Authorization: Bearer dev-api-token-0123456789' \
  -H 'Content-Type: application/json' \
  -d '{"static_site_id":"demo","slug":"demo-site",
       "content_download_url":"http://collectives-mock/sample-collective.tar.gz",
       "callback_status_url":"http://…"}'
```

Then, in order:

1. `curl http://127.0.0.1:8081/demo-site/` returns the site — this is the check that catches a
   promotion left at `0750`, which every unit test misses.
2. `curl http://127.0.0.1:8081/.staging/` returns 404.
3. The build temp tree under `demo/` is gone; the callback receiver logged one `success` with
   the right page count.
4. **Rebuild** the same site with a page deleted from the source, and confirm it is gone from
   the served tree — proving the swap replaced rather than merged.
5. **Failure path** — point `content_download_url` at a 404. Expect three attempts ~15s and
   ~60s apart, one `failed` callback, `FAILED_DIR/<build_id>/` populated, build volume clean.
6. **Non-retryable path** — send `"slug": "../etc"`. Expect exactly one attempt, an immediate
   `failed` callback, and nothing written outside the base dirs.
7. **The worker is still consuming after step 5.** This is the failure mode the
   `exchange: { name: '' }` block and the `delays` exchange exist to prevent, and step 5 is the
   first thing that exercises it.

---

## 7. Open questions carried forward

1. **Slug character set** (§4.2) — blocks the published-path decision if real slugs contain
   spaces or dots.
2. **Slug uniqueness** (§4.2) — whether a collision guard is needed.
3. **Two or three build attempts** (§4.1) — the reading of "retry 2 times".
4. **Mid-flight kills.** Branch 14 flags this and it is unfixed here too: a build killed by
   `consumer_timeout`, OOM or `SIGKILL` emits no outcome, and nothing on disk records
   `callback_status_url`, so nothing can notify the client. Closing it needs a job record
   written before the work starts, plus a sweeper. The pragmatic backstop is a client-side
   timeout.
5. **`FAILED_DIR` grows unbounded** — no retention sweep is planned here.
