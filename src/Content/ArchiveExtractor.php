<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Unpacks a job's downloaded content archive.
 *
 * Shells out to tar rather than using PharData: PharData mishandles non-ASCII
 * entry names, which a Collectives export is full of -- it throws on the
 * sample corpus's "Gorila_de_montaña_(...).jpg" alone.
 *
 * The format is assumed to be a gzipped tar. Nothing here validates that, or
 * guards against hostile archives (paths escaping the target, symlinks,
 * decompression bombs) -- see the notes in the plan before this is fed
 * anything less trusted than the integration fixture.
 */
final class ArchiveExtractor
{
    /**
     * Creates $targetDir if needed, then unpacks $archive into it.
     *
     * @throws \RuntimeException if the directory cannot be created or tar fails
     */
    public function extract(string $archive, string $targetDir): void
    {
        // tar -C needs the destination to exist, and nobody else creates it:
        // publish only provisions input/ and output/. The second is_dir()
        // covers a concurrent create between the check and the mkdir.
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0o755, true) && !is_dir($targetDir)) {
            $reason = error_get_last()['message'] ?? 'unknown error';

            throw new \RuntimeException("Could not create {$targetDir}: {$reason}");
        }

        // 2>&1 so tar's own message ends up in $output and can be reported;
        // without it the exception would only carry a numeric exit code.
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
