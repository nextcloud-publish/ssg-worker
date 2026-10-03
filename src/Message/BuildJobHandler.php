<?php

declare(strict_types=1);

namespace App\Message;

use App\Job\ArchiveExtractor;
use App\Job\ContentDownloader;
use App\Job\JobWorkspace;
use App\Job\SiteRenderer;
use App\Job\StatusNotifier;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Builds a job received from publish API via symfony messenger:
 *  download, extract, render, publish, report.
 *
 * A build that fails exits this method by throwing, not by returning an
 * error, and which exception type is thrown decides what Messenger does
 * next:
 *  - \InvalidArgumentException: triggered by an unsafe id or
 *    slug, a URL we won't fetch, an archive with no pages. Stops retry via an
 *    UnrecoverableMessageHandlingException so the transport reports it instead of retrying the build.
 *  - \RuntimeException: the environment might differ next time (disk,
 *    network, a half-finished swap). Retry via Messenger redelivery.
 *
 * BuildFailureHandler handles a failed build if retries are exhausted -- 
 * Successfully built sites are handled here.
 * 
 * @param ContentDownloader $contentDownloader The content downloader to use to download the content.
 * @param ArchiveExtractor $archiveExtractor The archive extractor to use to extract the content.
 * @param SiteRenderer $siteRenderer The site renderer to use to render the content.
 * @param JobWorkspace $jobWorkspace The job workspace to use to manage the job workspace.
 * @param StatusNotifier $notifier The status notifier to use to notify the status of the build.
 *
 * Registered as a message handler in services.yaml rather than via
 * #[AsMessageHandler], which pins it to the "builds" transport.
 */
final class BuildJobHandler
{
    public function __construct(
        private readonly ContentDownloader $contentDownloader,
        private readonly ArchiveExtractor $archiveExtractor,
        private readonly SiteRenderer $siteRenderer,
        private readonly JobWorkspace $jobWorkspace,
        private readonly StatusNotifier $notifier,
    ) {
    }

    public function __invoke(BuildJob $message): void
    {
        // build, publish and notify success are all inside the same try block to ensure that
        // a failed build in any step is retried or handled as a failure by Messenger.
        try {
            $this->assertUsableJob($message);

            $pages = $this->build($message);

            $this->jobWorkspace->publish($message->build_id, $message->static_site_id, $message->slug);

            $this->jobWorkspace->clear($message->build_id);

            error_log(sprintf(
                '[INFO] published %s/%s from build %s (%d page(s))',
                $message->static_site_id,
                $message->slug,
                $message->build_id,
                $pages,
            ));

            $this->notifier->notifyPublished(
                callbackStatusUrl: $message->callback_status_url,
                buildId: $message->build_id,
                publishUrl: $this->jobWorkspace->publishUrl($message->static_site_id, $message->slug),
            );
        } catch (\InvalidArgumentException $e) {
            // Marked unrecoverable so the retry strategy is skipped entirely:
            // e.g. an unsafe slug not causing senseless retries.
            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Checks every field the job will act on, before any work is done. Nothing
     * downstream re-checks. title and created_at are not checked: the first is
     * a free-form heading, the second is never read.
     *
     * @param BuildJob $message The build job to check.
     * @return void
     * @throws \InvalidArgumentException if any field is unusable
     */
    private function assertUsableJob(BuildJob $message): void
    {
        JobWorkspace::assertSafeJob($message->static_site_id, $message->build_id, $message->slug);
        ContentDownloader::assertFetchableUrl($message->content_download_url);
        StatusNotifier::assertCallableUrl($message->callback_status_url, $message->build_id);
    }

    /** Download, extract, render. Everything before the site goes live. */
    private function build(BuildJob $message): int
    {
        /** Clear any previous attempt to avoid any mess when mixing up builds. */
        $jobDir = $this->jobWorkspace->reset($message->build_id);

        error_log(sprintf('[INFO] prepared job workdir %s', $jobDir));

        $inputDir = $this->jobWorkspace->buildJobInputDir($message->build_id);
        $file = $this->contentDownloader->download($message->content_download_url, $inputDir);

        error_log(sprintf(
            '[INFO] downloaded %s to %s (%d bytes)',
            $message->content_download_url,
            $file,
            (int) @filesize($file),
        ));

        $unarchivedDir = $this->jobWorkspace->buildJobUnarchivedDir($message->build_id);
        $this->archiveExtractor->extract($file, $unarchivedDir);

        error_log(sprintf('[INFO] extracted %s into %s', $file, $unarchivedDir));

        $outputDir = $this->jobWorkspace->buildJobOutputDir($message->build_id);

        $pages = $this->siteRenderer->render($unarchivedDir, $outputDir, $message->title);

        error_log(sprintf('[INFO] rendered %d page(s) into %s', $pages, $outputDir));

        return $pages;
    }
}
