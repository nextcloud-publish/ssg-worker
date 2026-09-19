<?php

declare(strict_types=1);

namespace App\Tests\Message;

use App\Message\BuildJob;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * BuildJob is a hand-synced contract across two repositories: publish declares
 * the same class name with the same property names and encodes it, this
 * service decodes it, and the `type` header links the two. Nothing but a test
 * like this notices when they drift.
 *
 * The fixtures below are literal JSON on purpose -- the bytes that come off
 * the queue, not something re-encoded from an object in this repo, so the
 * test can't agree with itself regardless of what publish actually sends.
 *
 * The compatibility case below matters because an unrecognized key must not
 * break decoding: a future publish field this service doesn't know about yet
 * has to be ignored, not fatal.
 */
final class BuildJobContractTest extends KernelTestCase
{
    private const TYPE_HEADER = ['type' => BuildJob::class, 'Content-Type' => 'application/json'];

    private function serializer(): SerializerInterface
    {
        self::bootKernel();

        /** @var SerializerInterface $serializer */
        $serializer = self::getContainer()->get('messenger.transport.symfony_serializer');

        return $serializer;
    }

    /**
     * @param array<string, mixed> $body
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
     * @return array<string, mixed>
     */
    private static function currentPayload(): array
    {
        return [
            'build_id' => '16ef078ad37fd894',
            'static_site_id' => '1234-5678',
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
        self::assertSame('1234-5678', $build->static_site_id);
        self::assertSame('my-team-handbook', $build->slug);
        self::assertSame('https://cloud.example.org/collectives/publish/1234-5678', $build->content_download_url);
        self::assertSame('https://cloud.example.org/collectives/publish/1234-5678', $build->callback_status_url);
        self::assertSame('2026-09-17T10:00:00+00:00', $build->created_at);
        self::assertSame('My Team Handbook', $build->title);
    }

    /**
     * Unknown keys are ignored rather than fatal, so publish can add a field
     * this service doesn't know about yet without breaking decoding here.
     */
    public function testAnUnknownFieldDoesNotBreakDecoding(): void
    {
        $build = $this->decode([...self::currentPayload(), 'some_future_field' => 'added by a newer publish']);

        self::assertSame('my-team-handbook', $build->slug);
    }

    /**
     * The property names are the JSON keys -- Messenger's symfony_serializer
     * maps them verbatim onto constructor parameters. Renaming one here
     * without renaming it in publish breaks decoding, so the set is pinned.
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

    /**
     * ObjectNormalizer extracts getters on the encoding side, so a getter here
     * would appear as an extra JSON key on a message publish round-trips.
     */
    public function testTheMessageHasNoGetters(): void
    {
        $methods = (new \ReflectionClass(BuildJob::class))->getMethods(\ReflectionMethod::IS_PUBLIC);
        $names = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), $methods);

        self::assertSame(['__construct'], $names);
    }
}
