# Implementation state — result handling

**Scratch file. Delete before committing.** Plan:
`/home/paul/.claude/plans/ssg-worker-needs-to-be-expressive-puffin.md`

Branch: `16-add-result-handling-capabilities-to-ssg-worker` (ssg-worker),
`33-add-result-handling-capabilities-to-publish` (publish).

## Status: code complete, offline-verified. Not yet run end to end.

`php bin/phpunit` in ssg-worker: **277 tests, 538 assertions, green.**
`lint:yaml`, `lint:container`, `php -l` across src+tests: **clean.**
`docker compose -f docker/dev/compose.yaml config`: **valid.**
`jq` checks on `definitions.json`: **pass.**

## Done

**ssg-worker** — `Filesystem`, `JobLayout`, `BuildPromoter`, `BuildQuarantine` (borrowed
from `publish:4-implement-result-worker`, namespaced to `App\Job`, slug threaded,
`UnrecoverableMessageHandlingException` → SPL taxonomy); `Callback/StatusNotifier` +
new `Callback/ErrorRedactor`; new `Messenger/RetryCountMiddleware`; `JobWorkspace`
rewritten (`createJobDirectories` → `reset($site,$build,$slug)`, keyed on build_id, wipes
previous attempt); `Helper` **deleted** (merged into `Filesystem`); `SiteRenderer`
pre-checks for `.md` and throws `InvalidArgumentException`; `ArchiveExtractor` caps tar
output; `BuildJob` gains `title` (optional, last); `BuildJobHandler` rewritten with the
retry gate. Config: `messenger.yaml` (retry_strategy, exchange block, quorum arg,
middleware, `when@test` in-memory), `services.yaml` (`%build.max_retries%`, three roots,
SSRF client, `when@test` retry-strategy alias), `framework.yaml` (`test: true`), `.env`
(`AMQP_HEARTBEAT=10` — was referenced and set nowhere), `.env.test`, `Dockerfile`
(pcntl + tar + time/memory limits), `ci.yml` (**`sockets` → `amqp`: CI was red**).
Docs: `docs/build-pipeline.md`, `docs/roadmap.md`, README rewritten.

**publish** — `BuildJob.title`; `BuildController` SAFE_ID validation (400) + title
fallback/cap + `is_string` guard; `definitions.json` `delays` exchange;
`rabbitmq.conf` `consumer_timeout` 30s → 120s; `docker/dev/` full stack (compose,
collectives-mock with :80 archives / :8081 published / :8083 callback sink,
published/.gitignore); `ci.yml` jq guard; README.

## Left to do

1. **Verify SSRF wiring** — the command that was interrupted:
   ```bash
   cd ssg-worker && APP_ENV=test JOB_STORAGE_DIR=/tmp/x/b PUBLISHED_DIR=/tmp/x/p \
     FAILED_DIR=/tmp/x/f MAX_DOWNLOAD_MB=256 AMQP_DSN='amqp://g:g@127.0.0.1:5672/%2f' \
     php bin/console debug:container 'App\Callback\StatusNotifier' --show-arguments
   ```
   Expect `app.http_client.no_private_network` on `$httpClient`, and
   `App\Job\ContentDownloader` still on the plain `http_client`.

2. **Run publish's tests** — never executed. The sandbox confined writes to the
   ssg-worker dir and Symfony hardcodes `getCacheDir()` to `projectDir/var/cache`, so
   `publish/var/cache/test` was unwritable. `BuildControllerTest` gained ~5 cases
   (unsafe-name provider, non-string slug, title separation, overlong title) that have
   **only been syntax-checked**:
   ```bash
   cd publish && php bin/phpunit
   ```

3. **Delete the superseded compose file** — sandbox blocked the `rm`:
   ```bash
   cd publish && git rm docker/compose.dev.yaml
   ```
   `README.md` already points at `docker/dev/compose.yaml`. `SETUP.md` (line ~65, ~102,
   ~104) and `todo.md` (line ~200) still reference the old path — both are **untracked
   files of yours describing a different branch**, so they were left alone deliberately.

4. **Dev-stack end-to-end verification** — none of it has been run. Full steps in §9 of
   the plan. The load-bearing ones:
   - happy path → `curl -I http://127.0.0.1:8081/demo-site/` returns **200** (catches a
     promotion left at 0750, which no unit test can see);
   - failure path (`not-an-archive.tar.gz`) → **exactly two** extraction attempts ~15s
     apart, delay queues named `delay__q.builds_15000_retry` (**double** underscore), a
     third delivery that does not download, one `failed` at the sink, `FAILED_DIR`
     populated;
   - **the worker is still consuming afterwards** — `consumers: 1` on `q.builds`. This is
     what the `delays` exchange exists to prevent, and nothing offline can check it;
   - `no-pages.tar.gz` → terminal, reported on the first delivery, no delay queue;
   - `stat -c '%d %n'` on the three roots → build_temp and build_failed share a device,
     published differs.

5. **Confirm CI goes green** on a PR — the `ext-amqp` fix should turn lint+test from red
   to green. Check the PR #7 run first to confirm it was red for that reason before
   writing the commit message.

6. `docs/result-handling-plan.md` (untracked, yours) is superseded by
   `build-pipeline.md` + `roadmap.md`. Your call whether to delete it.

## Deliberate decisions worth remembering

- **Two build attempts**, not three: gate is `$retryCount >= $maxRetries` at the top of
  the handler, `max_retries: 2`, so deliveries 1–2 build and delivery 3 only reports.
- **No slug collision guard** — a build claiming an existing slug takes it over. Moves to
  the publish API with Postgres. Pinned by
  `BuildPromoterTest::testADifferentSiteClaimingTheSameSlugTakesItOver`.
- **`ErrorDetailsStamp` round-trip is best-effort.** It only decodes because the
  *container's* serializer has `ProblemNormalizer` ahead of `ObjectNormalizer`; the
  standalone `Serializer::create()` chain fails outright. `StampRoundTripTest` pins it
  against the container service on purpose — rewriting it to build its own serializer
  would make it lie.
- **`title` must stay optional and last**, or every in-flight message fails to decode and
  is dropped with no callback. `BuildJobContractTest` pins it.
- `RejectRedeliveredMessageMiddleware` sits at position 2 in the bus, **before** the
  middleware at position 6 — so any AMQP redelivery spends a build attempt without the
  handler running. Documented as limitation 2 in `roadmap.md`.
