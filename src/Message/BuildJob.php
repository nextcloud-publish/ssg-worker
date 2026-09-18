<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Encodes and decodes the messages send via symfony messenger, which the publish
 * API enqueues. It declares the same namespace, class name and property names:
 * Messenger picks the target class from the message's `type` header
 * ("App\Message\BuildJob"), then the serializer maps JSON keys onto constructor parameter names,
 * so a mismatch on either breaks decoding.
 */
final class BuildJob
{
    public function __construct(
        /** Per-build id, for tracing a job across services. */
        public readonly string $build_id,
        /** Used for the job's folder under JOB_STORAGE_DIR. */
        public readonly string $static_site_id,
        /**
         * Names the published site directory under PUBLISHED_DIR which translates
         * to the uri path of the published site.
         * It is restricted to [A-Za-z0-9_-]{1,128} and is re-checked in JobWorkspace
         */
        public readonly string $slug,
        /** Download url for the build assets */
        public readonly string $content_download_url,
        /** Callback url to update the build status */
        public readonly string $callback_status_url,
        /** Timestamp when the job was created by the publish API. */
        public readonly string $created_at,
        /** Site title, shown in the page header. */
        public readonly string $title,
    ) {
    }
}
