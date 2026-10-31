<?php

declare(strict_types=1);

namespace App\Logging;

use Sentry\SentrySdk;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/**
 * Gives every message its own Sentry context, so an event carries only the breadcrumbs of its own build.
 * Without a Sentry client, as in dev and test, the contexts hold breadcrumbs that are never sent.
 */
final class SentryMessageContext
{
    /**
     * Starts the context before the other listeners log anything for the message.
     *
     * @param WorkerMessageReceivedEvent $event The message the worker took off the queue.
     * @return void
     */
    #[AsEventListener(priority: 2048)]
    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        SentrySdk::startContext();
    }

    /**
     * Ends the context once the worker is done with its message, after the failure listeners.
     *
     * @param WorkerRunningEvent $event The worker between two messages.
     * @return void
     */
    #[AsEventListener(priority: -2048)]
    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        SentrySdk::endContext();
    }
}
