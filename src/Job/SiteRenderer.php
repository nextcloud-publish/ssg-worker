<?php

declare(strict_types=1);

namespace App\Job;

use SsgLab\SiteBuilder;

/**
 * Renders an extracted Collectives export (already unpacked into $pagesDir)
 * into a static site under $outputDir.
 */
final class SiteRenderer
{
    /**
     * Validates the export in $pagesDir and renders it into $outputDir.
     *
     * @param string $pagesDir The directory the export is unpacked in.
     * @param string $outputDir The directory to output the rendered site to.
     * @param string $siteTitle The human-readable heading, i.e. BuildJob::$title
     * @return int number of rendered pages
     * @throws \InvalidArgumentException if the export fails validation
     * @throws \RuntimeException if rendering fails for an environmental reason
     */
    public function render(string $pagesDir, string $outputDir, string $siteTitle): int
    {
        $this->validateCollectiveExport($pagesDir);

        return (new SiteBuilder())->build($pagesDir, $outputDir, $siteTitle);
    }

    /**
     * Checks that the export in $pagesDir can be rendered.
     * Throws \InvalidArgumentException rather than leaving it to SiteBuilder, which throws \RuntimeException:
     * a broken export is a permanent content problem, not one worth retrying.
     *
     * @param string $pagesDir The directory the export is unpacked in.
     * @return void
     * @throws \InvalidArgumentException if the export contains no Markdown pages
     */
    private function validateCollectiveExport(string $pagesDir): void
    {
        if (!$this->hasMarkdownPages($pagesDir)) {
            throw new \InvalidArgumentException('Invalid Collectives export: no .md file found in the archive or any of its subdirectories.');
        }
    }

    /**
     * Checks if $pagesDir contains any Markdown pages, in any subdirectory.
     *
     * @param string $pagesDir The directory to check.
     * @return bool true when $pagesDir contains a Markdown page, false when it does not
     */
    private function hasMarkdownPages(string $pagesDir): bool
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pagesDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);

            if ($entry->isFile() && strtolower($entry->getExtension()) === 'md') {
                return true;
            }
        }

        return false;
    }
}
