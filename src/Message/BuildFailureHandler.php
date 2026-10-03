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
 * 
 * Before sending the notification the error message is redacted to strip out any sensitive information.
 * 
 * 
 * @param JobWorkspace $jobWorkspace The job workspace helper class.
 * @param StatusNotifier $notifier The notifier to use to notify the status callback.
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

        $this->jobWorkspace->clear($message->build_id);

        try {
            $this->notifier->notifyFailed(
                callbackStatusUrl: $message->callback_status_url,
                buildId: $message->build_id,
                errorMessage: $this->redact($error),
            );
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[ERROR] could not report failed for build %s: %s',
                $message->build_id,
                $e->getMessage(),
            ));
        }
    }

    /** Strips the deployment's filesystem layout out of a failure message.
     * 
     * @param string $message The error message to redact.
     * @return string The redacted error message.
     */
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
