<?php

declare(strict_types=1);

namespace App\Tests\Job;

use App\Job\Filesystem;
use PHPUnit\Framework\TestCase;

/**
 * The cross-mount tests use /dev/shm, which is a tmpfs and therefore a
 * different mount point from sys_get_temp_dir() on a normal Linux box. That is
 * the only way to make rename() actually return EXDEV in a unit test, and
 * without it the copy fallback is never executed -- every other test in this
 * suite puts both trees in one temp directory, where rename() simply succeeds.
 *
 * Skipped rather than faked where /dev/shm is not available, because a fake
 * would test the fake.
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

    /** A directory on a different mount point, or a skipped test. */
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

    private function givenTree(string $at): void
    {
        mkdir($at . '/nested/deeper', 0o755, true);
        file_put_contents($at . '/index.html', 'root page');
        file_put_contents($at . '/nested/page.html', 'nested page');
        file_put_contents($at . '/nested/deeper/leaf.html', 'leaf page');
    }

    public function testCopyDirReproducesTheWholeTree(): void
    {
        $this->givenTree($this->root . '/from');

        Filesystem::copyDir($this->root . '/from', $this->root . '/to');

        self::assertSame('root page', file_get_contents($this->root . '/to/index.html'));
        self::assertSame('nested page', file_get_contents($this->root . '/to/nested/page.html'));
        self::assertSame('leaf page', file_get_contents($this->root . '/to/nested/deeper/leaf.html'));
    }

    public function testCopyDirLeavesTheSourceAlone(): void
    {
        $this->givenTree($this->root . '/from');

        Filesystem::copyDir($this->root . '/from', $this->root . '/to');

        self::assertFileExists($this->root . '/from/index.html');
    }

    public function testCopiedDirectoriesGetTheRequestedMode(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('running as root: permission bits are not enforced');
        }

        $this->givenTree($this->root . '/from');

        Filesystem::copyDir($this->root . '/from', $this->root . '/to', 0o755);

        self::assertSame(0o755, fileperms($this->root . '/to') & 0o777);
        self::assertSame(0o755, fileperms($this->root . '/to/nested') & 0o777);
    }

    /**
     * Nothing that generates a site writes symlinks, so one appearing means
     * something is wrong. Following it would either duplicate content or
     * publish a link pointing out of the served tree.
     */
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

    // --- the cross-mount path ---------------------------------------------

    public function testRenameFailsAcrossMountPointsWhichIsWhyMoveDirExists(): void
    {
        $other = $this->onAnotherMount();
        $this->givenTree($this->root . '/from');

        // The premise of the whole design. PHP has no directory fallback, so
        // this is a hard failure rather than a slow success.
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

        // A move, not a copy: the source is gone afterwards.
        self::assertDirectoryDoesNotExist($this->root . '/from');
    }

    public function testMoveDirStillRenamesWhenItCan(): void
    {
        // Same mount: the cheap path. Proven by the inode surviving, which a
        // copy would not preserve.
        $this->givenTree($this->root . '/from');
        $inode = fileinode($this->root . '/from');

        Filesystem::moveDir($this->root . '/from', $this->root . '/to');

        self::assertSame($inode, fileinode($this->root . '/to'));
        self::assertDirectoryDoesNotExist($this->root . '/from');
    }
}
