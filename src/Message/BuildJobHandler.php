<?php

declare(strict_types=1);

namespace App\Message;

use App\Job\ArchiveExtractor;
use App\Job\ContentDownloader;
use App\Job\JobWorkspace;
use App\Job\SiteRenderer;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handles build jobs messages reveived via q.builds into html:
 *  - creates the job's folders
 *  - downloads and extracts the content archive
 *  - renders the pages into html
 *  - reports the outcome on q.build-results or q.build-failures
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
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Never throws for a failed BUILD -- the failure is reported as a
     * BuildFailed message and the BuildJob is acked. That is what
     * `retry_strategy.max_retries: 0` on the builds transport already asks for
     * ("log and ack, never redeliver"), except that now the outcome leaves a
     * trace instead of vanishing.
     *
     * It does still throw if the BROKER is unreachable while reporting, which
     * is a different problem with a different fix: that must be retried, not
     * swallowed, or a finished build is acked with nobody told about it.
     *
     * @throws \Throwable if dispatching the outcome fails
     */
    public function __invoke(BuildJob $message): void
    {
        try {
            $pages = $this->build($message);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[ERROR] build %s failed: %s',
                $message->build_id,
                $e->getMessage(),
            ));

            // Deliberately NOT inside a catch of its own: see the docblock.
            $this->bus->dispatch(new BuildFailed(
                build_id: $message->build_id,
                static_site_id: $message->static_site_id,
                callback_status_url: $message->callback_status_url,
                error: $e->getMessage(),
                failed_at: self::now(),
            ));

            return;
        }

        // OUTSIDE the try/catch on purpose. If this dispatch were inside it, a
        // broker outage here would be caught and reported as a *build* failure
        // -- telling the client their site is broken when it rendered fine.
        $this->bus->dispatch(new BuildSucceeded(
            build_id: $message->build_id,
            static_site_id: $message->static_site_id,
            slug: $message->slug,
            pages: $pages,
            callback_status_url: $message->callback_status_url,
            finished_at: self::now(),
        ));
    }

    /**
     * The build pipeline itself.
     *
     * @return int number of rendered pages
     *
     * @throws \InvalidArgumentException if either id is unsafe
     * @throws \RuntimeException         if any step fails
     */
    private function build(BuildJob $message): int
    {
        // Creates the job's input/output folders.
        $jobDir = $this->jobWorkspace->createJobDirectories(
            $message->static_site_id,
            $message->build_id,
        );

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

        return $pages;
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);
    }
}
