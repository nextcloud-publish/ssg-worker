<?php

declare(strict_types=1);

namespace App\Tests\Rendering;

use App\Rendering\SiteRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real ssg-library rather than a double: it only reads and
 * writes directories, so running it is cheap, and the point of these tests is
 * that the wiring to it actually produces a site.
 */
final class SiteRendererTest extends TestCase
{
    private string $tmp;
    private string $pagesDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/worker-render-test-' . bin2hex(random_bytes(6));
        $this->pagesDir = $this->tmp . '/content_unarchived';
        $this->outputDir = $this->tmp . '/output';

        mkdir($this->pagesDir . '/Cats', 0o755, true);
        mkdir($this->outputDir, 0o755, true);

        file_put_contents($this->pagesDir . '/Readme.md', "# Welcome\n\nSome **bold** text.\n");
        file_put_contents($this->pagesDir . '/Cats/Readme.md', "# Cats\n\nThey nap.\n");
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            exec('rm -rf ' . escapeshellarg($this->tmp));
        }
    }

    public function testRendersPagesIntoTheOutputDirectory(): void
    {
        $pages = (new SiteRenderer())->render($this->pagesDir, $this->outputDir, 'my_collective');

        self::assertGreaterThan(0, $pages);
        self::assertFileExists($this->outputDir . '/index.html');
        self::assertFileExists($this->outputDir . '/Cats/index.html');

        $html = (string) file_get_contents($this->outputDir . '/index.html');
        self::assertStringContainsString('Welcome', $html);
        self::assertStringContainsString('<strong>bold</strong>', $html);
    }

    public function testPutsTheGivenSiteTitleOnThePage(): void
    {
        // The title comes from the job message's slug; without this the header
        // would silently read "Collectives lab" on every published site.
        (new SiteRenderer())->render($this->pagesDir, $this->outputDir, 'my_collective');

        self::assertStringContainsString(
            'my_collective',
            (string) file_get_contents($this->outputDir . '/index.html'),
        );
    }

    public function testCopiesTheStylesheetAndThemeScript(): void
    {
        // The library copies these out of its own src/. A vendored install
        // missing them would render an unstyled site rather than fail loudly.
        (new SiteRenderer())->render($this->pagesDir, $this->outputDir, 'my_collective');

        self::assertFileExists($this->outputDir . '/style.css');
        self::assertFileExists($this->outputDir . '/theme.js');
    }

    public function testThrowsWhenThePagesDirectoryIsMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Pages directory not found');

        (new SiteRenderer())->render($this->tmp . '/nope', $this->outputDir, 'my_collective');
    }

    public function testThrowsWhenThereIsNothingToRender(): void
    {
        $empty = $this->tmp . '/empty';
        mkdir($empty, 0o755, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No .md files found');

        (new SiteRenderer())->render($empty, $this->outputDir, 'my_collective');
    }
}
