<?php

declare(strict_types=1);

namespace App\Job;

/**
 * Filesystem operations helper with specific error handling.
 */
final class Filesystem
{
    /**
     * Creates $dir (and any missing parents), tolerating one already there.
     * $mode has no default on purpose to avoid accidentally setting the wrong mode.
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
     * @throws \RuntimeException with the reason if the rename fails
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
     * Moves a directory in an and across different mount points.
     * 
     * First try a rename but in case of cross-mount that might fail.
     * Then fallback to a copy+remove to avoid leaving a dangling directory.
     *
     * @throws \RuntimeException with the reason if the move fails
     */
    public static function moveDir(string $from, string $to): void
    {
        if (@rename($from, $to)) {
            return;
        }

        self::copyDir($from, $to);

        if (!self::removeDir($from)) {
            error_log(sprintf('[WARN] copied %s to %s but could not remove the source', $from, $to));
        }
    }

    /**
     * Recursively copies $from to $to preserving the modes of the source.
     * Symlinks are skipped, not followed or recreated. Skipping is logged because it's unexpected.
     *
     * @throws \RuntimeException if $from is not a directory, a directory cannot be created,
     *                           or a file cannot be copied
     */
    public static function copyDir(string $from, string $to): void
    {
        if (!is_dir($from)) {
            throw new \RuntimeException(sprintf('Cannot copy %s: not a directory.', $from));
        }

        self::ensureDir($to, fileperms($from) & 0o777);

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);

            $target = $to . '/' . $entries->getSubPathname();

            // isLink() MUST be tested first: isDir() and isFile() follow symlinks we want to skip.
            if ($entry->isLink()) {
                error_log(sprintf('[WARN] skipping symlink %s while copying %s', $entry->getPathname(), $from));
            } elseif ($entry->isDir()) {
                // getPerms() carries the file-type bits too, so mask them off.
                self::ensureDir($target, $entry->getPerms() & 0o777);
            } else {
                $copied = @copy($entry->getPathname(), $target);

                if (!$copied) {
                    throw new \RuntimeException(sprintf(
                        'Could not copy %s to %s: %s',
                        $entry->getPathname(),
                        $target,
                        error_get_last()['message'] ?? 'unknown error',
                    ));
                }

                // copy() ignores the source mode, so carry it over by hand.
                @chmod($target, $entry->getPerms() & 0o777);
            }
        }
    }

    /**
     * Recursively forces ownership and modes on $dir and everything under it.
     *
     * $owner and $group default to null, meaning "leave alone". Setting either
     * needs CAP_CHOWN, so they only work where the worker runs as root.
     *
     * @throws \RuntimeException if $dir is not a directory, or any chmod/chown/chgrp fails
     */
    public static function applyPermissions(
        string $dir,
        int $dirMode,
        int $fileMode,
        ?string $owner = null,
        ?string $group = null,
    ): void {
        if (!is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot apply permissions to %s: not a directory.', $dir));
        }

        self::applyTo($dir, $dirMode, $owner, $group);

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);

            // Skip symlinks avoiding jumping inside or outside the tree.
            if ($entry->isLink()) {
                error_log(sprintf('[WARN] skipping symlink %s while setting permissions', $entry->getPathname()));
            } else {
                self::applyTo($entry->getPathname(), $entry->isDir() ? $dirMode : $fileMode, $owner, $group);
            }
        }
    }

    /**
     * Applies $mode, $owner, and $group to dir or file at $path.
     * @throws \RuntimeException if any of the three cannot be applied
     */
    private static function applyTo(string $path, int $mode, ?string $owner, ?string $group): void
    {
        $modeSet = @chmod($path, $mode);

        if (!$modeSet) {
            throw new \RuntimeException(sprintf(
                'Could not set mode %04o on %s: %s',
                $mode,
                $path,
                error_get_last()['message'] ?? 'unknown error',
            ));
        }

        if ($owner !== null) {
            $ownerSet = @chown($path, $owner);

            if (!$ownerSet) {
                throw new \RuntimeException(sprintf(
                    'Could not set owner %s on %s: %s',
                    $owner,
                    $path,
                    error_get_last()['message'] ?? 'unknown error',
                ));
            }
        }

        if ($group !== null) {
            $groupSet = @chgrp($path, $group);

            if (!$groupSet) {
                throw new \RuntimeException(sprintf(
                    'Could not set group %s on %s: %s',
                    $group,
                    $path,
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
