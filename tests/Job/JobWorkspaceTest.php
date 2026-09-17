<?php

declare(strict_types=1);

namespace App\Tests\Job;

use App\Job\JobWorkspace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JobWorkspaceTest extends TestCase
{
    private const BUILD_ID = '16ef078ad37fd894';

    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/ssg-worker-workspace-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->baseDir)) {
            exec('rm -rf ' . escapeshellarg($this->baseDir));
        }
    }

    public function testCreatesInputAndOutputDirectories(): void
    {
        $jobDir = (new JobWorkspace($this->baseDir))->createJobDirectories('site-42', self::BUILD_ID);

        self::assertDirectoryExists($this->baseDir . '/site-42/' . self::BUILD_ID . '/input');
        self::assertDirectoryExists($this->baseDir . '/site-42/' . self::BUILD_ID . '/output');

        // The folder holding the pair, not one of the two.
        self::assertSame($this->baseDir . '/site-42/' . self::BUILD_ID, $jobDir);
    }

    public function testNormalisesATrailingSlashOnTheBaseDirectory(): void
    {
        // JOB_STORAGE_DIR is set by hand, so a trailing slash is likely.
        $jobDir = (new JobWorkspace($this->baseDir . '/'))->createJobDirectories('site-42', self::BUILD_ID);

        self::assertSame($this->baseDir . '/site-42/' . self::BUILD_ID, $jobDir);
        self::assertDirectoryExists($jobDir . '/input');
    }

    public function testCreatesDirectoriesThatAreWritable(): void
    {
        // Both get written into, so present is not enough.
        (new JobWorkspace($this->baseDir))->createJobDirectories('site-42', self::BUILD_ID);

        self::assertDirectoryIsWritable($this->baseDir . '/site-42/' . self::BUILD_ID . '/input');
        self::assertDirectoryIsWritable($this->baseDir . '/site-42/' . self::BUILD_ID . '/output');
    }

    public function testIsIdempotentForTheSameBuild(): void
    {
        // A redelivery of the same build reuses the folders and leaves their
        // contents alone.
        $workspace = new JobWorkspace($this->baseDir);
        $workspace->createJobDirectories('site-42', self::BUILD_ID);
        file_put_contents($this->baseDir . '/site-42/' . self::BUILD_ID . '/input/keep.md', '# keep');

        $workspace->createJobDirectories('site-42', self::BUILD_ID);

        self::assertDirectoryExists($this->baseDir . '/site-42/' . self::BUILD_ID . '/output');
        self::assertFileExists($this->baseDir . '/site-42/' . self::BUILD_ID . '/input/keep.md');
    }

    /**
     * The point of keying on build_id. Two builds of one site get separate
     * trees, so the result worker can tell "already promoted" from "the next
     * build is mid-flight", and a rebuild never inherits pages that were
     * deleted from the collective (SiteBuilder does not clear its output dir).
     */
    public function testTwoBuildsOfTheSameSiteGetSeparateDirectories(): void
    {
        $workspace = new JobWorkspace($this->baseDir);

        $first = $workspace->createJobDirectories('site-42', 'aaaaaaaaaaaaaaaa');
        file_put_contents($first . '/output/stale.html', 'from the first build');

        $second = $workspace->createJobDirectories('site-42', 'bbbbbbbbbbbbbbbb');

        self::assertNotSame($first, $second);
        self::assertFileDoesNotExist($second . '/output/stale.html');
        self::assertFileExists($first . '/output/stale.html');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideUnsafeBuildIds(): array
    {
        return self::provideUnsafeIds();
    }

    #[DataProvider('provideUnsafeBuildIds')]
    public function testRejectsUnsafeBuildId(string $unsafeId): void
    {
        // build_id becomes a directory name too, so it gets the same
        // allow-list. It is bin2hex(random_bytes(8)) on publish's side and
        // passes unchanged, but nothing in the message itself guarantees that.
        self::assertFalse(JobWorkspace::isValidBuildId($unsafeId));

        $this->expectException(\InvalidArgumentException::class);
        (new JobWorkspace($this->baseDir))->createJobDirectories('site-42', $unsafeId);
    }

    public function testAnUnsafeBuildIdCreatesNothing(): void
    {
        $escapee = dirname($this->baseDir) . '/ssg-worker-escaped-' . bin2hex(random_bytes(6));

        try {
            (new JobWorkspace($this->baseDir))
                ->createJobDirectories('site-42', '../../' . basename($escapee));
            self::fail('Expected an InvalidArgumentException for a traversal build id.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('build_id', $e->getMessage());
            self::assertDirectoryDoesNotExist($escapee);
            self::assertDirectoryDoesNotExist($this->baseDir);
        }
    }

    public function testAcceptsAUuidStaticSiteId(): void
    {
        // What trigger-build.sh posts. The allow-list stays wider than a
        // UUID so it accepts whatever publish accepts.
        $uuid = '11f5b798-6f34-4951-ad8b-bfd623ded5c2';

        (new JobWorkspace($this->baseDir))->createJobDirectories($uuid, self::BUILD_ID);

        self::assertDirectoryExists($this->baseDir . '/' . $uuid . '/' . self::BUILD_ID . '/input');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideUnsafeIds(): array
    {
        return [
            'parent traversal' => ['../escape'],
            'nested traversal' => ['../../etc/cron.d'],
            'bare dotdot' => ['..'],
            'single dot' => ['.'],
            'absolute path' => ['/etc/cron.d'],
            'contains slash' => ['site/nested'],
            'null byte' => ["site\0"],
            'empty' => [''],
            'too long' => [str_repeat('a', 129)],
        ];
    }

    #[DataProvider('provideUnsafeIds')]
    public function testRejectsUnsafeStaticSiteId(string $unsafeId): void
    {
        self::assertFalse(JobWorkspace::isValidStaticSiteId($unsafeId));

        // Not RuntimeException: a bad id is the caller's mistake, not the
        // environment's.
        $this->expectException(\InvalidArgumentException::class);
        (new JobWorkspace($this->baseDir))->createJobDirectories($unsafeId, self::BUILD_ID);
    }

    public function testTraversalIdCreatesNothingOutsideTheBaseDirectory(): void
    {
        $escapee = dirname($this->baseDir) . '/ssg-worker-escaped-' . bin2hex(random_bytes(6));

        try {
            (new JobWorkspace($this->baseDir))->createJobDirectories('../' . basename($escapee), self::BUILD_ID);
            self::fail('Expected an InvalidArgumentException for a traversal id.');
        } catch (\InvalidArgumentException) {
            self::assertDirectoryDoesNotExist($escapee);
        }
    }

    public function testThrowsWhenTheBaseDirectoryCannotBeCreated(): void
    {
        // A regular file where the base directory should be.
        $blocked = sys_get_temp_dir() . '/ssg-worker-blocked-' . bin2hex(random_bytes(6));
        file_put_contents($blocked, 'not a directory');

        try {
            (new JobWorkspace($blocked))->createJobDirectories('site-42', self::BUILD_ID);
            self::fail('Expected a RuntimeException for an uncreatable directory.');
        } catch (\RuntimeException $e) {
            // Without the reason, a full disk, an unmounted volume and a
            // typo in JOB_STORAGE_DIR all read identically.
            self::assertStringContainsString('Not a directory', $e->getMessage());
            self::assertStringNotContainsString('unknown error', $e->getMessage());
            // And the path, so it is clear which directory failed.
            self::assertStringContainsString($blocked, $e->getMessage());
        } finally {
            unlink($blocked);
        }
    }

    public function testFailureReasonIsTheRealCauseNotAnEarlierWarning(): void
    {
        // error_get_last() is global and could hand back an unrelated
        // warning; a failing mkdir() always raises its own one first.
        @file_get_contents('/definitely/not/here');

        $blocked = sys_get_temp_dir() . '/ssg-worker-blocked-' . bin2hex(random_bytes(6));
        file_put_contents($blocked, 'not a directory');

        try {
            (new JobWorkspace($blocked))->createJobDirectories('site-42', self::BUILD_ID);
            self::fail('Expected a RuntimeException for an uncreatable directory.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Not a directory', $e->getMessage());
            self::assertStringNotContainsString('file_get_contents', $e->getMessage());
        } finally {
            unlink($blocked);
        }
    }
}
