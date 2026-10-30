# Logging

Draft for discussion: which lines `ssg-worker` logs, at which level, and what a build looks like
in the log. Nothing here is implemented yet.

## Output

Line format, to a file under `var/log/` and to stderr, from `LOG_LEVEL` up: `info` in prod,
`debug` in dev.

```text
[datetime] channel.LEVEL: message {context} {extra}
```

- `context` holds the data of that one line: paths, sizes, counts.
- `extra` holds what every line during a build carries, Messenger's and HttpClient's included.
  `grep <build_id>` finds the whole build.

| `extra` key | Value |
|---|---|
| `build_id` | from the `BuildJob`; publish logs the same id |
| `static_site_id`, `slug` | from the `BuildJob` |
| `attempt` | 1 for the first try, 2 for the retry |
| `hostname` | the container id, which tells the replicas apart in the shared log file |

A processor sets these fields. It reads the `BuildJob` on Messenger's `WorkerMessageReceivedEvent`
and forgets it on `WorkerRunningEvent`, which follows the handler, `BuildFailureHandler` and the
retry decision. Lines outside a build, such as the worker stopping, have only `hostname`.

## Groups

| Group | Level | In prod | Answers |
|---|---|---|---|
| Lifecycle | `info` | yes | What happened to build X? |
| Steps | `debug` | no | How far did it get, with which files? |
| Anomalies | `warning` | yes | It worked, but something needs a look. |
| Failures | `error` | yes | It did not work, and why. |

## Lines

| Group | Message | Context | Where |
|---|---|---|---|
| Lifecycle | `Build started` | `url` | BuildJobHandler, before the first step |
| Steps | `Prepared job directory` | `dir` | BuildJobHandler, after `reset()` |
| Steps | `Downloaded content archive` | `file`, `bytes` | BuildJobHandler, after `download()` |
| Steps | `Extracted content archive` | `dir` | BuildJobHandler, after `extract()` |
| Steps | `Rendered site` | `pages`, `dir` | BuildJobHandler, after `render()` |
| Steps | `Moved site into place` | `from`, `to` | JobWorkspace, after the swap in `publish()` |
| Steps | `Cleared job directory` | `dir` | JobWorkspace, after `clear()` |
| Lifecycle | `Published site` | `pages`, `duration_ms`, `url` | BuildJobHandler, before the callback |
| Lifecycle | `Reported build status` | `status`, `url` | StatusNotifier, after a 2xx |
| Anomalies | `Skipped symlink while copying` | `path`, `from` | Filesystem |
| Anomalies | `Skipped symlink while setting permissions` | `path` | Filesystem |
| Anomalies | `Could not remove source after copy` | `from`, `to` | Filesystem |
| Anomalies | `Could not remove job directory` | `dir` | JobWorkspace |
| Failures | `Build failed` | `exception` | BuildFailureHandler |
| Failures | `Could not report failed build` | `exception` | BuildFailureHandler |

`duration_ms` is the time from `Build started` to `Published site` in that attempt.
`url` is the job's `content_download_url` in `Build started`, its `callback_status_url` in
`Reported build status`, and the public URL of the site in `Published site`.
`exception` writes the class, message, file, line and stack trace.

## URLs

The ids in `content_download_url` and `callback_status_url` are what keeps them private: they
cannot be guessed. The log carries the same ids as `build_id` and `static_site_id` to make builds
traceable, so the URLs are logged in full, and error messages repeat them.

Each class gets `LoggerInterface` through its constructor. `Filesystem` becomes a service for
that: its methods lose `static`, and `JobWorkspace` and `ArchiveExtractor` get it injected.

## Channels

Our code logs to `app`: every class takes a plain `LoggerInterface`. Symfony's components log to
their own channels through the same handlers, each from its own level:

| Channel | Who | dev | prod |
|---|---|---|---|
| `app` | our code | `debug` | `LOG_LEVEL` |
| `php` | PHP warnings, notices and uncaught errors | `debug` | `LOG_LEVEL` |
| `console` | a `bin/console` command ending in an exception | `debug` | `LOG_LEVEL` |
| `messenger` | Messenger | `debug` | `warning` |
| `http_client` | HttpClient | `debug` | `warning` |
| `deprecation` | deprecated calls | `debug` | off |
| `event` | a line per event listener call | off | off |

In prod a successful build shows only our lifecycle lines; retries, dropped messages and PHP
errors still show.

The `messenger` and `http_client` lines a build produces:

| Channel | Level | Message |
|---|---|---|
| `messenger` | `info` | `Received message …`, `… handled by …`, `… was handled successfully` |
| `messenger` | `warning` | `Error thrown while handling message …. Sending for retry #1 …` |
| `messenger` | `critical` | `Error thrown while handling message …. Removing from transport …` |
| `messenger` | `info` | `Worker stopped due to time limit …` |
| `http_client` | `info` | `Request: "GET …"`, `Response: "200 …" 0.41 seconds` |

## Examples

Prod, a successful build:

```text
[…14:03:12] app.INFO: Build started {"url":"https://cloud.example/…/content.tar.gz"} {"build_id":"0199c3e2-…","static_site_id":"site-42","slug":"handbook","attempt":1,"hostname":"3f9a1c2b7d4e"}
[…14:03:14] app.INFO: Published site {"pages":12,"duration_ms":2310,"url":"https://sites.example/site-42/handbook/"} {"build_id":"0199c3e2-…",…}
[…14:03:14] app.INFO: Reported build status {"status":"published","url":"https://cloud.example/…/status"} {"build_id":"0199c3e2-…",…}
```

Prod, a build that fails for good:

```text
app.INFO: Build started {…} {"build_id":"0199c3e2-…",…}
messenger.CRITICAL: Error thrown while handling message App\Message\BuildJob. Removing from transport after 1 retries. Error: "Invalid Collectives export: …" {…} {"build_id":"0199c3e2-…",…}
app.ERROR: Build failed {"exception":"[object] (InvalidArgumentException(code: 0): Invalid Collectives export: … at /ssg-worker/src/Job/SiteRenderer.php:44)\n[stacktrace]\n#0 …"} {"build_id":"0199c3e2-…",…}
app.INFO: Reported build status {"status":"failed",…} {"build_id":"0199c3e2-…",…}
```

A worker killed mid-build leaves `Build started` with neither `Published site` nor `Build failed`
after it.

## Open questions

- Are the steps right at `debug`, or should some be visible in prod?

## Sentry

Errors go to [GlitchTip](https://glitchtip.com/), which speaks Sentry's protocol, through
`sentry/sentry-symfony`. Sentry is one more Monolog handler: what reaches it is decided in
`monolog.yaml`, like the file and stderr. The bundle is registered in prod only, and without
`SENTRY_DSN` it sends nothing.

| Variable     | Required | Default | Description                                     |
| ------------ | -------- | ------- | ----------------------------------------------- |
| `SENTRY_DSN` | no       | none    | GlitchTip project DSN; unset sends no events    |

| Log line | In Sentry |
|---|---|
| `error` and up with an `exception`, except `messenger` and `http_client` | an event |
| `messenger.CRITICAL` `… Removing from transport …` | nothing; `Build failed` reports the same exception |
| `messenger.WARNING` `… Sending for retry …` | nothing; only the last attempt is reported |
| `error` without an `exception` | nothing |
| everything below `error`, also `debug` whatever `LOG_LEVEL` is | a breadcrumb on the next event of the build |

In practice that is `Build failed`, `Could not report failed build`, a `console.CRITICAL` when
`messenger:consume` itself dies, and PHP fatal errors. A message that is not a `BuildJob` and fails
is only in the log.
The bundle's own error listener, error handler and Messenger listener are off, so an exception
arrives once. Tracing is off.

An event carries:

- the exception with its stack trace,
- the log line's `extra` as `monolog.extra`: `build_id`, `static_site_id`, `slug`, `attempt`,
  `hostname`,
- the build's earlier lines as breadcrumbs, with their context: `Build started`,
  `Downloaded content archive`, … up to the step that failed.

The ids and the URLs go to GlitchTip like they go to the log file: `Build started` carries
`content_download_url`, `Reported build status` the callback URL, and exception messages repeat
them. Whoever reads GlitchTip can read the URLs.

`App\Logging\SentryMessageContext` starts a Sentry context per message, so an event carries only
the breadcrumbs of its own build, not those of the builds the worker handled before.

`release` is the Composer version of the app (`dev-main@<commit>` in a checkout) unless
`SENTRY_RELEASE` is set; `environment` is `prod`.
