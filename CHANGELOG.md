# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Logging with Monolog to `var/log/ssg-worker.log` and stderr, in line format, from `LOG_LEVEL` up
  (`info`, `debug` in dev). Every line carries the container's `hostname`. The `messenger` and
  `http_client` channels write from `warning` up in prod.

### Changed

- The image runs `messenger:consume` without `-vv`; `LOG_LEVEL` sets what is logged.

## [0.2.0] - 2026-10-30

### Changed

- Images in GHCR are tagged `main` for the newest build of `main`, the branch name for a pull
  request, and `1.2.3`, `1.2`, `1` and `latest` for a release tag `1.2.3` (no `0` during 0.x).
  Every image also gets `sha-<commit>`. CI runs on pushes to `main` and on release tags.

## [0.1.0] - 2026-10-29

### Added

- Consumes `BuildJob` messages from `q.builds` with Symfony Messenger, the format the publish
  API enqueues.
- Builds a site per job: downloads the content archive, extracts it, renders the Markdown with
  ssg-library, and publishes it to `PUBLISHED_DIR/<static_site_id>/<slug>/` by staging and
  renaming.
- Reports the outcome with a `PUT` to `callback_status_url`: `published` with the site's URL, or
  `failed` with a redacted error message.
- Two attempts per build; errors that cannot succeed on retry are reported on the first.
- The callback client refuses private network addresses.
- `docker/Dockerfile` with the stages `base` (no source) and `release`, running as a non-root
  user. PHP reads its time zone from `TZ`, default UTC.
- CI on pull requests: lint, dependency audit, PHPStan at level 10, tests, and an image pushed to
  GHCR.
- Pre-commit hooks that run the CI lint and test steps.

[Unreleased]: https://github.com/nextcloud-publish/ssg-worker/compare/0.2.0...HEAD
[0.2.0]: https://github.com/nextcloud-publish/ssg-worker/releases/tag/0.2.0
[0.1.0]: https://github.com/nextcloud-publish/ssg-worker/releases/tag/0.1.0
