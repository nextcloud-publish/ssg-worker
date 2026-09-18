<?php

declare(strict_types=1);

namespace App\Job;

/**
 * The handful of filesystem primitives the build pipeline needs, each failing
 * with the operating system's own reason rather than a generic message.
 *
 * That last part is the whole point of wrapping them: a full disk, an unmounted
 * volume, a permission problem and a typo'd environment variable all present as
 * "rename failed" otherwise, and what reaches the client is a failure callback
 * that says nothing useful.
 *
 * This replaced App\Job\Helper, which hardcoded 0750 and never chmod'd. The
 * difference is load-bearing and invisible to a unit test: output/ is created
 * at 0750 and the container runs as root, so a promoted tree left at that mode
 * is one whatever serves the site cannot traverse -- a 403 on every page. Hence
 * the explicit $mode here, with no default.
 */
final class Filesystem
{
    /**
     * Creates $dir (and any missing parents), tolerating one already there.
     *
     * Mode is explicit rather than defaulted, because the two callers want
     * different things: the published tree has to be readable by whatever uid
     * serves it, the quarantine tree deliberately does not.
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
     * rename(2) compares MOUNT POINTS, not filesystems -- the kernel rejects
     * `old_path.mnt != new_path.mnt` before it ever looks at the superblock --
     * and PHP's rename() has no fallback for directories, so a cross-mount move
     * fails outright with EXDEV rather than degrading to a copy. The dev stack
     * deliberately puts the build temp tree, the published tree and the
     * quarantine tree on three separate mounts, so every move between them
     * lands here.
     *
     * rename() is still tried first: it is atomic and instant when the two
     * happen to share a mount, which is the case in the unit tests and in any
     * deployment that consolidates them.
     *
     * THE COPY IS NOT ATOMIC, and callers have to account for that. Copy into a
     * scratch name on the destination mount and rename it into place there, so
     * a crash mid-copy leaves the scratch name rather than a half-built tree
     * something might publish.
     *
     * It is also not instant: the message stays unacked for the whole copy, so
     * a large enough site could in principle run past the broker's
     * consumer_timeout (30s in dev, 300s in prod) and be redelivered.
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
            // concerned; only the source is left behind. Losing the whole
            // promotion over a leftover scratch file would be worse.
            error_log(sprintf('[WARN] copied %s to %s but could not remove the source', $from, $to));
        }
    }

    /**
     * Recursively copies $from to $to, creating directories at $dirMode and
     * leaving files at whatever the umask gives them (0644 under the default,
     * which is what the published tree wants).
     *
     * Symlinks are SKIPPED, not followed and not recreated. Nothing that
     * generates a site writes them, so one appearing means something is wrong,
     * and copying it would either duplicate content or publish a link pointing
     * out of the tree. Skipping is logged so it is not silent.
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
     * here is cleaning up after work that already succeeded, and a leftover
     * scratch file must never replay a completed promotion.
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
