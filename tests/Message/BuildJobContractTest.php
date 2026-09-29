<?php

declare(strict_types=1);

namespace App\Tests\Message;

use App\Message\BuildJob;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Covers the wire contract of App\Message\BuildJob.
 *
 * BuildJob is hand-synced across two repositories: publish declares the same class name with
 * the same property names and encodes it, this service decodes it, and the `type` header links
 * the two. Nothing but a test like this notices when they drift.
 *
 * The fixtures below are literal JSON on purpose, so the test cannot agree with itself by
 * re-encoding an object from this repo.
 */
final class BuildJobContractTest extends KernelTestCase
{
    private const TYPE_HEADER = ['type' => BuildJob::class, 'Content-Type' => 'application/json'];

    /**
     * Boots the kernel and returns the transport serializer the builds transport uses.
     *
     * @return SerializerInterface the configured messenger transport serializer
     */
    private function serializer(): SerializerInterface
    {
        self::bootKernel();

        /** @var SerializerInterface $serializer */
        $serializer = self::getContainer()->get('messenger.transport.symfony_serializer');

        return $serializer;
    }

    /**
     * Decodes $body as a queue message and asserts it resolved to a BuildJob.
     *
     * @param array<string, mixed> $body The JSON payload to decode.
     * @return BuildJob the decoded message
     */
    private function decode(array $body): BuildJob
    {
        $envelope = $this->serializer()->decode([
            'body' => json_encode($body, JSON_THROW_ON_ERROR),
            'headers' => self::TYPE_HEADER,
        ]);

        $message = $envelope->getMessage();

        self::assertNotInstanceOf(
            MessageDecodingFailedException::class,
            $message,
            'the envelope failed to decode, which drops the build with no callback',
        );
        self::assertInstanceOf(BuildJob::class, $message);

        return $message;
    }

    /**
     * The payload publish sends today.
     *
     * @return array<string, mixed> the current wire format
     */
    private static function currentPayload(): array
    {
        return [
            'build_id' => '16ef078ad37fd894',
            'static_site_id' => '11f5b798-6f34-4951-ad8b-bfd623ded5c2',
            'slug' => 'my-team-handbook',
            'content_download_url' => 'https://cloud.example.org/collectives/publish/1234-5678',
            'callback_status_url' => 'https://cloud.example.org/collectives/publish/1234-5678',
            'created_at' => '2026-09-17T10:00:00+00:00',
            'title' => 'My Team Handbook',
        ];
    }

    public function testDecodesTheCurrentWireFormat(): void
    {
        $build = $this->decode(self::currentPayload());

        self::assertSame('16ef078ad37fd894', $build->build_id);
        self::assertSame('11f5b798-6f34-4951-ad8b-bfd623ded5c2', $build->static_site_id);
        self::assertSame('my-team-handbook', $build->slug);
        self::assertSame('https://cloud.example.org/collectives/publish/1234-5678', $build->content_download_url);
        self::assertSame('https://cloud.example.org/collectives/publish/1234-5678', $build->callback_status_url);
        self::assertSame('2026-09-17T10:00:00+00:00', $build->created_at);
        self::assertSame('My Team Handbook', $build->title);
    }

    /** Unknown keys are ignored rather than fatal, so publish can add a field this service does not know yet. */
    public function testAnUnknownFieldDoesNotBreakDecoding(): void
    {
        $build = $this->decode([...self::currentPayload(), 'some_future_field' => 'added by a newer publish']);

        self::assertSame('my-team-handbook', $build->slug);
    }

    /**
     * The property names are the JSON keys: symfony_serializer maps them verbatim onto constructor parameters.
     * Renaming one here without renaming it in publish breaks decoding, so the set is pinned.
     */
    public function testThePropertyNamesAreTheAgreedJsonKeys(): void
    {
        $parameters = (new \ReflectionClass(BuildJob::class))
            ->getConstructor()
            ?->getParameters() ?? [];

        self::assertSame(
            [
                'build_id',
                'static_site_id',
                'slug',
                'content_download_url',
                'callback_status_url',
                'created_at',
                'title',
            ],
            array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $parameters),
        );
    }

    /** ObjectNormalizer extracts getters when encoding, so a getter here would appear as an extra JSON key. */
    public function testTheMessageHasNoGetters(): void
    {
        $methods = (new \ReflectionClass(BuildJob::class))->getMethods(\ReflectionMethod::IS_PUBLIC);
        $names = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), $methods);

        self::assertSame(['__construct'], $names);
    }
}
