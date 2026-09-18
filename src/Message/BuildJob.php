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
        /**
         * PATH-SAFE. Names the published site directory under PUBLISHED_DIR, so
         * it is restricted to [A-Za-z0-9_-]{1,128} -- the same allow-list as the
         * two ids. publish rejects anything else with a 400, and JobLayout
         * re-checks it here because a queue is not a trust boundary.
         */
        public readonly string $slug,
        /** Download url for the build assets */
        public readonly string $content_download_url,
        /** Callback url to update the build status */
        public readonly string $callback_status_url,
        /** Timestamp when the job was created at the publish api service*/
        public readonly string $created_at,
        /**
         * Human-readable site title, rendered as the header link on every page.
         * Free-form -- SiteBuilder escapes it -- which is exactly why it cannot
         * be the slug any more now that the slug is a directory name.
         *
         * OPTIONAL AND LAST, and both of those are the compatibility story. A
         * message enqueued before this field existed carries no `title` key;
         * the serializer maps JSON keys onto constructor parameters, and a
         * parameter with no default and no key is a
         * MissingConstructorArgumentsException -- which is not a handler error
         * but a DECODE error, so the whole envelope becomes a
         * MessageDecodingFailedException. That class is NOT
         * UnrecoverableExceptionInterface, so it would burn the retry budget
         * and then be dropped with no callback at all. With the default it
         * decodes as '' and the handler falls back to the slug. The reverse
         * (publish sends `title` to a worker that predates it) is already safe:
         * ObjectNormalizer ignores unknown keys. So the two services can be
         * deployed in either order.
         *
         * NO GETTERS ON THIS CLASS, ever: publish NORMALIZES it, and
         * ObjectNormalizer would turn a getTitle() into an extra JSON key.
         */
        public readonly string $title = '',
    ) {
    }
}
