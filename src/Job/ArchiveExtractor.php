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
    /**
     * @throws \RuntimeException if the directory cannot be created or tar fails
     */
    public function extract(string $archive, string $targetDir): void
    {
        Helper::ensureDir($targetDir);

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
                implode(' ', $output),
            ));
        }
    }
}
