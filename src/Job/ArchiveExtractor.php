<?php

declare(strict_types=1);

namespace App\Job;

/**
 * Unpacks a job's downloaded content archive:
 *  - creates $targetDir if it doesn't exist
 *  - runs the tar command instead of using PharData, avoiding  crashes
 *    because of non-ASCII filenames in Collectives exports
 *  - assumes the archive is a gzipped tar, does not validate that
 *
 * Does not guard against a hostile archive: path traversal, symlinks,
 * decompression bombs. These are tolerable today only because the content
 * downloader restricts where an archive can come from (http/https, size-capped)
 * and this class still trusts everything inside one completely.
 */
final class ArchiveExtractor
{
    /** Directory mode for the extracted content: worker-private, never served. */
    private const EXTRACT_DIR_MODE = 0o750;

    /**
     * How much of tar's output makes it into the exception.
     *
     * tar prints one line per problem member, so a pathological archive can
     * produce megabytes. That does not just make a long failure callback: the
     * message ends up in an ErrorDetailsStamp, which travels as an AMQP HEADER
     * on the retry republish, and RabbitMQ's default frame_max is 128 KiB. An
     * unbounded message would make the retry publish itself fail, inside
     * Worker::ack() where nothing catches it.
     */
    private const MAX_TAR_OUTPUT_LINES = 5;
    private const MAX_TAR_OUTPUT_CHARS = 1000;

    /**
     * @throws \RuntimeException if the directory cannot be created or tar fails
     */
    public function extract(string $archive, string $targetDir): void
    {
        Filesystem::ensureDir($targetDir, self::EXTRACT_DIR_MODE);

        // captures tar's own error message for the exception below
        exec(
            sprintf('tar -xzf %s -C %s 2>&1', escapeshellarg($archive), escapeshellarg($targetDir)),
            $output,
            $exitCode,
        );

        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf(
                'Extracting %s failed (exit %d): %s',
                $archive,
                $exitCode,
                mb_substr(
                    implode(' ', \array_slice($output, 0, self::MAX_TAR_OUTPUT_LINES)),
                    0,
                    self::MAX_TAR_OUTPUT_CHARS,
                ),
            ));
        }
    }
}
