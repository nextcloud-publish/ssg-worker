<?php

declare(strict_types=1);

namespace App\Tests\Messenger;

use App\Message\BuildJob;
use App\Message\BuildFailureHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Asserts the retry budget the container actually built, rather than the YAML
 * that describes it.
 *
 * InMemoryTransportFactory discards the whole options array, so nothing under
 * `options:` in messenger.yaml is exercised by any test in this repo -- not
 * the queue name, the quorum argument, the exchange block, or the delays
 * exchange those retries need on the broker. retry_strategy is the one part
 * that's a real service, and this is the only place it's checked. See
 * docs/build-pipeline.md for the rest, which only the dev stack can reach.
 */
final class MessengerConfigTest extends KernelTestCase
{
    /** One retry, so two build attempts. Must match messenger.yaml. */
    private const MAX_RETRIES = 1;

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
     * Two build attempts: every delivery builds, so one retry is the budget.
     * The second failure leaves willRetry() false, which is what makes
     * BuildFailureHandler treat it as the outcome.
     */
    public function testTheTransportRedeliversExactlyOnce(): void
    {
        $strategy = $this->strategy();

        self::assertTrue($strategy->isRetryable($this->envelopeAtRetry(0)), 'a first failure must be retried');
        self::assertFalse(
            $strategy->isRetryable($this->envelopeAtRetry(self::MAX_RETRIES)),
            'the second failure is terminal -- BuildFailureHandler reports it',
        );
    }

    /**
     * Exact equality is only possible because jitter is 0, so this assertion
     * documents that setting as much as it checks the delay. Jitter would also
     * put a different number in the delay queue's name, declaring a fresh queue
     * per message per attempt.
     */
    public function testTheOnlyBackoffIsFifteenSeconds(): void
    {
        self::assertSame(15_000, $this->strategy()->getWaitingTime($this->envelopeAtRetry(0)));
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
     * Without this subscriber registered, a build that runs out of attempts is
     * dropped in silence: the handler reports only success, so nothing would
     * ever POST `failed`. Autoconfiguration is what tags it, so this catches a
     * services.yaml that stopped autoconfiguring as much as a missing class.
     */
    public function testTheFailureReporterIsSubscribedToTheWorkerEvent(): void
    {
        self::bootKernel();

        $listeners = self::getContainer()
            ->get('event_dispatcher')
            ->getListeners(WorkerMessageFailedEvent::class);

        $classes = array_map(
            static fn (callable $l): string => get_debug_type(\is_array($l) ? $l[0] : $l),
            $listeners,
        );

        self::assertContains(BuildFailureHandler::class, $classes);
    }
}
