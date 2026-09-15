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
     * @return int number of rendered pages
     *
     * @throws \RuntimeException if $pagesDir is missing or holds no .md files
     *                           (propagated from SiteBuilder::build())
     */
    public function render(string $pagesDir, string $outputDir, string $siteTitle): int
    {
        return (new SiteBuilder())->build($pagesDir, $outputDir, $siteTitle);
    }
}
