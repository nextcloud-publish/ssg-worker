<?php

declare(strict_types=1);

namespace App\Tests\Job;

use App\Job\JobWorkspace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers App\Job\JobWorkspace, apart from publish(), which JobWorkspacePublishTest covers.
 *
 * The lifecycle tests run against a temp directory. The path tests further down use fixed roots
 * instead, because there the literal strings are what is being asserted.
 */
final class JobWorkspaceTest extends TestCase
{
    private const SITE = '11f5b798-6f34-4951-ad8b-bfd623ded5c2';
    private const BUILD = '0199a1b2-3c4d-7e5f-8a9b-0c1d2e3f4a5b';
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

    /**
     * A workspace rooted at $baseDir, publishing into a "published" folder inside it.
     *
     * @param ?string $baseDir The build root to use, or null for $this->baseDir.
     * @return JobWorkspace the workspace under test
     */
    private function workspace(?string $baseDir = null): JobWorkspace
    {
        $base = $baseDir ?? $this->baseDir;

        return new JobWorkspace($base, $base . '/published', 'http://sites.test');
    }

    /**
     * The job directory the constants above resolve to.
     *
     * @return string the expected job directory path
     */
    private function buildJobDir(): string
    {
        return $this->baseDir . '/' . self::BUILD;
    }

    public function testCreatesInputAndOutputDirectories(): void
    {
        $jobDir = $this->workspace()->reset(self::BUILD);

        self::assertDirectoryExists($this->buildJobDir() . '/input');
        self::assertDirectoryExists($this->buildJobDir() . '/output');

        /** The folder holding the pair, not one of the two. */
        self::assertSame($this->buildJobDir(), $jobDir);
    }

    /**
     * Keyed on build_id alone, which is a UUID v7 and unique across sites.
     * Two concurrent builds of one site would otherwise share a directory and overwrite each other's output.
     */
    public function testTwoBuildsOfOneSiteGetSeparateDirectories(): void
    {
        $workspace = $this->workspace();

        $first = $workspace->reset(self::BUILD);
        $second = $workspace->reset('0199a1b2-3c4d-7e5f-8a9b-ffffffffffff');

        self::assertNotSame($first, $second);
        self::assertDirectoryExists($first . '/output');
        self::assertDirectoryExists($second . '/output');
    }

    /** Both directories get written into, so existing is not enough. */
    public function testCreatesDirectoriesThatAreWritable(): void
    {
        $this->workspace()->reset(self::BUILD);

        self::assertDirectoryIsWritable($this->buildJobDir() . '/input');
        self::assertDirectoryIsWritable($this->buildJobDir() . '/output');
    }

    /**
     * reset() is deliberately not idempotent.
     * A retry carries the same build_id and SsgLab\SiteBuilder never clears its output directory,
     * so a reused output/ would republish pages the source has since dropped.
     */
    public function testASecondAttemptStartsFromAnEmptyWorkdir(): void
    {
        $workspace = $this->workspace();
        $workspace->reset(self::BUILD);
        file_put_contents($this->buildJobDir() . '/input/stale.md', '# from the first attempt');
        file_put_contents($this->buildJobDir() . '/output/deleted-page.html', 'no longer in the source');

        $workspace->reset(self::BUILD);

        self::assertDirectoryExists($this->buildJobDir() . '/input');
        self::assertDirectoryExists($this->buildJobDir() . '/output');
        self::assertFileDoesNotExist($this->buildJobDir() . '/input/stale.md');
        self::assertFileDoesNotExist($this->buildJobDir() . '/output/deleted-page.html');
    }

    /**
     * Cleanup runs whichever way the job ended, so it lives in clear() rather than publish().
     * input/ holds the archive and its fully extracted copy, the bulkiest thing on the volume.
     */
    public function testClearRemovesTheWholeJobTree(): void
    {
        $workspace = $this->workspace();
        $workspace->reset(self::BUILD);
        file_put_contents($this->buildJobDir() . '/input/content.tar.gz', 'archive');

        $workspace->clear(self::BUILD);

        self::assertDirectoryDoesNotExist($this->buildJobDir());
    }

    /** Another build may be in flight. */
    public function testClearKeepsOtherBuilds(): void
    {
        $workspace = $this->workspace();
        $workspace->reset(self::BUILD);
        $other = $workspace->reset('0199a1b2-3c4d-7e5f-8a9b-ffffffffffff');

        $workspace->clear(self::BUILD);

        self::assertDirectoryDoesNotExist($this->buildJobDir());
        self::assertDirectoryExists($other);
    }

    /** Runs on the failure path, where throwing would cost the client its notice. */
    public function testClearDoesNotThrowWhenThereIsNothingToRemove(): void
    {
        $this->workspace()->clear(self::BUILD);

        $this->expectNotToPerformAssertions();
    }

    /**
     * An unsafe id reaches clear() routinely, because it is one of the things a build is failed for.
     * Nothing was created for such a job and the id cannot be turned into a path safely, so clear() does nothing.
     */
    #[DataProvider('provideUnsafeIds')]
    public function testClearRefusesToActOnAnUnsafeId(string $unsafeId): void
    {
        $escapee = dirname($this->baseDir) . '/ssg-worker-clear-escaped-' . bin2hex(random_bytes(6));
        mkdir($escapee, 0o750, true);

        try {
            $this->workspace()->clear($unsafeId);

            self::assertDirectoryExists($escapee);
        } finally {
            exec('rm -rf ' . escapeshellarg($escapee));
        }
    }

    /**
     * Ids the allow-list must reject.
     *
     * @return array<string, array{string}> the id per case name
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

            /** Not a traversal, but the reason the slug cannot double as the site title: a heading has spaces. */
            'contains a space' => ['My Team Handbook'],
        ];
    }

    /**
     * One check, and this is it.
     * reset() and publish() take the ids as already vetted, so nothing else stands between a queue payload and the filesystem.
     */
    #[DataProvider('provideUnsafeIds')]
    public function testAssertSafeJobRejectsAnUnsafeStaticSiteId(string $unsafeId): void
    {
        self::assertFalse(JobWorkspace::isValidId($unsafeId));

        /** Not RuntimeException: a bad id is the caller's mistake, not the environment's. */
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('static_site_id');

        JobWorkspace::assertSafeJob($unsafeId, self::BUILD, self::SLUG);
    }

    #[DataProvider('provideUnsafeIds')]
    public function testAssertSafeJobRejectsAnUnsafeBuildId(string $unsafeId): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('build_id');

        JobWorkspace::assertSafeJob(self::SITE, $unsafeId, self::SLUG);
    }

    /**
     * The slug is checked with the ids even though only publish() uses it.
     * Finding out after a five-minute render that it cannot name a directory wastes the attempt.
     */
    #[DataProvider('provideUnsafeIds')]
    public function testAssertSafeJobRejectsAnUnsafeSlug(string $unsafeSlug): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('slug');

        JobWorkspace::assertSafeJob(self::SITE, self::BUILD, $unsafeSlug);
    }

    public function testAssertSafeJobAcceptsAWholeValidJob(): void
    {
        JobWorkspace::assertSafeJob(self::SITE, self::BUILD, self::SLUG);

        $this->expectNotToPerformAssertions();
    }

    public function testThrowsWhenTheBaseDirectoryCannotBeCreated(): void
    {
        /** A regular file where the base directory should be. */
        $blocked = sys_get_temp_dir() . '/ssg-worker-blocked-' . bin2hex(random_bytes(6));
        file_put_contents($blocked, 'not a directory');

        try {
            $this->workspace($blocked)->reset(self::BUILD);
            self::fail('Expected a RuntimeException for an uncreatable directory.');
        } catch (\RuntimeException $e) {
            /** Without the reason, a full disk, an unmounted volume and a typo in JOB_STORAGE_DIR all read the same. */
            self::assertStringContainsString('Not a directory', $e->getMessage());
            self::assertStringNotContainsString('unknown error', $e->getMessage());

            /** And the path, so it is clear which directory failed. */
            self::assertStringContainsString($blocked, $e->getMessage());
        } finally {
            unlink($blocked);
        }
    }

    /** error_get_last() is global and can return an unrelated warning, so a failing mkdir() has to raise its own first. */
    public function testFailureReasonIsTheRealCauseNotAnEarlierWarning(): void
    {
        @file_get_contents('/definitely/not/here');

        $blocked = sys_get_temp_dir() . '/ssg-worker-blocked-' . bin2hex(random_bytes(6));
        file_put_contents($blocked, 'not a directory');

        try {
            $this->workspace($blocked)->reset(self::BUILD);
            self::fail('Expected a RuntimeException for an uncreatable directory.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Not a directory', $e->getMessage());
            self::assertStringNotContainsString('file_get_contents', $e->getMessage());
        } finally {
            unlink($blocked);
        }
    }

    // --- path arithmetic --------------------------------------------------
    //
    // Fixed roots rather than the temp dir: these assert the shape of the paths, which is a
    // cross-repo contract, so the literal strings are what matters. The published tree is
    // what an operator mounts and nginx serves.

    /**
     * A workspace on fixed roots, for asserting path strings.
     *
     * @return JobWorkspace a workspace rooted at /opt/ssg
     */
    private function paths(): JobWorkspace
    {
        return new JobWorkspace('/opt/ssg/build_temp', '/opt/ssg/published', 'https://sites.example.org');
    }

    public function testTheJobTreeIsKeyedOnBuild(): void
    {
        $paths = $this->paths();
        $jobDir = '/opt/ssg/build_temp/0199a1b2-3c4d-7e5f-8a9b-0c1d2e3f4a5b';

        self::assertSame($jobDir, $paths->buildJobDir(self::BUILD));
        self::assertSame($jobDir . '/input', $paths->buildJobInputDir(self::BUILD));

        /** Under input/, beside the downloaded archive rather than replacing it. */
        self::assertSame($jobDir . '/input/content_unarchived', $paths->buildJobUnarchivedDir(self::BUILD));
        self::assertSame($jobDir . '/output', $paths->buildJobOutputDir(self::BUILD));
    }

    /** The roots and the base URL are set by hand in compose, so a trailing slash is likely. */
    public function testNormalisesATrailingSlashOnTheRoots(): void
    {
        $paths = new JobWorkspace('/opt/ssg/build_temp/', '/opt/ssg/published/', 'https://sites.example.org/');

        self::assertSame('/opt/ssg/build_temp/' . self::BUILD, $paths->buildJobDir(self::BUILD));
        self::assertSame('/opt/ssg/published/11f5b798-6f34-4951-ad8b-bfd623ded5c2', $paths->publishSiteDir(self::SITE));
        self::assertSame('https://sites.example.org/11f5b798-6f34-4951-ad8b-bfd623ded5c2/demo-site/', $paths->publishUrl(self::SITE, self::SLUG));
    }

    /** The published tree is keyed on the site id; the slug is a directory inside it. */
    public function testThePublishedSiteIsKeyedOnTheStaticSiteId(): void
    {
        self::assertSame('/opt/ssg/published/11f5b798-6f34-4951-ad8b-bfd623ded5c2', $this->paths()->publishSiteDir(self::SITE));
    }

    /** The URL mirrors the published tree, with a trailing slash because it names a directory. */
    public function testThePublishUrlIsTheBaseThenSiteThenSlug(): void
    {
        self::assertSame('https://sites.example.org/11f5b798-6f34-4951-ad8b-bfd623ded5c2/demo-site/', $this->paths()->publishUrl(self::SITE, self::SLUG));
    }

    /**
     * Staging sits inside the published tree, so the swap is a same-mount rename however the two roots are mounted.
     * It starts with a dot, so a build still being copied stays unreachable behind nginx's `location ~ /\.` rule.
     */
    public function testStagingLivesInsideThePublishedTreeAndIsHidden(): void
    {
        $paths = $this->paths();

        self::assertStringStartsWith('/opt/ssg/published/.staging/', $paths->publishStagingDir(self::BUILD));
        self::assertStringContainsString('/.', $paths->publishStagingDir(self::BUILD));
    }

    /** Keyed on build_id, so two builds staging at once cannot collide. */
    public function testStagingIsKeyedOnBuild(): void
    {
        $paths = $this->paths();

        self::assertSame('/opt/ssg/published/.staging/' . self::BUILD, $paths->publishStagingDir(self::BUILD));
        self::assertNotSame($paths->publishStagingDir(self::BUILD), $paths->publishStagingDir('0199a1b2-3c4d-7e5f-8a9b-ffffffffffff'));
    }

    // --- the allow-list ---------------------------------------------------
    //
    // The unsafe cases run through reset() and clear() above, where they matter. These cover
    // the other direction: that the values publish actually sends are accepted.

    /**
     * Ids the allow-list must accept.
     *
     * @return array<string, array{string}> the id per case name
     */
    public static function provideSafeIds(): array
    {
        return [
            'simple' => ['demo'],
            'hyphenated' => ['demo-site'],
            'underscored' => ['some_collective'],
            'uuid' => ['11f5b798-6f34-4951-ad8b-bfd623ded5c2'],
            'hex build id' => ['16ef078ad37fd894'],
            'uuid v7 build id' => ['0199a1b2-3c4d-7e5f-8a9b-0c1d2e3f4a5b'],
            'at the length limit' => [str_repeat('a', 128)],
        ];
    }

    #[DataProvider('provideSafeIds')]
    public function testAcceptsASafeId(string $safe): void
    {
        self::assertTrue(JobWorkspace::isValidId($safe));
    }
}
