<?php

declare(strict_types=1);

namespace App\Tests\Job;

use App\Job\JobLayout;
use App\Job\JobWorkspace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JobWorkspaceTest extends TestCase
{
    private const SITE = 'site-42';
    private const BUILD = '16ef078ad37fd894';
    private const SLUG = 'demo-site';

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

    private function workspace(?string $baseDir = null): JobWorkspace
    {
        $base = $baseDir ?? $this->baseDir;

        // The published and failed roots are irrelevant here -- reset() only
        // ever touches the build temp tree -- but JobLayout owns all three.
        return new JobWorkspace(new JobLayout($base, $base . '/published', $base . '/failed'));
    }

    private function jobDir(): string
    {
        return $this->baseDir . '/' . self::SITE . '/' . self::BUILD;
    }

    public function testCreatesInputAndOutputDirectories(): void
    {
        $jobDir = $this->workspace()->reset(self::SITE, self::BUILD, self::SLUG);

        self::assertDirectoryExists($this->jobDir() . '/input');
        self::assertDirectoryExists($this->jobDir() . '/output');

        // The folder holding the pair, not one of the two.
        self::assertSame($this->jobDir(), $jobDir);
    }

    /**
     * Keyed on build_id under static_site_id, not on static_site_id alone:
     * two concurrent builds of one site would otherwise share a directory and
     * overwrite each other's output.
     */
    public function testTwoBuildsOfOneSiteGetSeparateDirectories(): void
    {
        $workspace = $this->workspace();

        $first = $workspace->reset(self::SITE, self::BUILD, self::SLUG);
        $second = $workspace->reset(self::SITE, 'bbbbbbbbbbbbbbbb', self::SLUG);

        self::assertNotSame($first, $second);
        self::assertDirectoryExists($first . '/output');
        self::assertDirectoryExists($second . '/output');
    }

    public function testCreatesDirectoriesThatAreWritable(): void
    {
        // Both get written into, so present is not enough.
        $this->workspace()->reset(self::SITE, self::BUILD, self::SLUG);

        self::assertDirectoryIsWritable($this->jobDir() . '/input');
        self::assertDirectoryIsWritable($this->jobDir() . '/output');
    }

    /**
     * NOT idempotent, deliberately -- this inverts what this class used to
     * promise. A retry carries the same build_id, and SsgLab\SiteBuilder never
     * clears its output directory, so a reused output/ would republish pages
     * the first attempt wrote and the source no longer has.
     */
    public function testASecondAttemptStartsFromAnEmptyWorkdir(): void
    {
        $workspace = $this->workspace();
        $workspace->reset(self::SITE, self::BUILD, self::SLUG);
        file_put_contents($this->jobDir() . '/input/stale.md', '# from the first attempt');
        file_put_contents($this->jobDir() . '/output/deleted-page.html', 'no longer in the source');

        $workspace->reset(self::SITE, self::BUILD, self::SLUG);

        self::assertDirectoryExists($this->jobDir() . '/input');
        self::assertDirectoryExists($this->jobDir() . '/output');
        self::assertFileDoesNotExist($this->jobDir() . '/input/stale.md');
        self::assertFileDoesNotExist($this->jobDir() . '/output/deleted-page.html');
    }

    public function testAcceptsAUuidStaticSiteId(): void
    {
        // What trigger-build.sh posts. The allow-list stays wider than a
        // UUID so it accepts whatever publish accepts.
        $uuid = '11f5b798-6f34-4951-ad8b-bfd623ded5c2';

        $this->workspace()->reset($uuid, self::BUILD, self::SLUG);

        self::assertDirectoryExists($this->baseDir . '/' . $uuid . '/' . self::BUILD . '/input');
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
            // Not a traversal, but the reason the slug cannot double as the
            // site title any more: a human-readable heading has spaces.
            'contains a space' => ['My Team Handbook'],
        ];
    }

    #[DataProvider('provideUnsafeIds')]
    public function testRejectsAnUnsafeStaticSiteId(string $unsafeId): void
    {
        self::assertFalse(JobLayout::isValidId($unsafeId));

        // Not RuntimeException: a bad id is the caller's mistake, not the
        // environment's.
        $this->expectException(\InvalidArgumentException::class);
        $this->workspace()->reset($unsafeId, self::BUILD, self::SLUG);
    }

    #[DataProvider('provideUnsafeIds')]
    public function testRejectsAnUnsafeBuildId(string $unsafeId): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->workspace()->reset(self::SITE, $unsafeId, self::SLUG);
    }

    /**
     * The slug is validated here even though nothing in reset() uses it: it
     * names the published directory, and finding out it is unusable after a
     * five-minute render wastes the attempt.
     */
    #[DataProvider('provideUnsafeIds')]
    public function testRejectsAnUnsafeSlug(string $unsafeSlug): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->workspace()->reset(self::SITE, self::BUILD, $unsafeSlug);
    }

    public function testATraversalIdCreatesNothingOutsideTheBaseDirectory(): void
    {
        $escapee = dirname($this->baseDir) . '/ssg-worker-escaped-' . bin2hex(random_bytes(6));

        try {
            $this->workspace()->reset('../' . basename($escapee), self::BUILD, self::SLUG);
            self::fail('Expected an InvalidArgumentException for a traversal id.');
        } catch (\InvalidArgumentException) {
            self::assertDirectoryDoesNotExist($escapee);
        }
    }

    public function testAnUnsafeSlugIsRejectedBeforeAnythingIsCreated(): void
    {
        try {
            $this->workspace()->reset(self::SITE, self::BUILD, '../etc');
            self::fail('Expected an InvalidArgumentException for a traversal slug.');
        } catch (\InvalidArgumentException) {
            // Nothing is created, so a failed build leaves no debris behind.
            self::assertDirectoryDoesNotExist($this->jobDir());
        }
    }

    public function testThrowsWhenTheBaseDirectoryCannotBeCreated(): void
    {
        // A regular file where the base directory should be.
        $blocked = sys_get_temp_dir() . '/ssg-worker-blocked-' . bin2hex(random_bytes(6));
        file_put_contents($blocked, 'not a directory');

        try {
            $this->workspace($blocked)->reset(self::SITE, self::BUILD, self::SLUG);
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
            $this->workspace($blocked)->reset(self::SITE, self::BUILD, self::SLUG);
            self::fail('Expected a RuntimeException for an uncreatable directory.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Not a directory', $e->getMessage());
            self::assertStringNotContainsString('file_get_contents', $e->getMessage());
        } finally {
            unlink($blocked);
        }
    }
}
