<?php

declare(strict_types=1);

namespace App\Message;

/**
 * The message dispatched onto the `q.build-failures` queue when a build throws,
 * and consumed by publish's result worker.
 *
 * Dispatched by BuildJobHandler and routed to the `build_failures` transport by
 * config/packages/messenger.yaml.
 *
 * HAND-SYNCED with publish's App\Message\BuildFailed -- see BuildSucceeded's
 * docblock for why that matters and what pins it.
 *
 * `error` is the whole reason this is an explicit message rather than a
 * failure_transport or a broker dead-letter: RabbitMQ's x-first-death-reason
 * only ever says `rejected`, `delivery_limit` or `expired`, and an
 * ErrorDetailsStamp rides in a header a handler cannot read. The actual cause
 * -- "tar: unexpected EOF", "Download failed with HTTP 404" -- only survives if
 * it is in the body.
 */
final class BuildFailed
{
    public function __construct(
        /** Per-build id, for tracing a job across services. */
        public readonly string $build_id,
        /** Names the job's folder under JOB_STORAGE_DIR. May be unsafe: it is what failed. */
        public readonly string $static_site_id,
        /** Where the result worker reports the outcome. */
        public readonly string $callback_status_url,
        /** The exception message from the build pipeline. */
        public readonly string $error,
        /** When the build failed, ATOM format. */
        public readonly string $failed_at,
    ) {
    }
}
