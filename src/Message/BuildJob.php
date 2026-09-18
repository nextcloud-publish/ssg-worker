<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Encodes and decodes the messages consumed from `q.builds`, which the publish
 * API enqueues. It declares the same namespace, class name and property names:
 * Messenger picks the target class from the message's `type` header
 * ("App\Message\BuildJob"), then the serializer maps JSON keys onto constructor
 * parameter names, so a mismatch on either breaks decoding.
 */

final class BuildJob
{
    public function __construct(
        /** Per-build id, for tracing a job across services. */
        public readonly string $build_id,
        /** Used for the job's folder under JOB_STORAGE_DIR. */
        public readonly string $static_site_id,
        /**
         * PATH-SAFE. Names the published site directory under PUBLISHED_DIR, so
         * it is restricted to [A-Za-z0-9_-]{1,128} -- the same allow-list as the
         * two ids. The publish API rejects anything else with a 400;
         * JobWorkspace re-checks it, because a queue is not a trust boundary.
         */
        public readonly string $slug,
        /** Download url for the build assets */
        public readonly string $content_download_url,
        /** Callback url to update the build status */
        public readonly string $callback_status_url,
        /** Timestamp when the job was created by the publish API. */
        public readonly string $created_at,
        /**
         * Human-readable site title, rendered as the header link on every page.
         * Free-form, which is why it is separate from the slug: SiteBuilder
         * escapes it, and the slug has to stay a safe directory name.
         *
         * KEEP THE DEFAULT. A queued message that predates this field carries
         * no `title` key, and the serializer maps JSON keys onto constructor
         * parameters: a required parameter with no key is a DECODE error, not a
         * handler error, so the envelope becomes a
         * MessageDecodingFailedException. That is not
         * UnrecoverableExceptionInterface, so it would burn the retry budget
         * and be dropped with no callback at all. With the default it decodes
         * as '' and the handler falls back to the slug.
         *
         * NO GETTERS ON THIS CLASS. The publish API normalizes it, and ObjectNormalizer
         * would turn a getTitle() into an extra JSON key.
         */
        public readonly string $title = '',
    ) {
    }
}
