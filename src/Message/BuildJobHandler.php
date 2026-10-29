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
 *  - \InvalidArgumentException: e.g. an archive with no pages or a callback that rejects us.
 *    Stops retry via an UnrecoverableMessageHandlingException so the transport reports it instead of retrying the build.
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
 * @param StatusNotifier $statusNotifier The status notifier to use to notify the status of the build.
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
        private readonly StatusNotifier $statusNotifier,
    ) {
    }

    public function __invoke(BuildJob $buildJob): void
    {
        // build, publish and notify success are all inside the same try block to ensure that
        // a failed build in any step is retried or handled as a failure by Messenger.
        try {
            $pageCount = $this->build($buildJob);
            $this->jobWorkspace->publish($buildJob->build_id, $buildJob->static_site_id, $buildJob->slug);
            $this->jobWorkspace->clear($buildJob->build_id);

            error_log(sprintf(
                '[INFO] published %s/%s from build %s (%d page(s))',
                $buildJob->static_site_id,
                $buildJob->slug,
                $buildJob->build_id,
                $pageCount,
            ));

            $this->statusNotifier->notifyPublished(
                callbackStatusUrl: $buildJob->callback_status_url,
                buildId: $buildJob->build_id,
                publishUrl: $this->jobWorkspace->publishUrl($buildJob->static_site_id, $buildJob->slug),
            );
        } catch (\InvalidArgumentException $e) {
            // Marked unrecoverable so the retry strategy is skipped entirely.
            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }
    }

    /** Download, extract, render. Everything before the site goes live. */
    private function build(BuildJob $buildJob): int
    {
        /** Clear any previous attempt to avoid any mess when mixing up builds. */
        $buildJobDir = $this->jobWorkspace->reset($buildJob->build_id);

        error_log(sprintf('[INFO] prepared job workdir %s', $buildJobDir));

        $buildJobInputDir = $this->jobWorkspace->buildJobInputDir($buildJob->build_id);
        $archiveFile = $this->contentDownloader->download($buildJob->content_download_url, $buildJobInputDir);

        error_log(sprintf(
            '[INFO] downloaded %s to %s (%d bytes)',
            $buildJob->content_download_url,
            $archiveFile,
            (int) @filesize($archiveFile),
        ));

        $buildJobUnarchivedDir = $this->jobWorkspace->buildJobUnarchivedDir($buildJob->build_id);
        $this->archiveExtractor->extract($archiveFile, $buildJobUnarchivedDir);

        error_log(sprintf('[INFO] extracted %s into %s', $archiveFile, $buildJobUnarchivedDir));

        $buildJobOutputDir = $this->jobWorkspace->buildJobOutputDir($buildJob->build_id);
        $pageCount = $this->siteRenderer->render($buildJobUnarchivedDir, $buildJobOutputDir, $buildJob->title);

        error_log(sprintf('[INFO] rendered %d page(s) into %s', $pageCount, $buildJobOutputDir));

        return $pageCount;
    }
}
