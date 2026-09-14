<?php

declare(strict_types=1);

namespace App\Job;

/**
 * Shared "create this directory or fail loudly" step for the job pipeline:
 * JobWorkspace's input/output pair and ArchiveExtractor's unpack target both
 * need it, with the same failure mode (a permission problem or a missing
 * parent on a shared volume).
 */
final class Helper
{
    /**
     * Creates $dir (and any missing parents), tolerating one already there.
     *
     * @throws \RuntimeException if $dir does not exist and cannot be created
     */
    public static function ensureDir(string $dir): void
    {
        @mkdir($dir, 0o750, true);

        if (!is_dir($dir)) {
            throw new \RuntimeException(sprintf(
                'Could not create %s: %s',
                $dir,
                error_get_last()['message'] ?? 'unknown error',
            ));
        }
    }
}
