<?php

declare(strict_types=1);

namespace App\Rendering;

use SsgLab\SiteBuilder;

/**
 * Renders a fetched and extracted Collectives export into output folder
 */
final class SiteRenderer
{
    /**
     * @return int number of rendered pages
     *
     * @throws \RuntimeException if $pagesDir is missing or holds no .md files
     */
    public function render(string $pagesDir, string $outputDir, string $siteTitle): int
    {
        return (new SiteBuilder())->build($pagesDir, $outputDir, $siteTitle);
    }
}
