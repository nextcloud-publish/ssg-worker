<?php

declare(strict_types=1);

namespace App\Job;

/**
 * Unpacks a job's downloaded content archive into $targetDir, creating it if needed.
 * Calls to the tar command via exec() rather than using Php's PharData, which crashes on the
 * non-ASCII filenames Collectives exports contain.
 * The archive is assumed to be a gzipped tar; nothing validates that before the tar command is called.
 *
 * Known limitation: does not guard against a hostile archive -- path traversal, symlinks and decompression bombs all pass.
 */
final class ArchiveExtractor
{
    /** Directory mode for the extracted content folder. */
    private const EXTRACT_DIR_MODE = 0o750;

    /**
     * How much of tar's output reaches the exception.
     * Tar prints a line per problem member, and the message travels in an ErrorDetailsStamp as an AMQP header on retry.
     * Setting size limits to avoid exceeding the AMQP frame_max limit of 128 KiB at the next try.
     */
    private const MAX_TAR_OUTPUT_LINES = 5;
    private const MAX_TAR_OUTPUT_CHARS = 1000;

    /**
     * Unpacks a job's downloaded content archive into $targetDir, creating it if needed.
     *
     * @param string $archive The archive to extract.
     * @param string $targetDir The directory to extract the archive to.
     * @return void
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
