<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Message\BuildJob;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\HandlerArgumentsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Hands BuildJobHandler the two things it needs from the envelope and cannot
 * reach: how many times this message has already been delivered, and why the
 * last delivery failed.
 *
 * A handler is given the message, never the envelope. HandlerArgumentsStamp is
 * Messenger's supported way around that -- HandleMessageMiddleware appends its
 * contents to the handler's arguments -- and it is NonSendable, so it is
 * stripped before anything is written to the broker and can never arrive from
 * outside.
 *
 * Registered in config/packages/messenger.yaml. Custom bus middleware is
 * spliced between the framework's default "before" group and
 * send_message/handle_message, so this runs after the envelope is decoded and
 * before the handler, with no ordering directive needed.
 *
 * How far each value can be trusted:
 *
 *  - RedeliveryStamp is written by Messenger's own retry republish and is
 *    load-bearing for the transport. If it were lost the message would retry
 *    forever, so it is as reliable as the retry mechanism itself.
 *
 *  - ErrorDetailsStamp is added by AddErrorDetailsStampListener (priority 200)
 *    before SendFailedMessageForRetryListener (priority 100) republishes, so
 *    attempt N's message is on the envelope for delivery N+1. It travels as an
 *    X-Message-Stamp-... HEADER holding a FlattenException, and round-trips
 *    only because the container's serializer has ProblemNormalizer ahead of
 *    ObjectNormalizer. BEST-EFFORT, never a correctness dependency -- hence the
 *    nullable second argument and the handler's fallback.
 *    tests/Messenger/StampRoundTripTest.php pins it.
 */
final class RetryCountMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // Only for a BuildJob that came off a transport. A locally dispatched
        // message has no delivery history, and stamping extra arguments onto a
        // message whose handler does not accept them would be a TypeError at
        // call time rather than a config error at compile time.
        if ($envelope->getMessage() instanceof BuildJob && $envelope->last(ReceivedStamp::class) !== null) {
            $envelope = $envelope->with(new HandlerArgumentsStamp([
                RedeliveryStamp::getRetryCountFromEnvelope($envelope),
                $envelope->last(ErrorDetailsStamp::class)?->getExceptionMessage(),
            ]));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
