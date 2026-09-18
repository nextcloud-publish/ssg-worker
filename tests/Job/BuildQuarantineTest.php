<?php

declare(strict_types=1);

namespace App\Tests\Job;

use App\Job\BuildQuarantine;
use App\Job\JobLayout;
use PHPUnit\Framework\TestCase;

/**
 * Quarantining is best effort by design. This whole path exists to tell a
 * client their build failed, and nothing about moving a directory aside is
 * allowed to suppress that -- so the assertions below are as much about what is
 * NOT thrown as about what is moved.
 */
final class BuildQuarantineTest extends TestCase
{
    private const SITE = 'site-42';
    private const BUILD = '16ef078ad37fd894';

    private string $root;
    private ?string $failedOnOtherMount = null;
    private JobLayout $layout;
    private BuildQuarantine $quarantine;
    private string $logFile;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/worker-quarantine-test-' . bin2hex(random_bytes(6));

        $this->layout = new JobLayout(
            $this->root . '/build_temp',
            $this->root . '/published',
            $this->root . '/build_failed',
        );
        $this->quarantine = new BuildQuarantine($this->layout);

        $this->logFile = sys_get_temp_dir() . '/worker-quarantine-log-' . bin2hex(random_bytes(6)) . '.log';
        $this->previousErrorLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        @unlink($this->logFile);

        foreach ([$this->root, $this->failedOnOtherMount] as $dir) {
            if ($dir !== null && is_dir($dir)) {
                exec('rm -rf ' . escapeshellarg($dir));
            }
        }
    }

    private function givenAFailedJob(string $build = self::BUILD): string
    {
        $jobDir = $this->layout->jobDir(self::SITE, $build);
        mkdir($jobDir . '/input', 0o750, true);
        file_put_contents($jobDir . '/input/content.tar.gz', 'half a download');

        return $jobDir;
    }

    public function testMovesTheWholeJobTreeAside(): void
    {
        $jobDir = $this->givenAFailedJob();

        self::assertTrue($this->quarantine->quarantine(self::SITE, self::BUILD));

        $failed = $this->layout->failedJobDir(self::BUILD);
        self::assertFileExists($failed . '/input/content.tar.gz');

        // The source is gone: this is a move, not a copy, whichever mechanism
        // Filesystem::moveDir() ended up using underneath.
        self::assertDirectoryDoesNotExist($jobDir);
    }

    public function testIsKeyedOnBuildIdSoTwoFailuresOfOneSiteDoNotCollide(): void
    {
        $this->givenAFailedJob('aaaaaaaaaaaaaaaa');
        $this->givenAFailedJob('bbbbbbbbbbbbbbbb');

        $this->quarantine->quarantine(self::SITE, 'aaaaaaaaaaaaaaaa');
        $this->quarantine->quarantine(self::SITE, 'bbbbbbbbbbbbbbbb');

        self::assertDirectoryExists($this->layout->failedJobDir('aaaaaaaaaaaaaaaa'));
        self::assertDirectoryExists($this->layout->failedJobDir('bbbbbbbbbbbbbbbb'));
    }

    /**
     * The normal case when the build failed before its directories existed --
     * an unsafe id, or a workspace that could not be created. Must not throw,
     * or the client never hears why.
     */
    public function testAMissingJobDirectoryIsNotAnError(): void
    {
        self::assertFalse($this->quarantine->quarantine(self::SITE, self::BUILD));
    }

    public function testAReplayIsANoOpAndDoesNotThrow(): void
    {
        $this->givenAFailedJob();
        $this->quarantine->quarantine(self::SITE, self::BUILD);

        // Second delivery after a failed callback: already quarantined.
        self::assertFalse($this->quarantine->quarantine(self::SITE, self::BUILD));
        self::assertFileExists($this->layout->failedJobDir(self::BUILD) . '/input/content.tar.gz');
    }

    /**
     * An unsafe static_site_id is one of the things ssg-worker fails a build
     * FOR, so it arrives here routinely. There is no directory to move and the
     * id cannot be used to build a path -- but the callback still has to go.
     */
    public function testAnUnsafeIdIsRefusedWithoutThrowing(): void
    {
        self::assertFalse($this->quarantine->quarantine('../escape', self::BUILD));
        self::assertStringContainsString('not quarantining', (string) file_get_contents($this->logFile));
    }

    public function testATraversalIdWritesNothingOutsideTheRoots(): void
    {
        $escapee = \dirname($this->root) . '/worker-quarantine-escaped-' . bin2hex(random_bytes(6));

        self::assertFalse($this->quarantine->quarantine('../' . basename($escapee), self::BUILD));

        self::assertDirectoryDoesNotExist($escapee);
    }

    /**
     * In the dev stack the temp tree is a named volume and the quarantine tree
     * is a bind mount, so this move crosses a mount point and copies rather
     * than renaming. /dev/shm is the only way to reproduce that here.
     */
    public function testQuarantinesAcrossAMountBoundary(): void
    {
        if (!is_dir('/dev/shm') || !is_writable('/dev/shm')) {
            self::markTestSkipped('no second mount point available to force EXDEV');
        }

        $this->failedOnOtherMount = '/dev/shm/worker-quarantine-' . bin2hex(random_bytes(6));
        mkdir($this->failedOnOtherMount, 0o750, true);

        if (stat($this->root)['dev'] === stat($this->failedOnOtherMount)['dev']) {
            self::markTestSkipped('/dev/shm shares a device with the temp dir');
        }

        $layout = new JobLayout(
            $this->root . '/build_temp',
            $this->root . '/published',
            $this->failedOnOtherMount,
        );

        $jobDir = $layout->jobDir(self::SITE, self::BUILD);
        mkdir($jobDir . '/input', 0o750, true);
        file_put_contents($jobDir . '/input/content.tar.gz', 'half a download');

        self::assertTrue((new BuildQuarantine($layout))->quarantine(self::SITE, self::BUILD));

        self::assertFileExists($layout->failedJobDir(self::BUILD) . '/input/content.tar.gz');
        self::assertDirectoryDoesNotExist($jobDir);
    }

    /**
     * The copy is not atomic, so a crash mid-copy leaves a partial tree. It must
     * not sit at the path the replay guard reads as "already quarantined", or
     * the next delivery would skip a job that was never fully moved.
     */
    public function testAHalfCopiedQuarantineDoesNotCountAsQuarantined(): void
    {
        $partial = $this->layout->failedJobDir(self::BUILD) . '.partial';
        mkdir($partial, 0o750, true);
        file_put_contents($partial . '/input', 'half copied');

        $this->givenAFailedJob();

        self::assertTrue($this->quarantine->quarantine(self::SITE, self::BUILD));

        // The stale partial was discarded rather than merged into or mistaken
        // for a finished quarantine.
        self::assertFileExists($this->layout->failedJobDir(self::BUILD) . '/input/content.tar.gz');
        self::assertDirectoryDoesNotExist($partial);
    }

    public function testTheQuarantineTreeIsNotWorldReadable(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('running as root: permission bits are not enforced');
        }

        $this->givenAFailedJob();
        $this->quarantine->quarantine(self::SITE, self::BUILD);

        // Unlike the published tree, nothing serves this one.
        self::assertSame(0o750, fileperms($this->root . '/build_failed') & 0o777);
    }
}
