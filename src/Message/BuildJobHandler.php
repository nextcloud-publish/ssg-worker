<?php

declare(strict_types=1);

namespace App\Message;

use App\Job\ArchiveExtractor;
use App\Job\ContentDownloader;
use App\Job\JobWorkspace;
use App\Job\SiteRenderer;

/**
 * Handles build jobs messages reveived via q.builds into html:
 *  - creates the job's folders
 *  - downloads and extracts the content archive
 *  - renders the pages into html
 *
 * Registered as a message handler in services.yaml instead of via
 * #[AsMessageHandler] attribute.
 */
final class BuildJobHandler
{
    /** Folder where the archive is unpacked, relative to the job's input/ folder. */
    public const UNARCHIVED_DIR = 'content_unarchived';

    public function __construct(
        private readonly ContentDownloader $contentDownloader,
        private readonly ArchiveExtractor $archiveExtractor,
        private readonly SiteRenderer $siteRenderer,
        private readonly JobWorkspace $jobWorkspace,
    ) {
    }

    /**
     * @throws \InvalidArgumentException if the static_site_id is unsafe
     * @throws \RuntimeException         if any step fails
     */
    public function __invoke(BuildJob $message): void
    {
        // Creates the job's input/output folders.
        $jobDir = $this->jobWorkspace->createJobDirectories($message->static_site_id);

        error_log(sprintf('[INFO] prepared job workdir %s', $jobDir));

        $url = $message->content_download_url;
        $inputDir = $jobDir . '/' . JobWorkspace::INPUT_DIR;
        $file = $this->contentDownloader->download($url, $inputDir);

        error_log(sprintf(
            '[INFO] downloaded %s to %s (%d bytes)',
            $url,
            $file,
            (int) @filesize($file),
        ));

        // Extracted content assets
        $unarchivedDir = $inputDir . '/' . self::UNARCHIVED_DIR;
        $this->archiveExtractor->extract($file, $unarchivedDir);

        error_log(sprintf('[INFO] extracted %s into %s', $file, $unarchivedDir));

        // slug is used as the site header on every page.
        $outputDir = $jobDir . '/' . JobWorkspace::OUTPUT_DIR;
        $pages = $this->siteRenderer->render($unarchivedDir, $outputDir, $message->slug);

        error_log(sprintf('[INFO] rendered %d page(s) into %s', $pages, $outputDir));
    }
}
