<?php

declare(strict_types=1);

namespace App\Message;

use App\Job\JobWorkspace;
use App\Job\StatusNotifier;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Reports a build that ran out of retry attempts and clears its workspace.
 *
 * Event subscriber rather than a message handler: RejectRedeliveredMessageMiddleware
 * throws before BuildJobHandler runs on a consumer_timeout requeue, but
 * Worker::ack() still dispatches WorkerMessageFailedEvent for it. Subscribing
 * catches every terminal failure instead of only the ones BuildJobHandler saw.
 * It also gets the throwable directly, instead of round-tripping it through an
 * ErrorDetailsStamp on redelivery.
 *
 * BuildJobHandler reports success (it has the page count); this covers failure.
 * Must never throw itself, since nothing catches an exception past Worker::ack().
 *
 * Logs the raw error (paths and all, for operators) but sends redact()'s
 * version to the callback, since callback_status_url is caller-supplied and a
 * raw path would leak the volume layout.
 */
final class BuildFailureHandler implements EventSubscriberInterface
{
    /** Caps a pathological error message from bloating the callback body. */
    private const MAX_ERROR_LENGTH = 500;

    private const TRUNCATION_MARKER = ' ...(truncated)';

    /** @var array<string, string> root => placeholder, longest root first */
    private readonly array $roots;

    /** Same env vars as JobWorkspace; passed directly since redact() matches on plain strings. */
    public function __construct(
        private readonly JobWorkspace $jobWorkspace,
        private readonly StatusNotifier $notifier,
        string $buildTempDir,
        string $publishedDir,
    ) {
        $roots = [
            rtrim($buildTempDir, '/') => '<build>',
            rtrim($publishedDir, '/') => '<published>',
        ];

        // Longest first, so a root nested inside another is replaced by its own
        // name rather than by its parent's.
        uksort($roots, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        $this->roots = $roots;
    }

    /** Priority below SendFailedMessageForRetryListener's 100, so willRetry() reflects its setForRetry() call. */
    public static function getSubscribedEvents(): array
    {
        return [WorkerMessageFailedEvent::class => ['onMessageFailed', 0]];
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        // Not a terminal outcome; reset() wipes the workspace at the next attempt.
        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();

        if (!$message instanceof BuildJob) {
            return;
        }

        $error = $event->getThrowable()->getMessage();

        // Raw, with paths: this is the operator's record.
        error_log(sprintf(
            '[ERROR] build %s of %s failed: %s',
            $message->build_id,
            $message->static_site_id,
            $error,
        ));

        // A failed build keeps nothing; see docs/roadmap.md for why.
        $this->jobWorkspace->clear($message->static_site_id, $message->build_id);

        try {
            $this->notifier->notify(
                callbackStatusUrl: $message->callback_status_url,
                status: StatusNotifier::STATUS_FAILED,
                buildId: $message->build_id,
                staticSiteId: $message->static_site_id,
                slug: $message->slug,
                finishedAt: (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
                error: $this->redact($error),
            );
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[ERROR] could not report failed for build %s: %s',
                $message->build_id,
                $e->getMessage(),
            ));
        }
    }

    /** Strips the deployment's filesystem layout out of a failure message. */
    private function redact(string $message): string
    {
        // 1. Flatten control chars: tar output can contain them, and a raw NUL breaks JSON.
        $message = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message);

        // 2. Name known roots before the generic collapse below, so the message
        //    still says which tree failed.
        foreach ($this->roots as $root => $placeholder) {
            if ($root !== '') {
                $message = str_replace($root, $placeholder, $message);
            }
        }

        // 3. Strip URL userinfo before that collapse, so a credential embedded
        //    in the (client-supplied) download URL can't survive.
        $message = (string) preg_replace('#([a-z][a-z0-9+.-]*://)[^/@\s]*@#i', '$1', $message);

        // 4. Collapse remaining absolute paths. The lookbehind excludes: \w
        //    (leaves https://host/a/b alone), : and / (the scheme's own "//"),
        //    and > (text after a <build>/<published> placeholder).
        $message = (string) preg_replace('#(?<![\w:/>])/(?:[\w.+@%-]+/)*[\w.+@%-]+#', '<path>', $message);

        $message = trim($message);

        if (mb_strlen($message) > self::MAX_ERROR_LENGTH) {
            return mb_substr($message, 0, self::MAX_ERROR_LENGTH) . self::TRUNCATION_MARKER;
        }

        return $message;
    }
}
