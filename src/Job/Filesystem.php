<?php

declare(strict_types=1);

namespace App\Job;

use Psr\Log\LoggerInterface;

/**
 * Filesystem operations helper class with specific error handling.
 *
 * @param LoggerInterface $logger The app logger, for what is skipped or left behind.
 */
final class Filesystem
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Creates $dir (and any missing parents), tolerating one already there.
     * $mode has no default on purpose to avoid accidentally setting the wrong mode.
     *
     * @param string $dir The directory to create.
     * @param int $mode The mode to set for the directory.
     * @return void
     * @throws \RuntimeException if $dir does not exist and cannot be created
     */
    public function ensureDir(string $dir, int $mode): void
    {
        if (is_dir($dir)) {
            return;
        }

        @mkdir($dir, $mode, true);
        if (!is_dir($dir)) {
            throw new \RuntimeException(sprintf(
                'Could not create %s: %s', $dir, error_get_last()['message'] ?? 'unknown error'));
        }

        // mkdir() applies the process umask to $mode, so an explicit chmod is
        // what actually makes the mode explicit.
        @chmod($dir, $mode);
    }

    /**
     * Renames a directory from $from to $to.
     *
     * @param string $from The directory to move from.
     * @param string $to The directory to move to.
     * @return void
     * @throws \RuntimeException with the reason if the rename fails
     */
    public function rename(string $from, string $to): void
    {
        if (@rename($from, $to)) {
            return;
        }
        throw new \RuntimeException(sprintf(
            'Could not move %s to %s: %s', $from, $to, error_get_last()['message'] ?? 'unknown error'));
    }

    /**
     * Moves a directory in an and across different mount points.
     *
     * First try a rename but in case of cross-mount that might fail.
     * Then fallback to a copy+remove to avoid leaving a dangling directory.
     *
     * @param string $from The directory to move from.
     * @param string $to The directory to move to.
     * @return void
     * @throws \RuntimeException with the reason if the move fails
     */
    public function moveDir(string $from, string $to): void
    {
        if (@rename($from, $to)) {
            return;
        }

        $this->copyDir($from, $to);

        if (!$this->removeDir($from)) {
            $this->logger->warning('Could not remove source after copy', ['from' => $from, 'to' => $to]);
        }
    }

    /**
     * Recursively copies $from to $to preserving the modes of the source.
     * Symlinks are skipped, not followed or recreated. Skipping is logged because it's unexpected.
     *
     * @param string $from The directory to copy from.
     * @param string $to The directory to copy to.
     * @return void
     * @throws \RuntimeException if $from is not a directory, a directory cannot be created,
     *                           or a file cannot be copied
     */
    public function copyDir(string $from, string $to): void
    {
        if (is_link($from)) {
            throw new \RuntimeException(sprintf('Refusing to copy through the symlink %s.', $from));
        }

        if (!is_dir($from)) {
            throw new \RuntimeException(sprintf('Cannot copy %s: not a directory.', $from));
        }

        $this->ensureDir($to, fileperms($from) & 0o777);

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);

            $target = $to . '/' . $entries->getSubPathname();

            // isLink() MUST be tested first: isDir() and isFile() follow symlinks we want to skip.
            if ($entry->isLink()) {
                $this->logger->warning('Skipped symlink while copying', ['path' => $entry->getPathname(), 'from' => $from]);
            } elseif ($entry->isDir()) {
                // getPerms() carries the file-type bits too, so mask them off.
                $this->ensureDir($target, $entry->getPerms() & 0o777);
            } else {
                $copied = @copy($entry->getPathname(), $target);

                if (!$copied) {
                    throw new \RuntimeException(sprintf(
                        'Could not copy %s to %s: %s', $entry->getPathname(), $target, error_get_last()['message'] ?? 'unknown error'));
                }

                // copy() ignores the source mode, so carry it over by hand.
                @chmod($target, $entry->getPerms() & 0o777);
            }
        }
    }

    /**
     * Recursively forces ownership and modes on $dir and everything under it.
     *
     * $owner and $group default to null, meaning "leave alone".
     * Setting either needs CAP_CHOWN, so they only work where the worker runs as root.
     *
     * @param string $dir The directory to set the file access for.
     * @param int $dirMode The mode to set for the directory.
     * @param int $fileMode The mode to set for the files.
     * @param ?string $owner The owner to set for the directory.
     * @param ?string $group The group to set for the directory.
     * @return void
     * @throws \RuntimeException if $dir is not a directory, or any chmod/chown/chgrp fails
     */
    public function setFileAccess(string $dir, int $dirMode, int $fileMode, ?string $owner = null, ?string $group = null): void
    {
        // is_dir() resolves symlinks, so this would otherwise chmod/chown the
        // tree the link points at. Refused rather than unlinked: nothing here
        // is meant to be destructive.
        if (is_link($dir)) {
            throw new \RuntimeException(sprintf('Refusing to set file access through the symlink %s.', $dir));
        }

        if (!is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot set file access on %s: not a directory.', $dir));
        }

        $this->applyFileAccessTo($dir, $dirMode, $owner, $group);

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);

            // Skip symlinks avoiding jumping inside or outside the tree.
            if ($entry->isLink()) {
                $this->logger->warning('Skipped symlink while setting permissions', ['path' => $entry->getPathname()]);
            } else {
                $this->applyFileAccessTo($entry->getPathname(), $entry->isDir() ? $dirMode : $fileMode, $owner, $group);
            }
        }
    }

    /**
     * Applies $mode, $owner, and $group to dir or file at $path.
     *
     * @param string $path The path to apply the file access to.
     * @param int $mode The mode to set for the directory or file.
     * @param ?string $owner The owner to set for the directory or file.
     * @param ?string $group The group to set for the directory or file.
     * @return void
     * @throws \RuntimeException if any of the three cannot be applied
     */
    private function applyFileAccessTo(string $path, int $mode, ?string $owner, ?string $group): void
    {
        $modeSet = @chmod($path, $mode);

        if (!$modeSet) {
            throw new \RuntimeException(sprintf(
                'Could not set mode %04o on %s: %s', $mode, $path, error_get_last()['message'] ?? 'unknown error'));
        }

        if ($owner !== null) {
            $ownerSet = @chown($path, $owner);
            if (!$ownerSet) {
                throw new \RuntimeException(sprintf(
                    'Could not set owner %s on %s: %s', $owner, $path, error_get_last()['message'] ?? 'unknown error'));
            }
        }

        if ($group !== null) {
            $groupSet = @chgrp($path, $group);
            if (!$groupSet) {
                throw new \RuntimeException(sprintf(
                    'Could not set group %s on %s: %s', $group, $path, error_get_last()['message'] ?? 'unknown error'));
            }
        }
    }

    /**
     * Recursively deletes $dir.
     * Returns false when deletetion of $dir fails which can be caused by a failure deleting files/folders inside $dir.
     *
     * @param string $dir The directory to delete.
     * @return bool true when $dir is deleted, false when deletion fails
     */
    public function removeDir(string $dir): bool
    {
        // is_dir() resolves symlinks. We simply unlink them.
        if (is_link($dir)) {
            return @unlink($dir);
        }

        if (!is_dir($dir)) {
            return true;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);

            // isDir() follows symlinks, so we need to check for them.
            $isDir = $entry->isDir() && !$entry->isLink();

            if ($isDir) {
                /** remove directories */
                @rmdir($entry->getPathname());
            } else {
                /** remove files and symlinks */
                @unlink($entry->getPathname());
            }
        }

        return @rmdir($dir);
    }

    /**
     * True when $dir holds no entries other than . and ..
     *
     * @param string $dir The directory to check if it is empty.
     * @return bool true when $dir is empty, false when it is not
     */
    public function isEmptyDir(string $dir): bool
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
