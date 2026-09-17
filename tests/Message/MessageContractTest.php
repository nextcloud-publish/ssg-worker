<?php

declare(strict_types=1);

namespace App\Tests\Message;

use App\Message\BuildFailed;
use App\Message\BuildJob;
use App\Message\BuildSucceeded;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Pins the wire format of every message that crosses between this repo and
 * publish.
 *
 * WHY THIS EXISTS. App\Message\BuildJob, BuildSucceeded and BuildFailed are
 * duplicated BY HAND in both repos, held in sync by nothing but a comment.
 * Messenger's `type` header carries the fully-qualified class name and the
 * symfony_serializer maps JSON keys straight onto constructor arguments, so
 * renaming a property on one side is a decode failure on the other -- in
 * production, at runtime, with no test in either repo failing first. Every
 * assertion below is against a hard-coded literal for that reason: if this file
 * needs updating, the change is a contract change and the other repo needs the
 * same edit in the same commit.
 *
 * THE FIXTURES BELOW ARE SHARED. tests/Message/MessageContractTest.php in
 * publish contains the same constants; they must stay byte-identical.
 *
 * It asks the container for the app's OWN serializer rather than building one,
 * because a hand-built serializer would test the test rather than the app.
 */
final class MessageContractTest extends KernelTestCase
{
    private const BUILD_JOB_TYPE = 'App\Message\BuildJob';
    private const BUILD_SUCCEEDED_TYPE = 'App\Message\BuildSucceeded';
    private const BUILD_FAILED_TYPE = 'App\Message\BuildFailed';

    private const BUILD_JOB_BODY = '{'
        . '"build_id":"16ef078ad37fd894",'
        . '"static_site_id":"11f5b798-6f34-4951-ad8b-bfd623ded5c2",'
        . '"slug":"some_collective",'
        . '"content_download_url":"https:\/\/cloud.example.org\/collectives\/export",'
        . '"callback_status_url":"https:\/\/cloud.example.org\/collectives\/status\/1234",'
        . '"created_at":"2026-09-16T12:00:00+00:00"'
        . '}';

    private const BUILD_SUCCEEDED_BODY = '{'
        . '"build_id":"16ef078ad37fd894",'
        . '"static_site_id":"11f5b798-6f34-4951-ad8b-bfd623ded5c2",'
        . '"slug":"some_collective",'
        . '"pages":7,'
        . '"callback_status_url":"https:\/\/cloud.example.org\/collectives\/status\/1234",'
        . '"finished_at":"2026-09-16T12:00:00+00:00"'
        . '}';

    private const BUILD_FAILED_BODY = '{'
        . '"build_id":"16ef078ad37fd894",'
        . '"static_site_id":"11f5b798-6f34-4951-ad8b-bfd623ded5c2",'
        . '"callback_status_url":"https:\/\/cloud.example.org\/collectives\/status\/1234",'
        . '"error":"Extracting content.tar.gz failed (exit 2): not in gzip format",'
        . '"failed_at":"2026-09-16T12:00:00+00:00"'
        . '}';

    private function serializer(): SerializerInterface
    {
        self::bootKernel();

        // The same service both repos pin in messenger.yaml. Not the framework
        // default, which is PHP serialize() and would make the body unreadable
        // in the management UI and weld the format to this app's class names.
        return static::getContainer()->get('messenger.transport.symfony_serializer');
    }

    private static function buildJob(): BuildJob
    {
        return new BuildJob(
            build_id: '16ef078ad37fd894',
            static_site_id: '11f5b798-6f34-4951-ad8b-bfd623ded5c2',
            slug: 'some_collective',
            content_download_url: 'https://cloud.example.org/collectives/export',
            callback_status_url: 'https://cloud.example.org/collectives/status/1234',
            created_at: '2026-09-16T12:00:00+00:00',
        );
    }

    private static function buildSucceeded(): BuildSucceeded
    {
        return new BuildSucceeded(
            build_id: '16ef078ad37fd894',
            static_site_id: '11f5b798-6f34-4951-ad8b-bfd623ded5c2',
            slug: 'some_collective',
            pages: 7,
            callback_status_url: 'https://cloud.example.org/collectives/status/1234',
            finished_at: '2026-09-16T12:00:00+00:00',
        );
    }

    private static function buildFailed(): BuildFailed
    {
        return new BuildFailed(
            build_id: '16ef078ad37fd894',
            static_site_id: '11f5b798-6f34-4951-ad8b-bfd623ded5c2',
            callback_status_url: 'https://cloud.example.org/collectives/status/1234',
            error: 'Extracting content.tar.gz failed (exit 2): not in gzip format',
            failed_at: '2026-09-16T12:00:00+00:00',
        );
    }

    // --- encoding: what this repo puts on the wire ------------------------

    public function testEncodesBuildJobExactly(): void
    {
        $encoded = $this->serializer()->encode(new Envelope(self::buildJob()));

        self::assertSame(self::BUILD_JOB_TYPE, $encoded['headers']['type']);
        self::assertJsonStringEqualsJsonString(self::BUILD_JOB_BODY, $encoded['body']);
    }

    public function testEncodesBuildSucceededExactly(): void
    {
        $encoded = $this->serializer()->encode(new Envelope(self::buildSucceeded()));

        self::assertSame(self::BUILD_SUCCEEDED_TYPE, $encoded['headers']['type']);
        self::assertJsonStringEqualsJsonString(self::BUILD_SUCCEEDED_BODY, $encoded['body']);
    }

    public function testEncodesBuildFailedExactly(): void
    {
        $encoded = $this->serializer()->encode(new Envelope(self::buildFailed()));

        self::assertSame(self::BUILD_FAILED_TYPE, $encoded['headers']['type']);
        self::assertJsonStringEqualsJsonString(self::BUILD_FAILED_BODY, $encoded['body']);
    }

    /**
     * The property names ARE the JSON keys, so a rename is a wire change. This
     * asserts the key set separately from the values, because that is the half
     * a careless rename breaks.
     */
    public function testTheJsonKeysAreThePropertyNames(): void
    {
        $serializer = $this->serializer();

        self::assertSame(
            ['build_id', 'static_site_id', 'slug', 'content_download_url', 'callback_status_url', 'created_at'],
            array_keys((array) json_decode($serializer->encode(new Envelope(self::buildJob()))['body'], true)),
        );

        self::assertSame(
            ['build_id', 'static_site_id', 'slug', 'pages', 'callback_status_url', 'finished_at'],
            array_keys((array) json_decode($serializer->encode(new Envelope(self::buildSucceeded()))['body'], true)),
        );

        self::assertSame(
            ['build_id', 'static_site_id', 'callback_status_url', 'error', 'failed_at'],
            array_keys((array) json_decode($serializer->encode(new Envelope(self::buildFailed()))['body'], true)),
        );
    }

    // --- decoding: what this repo accepts off the wire --------------------

    public function testDecodesBuildJobFromTheFixture(): void
    {
        $message = $this->serializer()
            ->decode(['body' => self::BUILD_JOB_BODY, 'headers' => ['type' => self::BUILD_JOB_TYPE]])
            ->getMessage();

        self::assertInstanceOf(BuildJob::class, $message);
        self::assertSame('16ef078ad37fd894', $message->build_id);
        self::assertSame('11f5b798-6f34-4951-ad8b-bfd623ded5c2', $message->static_site_id);
        self::assertSame('some_collective', $message->slug);
        self::assertSame('https://cloud.example.org/collectives/export', $message->content_download_url);
        self::assertSame('https://cloud.example.org/collectives/status/1234', $message->callback_status_url);
        self::assertSame('2026-09-16T12:00:00+00:00', $message->created_at);
    }

    public function testDecodesBuildSucceededFromTheFixture(): void
    {
        $message = $this->serializer()
            ->decode(['body' => self::BUILD_SUCCEEDED_BODY, 'headers' => ['type' => self::BUILD_SUCCEEDED_TYPE]])
            ->getMessage();

        self::assertInstanceOf(BuildSucceeded::class, $message);
        self::assertSame('16ef078ad37fd894', $message->build_id);
        self::assertSame('11f5b798-6f34-4951-ad8b-bfd623ded5c2', $message->static_site_id);
        self::assertSame('some_collective', $message->slug);
        self::assertSame(7, $message->pages);
        self::assertSame('https://cloud.example.org/collectives/status/1234', $message->callback_status_url);
        self::assertSame('2026-09-16T12:00:00+00:00', $message->finished_at);
    }

    public function testDecodesBuildFailedFromTheFixture(): void
    {
        $message = $this->serializer()
            ->decode(['body' => self::BUILD_FAILED_BODY, 'headers' => ['type' => self::BUILD_FAILED_TYPE]])
            ->getMessage();

        self::assertInstanceOf(BuildFailed::class, $message);
        self::assertSame('16ef078ad37fd894', $message->build_id);
        self::assertSame('11f5b798-6f34-4951-ad8b-bfd623ded5c2', $message->static_site_id);
        self::assertSame('https://cloud.example.org/collectives/status/1234', $message->callback_status_url);
        self::assertSame('Extracting content.tar.gz failed (exit 2): not in gzip format', $message->error);
        self::assertSame('2026-09-16T12:00:00+00:00', $message->failed_at);
    }
}
