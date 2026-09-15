<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Used to encode and decode messages consumed from the `q.builds` queue which
 * are enqueued by the publish api service: same namespace, class
 * name, and property names. Messenger library picks the target class from the
 * message's `type` header ("App\Message\BuildJob"), then the serializer maps
 * JSON keys to constructor parameter names -- a mismatch breaks decoding.
 */

final class BuildJob
{
    public function __construct(
        /** Per-build id, for tracing a job across services. */
        public readonly string $build_id,
        /** Used for the job's folder under JOB_STORAGE_DIR. */
        public readonly string $static_site_id,
        /** Used for publishing the build page */
        public readonly string $slug,
        /** Download url for the build assets */
        public readonly string $content_download_url,
        /** Callback url to update the build status */
        public readonly string $callback_status_url,
        /** Timestamp when the job was created at the publish api service*/
        public readonly string $created_at,
    ) {
    }
}
