<?php

declare(strict_types=1);

namespace App\Tests\Job;

use App\Job\Filesystem;
use PHPUnit\Framework\TestCase;

/**
 * Covers App\Job\Filesystem.
 *
 * The cross-mount tests use /dev/shm, a tmpfs and therefore a different mount point from
 * sys_get_temp_dir() on a normal Linux box.
 * That is the only way to make rename() return EXDEV in a unit test, and without it the copy
 * fallback in moveDir() never runs.
 * Where /dev/shm is unavailable those tests are skipped rather than faked.
 */
final class FilesystemTest extends TestCase
{
    private string $root;
    private ?string $otherMount = null;
    private string $logFile;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/worker-fs-test-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);

        $this->logFile = sys_get_temp_dir() . '/worker-fs-log-' . bin2hex(random_bytes(6)) . '.log';
        $this->previousErrorLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        @unlink($this->logFile);

        foreach ([$this->root, $this->otherMount] as $dir) {
            if ($dir !== null && is_dir($dir)) {
                exec('rm -rf ' . escapeshellarg($dir));
            }
        }
    }

    /**
     * Creates a directory on a mount point other than the one holding $this->root.
     * Skips the test when /dev/shm is missing or shares a device with the temp dir.
     *
     * @return string the path of the new directory on the other mount
     */
    private function onAnotherMount(): string
    {
        if (!is_dir('/dev/shm') || !is_writable('/dev/shm')) {
            self::markTestSkipped('no second mount point available to force EXDEV');
        }

        $this->otherMount = '/dev/shm/worker-fs-test-' . bin2hex(random_bytes(6));
        mkdir($this->otherMount, 0o755, true);

        $srcDev = stat($this->root)['dev'];
        $dstDev = stat($this->otherMount)['dev'];
        if ($srcDev === $dstDev) {
            self::markTestSkipped('/dev/shm shares a device with the temp dir, so rename() would not fail');
        }

        return $this->otherMount;
    }

    /**
     * Builds a three-level tree with siblings at every level, the shape a rendered site has.
     * A single-chain fixture would not catch anything that mishandles a directory holding more than one entry.
     *
     *   index.html  style.css  assets/logo.svg
     *   nested/page.html  nested/other.html
     *   nested/deeper/leaf.html
     *
     * @param string $at The directory to build the tree in.
     * @return void
     */
    private function givenTree(string $at): void
    {
        mkdir($at . '/nested/deeper', 0o755, true);
        mkdir($at . '/assets', 0o755, true);

        file_put_contents($at . '/index.html', 'root page');
        file_put_contents($at . '/style.css', 'body{}');
        file_put_contents($at . '/assets/logo.svg', '<svg/>');
        file_put_contents($at . '/nested/page.html', 'nested page');
        file_put_contents($at . '/nested/other.html', 'sibling page');
        file_put_contents($at . '/nested/deeper/leaf.html', 'leaf page');
    }

    // --- copyDir ----------------------------------------------------------

    public function testCopyDirReproducesTheWholeTree(): void
    {
        $this->givenTree($this->root . '/from');

        Filesystem::copyDir($this->root . '/from', $this->root . '/to');

        self::assertSame('root page', file_get_contents($this->root . '/to/index.html'));
        self::assertSame('nested page', file_get_contents($this->root . '/to/nested/page.html'));
        self::assertSame('leaf page', file_get_contents($this->root . '/to/nested/deeper/leaf.html'));

        /** Siblings, not just the deepest chain. */
        self::assertSame('body{}', file_get_contents($this->root . '/to/style.css'));
        self::assertSame('<svg/>', file_get_contents($this->root . '/to/assets/logo.svg'));
        self::assertSame('sibling page', file_get_contents($this->root . '/to/nested/other.html'));
    }

    public function testCopyDirLeavesTheSourceAlone(): void
    {
        $this->givenTree($this->root . '/from');

        Filesystem::copyDir($this->root . '/from', $this->root . '/to');

        self::assertFileExists($this->root . '/from/index.html');
    }

    /** The three modes are deliberately different, so a copy that ignored the source would fail here. */
    public function testCopiedDirectoriesKeepTheSourceMode(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('running as root: permission bits are not enforced');
        }

        $this->givenTree($this->root . '/from');
        chmod($this->root . '/from/nested/deeper', 0o700);
        chmod($this->root . '/from/nested', 0o750);
        chmod($this->root . '/from', 0o711);

        Filesystem::copyDir($this->root . '/from', $this->root . '/to');

        self::assertSame(0o711, fileperms($this->root . '/to') & 0o777);
        self::assertSame(0o750, fileperms($this->root . '/to/nested') & 0o777);
        self::assertSame(0o700, fileperms($this->root . '/to/nested/deeper') & 0o777);
    }

    /** moveDir() picks rename or copy from the mount layout alone, so both paths must leave the same modes. */
    public function testCopyingAcrossMountsMatchesWhatRenameWouldHaveLeft(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('running as root: permission bits are not enforced');
        }

        $other = $this->onAnotherMount();

        $this->givenTree($this->root . '/from');
        chmod($this->root . '/from/nested', 0o750);
        chmod($this->root . '/from', 0o750);

        /** Same mount: rename, which preserves modes by definition. */
        Filesystem::moveDir($this->root . '/from', $this->root . '/renamed');

        $this->givenTree($this->root . '/from2');
        chmod($this->root . '/from2/nested', 0o750);
        chmod($this->root . '/from2', 0o750);

        /** Different mount: the copy fallback. */
        Filesystem::moveDir($this->root . '/from2', $other . '/copied');

        self::assertSame(
            fileperms($this->root . '/renamed') & 0o777,
            fileperms($other . '/copied') & 0o777,
        );
        self::assertSame(
            fileperms($this->root . '/renamed/nested') & 0o777,
            fileperms($other . '/copied/nested') & 0o777,
        );
    }

    public function testCopiedFilesKeepTheSourceMode(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('running as root: permission bits are not enforced');
        }

        $this->givenTree($this->root . '/from');
        chmod($this->root . '/from/index.html', 0o640);
        chmod($this->root . '/from/nested/page.html', 0o600);

        Filesystem::copyDir($this->root . '/from', $this->root . '/to');

        self::assertSame(0o640, fileperms($this->root . '/to/index.html') & 0o777);
        self::assertSame(0o600, fileperms($this->root . '/to/nested/page.html') & 0o777);
    }

    /** Following a symlink would duplicate content or publish a link pointing out of the served tree. */
    public function testCopyDirSkipsSymlinksAndSaysSo(): void
    {
        mkdir($this->root . '/from', 0o755, true);
        file_put_contents($this->root . '/from/real.html', 'real');
        file_put_contents($this->root . '/outside.txt', 'should not be published');
        symlink($this->root . '/outside.txt', $this->root . '/from/escape.html');

        Filesystem::copyDir($this->root . '/from', $this->root . '/to');

        self::assertFileExists($this->root . '/to/real.html');
        self::assertFileDoesNotExist($this->root . '/to/escape.html');
        self::assertStringContainsString('skipping symlink', (string) file_get_contents($this->logFile));
    }

    // --- setFileAccess ----------------------------------------------------

    public function testSetFileAccessSetsTheWholeTree(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('running as root: permission bits are not enforced');
        }

        $this->givenTree($this->root . '/tree');
        chmod($this->root . '/tree/nested', 0o700);
        chmod($this->root . '/tree/index.html', 0o600);
        chmod($this->root . '/tree/nested/deeper/leaf.html', 0o777);

        Filesystem::setFileAccess($this->root . '/tree', 0o755, 0o644);

        /** The root itself, not just what is under it. */
        self::assertSame(0o755, fileperms($this->root . '/tree') & 0o777);
        self::assertSame(0o755, fileperms($this->root . '/tree/nested') & 0o777);
        self::assertSame(0o755, fileperms($this->root . '/tree/nested/deeper') & 0o777);

        self::assertSame(0o644, fileperms($this->root . '/tree/index.html') & 0o777);
        self::assertSame(0o644, fileperms($this->root . '/tree/nested/page.html') & 0o777);
        self::assertSame(0o644, fileperms($this->root . '/tree/nested/deeper/leaf.html') & 0o777);
    }

    /** chmod() and chown() act on a symlink's target, so following one would change permissions outside the tree. */
    public function testSetFileAccessSkipsSymlinksRatherThanFollowingThem(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('running as root: permission bits are not enforced');
        }

        mkdir($this->root . '/tree', 0o755, true);
        file_put_contents($this->root . '/outside.txt', 'not ours');
        chmod($this->root . '/outside.txt', 0o600);
        symlink($this->root . '/outside.txt', $this->root . '/tree/link.txt');

        Filesystem::setFileAccess($this->root . '/tree', 0o755, 0o644);

        self::assertSame(0o600, fileperms($this->root . '/outside.txt') & 0o777);
        self::assertStringContainsString('skipping symlink', (string) file_get_contents($this->logFile));
    }

    public function testSetFileAccessRefusesAPathThatIsNotADirectory(): void
    {
        file_put_contents($this->root . '/afile', 'x');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a directory');

        Filesystem::setFileAccess($this->root . '/afile', 0o755, 0o644);
    }

    /** "nosuchuser!" cannot resolve, which is the same failure an unprivileged worker hits. */
    public function testSetFileAccessThrowsWhenTheOwnerCannotBeSet(): void
    {
        $this->givenTree($this->root . '/tree');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not set owner');

        Filesystem::setFileAccess($this->root . '/tree', 0o755, 0o644, 'nosuchuser!');
    }

    // --- removeDir --------------------------------------------------------

    /**
     * rmdir() only works on an empty directory, so removeDir() depends on CHILD_FIRST returning every entry before its parent.
     * The fixture is three levels deep, so a shallow tree cannot pass by accident.
     */
    public function testRemoveDirDeletesFilesAndNestedDirectories(): void
    {
        $this->givenTree($this->root . '/tree');

        self::assertTrue(Filesystem::removeDir($this->root . '/tree'));
        self::assertDirectoryDoesNotExist($this->root . '/tree');
    }

    /** reset() relies on this: nothing to remove is not a failure. */
    public function testRemoveDirReportsSuccessWhenThereIsNothingThere(): void
    {
        self::assertTrue(Filesystem::removeDir($this->root . '/never-existed'));
    }

    /**
     * rmdir() refuses a symlink with ENOTDIR, so without the isLink() guard the link survives and the removal reports failure.
     * The assertions on the targets check that nothing outside the tree is touched, because an archive-derived tree can point anywhere.
     */
    public function testRemoveDirUnlinksSymlinksInsteadOfFollowingThem(): void
    {
        mkdir($this->root . '/outside/keep', 0o755, true);
        file_put_contents($this->root . '/outside/keep/precious.txt', 'must survive');
        file_put_contents($this->root . '/outside/loose.txt', 'must survive too');

        mkdir($this->root . '/tree', 0o755, true);
        file_put_contents($this->root . '/tree/own.txt', 'goes away');
        symlink($this->root . '/outside/keep', $this->root . '/tree/link-to-dir');
        symlink($this->root . '/outside/loose.txt', $this->root . '/tree/link-to-file');

        self::assertTrue(Filesystem::removeDir($this->root . '/tree'));

        /** The tree and both links are gone. */
        self::assertDirectoryDoesNotExist($this->root . '/tree');

        /** Nothing they pointed at was touched. */
        self::assertSame('must survive', file_get_contents($this->root . '/outside/keep/precious.txt'));
        self::assertSame('must survive too', file_get_contents($this->root . '/outside/loose.txt'));
    }

    /** Every caller branches on the return value: reset() throws on it, clear() and moveDir() warn. */
    public function testRemoveDirReportsFailureWhenSomethingCannotBeRemoved(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('running as root: permission bits are not enforced');
        }

        mkdir($this->root . '/tree/locked', 0o755, true);
        file_put_contents($this->root . '/tree/locked/stuck.txt', 'cannot be unlinked');

        // Unlinking needs write permission on the containing directory.
        chmod($this->root . '/tree/locked', 0o500);

        try {
            self::assertFalse(Filesystem::removeDir($this->root . '/tree'));
            self::assertFileExists($this->root . '/tree/locked/stuck.txt');
        } finally {
            chmod($this->root . '/tree/locked', 0o755);
        }
    }

    // --- a symlink handed in as the root ----------------------------------
    //
    // is_dir() resolves symlinks, so every entry point here has to check the path it is
    // given, not just the entries it finds. The iterator refuses to descend into links
    // inside a tree, but it opens the root directly.

    /** Without the isLink() guard this deletes the target's contents and then returns false. */
    public function testRemoveDirUnlinksARootThatIsItselfASymlink(): void
    {
        $this->givenTree($this->root . '/precious');
        symlink($this->root . '/precious', $this->root . '/link');

        self::assertTrue(Filesystem::removeDir($this->root . '/link'));

        self::assertFalse(is_link($this->root . '/link'));
        self::assertDirectoryExists($this->root . '/precious');
        self::assertSame('root page', file_get_contents($this->root . '/precious/index.html'));
        self::assertSame('leaf page', file_get_contents($this->root . '/precious/nested/deeper/leaf.html'));
    }

    public function testSetFileAccessRefusesARootThatIsItselfASymlink(): void
    {
        $this->givenTree($this->root . '/precious');
        symlink($this->root . '/precious', $this->root . '/link');

        try {
            Filesystem::setFileAccess($this->root . '/link', 0o700, 0o600);
            self::fail('Expected a symlinked root to be refused.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('symlink', $e->getMessage());
        }

        /** Nothing the link points at was touched. */
        self::assertSame(0o755, fileperms($this->root . '/precious/nested') & 0o777);
    }

    public function testCopyDirRefusesASourceThatIsItselfASymlink(): void
    {
        $this->givenTree($this->root . '/precious');
        symlink($this->root . '/precious', $this->root . '/link');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('symlink');

        Filesystem::copyDir($this->root . '/link', $this->root . '/to');
    }

    // --- the cross-mount path ---------------------------------------------

    public function testRenameFailsAcrossMountPointsWhichIsWhyMoveDirExists(): void
    {
        $other = $this->onAnotherMount();
        $this->givenTree($this->root . '/from');

        // PHP has no directory fallback for rename() across mounts, so this is a hard failure.
        self::assertFalse(@rename($this->root . '/from', $other . '/to'));
        self::assertStringContainsString('cross-device', strtolower(error_get_last()['message'] ?? ''));
    }

    public function testMoveDirCopiesAcrossMountPoints(): void
    {
        $other = $this->onAnotherMount();
        $this->givenTree($this->root . '/from');

        Filesystem::moveDir($this->root . '/from', $other . '/to');

        self::assertSame('root page', file_get_contents($other . '/to/index.html'));
        self::assertSame('leaf page', file_get_contents($other . '/to/nested/deeper/leaf.html'));

        /** A move, not a copy: the source is gone afterwards. */
        self::assertDirectoryDoesNotExist($this->root . '/from');
    }

    public function testMoveDirStillRenamesWhenItCan(): void
    {
        /** Same mount, so the rename path. The inode surviving proves it was not a copy. */
        $this->givenTree($this->root . '/from');
        $inode = fileinode($this->root . '/from');

        Filesystem::moveDir($this->root . '/from', $this->root . '/to');

        self::assertSame($inode, fileinode($this->root . '/to'));
        self::assertDirectoryDoesNotExist($this->root . '/from');
    }
}
