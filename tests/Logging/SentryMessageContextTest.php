<?php

declare(strict_types=1);

namespace App\Tests\Logging;

use App\Logging\SentryMessageContext;
use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;

/**
 * Covers App\Logging\SentryMessageContext against a Sentry client that keeps its events in memory.
 */
final class SentryMessageContextTest extends TestCase
{
    /** @var TransportInterface&object{sent: list<Event>} */
    private TransportInterface $transport;

    protected function setUp(): void
    {
        $this->transport = new class implements TransportInterface {
            /** @var list<Event> */
            public array $sent = [];

            public function send(Event $event): Result
            {
                $this->sent[] = $event;

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };

        SentrySdk::init()->bindClient(
            ClientBuilder::create(['dsn' => 'http://key@sentry.example/1', 'default_integrations' => false])
                ->setTransport($this->transport)
                ->getClient(),
        );
    }

    protected function tearDown(): void
    {
        SentrySdk::endContext();
        SentrySdk::init();
    }

    public function testAnEventCarriesOnlyTheBreadcrumbsOfItsOwnMessage(): void
    {
        $listener = new SentryMessageContext();

        $this->handleMessage($listener, 'first build');
        $this->handleMessage($listener, 'second build');

        $sent = $this->transport->sent;
        self::assertCount(2, $sent);
        self::assertSame(['first build'], self::breadcrumbMessages($sent[0]));
        self::assertSame(['second build'], self::breadcrumbMessages($sent[1]));
    }

    /**
     * Runs the listener around a message that leaves $breadcrumb and then sends an event.
     *
     * @param SentryMessageContext $listener The listener under test.
     * @param string $breadcrumb The breadcrumb message the message leaves.
     * @return void
     */
    private function handleMessage(SentryMessageContext $listener, string $breadcrumb): void
    {
        $listener->onMessageReceived(new WorkerMessageReceivedEvent(new Envelope(new \stdClass()), 'builds'));

        SentrySdk::getCurrentHub()->addBreadcrumb(new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'app', $breadcrumb));
        SentrySdk::getCurrentHub()->captureMessage('Build failed');

        $worker = new Worker([], $this->createStub(MessageBusInterface::class));
        $listener->onWorkerRunning(new WorkerRunningEvent($worker, false));
    }

    /**
     * Returns the messages of the breadcrumbs $event carries.
     *
     * @param Event $event The sent event.
     * @return list<string|null> the breadcrumb messages, oldest first
     */
    private static function breadcrumbMessages(Event $event): array
    {
        return array_values(array_map(static fn (Breadcrumb $breadcrumb): ?string => $breadcrumb->getMessage(), $event->getBreadcrumbs()));
    }
}
