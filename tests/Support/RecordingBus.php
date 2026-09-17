<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A real MessageBusInterface that keeps what it was handed.
 *
 * Not a mock: the rest of this suite deliberately uses real collaborators (the
 * job pipeline classes are final and cannot be mocked anyway), and asserting on
 * what a bus actually received reads better than a mock expectation.
 *
 * Optionally throws instead, so a test can cover the case the handler treats as
 * genuinely different from a failed build: the broker being unreachable while
 * reporting an outcome.
 */
final class RecordingBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public function __construct(private readonly ?\Throwable $throwOnDispatch = null)
    {
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if ($this->throwOnDispatch !== null) {
            throw $this->throwOnDispatch;
        }

        $this->dispatched[] = $message;

        return new Envelope($message, $stamps);
    }

    public function last(): ?object
    {
        return $this->dispatched === [] ? null : $this->dispatched[array_key_last($this->dispatched)];
    }

    public function count(): int
    {
        return \count($this->dispatched);
    }
}
