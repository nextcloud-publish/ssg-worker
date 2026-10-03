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
     * @param string $siteTitle the human-readable heading, i.e. BuildJob::$title
     * @param string $outputDir The directory to output the rendered site to.
     * @param string $siteTitle The human-readable heading, i.e. BuildJob::$title
     * @return int number of rendered pages
     * @throws \InvalidArgumentException if the archive contains no Markdown pages
     * @throws \RuntimeException if rendering fails for an environmental reason
     */
    public function render(string $pagesDir, string $outputDir, string $siteTitle): int
    {
        // Checked here rather than left to SiteBuilder, which reports it as a
        // RuntimeException. No pages is a permanent content problem, not a
        // transient one worth retrying.
        if (!$this->hasMarkdownPages($pagesDir)) {
            throw new \InvalidArgumentException('The archive contains no Markdown pages.');
        }

        return (new SiteBuilder())->build($pagesDir, $outputDir, $siteTitle);
    }

    /**
     * Checks if the pages directory contains any markdown pages.
     * 
     * @param string $pagesDir The directory to check.
     * @return bool true if the pages directory contains any markdown pages, false otherwise.
     */
    private function hasMarkdownPages(string $pagesDir): bool
    {
        if (!is_dir($pagesDir)) {
            // Left to SiteBuilder, which names the missing directory. That is
            // an environmental failure, not a content one.
            return true;
        }

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
