<?php

declare(strict_types=1);

namespace App\Job;

/**
 * The filesystem primitives the build pipeline needs, each failing with the
 * operating system's own reason instead of a generic message -- a full disk,
 * an unmounted volume, a permission problem and a typo'd env var otherwise all
 * present as "rename failed", with nothing useful reaching the client.
 */
final class Filesystem
{
    /**
     * Creates $dir (and any missing parents), tolerating one already there.
     *
     * $mode has no default on purpose: the published tree must be readable by
     * the uid that serves it and the build temp tree must not, and getting that
     * backwards is a 403 on every page that no unit test would catch.
     *
     * @throws \RuntimeException if $dir does not exist and cannot be created
     */
    public static function ensureDir(string $dir, int $mode): void
    {
        if (is_dir($dir)) {
            return;
        }

        @mkdir($dir, $mode, true);

        if (!is_dir($dir)) {
            throw new \RuntimeException(sprintf(
                'Could not create %s: %s',
                $dir,
                error_get_last()['message'] ?? 'unknown error',
            ));
        }

        // mkdir() applies the process umask to $mode, so an explicit chmod is
        // what actually makes the mode explicit.
        @chmod($dir, $mode);
    }

    /**
     * @throws \RuntimeException with the real reason if the rename fails
     */
    public static function rename(string $from, string $to): void
    {
        if (@rename($from, $to)) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Could not move %s to %s: %s',
            $from,
            $to,
            error_get_last()['message'] ?? 'unknown error',
        ));
    }

    /**
     * Moves a directory that may be on a different mount point.
     *
     * rename(2) compares mount points, not filesystems, and PHP's rename() has
     * no directory fallback -- a cross-mount move fails outright with EXDEV
     * instead of degrading to a copy. Whether the build temp tree and the
     * published tree share a mount is the operator's choice (see
     * JobWorkspace), so this exists to make that not matter. rename() is
     * tried first since it's atomic and instant when they do share one.
     *
     * The copy fallback is neither atomic nor instant: callers must account
     * for a crash mid-copy (JobWorkspace::publish() copies into .staging, not
     * the live path, for that reason) and for a large site running past the
     * broker's consumer_timeout while the message stays unacked.
     *
     * @throws \RuntimeException with the real reason if the move fails
     */
    public static function moveDir(string $from, string $to): void
    {
        if (@rename($from, $to)) {
            return;
        }

        self::copyDir($from, $to);

        if (!self::removeDir($from)) {
            // The copy succeeded, so the move is done as far as the caller is
            // concerned and only the source is left behind. Failing the
            // publish over a stray scratch directory would be worse.
            error_log(sprintf('[WARN] copied %s to %s but could not remove the source', $from, $to));
        }
    }

    /**
     * Recursively copies $from to $to, creating directories at $dirMode and
     * leaving files at whatever the umask gives them (0644 under the default,
     * which is what the published tree wants).
     *
     * Symlinks are skipped, not followed or recreated: nothing that generates
     * a site writes them, so one appearing means something is wrong, and
     * copying it would either duplicate content or publish a link pointing
     * out of the tree. Skipping is logged so it isn't silent.
     *
     * @throws \RuntimeException if a directory cannot be created or a file cannot be copied
     */
    public static function copyDir(string $from, string $to, int $dirMode = 0o755): void
    {
        self::ensureDir($to, $dirMode);

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);

            $target = $to . '/' . $entries->getSubPathname();

            if ($entry->isLink()) {
                error_log(sprintf('[WARN] skipping symlink %s while copying %s', $entry->getPathname(), $from));
                continue;
            }

            if ($entry->isDir()) {
                self::ensureDir($target, $dirMode);
                continue;
            }

            if (!@copy($entry->getPathname(), $target)) {
                throw new \RuntimeException(sprintf(
                    'Could not copy %s to %s: %s',
                    $entry->getPathname(),
                    $target,
                    error_get_last()['message'] ?? 'unknown error',
                ));
            }
        }
    }

    /**
     * Recursively deletes $dir. Returns false instead of throwing: every caller
     * is cleaning up after work that already succeeded, and a stray scratch
     * directory must never undo it.
     *
     * Iterates rather than shelling out to `rm -rf`: the paths are derived from
     * queue input, and the failure mode of a mistake in a shell command is very
     * much worse than the failure mode of a mistake in a loop.
     */
    public static function removeDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        $ok = true;
        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);

            // isDir() follows symlinks, and a symlink to a directory has to be
            // unlinked rather than rmdir'd -- otherwise a hostile archive could
            // get a link followed out of the tree.
            $ok = ($entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname()))
                && $ok;
        }

        return @rmdir($dir) && $ok;
    }

    /** True when $dir holds no entries other than . and .. */
    public static function isEmptyDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $_) {
            return false;
        }

        return true;
    }
}
