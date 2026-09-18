<?php

declare(strict_types=1);

namespace App\Tests\Messenger;

use App\Message\BuildJob;
use App\Messenger\RetryCountMiddleware;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Asserts the retry budget the CONTAINER actually built, rather than the YAML
 * that describes it.
 *
 * This matters more here than it looks: InMemoryTransportFactory discards the
 * whole options array, so nothing under `options:` in messenger.yaml is
 * exercised by any test in this repo -- not the queue name, not the quorum
 * argument, not the exchange block, not the delays exchange those retries need
 * to exist on the broker. The retry_strategy is the one part that IS a real
 * service, and this is the only place it is checked. See docs/build-pipeline.md
 * for the rest, which only the dev stack can reach.
 */
final class MessengerConfigTest extends KernelTestCase
{
    /** Must match %build.max_retries% and BuildJobHandler's gate. */
    private const MAX_RETRIES = 2;

    private function strategy(): RetryStrategyInterface
    {
        self::bootKernel();

        /** @var RetryStrategyInterface $strategy */
        $strategy = self::getContainer()->get('test.retry_strategy.builds');

        return $strategy;
    }

    private function envelopeAtRetry(int $retryCount): Envelope
    {
        $job = new BuildJob(
            build_id: '16ef078ad37fd894',
            static_site_id: 'site-42',
            slug: 'demo-site',
            content_download_url: 'https://cloud.example.org/export.tar.gz',
            callback_status_url: 'https://cloud.example.org/status',
            created_at: '2026-09-17T10:00:00+00:00',
            title: 'My Team Handbook',
        );

        return $retryCount === 0
            ? new Envelope($job)
            : new Envelope($job, [new RedeliveryStamp($retryCount)]);
    }

    /**
     * Two build attempts. The handler builds at counts 0 and 1; at 2 it must
     * not build, because the transport will not redeliver again and a failure
     * there would have nowhere to be reported.
     */
    public function testTheTransportRedeliversExactlyTwice(): void
    {
        $strategy = $this->strategy();

        self::assertTrue($strategy->isRetryable($this->envelopeAtRetry(0)), 'a first failure must be retried');
        self::assertTrue($strategy->isRetryable($this->envelopeAtRetry(1)), 'a second failure must be retried');
        self::assertFalse(
            $strategy->isRetryable($this->envelopeAtRetry(self::MAX_RETRIES)),
            'the transport must not redeliver after the budget is spent -- '
            . 'BuildJobHandler reports the failure on that delivery instead',
        );
    }

    /**
     * Exact equality is only possible BECAUSE jitter is 0, so this assertion
     * documents that setting as much as it checks the delays. Jitter would also
     * put a different number in each delay queue's name, declaring a fresh
     * queue per message per attempt.
     */
    public function testTheBackoffIsFifteenSecondsThenSixty(): void
    {
        $strategy = $this->strategy();

        self::assertSame(15_000, $strategy->getWaitingTime($this->envelopeAtRetry(0)));
        self::assertSame(60_000, $strategy->getWaitingTime($this->envelopeAtRetry(1)));
    }

    /** The when@test override applied, so no test can reach a real broker. */
    public function testTheBuildsTransportIsInMemoryUnderTest(): void
    {
        self::bootKernel();

        self::assertInstanceOf(
            InMemoryTransport::class,
            self::getContainer()->get('messenger.transport.builds'),
        );
    }

    /**
     * Without this middleware in the chain the handler always sees retryCount 0
     * and its gate never fires, so a failing build retries forever and the
     * client is never told.
     *
     * Asserted against the middleware the bus was actually built with, not
     * against the YAML: registering it under the wrong bus id is a silent
     * no-op, and RetryCountMiddlewareTest would still pass because it
     * constructs the middleware directly.
     */
    public function testTheRetryCountMiddlewareIsInTheDefaultBus(): void
    {
        self::bootKernel();

        $bus = self::getContainer()->get('messenger.bus.default');

        $aggregate = (new \ReflectionObject($bus))
            ->getProperty('middlewareAggregate')
            ->getValue($bus);

        $middleware = array_map(get_debug_type(...), iterator_to_array($aggregate, false));

        self::assertContains(RetryCountMiddleware::class, $middleware);

        // It has to run BEFORE handle_message, or the stamp it adds is never
        // read. The framework splices custom middleware between its "before"
        // and "after" groups, so this holds by construction -- pinned here
        // because the ordering is invisible in messenger.yaml.
        self::assertLessThan(
            array_search(HandleMessageMiddleware::class, $middleware, true),
            array_search(RetryCountMiddleware::class, $middleware, true),
        );
    }
}
