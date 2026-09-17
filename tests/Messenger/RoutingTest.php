<?php

declare(strict_types=1);

namespace App\Tests\Messenger;

use App\Message\BuildFailed;
use App\Message\BuildSucceeded;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Asserts that each outcome class is routed to its own transport.
 *
 * This is the only thing that catches a typo in messenger.yaml's `routing:`
 * map offline. Get it wrong and successes land on the failure queue -- where a
 * handler pinned with from_transport will not fire at all, so the client is
 * simply never told anything.
 *
 * WHAT THIS DOES NOT COVER, and cannot: InMemoryTransportFactory discards the
 * options array entirely, so the queue names, the exchange blocks, auto_setup,
 * confirm_timeout and the retry strategy are all invisible here. A wrong queue
 * name in `queues:`, a missing queue in definitions.json, or the missing
 * `exchange: { name: '' }` that kills a retrying worker all pass this test and
 * fail only against a real broker. See publish/docker/dev for the stack that
 * exercises them.
 */
final class RoutingTest extends KernelTestCase
{
    private function transport(string $name): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.' . $name);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    public function testBuildSucceededIsRoutedToTheResultsTransport(): void
    {
        self::bootKernel();

        static::getContainer()->get(MessageBusInterface::class)->dispatch(new BuildSucceeded(
            build_id: '16ef078ad37fd894',
            static_site_id: 'site-42',
            slug: 'some_collective',
            pages: 7,
            callback_status_url: 'https://cloud.example.org/status/1234',
            finished_at: '2026-09-16T12:00:00+00:00',
        ));

        self::assertCount(1, $this->transport('build_results')->getSent());
        self::assertCount(0, $this->transport('build_failures')->getSent());
        self::assertCount(0, $this->transport('build_dead')->getSent());
    }

    public function testBuildFailedIsRoutedToTheFailuresTransport(): void
    {
        self::bootKernel();

        static::getContainer()->get(MessageBusInterface::class)->dispatch(new BuildFailed(
            build_id: '16ef078ad37fd894',
            static_site_id: 'site-42',
            callback_status_url: 'https://cloud.example.org/status/1234',
            error: 'not in gzip format',
            failed_at: '2026-09-16T12:00:00+00:00',
        ));

        self::assertCount(1, $this->transport('build_failures')->getSent());
        self::assertCount(0, $this->transport('build_results')->getSent());
        self::assertCount(0, $this->transport('build_dead')->getSent());
    }

    /**
     * Routing a class to a transport is what makes dispatch() publish instead
     * of handle. If an outcome class ever gained a local handler here, it would
     * be handled in-process and never reach publish's result worker.
     */
    public function testOutcomesAreNotHandledLocally(): void
    {
        self::bootKernel();

        // Asserted through the observable consequence rather than by
        // introspecting the bus: a message only lands on a transport when
        // nothing handled it synchronously.
        static::getContainer()->get(MessageBusInterface::class)->dispatch(new BuildSucceeded(
            build_id: '16ef078ad37fd894',
            static_site_id: 'site-42',
            slug: 'some_collective',
            pages: 1,
            callback_status_url: '',
            finished_at: '2026-09-16T12:00:00+00:00',
        ));

        self::assertCount(1, $this->transport('build_results')->getSent());
    }
}
