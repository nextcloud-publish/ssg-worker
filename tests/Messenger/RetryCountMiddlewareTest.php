<?php

declare(strict_types=1);

namespace App\Tests\Messenger;

use App\Message\BuildJob;
use App\Messenger\RetryCountMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\HandlerArgumentsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * A real StackMiddleware rather than a mock, matching the rest of this repo:
 * the classes involved are final and the thing worth asserting is what the
 * next middleware actually sees.
 */
final class RetryCountMiddlewareTest extends TestCase
{
    private function job(): BuildJob
    {
        return new BuildJob(
            build_id: '16ef078ad37fd894',
            static_site_id: 'site-42',
            slug: 'demo-site',
            content_download_url: 'https://example.org/c.tar.gz',
            callback_status_url: 'https://example.org/status',
            created_at: '2026-09-17T10:00:00+00:00',
            title: 'My Team Handbook',
        );
    }

    /** The stamp the next middleware in the chain would see, if any. */
    private function stampFor(Envelope $envelope): ?HandlerArgumentsStamp
    {
        // A bare StackMiddleware is the end of the chain: it hands back the
        // envelope exactly as the next middleware would have received it.
        return (new RetryCountMiddleware())
            ->handle($envelope, new StackMiddleware())
            ->last(HandlerArgumentsStamp::class);
    }

    public function testAFirstDeliveryCarriesACountOfZeroAndNoError(): void
    {
        $stamp = $this->stampFor(new Envelope($this->job(), [new ReceivedStamp('builds')]));

        self::assertNotNull($stamp);
        self::assertSame([0, null], $stamp->getAdditionalArguments());
    }

    public function testARedeliveryCarriesItsRetryCount(): void
    {
        $stamp = $this->stampFor(new Envelope($this->job(), [
            new ReceivedStamp('builds'),
            new RedeliveryStamp(1),
        ]));

        self::assertSame(1, $stamp?->getAdditionalArguments()[0]);
    }

    /**
     * Messenger appends a RedeliveryStamp per attempt rather than replacing it,
     * so the LAST one is the current count. Getting this wrong would read a
     * third delivery as a first and rebuild forever.
     */
    public function testTheLastRedeliveryStampWins(): void
    {
        $stamp = $this->stampFor(new Envelope($this->job(), [
            new ReceivedStamp('builds'),
            new RedeliveryStamp(1),
            new RedeliveryStamp(2),
        ]));

        self::assertSame(2, $stamp?->getAdditionalArguments()[0]);
    }

    /**
     * The reason the previous attempt failed is what the reporting delivery
     * puts in the `failed` callback -- it never runs the build itself, so this
     * stamp is the only thing that knows what went wrong.
     */
    public function testThePreviousAttemptsErrorIsPassedThrough(): void
    {
        $stamp = $this->stampFor(new Envelope($this->job(), [
            new ReceivedStamp('builds'),
            new RedeliveryStamp(1),
            ErrorDetailsStamp::create(new \RuntimeException('tar: unexpected EOF in archive')),
        ]));

        self::assertSame(
            [1, 'tar: unexpected EOF in archive'],
            $stamp?->getAdditionalArguments(),
        );
    }

    /**
     * A locally dispatched message has no delivery history, and the handler's
     * defaults cover it. Stamping arguments onto a message whose handler did
     * not expect them would be a TypeError at call time.
     */
    public function testALocallyDispatchedMessageIsNotStamped(): void
    {
        $stamp = $this->stampFor(new Envelope($this->job()));

        self::assertNull($stamp);
    }

    public function testAnUnrelatedMessageIsNotStamped(): void
    {
        $stamp = $this->stampFor(new Envelope(new \stdClass(), [new ReceivedStamp('builds')]));

        self::assertNull($stamp);
    }
}
