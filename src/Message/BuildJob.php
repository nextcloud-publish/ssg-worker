<?php

declare(strict_types=1);

namespace App\Message;

/**
 * One build job, as it arrives from q.builds.
 *
 * Deliberately an exact mirror of publish's App\Message\BuildJob (same
 * namespace, same class name, same properties): Messenger's AMQP transport
 * decodes the wire body using messenger.transport.symfony_serializer, which
 * picks the target class from the `type` header publish stamps on every
 * message -- that header carries the literal string "App\Message\BuildJob",
 * so this class has to exist under that exact name for decoding to resolve
 * at all, no explicit class-name mapping required.
 *
 * The properties are snake_case for the same reason they are on the publish
 * side: they are the wire contract, not an internal detail.
 *
 * No #[AsMessageHandler]-relevant fields beyond what publish actually sends.
 * Notably absent: job_workdir. BuildJobHandler here only logs the payload for
 * now -- it does not download, extract or render -- so there is nothing on
 * this side that needs a field publish does not send.
 */
final class BuildJob
{
    public function __construct(
        /** Opaque per-build id, for tracing the job across services. */
        public readonly string $build_id,
        public readonly string $static_site_id,
        public readonly string $slug,
        public readonly string $content_download_url,
        public readonly string $callback_status_url,
        /** ATOM-formatted string, exactly as published -- see publish's BuildJob for why. */
        public readonly string $created_at,
    ) {
    }
}
