<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Storage\JobWorkspace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JobWorkspaceTest extends TestCase
{
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
        $jobDir = (new JobWorkspace($this->baseDir))->createJobDirectories('site-42');

        self::assertDirectoryExists($this->baseDir . '/site-42/input');
        self::assertDirectoryExists($this->baseDir . '/site-42/output');

        // The folder holding the pair, not one of the two.
        self::assertSame($this->baseDir . '/site-42', $jobDir);
    }

    public function testNormalisesATrailingSlashOnTheBaseDirectory(): void
    {
        // JOB_STORAGE_DIR is set by hand, so a trailing slash is likely.
        $jobDir = (new JobWorkspace($this->baseDir . '/'))->createJobDirectories('site-42');

        self::assertSame($this->baseDir . '/site-42', $jobDir);
        self::assertDirectoryExists($jobDir . '/input');
    }

    public function testCreatesDirectoriesThatAreWritable(): void
    {
        // Both get written into, so present is not enough.
        (new JobWorkspace($this->baseDir))->createJobDirectories('site-42');

        self::assertDirectoryIsWritable($this->baseDir . '/site-42/input');
        self::assertDirectoryIsWritable($this->baseDir . '/site-42/output');
    }

    public function testIsIdempotentForTheSameStaticSiteId(): void
    {
        // A rebuild reuses the folders and leaves their contents alone.
        $workspace = new JobWorkspace($this->baseDir);
        $workspace->createJobDirectories('site-42');
        file_put_contents($this->baseDir . '/site-42/input/keep.md', '# keep');

        $workspace->createJobDirectories('site-42');

        self::assertDirectoryExists($this->baseDir . '/site-42/output');
        self::assertFileExists($this->baseDir . '/site-42/input/keep.md');
    }

    public function testAcceptsAUuidStaticSiteId(): void
    {
        // What trigger-build.sh posts. The allow-list stays wider than a
        // UUID so it accepts whatever publish accepts.
        $uuid = '11f5b798-6f34-4951-ad8b-bfd623ded5c2';

        (new JobWorkspace($this->baseDir))->createJobDirectories($uuid);

        self::assertDirectoryExists($this->baseDir . '/' . $uuid . '/input');
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
        (new JobWorkspace($this->baseDir))->createJobDirectories($unsafeId);
    }

    public function testTraversalIdCreatesNothingOutsideTheBaseDirectory(): void
    {
        $escapee = dirname($this->baseDir) . '/ssg-worker-escaped-' . bin2hex(random_bytes(6));

        try {
            (new JobWorkspace($this->baseDir))->createJobDirectories('../' . basename($escapee));
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
            (new JobWorkspace($blocked))->createJobDirectories('site-42');
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
            (new JobWorkspace($blocked))->createJobDirectories('site-42');
            self::fail('Expected a RuntimeException for an uncreatable directory.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Not a directory', $e->getMessage());
            self::assertStringNotContainsString('file_get_contents', $e->getMessage());
        } finally {
            unlink($blocked);
        }
    }
}
