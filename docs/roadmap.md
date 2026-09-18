# Known limitations, and what a database changes

Two halves, in one file because most of the first is answered by the second.

The through-line: **a build's entire existence is currently one unacked AMQP message.**
Nothing is written down before the work starts, so anything that kills the process loses
the job *and* the only copy of `callback_status_url` — there is nowhere to look up what
was in flight, and nothing to tell afterwards. Almost every limitation below is a
consequence of that one fact.

See [build-pipeline.md](build-pipeline.md) for how the pipeline works today.

---

## Known limitations

### 1. A build that succeeds but cannot report is never reported

**Trigger:** `callback_status_url` is unreachable, or returns 5xx, for the whole
attempt.

**Blast radius:** the site is live and correct; the client never hears so. The
`[ERROR] could not report success` log line is the only record.

**Why it is like this:** the callback deliberately never fails the message. Letting
`StatusNotifier` throw would replay the whole handler — download, extract, render,
promote — and spend the *build* retry budget on an HTTP problem, so a slow client
endpoint would end up reported as a failed build.

**Backstop today:** the log, plus a client-side timeout. The cheap fix short of a
database is a `.staging/<build_id>.done` marker written after promotion and removed after
a successful callback, letting a replay skip straight to the callback.

### 2. A mid-flight kill on the final delivery emits nothing

**Trigger:** OOM, `SIGKILL`, or a `consumer_timeout` during the quarantine/callback step
of the *third* delivery.

**Blast radius:** no callback, no quarantine, nothing. The message comes back
redelivered, `RejectRedeliveredMessageMiddleware` throws before the handler runs,
`isRetryable` is false (2 ≮ 2), and the message is rejected. Nothing on disk records
`callback_status_url`, so nothing can notify the client afterwards — not even by hand.

**Related, and more common:** the same middleware means *any* redelivery spends a build
attempt without the handler seeing it. A site that renders for longer than
`consumer_timeout` burns both attempts and is reported `failed` having never actually
failed — and the person debugging it starts with the renderer, which is the wrong place.
Dev `consumer_timeout` was raised to 120s for this; production is 300s.

**Backstop today:** a client-side timeout. A `WorkerMessageFailedEvent` subscriber firing
on `!$event->willRetry()` would catch every terminal path including this one (~40 lines),
but it duplicates the start-of-handler gate and still cannot help when the process dies
outright.

### 3. The callback is at-least-once, and duplicates are likely

**Trigger:** a crash between the POST and the ack; a `consumer_timeout` requeue; a
promote-failure retry that produces a `failed` and then a `success`.

**Blast radius:** the receiver sees the same outcome twice, or two different outcomes for
one `build_id`.

**Backstop today:** the published contract — receivers dedupe on `(build_id, status)` and
treat a terminal status as final. This is documented in
[build-pipeline.md](build-pipeline.md) and in the README, because it is a requirement on
the *receiver*, not an implementation detail.

### 4. Slug collisions are unguarded, by decision

**Trigger:** two different `static_site_id`s claiming the same slug.

**Blast radius:** they take the published tree from each other. Each reports `success`,
and nothing anywhere records which site owns a path.

**Why it is like this:** `publish` validates the slug's *shape* but has no store, so it
cannot enforce uniqueness — and the worker cannot either, because it only ever sees one
job at a time. Accepted deliberately: a new build simply takes the slug over, which the
promoter's swap already does as a matter of course.
[BuildPromoterTest](../tests/Job/BuildPromoterTest.php) pins that behaviour so the day it
changes, it changes on purpose.

**Backstop today:** none. This is the limitation most worth closing first.

### 5. `FAILED_DIR` grows without bound

**Trigger:** every terminal failure, forever.

**Blast radius:** a full volume eventually, which then fails *builds* — and quarantining
the resulting failures also fails.

**Backstop today:** none. No retention sweep is planned, and nothing records how old a
quarantined build is beyond its mtime.

### 6. `error_log()` is the only observability

**Trigger:** any question of the form "what happened to build X?".

**Blast radius:** answering it means reading `docker logs`. There is no status endpoint,
no build history, and no way to ask about a build by id.

Worth knowing: `messenger:consume` runs with `-vv`, so Messenger's own `critical` line
for a dropped message goes to a different stream than the handler's `[ERROR]` lines. When
limitation 2 fires, that `critical` line is the *only* evidence.

---

## What a job record unlocks

Each of these is a small piece of work once there is a table to write to — the hard part
is having one at all.

| Once there is a database | What it closes |
| --- | --- |
| Slug ownership + a blocked-slug registry in the `publish` API | **4** — a collision becomes a synchronous 400 at enqueue time instead of a silent takeover ~75 seconds later |
| A job row written *before* the build starts, carrying `callback_status_url` | **2** — a sweeper can report `failed` for jobs killed mid-flight, which nothing can do today because the URL dies with the message |
| A `reported_at` / terminal-status column | **1** and **3** — an unreported success can be retried out-of-band, and a duplicate POST can be suppressed at the source, making at-least-once effectively once |
| Build history per site | **5** — `FAILED_DIR` retention driven by a real policy ("keep the last N failures per site") rather than growing forever |
| An attempt counter persisted next to the job | The retry budget survives a broker restart, and a `consumer_timeout` requeue can be told apart from a genuine build failure — the thing that currently makes a slow render look like a failed one |
| Structured build records | **6** — an actual `GET /build/<id>` instead of `docker logs` |

### Where it belongs

**In `publish`, not here.** It is the only component that can answer synchronously, it
already generates `build_id`, and slug ownership is an API-level concern — the worker
sees one job at a time and cannot know what else exists. The worker's side of it is
narrow: report the outcome it already reports, against a record it did not have to
create.

That also fixes an asymmetry worth naming: `publish` generates `build_id` and then keeps
no record of it, so today it cannot correlate a callback to a job even if one arrived.
