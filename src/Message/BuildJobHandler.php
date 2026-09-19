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
 * Registered as a message handler in services.yaml rather than via
 * #[AsMessageHandler], which pins it to the "builds" transport.
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
        private readonly StatusNotifier $notifier,
    ) {
    }

    public function __invoke(BuildJob $message): void
    {
        // build, publish and notify success are all inside the same try block to ensure that
        // a failed build in any step is retried or handled as a failure by Messenger.
        try {
            // Everything that becomes a directory name, checked once, before
            // any work. Nothing downstream re-checks.
            JobWorkspace::assertSafeJob($message->static_site_id, $message->build_id, $message->slug);

            $pages = $this->build($message);

            $this->jobWorkspace->publish($message->static_site_id, $message->build_id, $message->slug);

            $this->jobWorkspace->clear($message->static_site_id, $message->build_id);

            error_log(sprintf(
                '[INFO] published %s from build %s (%d page(s))',
                $message->slug,
                $message->build_id,
                $pages,
            ));

            $this->notifier->notify(
                callbackStatusUrl: $message->callback_status_url,
                status: StatusNotifier::STATUS_SUCCESS,
                buildId: $message->build_id,
                staticSiteId: $message->static_site_id,
                slug: $message->slug,
                finishedAt: (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
                pages: $pages,
            );
        } catch (\InvalidArgumentException $e) {
            // Marked unrecoverable so the retry strategy is skipped entirely:
            // e.g. an unsafe slug not causing senseless retries.
            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }
    }

    /** Download, extract, render. Everything before the site goes live. */
    private function build(BuildJob $message): int
    {
        // Clear any previous attempt to avoid anymess when mixing up builds.
        $jobDir = $this->jobWorkspace->reset($message->static_site_id, $message->build_id);

        error_log(sprintf('[INFO] prepared job workdir %s', $jobDir));

        $inputDir = $this->jobWorkspace->buildInputDir($message->static_site_id, $message->build_id);
        $file = $this->contentDownloader->download($message->content_download_url, $inputDir);

        error_log(sprintf(
            '[INFO] downloaded %s to %s (%d bytes)',
            $message->content_download_url,
            $file,
            (int) @filesize($file),
        ));

        $unarchivedDir = $inputDir . '/' . self::UNARCHIVED_DIR;
        $this->archiveExtractor->extract($file, $unarchivedDir);

        error_log(sprintf('[INFO] extracted %s into %s', $file, $unarchivedDir));

        $outputDir = $this->jobWorkspace->buildOutputDir($message->static_site_id, $message->build_id);

        $pages = $this->siteRenderer->render($unarchivedDir, $outputDir, $message->title);

        error_log(sprintf('[INFO] rendered %d page(s) into %s', $pages, $outputDir));

        return $pages;
    }
}
