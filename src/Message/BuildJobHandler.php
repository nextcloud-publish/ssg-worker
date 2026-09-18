<?php

declare(strict_types=1);

namespace App\Message;

use App\Callback\ErrorRedactor;
use App\Callback\StatusNotifier;
use App\Job\ArchiveExtractor;
use App\Job\ContentDownloader;
use App\Job\JobWorkspace;
use App\Job\SiteRenderer;

/**
 * Builds a job from q.builds and owns its outcome: publish on success, clean
 * up and report on terminal failure, and in between let the transport
 * redeliver.
 *
 * TWO EXCEPTION TYPES, and the whole handler turns on the difference:
 *  - \InvalidArgumentException -- nothing a retry could change (an unsafe id or
 *    slug, a URL we will not fetch, an archive with no pages). Reported now.
 *  - \RuntimeException -- the environment might differ next time (disk,
 *    network, a half-finished swap). Rethrown so Messenger redelivers it.
 *
 * THE CLIENT IS TOLD EXACTLY ONCE, by whichever delivery reaches a terminal
 * state. A delivery that rethrows tells nobody; the reason travels forward on
 * the envelope's ErrorDetailsStamp, and the delivery that finds no attempt left
 * reports it.
 *
 * Registered as a message handler in services.yaml instead of via the
 * #[AsMessageHandler] attribute, which pins it to the "builds" transport.
 */
final class BuildJobHandler
{
    /** Folder where the archive is unpacked, relative to the job's input/ folder. */
    public const UNARCHIVED_DIR = 'content_unarchived';

    /**
     * What the callback says when a build failed on every attempt but the
     * reason did not survive the trip back. See RetryCountMiddleware: the
     * reason rides in a transport header, and a header is a weaker promise
     * than a message body.
     */
    private const UNKNOWN_ERROR = 'The build failed on every attempt; the reason was not recorded.';

    public function __construct(
        private readonly ContentDownloader $contentDownloader,
        private readonly ArchiveExtractor $archiveExtractor,
        private readonly SiteRenderer $siteRenderer,
        private readonly JobWorkspace $jobWorkspace,
        private readonly StatusNotifier $notifier,
        private readonly ErrorRedactor $redactor,
        /**
         * MUST equal messenger.yaml's retry_strategy.max_retries for the builds
         * transport; both read the %build.max_retries% parameter so they cannot
         * drift. Too low and an attempt is thrown away unused; too high and
         * this gate never fires, so the last failure is dropped unreported.
         */
        private readonly int $maxRetries,
    ) {
    }

    /**
     * $retryCount and $previousError are supplied by RetryCountMiddleware
     * through a HandlerArgumentsStamp. They default so that a direct call
     * (every test in this repo) and any dispatch that skips the middleware
     * still work.
     */
    public function __invoke(BuildJob $message, int $retryCount = 0, ?string $previousError = null): void
    {
        // THE GATE. max_retries: 2 means deliveries arrive with counts 0, 1, 2,
        // and MultiplierRetryStrategy::isRetryable() ($retries < $maxRetries)
        // refuses to redeliver after count 2. A build started on that last
        // delivery would have nowhere to report a failure: throwing would have
        // the message logged and dropped, returning would claim a success that
        // never happened. So it does not build. It reports what the previous
        // attempt hit, clears what the previous attempt left, and acks.
        if ($retryCount >= $this->maxRetries) {
            error_log(sprintf(
                '[ERROR] build %s exhausted its %d attempts',
                $message->build_id,
                $this->maxRetries,
            ));

            $this->reportFailure($message, $previousError ?? self::UNKNOWN_ERROR);

            return;
        }

        try {
            $pages = $this->build($message);

            // Publishing is inside the same try on purpose. A failed swap is
            // a failed build as far as the client is concerned, and the retry
            // that follows rebuilds from scratch.
            $this->jobWorkspace->publish($message->static_site_id, $message->build_id, $message->slug);
        } catch (\InvalidArgumentException $e) {
            // Terminal on the first delivery. Spending the remaining attempts
            // and ~75s of backoff on an unsafe slug only delays the answer.
            $this->reportFailure($message, $e->getMessage());

            return;
        } catch (\RuntimeException $e) {
            // Raw, with paths: this is the log, not the callback.
            error_log(sprintf(
                '[ERROR] build %s attempt %d of %d failed: %s',
                $message->build_id,
                $retryCount + 1,
                $this->maxRetries,
                $e->getMessage(),
            ));

            // Out to Messenger: AddErrorDetailsStampListener (priority 200)
            // stamps this message onto the envelope before
            // SendFailedMessageForRetryListener (priority 100) republishes it,
            // so the next delivery can report it if it is the last.
            throw $e;
        }

        // Before the callback, so the volume is freed even if the client's
        // endpoint is slow. The site is already live at this point.
        $this->jobWorkspace->clear($message->static_site_id, $message->build_id);

        error_log(sprintf(
            '[INFO] published %s from build %s (%d page(s))',
            $message->slug,
            $message->build_id,
            $pages,
        ));

        $this->report($message, StatusNotifier::STATUS_SUCCESS, pages: $pages);
    }

    /** Download, extract, render. Everything before the site goes live. */
    private function build(BuildJob $message): int
    {
        // Wipes any previous attempt: SiteBuilder never clears its output dir,
        // so a reused output/ would republish pages deleted from the source.
        $jobDir = $this->jobWorkspace->reset(
            $message->static_site_id,
            $message->build_id,
            $message->slug,
        );

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

        // title, not slug: the slug is a directory name now and the allow-list
        // that makes it safe excludes spaces. Empty means the message predates
        // the field -- fall back rather than render a site titled "".
        $title = $message->title !== '' ? $message->title : $message->slug;
        $pages = $this->siteRenderer->render($unarchivedDir, $outputDir, $title);

        error_log(sprintf('[INFO] rendered %d page(s) into %s', $pages, $outputDir));

        return $pages;
    }

    /**
     * A FAILED BUILD KEEPS NOTHING. The wreckage is deleted, so the error in the
     * callback and the log line below are the whole record. Keeping failures
     * needs a database to index them by -- see docs/roadmap.md.
     *
     * clear() never throws, so cleanup can never cost the client the failure
     * notice that is the point of this path.
     */
    private function reportFailure(BuildJob $message, string $error): void
    {
        $this->jobWorkspace->clear($message->static_site_id, $message->build_id);

        error_log(sprintf(
            '[ERROR] build %s of %s failed: %s',
            $message->build_id,
            $message->static_site_id,
            $error,
        ));

        $this->report($message, StatusNotifier::STATUS_FAILED, $error);
    }

    /**
     * THE CALLBACK NEVER FAILS THE MESSAGE. Letting StatusNotifier's exception
     * out would have Messenger replay the whole handler -- download, extract,
     * render, publish -- spending the BUILD retry budget on an HTTP problem, so
     * a slow client endpoint would end up reported as a failed build.
     *
     * The cost: a callback URL unreachable for the whole attempt leaves a site
     * live and its client never told, with the log as the only record. See
     * docs/roadmap.md.
     */
    private function report(BuildJob $message, string $status, ?string $error = null, int $pages = 0): void
    {
        try {
            $this->notifier->notify(
                callbackStatusUrl: $message->callback_status_url,
                status: $status,
                buildId: $message->build_id,
                staticSiteId: $message->static_site_id,
                slug: $message->slug,
                finishedAt: (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
                pages: $pages,
                // Redacted here, not inside StatusNotifier: the logs above
                // want the real paths, the client must not get them.
                error: $error === null ? null : $this->redactor->redact($error),
            );
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[ERROR] could not report %s for build %s: %s',
                $status,
                $message->build_id,
                $e->getMessage(),
            ));
        }
    }
}
