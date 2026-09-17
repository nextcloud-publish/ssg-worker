<?php

declare(strict_types=1);

namespace App\Message;

/**
 * The message dispatched onto the `q.build-results` queue when a build finishes,
 * and consumed by publish's result worker.
 *
 * Dispatched by BuildJobHandler and routed to the `build_results` transport by
 * config/packages/messenger.yaml.
 *
 * HAND-SYNCED with publish's App\Message\BuildSucceeded: same FQCN, same
 * property names, same order. Messenger's `type` header carries the class name
 * and the symfony_serializer maps JSON keys straight onto constructor
 * arguments, so a rename on one side is a decode failure on the other that no
 * test in either repo will catch on its own. MessageContractTest in both repos
 * pins the wire format against a shared fixture; change both together.
 *
 * Property names are snake_case because the serializer uses them verbatim as
 * the JSON keys.
 *
 * static_site_id and callback_status_url have to travel on the wire even though
 * they were already on the BuildJob: there is no database and no on-disk job
 * record, so this message is the only thing the result worker has to act on.
 */
final class BuildSucceeded
{
    public function __construct(
        /** Per-build id, for tracing a job across services. */
        public readonly string $build_id,
        /** Names the job's folder under JOB_STORAGE_DIR, and the published site. */
        public readonly string $static_site_id,
        /** The site title the build was rendered with. */
        public readonly string $slug,
        /** How many pages were rendered. */
        public readonly int $pages,
        /** Where the result worker reports the outcome. */
        public readonly string $callback_status_url,
        /** When the build finished, ATOM format. */
        public readonly string $finished_at,
    ) {
    }
}
