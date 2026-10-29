<?php

declare(strict_types=1);

namespace App\Message;

use App\Job\JobWorkspace;
use App\Job\StatusNotifier;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Handles the failure of a build by clearing the job workspace and notifying the status callback.
 * Via the getSubscribedEvents method it subscribes to the WorkerMessageFailedEvent to handle build failures with the onMessageFailed method.
 * Setting a low priority ensures that the message is handled after the SendFailedMessageForRetryListener has had a chance to set the retry flag.
 * Before sending the notification the error message is redacted to strip out any sensitive information.
 *
 * @param JobWorkspace $jobWorkspace The job workspace helper class.
 * @param StatusNotifier $statusNotifier The notifier to use to notify the status callback.
 * @param string $buildTempDir The build temp directory.
 * @param string $publishedDir The published directory.
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
        private readonly StatusNotifier $statusNotifier,
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

    /**
     * Subscribes to the WorkerMessageFailedEvent to handle build failures with the onMessageFailed method.
     * Setting a low priority ensures that the message is handled after the SendFailedMessageForRetryListener has had a chance to set the retry flag.
     *
     * @return array<string, array<string, string|int>> The subscribed events and the method to call with which priority.
     */
    public static function getSubscribedEvents(): array
    {
        return [WorkerMessageFailedEvent::class => ['onMessageFailed', 0]];
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $buildJob = $event->getEnvelope()->getMessage();

        if (!$buildJob instanceof BuildJob) {
            return;
        }

        $errorMessage = $event->getThrowable()->getMessage();

        // Raw, with paths: this is the operator's record.
        error_log(sprintf(
            '[ERROR] build %s of %s failed: %s',
            $buildJob->build_id,
            $buildJob->static_site_id,
            $errorMessage,
        ));

        $this->jobWorkspace->clear($buildJob->build_id);

        try {
            $this->statusNotifier->notifyFailed(
                callbackStatusUrl: $buildJob->callback_status_url,
                buildId: $buildJob->build_id,
                errorMessage: $this->redact($errorMessage),
            );
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[ERROR] could not report failed for build %s: %s',
                $buildJob->build_id,
                $e->getMessage(),
            ));
        }
    }

    /** Strips the deployment's filesystem layout out of a failure message.
     *
     * @param string $errorMessage The error message to redact.
     * @return string The redacted error message.
     */
    private function redact(string $errorMessage): string
    {
        // 1. Flatten control chars: tar output can contain them, and a raw NUL breaks JSON.
        $errorMessage = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $errorMessage);

        // 2. Name known roots before the generic collapse below, so the message
        //    still says which tree failed.
        foreach ($this->roots as $root => $placeholder) {
            if ($root !== '') {
                $errorMessage = str_replace($root, $placeholder, $errorMessage);
            }
        }

        // 3. Strip URL userinfo before that collapse, so a credential embedded
        //    in the (client-supplied) download URL can't survive.
        $errorMessage = (string) preg_replace('#([a-z][a-z0-9+.-]*://)[^/@\s]*@#i', '$1', $errorMessage);

        // 4. Collapse remaining absolute paths. The lookbehind excludes: \w
        //    (leaves https://host/a/b alone), : and / (the scheme's own "//"),
        //    and > (text after a <build>/<published> placeholder).
        $errorMessage = (string) preg_replace('#(?<![\w:/>])/(?:[\w.+@%-]+/)*[\w.+@%-]+#', '<path>', $errorMessage);

        $errorMessage = trim($errorMessage);

        if (mb_strlen($errorMessage) > self::MAX_ERROR_LENGTH) {
            return mb_substr($errorMessage, 0, self::MAX_ERROR_LENGTH) . self::TRUNCATION_MARKER;
        }

        return $errorMessage;
    }
}
