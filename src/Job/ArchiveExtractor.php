<?php

declare(strict_types=1);

namespace App\Job;

/**
 * Unpacks a job's downloaded content archive into $targetDir, creating it if
 * needed. Shells out to tar rather than using PharData, which crashes on the
 * non-ASCII filenames Collectives exports contain. The archive is assumed to be
 * a gzipped tar; nothing validates that before tar is run.
 *
 * Does not guard against a hostile archive -- path traversal, symlinks and
 * decompression bombs all pass. That's tolerable only because ContentDownloader
 * limits where an archive may come from (http/https, size-capped); everything
 * inside one is trusted completely.
 */
final class ArchiveExtractor
{
    /** Directory mode for the extracted content: worker-private, never served. */
    private const EXTRACT_DIR_MODE = 0o750;

    /**
     * How much of tar's output reaches the exception. tar prints a line per
     * problem member, and the message travels in an ErrorDetailsStamp as an
     * AMQP header on retry -- an unbounded one would exceed RabbitMQ's 128 KiB
     * frame_max and fail the republish itself, uncaught, inside Worker::ack().
     */
    private const MAX_TAR_OUTPUT_LINES = 5;
    private const MAX_TAR_OUTPUT_CHARS = 1000;

    /**
     * @throws \RuntimeException if the directory cannot be created or tar fails
     */
    public function extract(string $archive, string $targetDir): void
    {
        Filesystem::ensureDir($targetDir, self::EXTRACT_DIR_MODE);

        // Captures tar's own error message for the exception below.
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
