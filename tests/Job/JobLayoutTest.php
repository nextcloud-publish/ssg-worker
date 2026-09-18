<?php

declare(strict_types=1);

namespace App\Tests\Job;

use App\Job\JobLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * JobLayout is the only thing standing between a queue payload and a
 * filesystem path, so the allow-list here is a security boundary rather than a
 * tidiness check. Pure path arithmetic -- nothing in this file touches disk.
 */
final class JobLayoutTest extends TestCase
{
    private const SITE = 'site-42';
    private const BUILD = '16ef078ad37fd894';
    private const SLUG = 'demo-site';

    private function layout(string $buildTemp = '/opt/ssg/build_temp'): JobLayout
    {
        return new JobLayout($buildTemp, '/opt/ssg/published', '/opt/ssg/build_failed');
    }

    public function testTheJobTreeIsKeyedOnSiteThenBuild(): void
    {
        $layout = $this->layout();

        self::assertSame('/opt/ssg/build_temp/site-42', $layout->siteTempDir(self::SITE));
        self::assertSame('/opt/ssg/build_temp/site-42/16ef078ad37fd894', $layout->jobDir(self::SITE, self::BUILD));
        self::assertSame(
            '/opt/ssg/build_temp/site-42/16ef078ad37fd894/input',
            $layout->buildInputDir(self::SITE, self::BUILD),
        );
        self::assertSame(
            '/opt/ssg/build_temp/site-42/16ef078ad37fd894/output',
            $layout->buildOutputDir(self::SITE, self::BUILD),
        );
    }

    /** JOB_STORAGE_DIR and friends are set by hand, so a trailing slash is likely. */
    public function testNormalisesATrailingSlashOnTheRoots(): void
    {
        $layout = new JobLayout('/opt/ssg/build_temp/', '/opt/ssg/published/', '/opt/ssg/build_failed/');

        self::assertSame('/opt/ssg/build_temp/site-42', $layout->siteTempDir(self::SITE));
        self::assertSame('/opt/ssg/published/demo-site', $layout->publishedSiteDir(self::SLUG));
        self::assertSame('/opt/ssg/build_failed/16ef078ad37fd894', $layout->failedJobDir(self::BUILD));
    }

    /** The published tree is keyed on the slug; the site id never appears in it. */
    public function testThePublishedSiteIsKeyedOnTheSlug(): void
    {
        self::assertSame('/opt/ssg/published/demo-site', $this->layout()->publishedSiteDir(self::SLUG));
    }

    /**
     * Staging lives INSIDE the published tree so the swap is two renames on one
     * mount, and starts with a dot so a half-promoted build stays unreachable
     * behind nginx's `location ~ /\.` rule.
     */
    public function testStagingLivesInsideThePublishedTreeAndIsHidden(): void
    {
        $layout = $this->layout();

        self::assertSame('/opt/ssg/published/.staging', $layout->stagingRoot());
        self::assertStringStartsWith($layout->stagingRoot() . '/', $layout->stagingDir(self::BUILD));
        self::assertStringContainsString('/.', $layout->stagingDir(self::BUILD));
    }

    /**
     * The three staging names must be distinct: the promoter relies on
     * `<build>` meaning "complete and publishable" while `<build>.partial`
     * means "a copy that may have been interrupted".
     */
    public function testTheThreeStagingNamesAreDistinct(): void
    {
        $layout = $this->layout();

        $names = [
            $layout->stagingDir(self::BUILD),
            $layout->partialStagingDir(self::BUILD),
            $layout->retiringDir(self::BUILD),
        ];

        self::assertSame($names, array_unique($names));
    }

    /** Keyed on build_id alone, so two failures of one site cannot collide. */
    public function testTheQuarantineIsKeyedOnBuildAlone(): void
    {
        self::assertSame('/opt/ssg/build_failed/16ef078ad37fd894', $this->layout()->failedJobDir(self::BUILD));
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
            'contains a space' => ['My Team Handbook'],
            'trailing dot' => ['site.'],
        ];
    }

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

    #[DataProvider('provideUnsafeIds')]
    public function testRejectsAnUnsafeId(string $unsafe): void
    {
        self::assertFalse(JobLayout::isValidId($unsafe));
    }

    #[DataProvider('provideSafeIds')]
    public function testAcceptsASafeId(string $safe): void
    {
        self::assertTrue(JobLayout::isValidId($safe));
    }

    #[DataProvider('provideUnsafeIds')]
    public function testAssertSafeIdsRejectsAnUnsafeStaticSiteId(string $unsafe): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('static_site_id');

        JobLayout::assertSafeIds($unsafe, self::BUILD);
    }

    #[DataProvider('provideUnsafeIds')]
    public function testAssertSafeIdsRejectsAnUnsafeBuildId(string $unsafe): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('build_id');

        JobLayout::assertSafeIds(self::SITE, $unsafe);
    }

    #[DataProvider('provideUnsafeIds')]
    public function testAssertSafeSlugRejectsAnUnsafeSlug(string $unsafe): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('slug');

        JobLayout::assertSafeSlug($unsafe);
    }

    /**
     * The slug is checked separately from the two ids because not every caller
     * has one: BuildQuarantine never touches the published tree.
     */
    public function testTheIdCheckDoesNotRequireASlug(): void
    {
        JobLayout::assertSafeIds(self::SITE, self::BUILD);

        $this->expectNotToPerformAssertions();
    }
}
