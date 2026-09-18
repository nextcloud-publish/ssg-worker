<?php

declare(strict_types=1);

namespace App\Tests\Messenger;

use App\Message\BuildJob;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Pins the one thing BuildJobHandler's failure reporting depends on and cannot
 * verify for itself: that a retried message still carries its retry count and
 * the previous attempt's error after a round trip through the transport
 * serializer.
 *
 * WHY THIS IS NOT PARANOIA. ErrorDetailsStamp holds a FlattenException, whose
 * $dataRepresentation is a VarDumper Data object with a required constructor
 * argument. Denormalized by ObjectNormalizer alone that throws, and a stamp
 * that cannot be decoded fails the WHOLE envelope: decode() hands back a
 * MessageDecodingFailedException, which is not UnrecoverableExceptionInterface,
 * so it burns the retry budget and is then dropped -- no callback at all, for
 * every failed build. It works only because the container's serializer has
 * ProblemNormalizer ahead of ObjectNormalizer, which flattens the exception to
 * a small problem document.
 *
 * So the serializer is pulled FROM THE CONTAINER on purpose. The standalone
 * Serializer::create() chain does not have ProblemNormalizer and fails this
 * test, which would be a false alarm; a hand-built chain would pass while the
 * app's own config had regressed. Only the real service proves the real path.
 *
 * This is also the first kernel-booting test in the repo, so it doubles as the
 * check that the container compiles at all.
 */
final class StampRoundTripTest extends KernelTestCase
{
    private const ERROR = 'Extracting /opt/ssg/build_temp/site-42/abc/input/content.tar.gz failed (exit 2): tar: unexpected EOF';

    private function serializer(): SerializerInterface
    {
        self::bootKernel();

        /** @var SerializerInterface $serializer */
        $serializer = self::getContainer()->get('messenger.transport.symfony_serializer');

        return $serializer;
    }

    private function job(): BuildJob
    {
        return new BuildJob(
            build_id: '16ef078ad37fd894',
            static_site_id: 'site-42',
            slug: 'demo-site',
            content_download_url: 'https://cloud.example.org/export.tar.gz',
            callback_status_url: 'https://cloud.example.org/status',
            created_at: '2026-09-17T10:00:00+00:00',
            title: 'My Team Handbook',
        );
    }

    public function testARetriedEnvelopeStillDecodes(): void
    {
        $serializer = $this->serializer();

        $envelope = new Envelope($this->job(), [
            new RedeliveryStamp(1),
            ErrorDetailsStamp::create(new \RuntimeException(self::ERROR)),
        ]);

        $decoded = $serializer->decode($serializer->encode($envelope));

        // Not a MessageDecodingFailedException masquerading as a message.
        self::assertInstanceOf(BuildJob::class, $decoded->getMessage());
        self::assertNotInstanceOf(MessageDecodingFailedException::class, $decoded->getMessage());
    }

    public function testTheRetryCountSurvivesTheRoundTrip(): void
    {
        $serializer = $this->serializer();

        $envelope = new Envelope($this->job(), [
            new RedeliveryStamp(2),
            ErrorDetailsStamp::create(new \RuntimeException(self::ERROR)),
        ]);

        $decoded = $serializer->decode($serializer->encode($envelope));

        // This is what the handler's gate reads. Lose it and every delivery
        // looks like a first one, so the build retries forever.
        self::assertSame(2, RedeliveryStamp::getRetryCountFromEnvelope($decoded));
    }

    public function testThePreviousErrorSurvivesTheRoundTrip(): void
    {
        $serializer = $this->serializer();

        $envelope = new Envelope($this->job(), [
            new RedeliveryStamp(1),
            ErrorDetailsStamp::create(new \RuntimeException(self::ERROR)),
        ]);

        $decoded = $serializer->decode($serializer->encode($envelope));

        // Best-effort in the handler, which falls back to a fixed sentence --
        // but when it does arrive it has to be the real reason, because the
        // reporting delivery never runs the build and has no other source.
        self::assertSame(
            self::ERROR,
            $decoded->last(ErrorDetailsStamp::class)?->getExceptionMessage(),
        );
    }

    /** The whole message, not just the stamps: title is the newest field. */
    public function testTheMessageItselfSurvivesIntact(): void
    {
        $serializer = $this->serializer();

        $decoded = $serializer->decode($serializer->encode(new Envelope($this->job())));

        $message = $decoded->getMessage();
        self::assertInstanceOf(BuildJob::class, $message);
        self::assertSame('demo-site', $message->slug);
        self::assertSame('My Team Handbook', $message->title);
        self::assertSame('16ef078ad37fd894', $message->build_id);
    }
}
