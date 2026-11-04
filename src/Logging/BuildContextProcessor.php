<?php

declare(strict_types=1);

namespace App\Logging;

use App\Message\BuildJob;
use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Adds the build the worker is handling to every log record: build_id, static_site_id, slug and attempt.
 * It covers every channel, so Messenger's and HttpClient's lines carry the build too.
 * Records logged outside a build get nothing.
 */
#[AsMonologProcessor]
final class BuildContextProcessor
{
    private ?BuildJob $buildJob = null;
    private int $attempt = 1;

    /**
     * Remembers the build before its handler runs.
     * Runs ahead of every other listener, so their lines carry the build too.
     *
     * @param WorkerMessageReceivedEvent $event The message the worker took off the queue.
     * @return void
     */
    #[AsEventListener(priority: 2048)]
    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $message = $envelope->getMessage();

        $this->buildJob = $message instanceof BuildJob ? $message : null;
        $this->attempt = 1 + RedeliveryStamp::getRetryCountFromEnvelope($envelope);
    }

    /**
     * Forgets the build once the worker is done with its message.
     * WorkerRunningEvent follows the handler, the failure listeners and the retry decision.
     *
     * @param WorkerRunningEvent $event The worker between two messages.
     * @return void
     */
    #[AsEventListener(priority: -2048)]
    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        $this->buildJob = null;
    }

    /**
     * Returns $record with the current build in its extra fields.
     *
     * @param LogRecord $record The record to add the build to.
     * @return LogRecord $record, unchanged outside a build
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        if ($this->buildJob === null) {
            return $record;
        }

        $record->extra['build_id'] = $this->buildJob->build_id;
        $record->extra['static_site_id'] = $this->buildJob->static_site_id;
        $record->extra['slug'] = $this->buildJob->slug;
        $record->extra['attempt'] = $this->attempt;

        return $record;
    }
}
