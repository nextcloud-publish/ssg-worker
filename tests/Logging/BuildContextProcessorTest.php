<?php

declare(strict_types=1);

namespace App\Tests\Logging;

use App\Logging\BuildContextProcessor;
use App\Message\BuildJob;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Worker;

/**
 * Covers App\Logging\BuildContextProcessor, directly and as the container wires it.
 */
final class BuildContextProcessorTest extends KernelTestCase
{
    private const BUILD_FIELDS = [
        'build_id' => '16ef078ad37fd894',
        'static_site_id' => '11f5b798-6f34-4951-ad8b-bfd623ded5c2',
        'slug' => 'demo-site',
    ];

    /**
     * Returns the BuildJob the build fields above describe.
     *
     * @return BuildJob the job
     */
    private static function buildJob(): BuildJob
    {
        return new BuildJob(
            build_id: self::BUILD_FIELDS['build_id'],
            static_site_id: self::BUILD_FIELDS['static_site_id'],
            slug: self::BUILD_FIELDS['slug'],
            content_download_url: 'https://cloud.example.org/export.tar.gz',
            callback_status_url: 'https://cloud.example.org/status',
            created_at: '2026-09-17T10:00:00+00:00',
            title: 'My Team Handbook',
        );
    }

    /**
     * Returns the event the worker dispatches when it takes $envelope off the queue.
     *
     * @param Envelope $envelope The received envelope.
     * @return WorkerMessageReceivedEvent the event
     */
    private static function received(Envelope $envelope): WorkerMessageReceivedEvent
    {
        return new WorkerMessageReceivedEvent($envelope, 'builds');
    }

    /**
     * Returns the event the worker dispatches after it is done with a message.
     *
     * @return WorkerRunningEvent the event
     */
    private function running(): WorkerRunningEvent
    {
        return new WorkerRunningEvent(new Worker([], $this->createStub(MessageBusInterface::class)), false);
    }

    /**
     * Returns a fresh record to run through the processor.
     *
     * @return LogRecord the record, with empty extra fields
     */
    private static function record(): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'Published site');
    }

    public function testAddsTheBuildToARecordDuringIt(): void
    {
        $processor = new BuildContextProcessor();
        $processor->onMessageReceived(self::received(new Envelope(self::buildJob())));

        self::assertSame(self::BUILD_FIELDS + ['attempt' => 1], $processor(self::record())->extra);
    }

    public function testCountsARetryAsTheSecondAttempt(): void
    {
        $processor = new BuildContextProcessor();
        $processor->onMessageReceived(self::received(new Envelope(self::buildJob(), [new RedeliveryStamp(1)])));

        self::assertSame(2, $processor(self::record())->extra['attempt']);
    }

    public function testForgetsTheBuildOnceTheWorkerIsDone(): void
    {
        $processor = new BuildContextProcessor();
        $processor->onMessageReceived(self::received(new Envelope(self::buildJob())));
        $processor->onWorkerRunning($this->running());

        self::assertSame([], $processor(self::record())->extra);
    }

    public function testAddsNothingOutsideABuild(): void
    {
        self::assertSame([], (new BuildContextProcessor())(self::record())->extra);
    }

    public function testAddsNothingForAMessageThatIsNotABuildJob(): void
    {
        $processor = new BuildContextProcessor();
        $processor->onMessageReceived(self::received(new Envelope(new \stdClass())));

        self::assertSame([], $processor(self::record())->extra);
    }

    /** The attributes register the listeners and the processor; this checks they reach the app logger. */
    public function testTheContainerAddsTheBuildToTheAppLogger(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $dispatcher = $container->get('event_dispatcher');
        $logger = $container->get('logger');
        $handler = $container->get('monolog.handler.test');
        self::assertInstanceOf(TestHandler::class, $handler);

        $dispatcher->dispatch(self::received(new Envelope(self::buildJob())));
        $logger->info('Published site');
        $dispatcher->dispatch($this->running());
        $logger->info('Worker stopped');

        $extraOf = [];
        foreach ($handler->getRecords() as $record) {
            $extraOf[$record->message] = $record->extra;
        }

        $expected = self::BUILD_FIELDS + ['attempt' => 1];
        self::assertSame($expected, array_intersect_key($extraOf['Published site'], $expected));
        self::assertArrayNotHasKey('build_id', $extraOf['Worker stopped']);
    }
}
