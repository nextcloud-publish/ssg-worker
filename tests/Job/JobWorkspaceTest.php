<?php

declare(strict_types=1);

namespace App\Tests\Job;

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

        return new JobWorkspace($base, $base . '/published');
    }

    private function jobDir(): string
    {
        return $this->baseDir . '/' . self::SITE . '/' . self::BUILD;
    }

    public function testCreatesInputAndOutputDirectories(): void
    {
        $jobDir = $this->workspace()->reset(self::SITE, self::BUILD);

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

        $first = $workspace->reset(self::SITE, self::BUILD);
        $second = $workspace->reset(self::SITE, 'bbbbbbbbbbbbbbbb');

        self::assertNotSame($first, $second);
        self::assertDirectoryExists($first . '/output');
        self::assertDirectoryExists($second . '/output');
    }

    public function testCreatesDirectoriesThatAreWritable(): void
    {
        // Both get written into, so present is not enough.
        $this->workspace()->reset(self::SITE, self::BUILD);

        self::assertDirectoryIsWritable($this->jobDir() . '/input');
        self::assertDirectoryIsWritable($this->jobDir() . '/output');
    }

    /**
     * NOT idempotent, deliberately. A retry carries the same build_id, and
     * SsgLab\SiteBuilder never clears its output directory, so a reused output/
     * would republish pages the first attempt wrote and the source has since
     * dropped.
     */
    public function testASecondAttemptStartsFromAnEmptyWorkdir(): void
    {
        $workspace = $this->workspace();
        $workspace->reset(self::SITE, self::BUILD);
        file_put_contents($this->jobDir() . '/input/stale.md', '# from the first attempt');
        file_put_contents($this->jobDir() . '/output/deleted-page.html', 'no longer in the source');

        $workspace->reset(self::SITE, self::BUILD);

        self::assertDirectoryExists($this->jobDir() . '/input');
        self::assertDirectoryExists($this->jobDir() . '/output');
        self::assertFileDoesNotExist($this->jobDir() . '/input/stale.md');
        self::assertFileDoesNotExist($this->jobDir() . '/output/deleted-page.html');
    }

    /**
     * Cleanup happens whichever way the job ended, so it lives here rather than
     * in publish(). input/ holds the archive and its fully extracted copy,
     * which is the bulkiest thing on the volume and which nothing reads again.
     */
    public function testClearRemovesTheWholeJobTree(): void
    {
        $workspace = $this->workspace();
        $workspace->reset(self::SITE, self::BUILD);
        file_put_contents($this->jobDir() . '/input/content.tar.gz', 'archive');

        $workspace->clear(self::SITE, self::BUILD);

        self::assertDirectoryDoesNotExist($this->jobDir());
        // The site's parent goes too once it holds no other build.
        self::assertDirectoryDoesNotExist($this->baseDir . '/' . self::SITE);
    }

    /** Another build of the same site may be in flight. */
    public function testClearKeepsTheSiteDirectoryWhileAnotherBuildIsThere(): void
    {
        $workspace = $this->workspace();
        $workspace->reset(self::SITE, self::BUILD);
        $other = $workspace->reset(self::SITE, 'bbbbbbbbbbbbbbbb');

        $workspace->clear(self::SITE, self::BUILD);

        self::assertDirectoryDoesNotExist($this->jobDir());
        self::assertDirectoryExists($other);
    }

    /** Runs on the failure path, where throwing would cost the client its notice. */
    public function testClearDoesNotThrowWhenThereIsNothingToRemove(): void
    {
        $this->workspace()->clear(self::SITE, self::BUILD);

        $this->expectNotToPerformAssertions();
    }

    /**
     * An unsafe id arrives here routinely -- it's one of the things a build is
     * failed for. Nothing was created for it, and the id cannot be turned into
     * a path safely, so the only correct move is to do nothing at all.
     */
    #[DataProvider('provideUnsafeIds')]
    public function testClearRefusesToActOnAnUnsafeId(string $unsafeId): void
    {
        $escapee = dirname($this->baseDir) . '/ssg-worker-clear-escaped-' . bin2hex(random_bytes(6));
        mkdir($escapee, 0o750, true);

        try {
            $this->workspace()->clear($unsafeId, self::BUILD);
            $this->workspace()->clear(self::SITE, $unsafeId);

            self::assertDirectoryExists($escapee);
        } finally {
            exec('rm -rf ' . escapeshellarg($escapee));
        }
    }

    public function testAcceptsAUuidStaticSiteId(): void
    {
        // What trigger-build.sh posts. The allow-list stays wider than a
        // UUID so it accepts whatever publish accepts.
        $uuid = '11f5b798-6f34-4951-ad8b-bfd623ded5c2';

        $this->workspace()->reset($uuid, self::BUILD);

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
            // site title: a human-readable heading has spaces.
            'contains a space' => ['My Team Handbook'],
        ];
    }

    /**
     * One check, and these are it. reset() and publish() do not re-validate --
     * they take the ids as already vetted -- so this is the whole of what
     * stands between a queue payload and the filesystem.
     */
    #[DataProvider('provideUnsafeIds')]
    public function testAssertSafeJobRejectsAnUnsafeStaticSiteId(string $unsafeId): void
    {
        self::assertFalse(JobWorkspace::isValidId($unsafeId));

        // Not RuntimeException: a bad id is the caller's mistake, not the
        // environment's.
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
     * The slug is checked with the ids even though only publish() uses it:
     * finding out after a five-minute render that it cannot name a directory
     * wastes the attempt and delays the client's answer for nothing.
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
        // A regular file where the base directory should be.
        $blocked = sys_get_temp_dir() . '/ssg-worker-blocked-' . bin2hex(random_bytes(6));
        file_put_contents($blocked, 'not a directory');

        try {
            $this->workspace($blocked)->reset(self::SITE, self::BUILD);
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
            $this->workspace($blocked)->reset(self::SITE, self::BUILD);
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
    // Fixed roots rather than the temp dir: these assert the SHAPE of the paths,
    // which is a cross-repo contract (publish writes nothing here, but the
    // published tree is what an operator mounts and nginx serves), so the
    // literal strings are the point.

    private function paths(): JobWorkspace
    {
        return new JobWorkspace('/opt/ssg/build_temp', '/opt/ssg/published');
    }

    public function testTheJobTreeIsKeyedOnSiteThenBuild(): void
    {
        $paths = $this->paths();

        self::assertSame('/opt/ssg/build_temp/site-42', $paths->siteTempDir(self::SITE));
        self::assertSame('/opt/ssg/build_temp/site-42/16ef078ad37fd894', $paths->jobDir(self::SITE, self::BUILD));
        self::assertSame(
            '/opt/ssg/build_temp/site-42/16ef078ad37fd894/input',
            $paths->buildInputDir(self::SITE, self::BUILD),
        );
        self::assertSame(
            '/opt/ssg/build_temp/site-42/16ef078ad37fd894/output',
            $paths->buildOutputDir(self::SITE, self::BUILD),
        );
    }

    /** The roots are set by hand in compose, so a trailing slash is likely. */
    public function testNormalisesATrailingSlashOnTheRoots(): void
    {
        $paths = new JobWorkspace('/opt/ssg/build_temp/', '/opt/ssg/published/');

        self::assertSame('/opt/ssg/build_temp/site-42', $paths->siteTempDir(self::SITE));
        self::assertSame('/opt/ssg/published/demo-site', $paths->publishedSiteDir(self::SLUG));
    }

    /** The published tree is keyed on the slug; the site id never appears in it. */
    public function testThePublishedSiteIsKeyedOnTheSlug(): void
    {
        self::assertSame('/opt/ssg/published/demo-site', $this->paths()->publishedSiteDir(self::SLUG));
    }

    /**
     * Staging lives INSIDE the published tree so the swap is a same-mount
     * rename however the two roots are mounted, and starts with a dot so a
     * build still being copied stays unreachable behind nginx's
     * `location ~ /\.` rule.
     */
    public function testStagingLivesInsideThePublishedTreeAndIsHidden(): void
    {
        $paths = $this->paths();

        self::assertSame('/opt/ssg/published/.staging', $paths->stagingRoot());
        self::assertStringStartsWith($paths->stagingRoot() . '/', $paths->stagingDir(self::BUILD));
        self::assertStringContainsString('/.', $paths->stagingDir(self::BUILD));
    }

    /** Keyed on build_id, so two builds staging at once cannot collide. */
    public function testStagingIsKeyedOnBuild(): void
    {
        $paths = $this->paths();

        self::assertSame('/opt/ssg/published/.staging/16ef078ad37fd894', $paths->stagingDir(self::BUILD));
        self::assertNotSame($paths->stagingDir(self::BUILD), $paths->stagingDir('bbbbbbbbbbbbbbbb'));
    }

    // --- the allow-list ---------------------------------------------------
    //
    // The unsafe cases are exercised through reset() and clear() above, which
    // is where they matter. These cover the other direction: that the values
    // publish actually sends are accepted.

    /**
     * @return array<string, array{string}>
     */
    public static function provideSafeIds(): array
    {
        return [
            'simple' => ['demo'],
            'hyphenated' => ['demo-site'],
            'underscored' => ['some_collective'],
            'uuid' => ['11f5b798-6f34-4951-ad8b-bfd623ded5c2'],
            'hex build id' => ['16ef078ad37fd894'],
            'at the length limit' => [str_repeat('a', 128)],
        ];
    }

    #[DataProvider('provideSafeIds')]
    public function testAcceptsASafeId(string $safe): void
    {
        self::assertTrue(JobWorkspace::isValidId($safe));
    }
}
