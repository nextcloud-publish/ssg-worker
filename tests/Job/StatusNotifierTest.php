<?php

declare(strict_types=1);

namespace App\Tests\Job;

use App\Job\StatusNotifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Uses a closure-based MockHttpClient so each captured request's method, url
 * and JSON body can be asserted on, instead of a single canned MockResponse.
 */
final class StatusNotifierTest extends TestCase
{
    private const CALLBACK_URL = 'https://cloud.example.org/collectives/status/1234';

    private string $logFile;
    private string|false $previousErrorLog;

    /** @var list<array{method: string, url: string, options: array}> */
    private array $requests;

    protected function setUp(): void
    {
        $this->requests = [];

        // error_log() writes to stderr/syslog by default, neither of which
        // a test can assert against. Restored in tearDown().
        $this->logFile = sys_get_temp_dir() . '/worker-status-log-' . bin2hex(random_bytes(6)) . '.log';
        $this->previousErrorLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        @unlink($this->logFile);
    }

    private function capturingClient(MockResponse $response): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return $response;
        });
    }

    private function log(): string
    {
        return (string) file_get_contents($this->logFile);
    }

    public function testNotifySuccessPostsThePublishUrl(): void
    {
        $client = $this->capturingClient(new MockResponse());

        (new StatusNotifier($client))->notifySuccess(
            self::CALLBACK_URL,
            'https://publish.example.com/publish/some-site-id',
        );

        self::assertCount(1, $this->requests);
        self::assertSame('PATCH', $this->requests[0]['method']);
        self::assertSame(self::CALLBACK_URL, $this->requests[0]['url']);

        // Nextcloud's OCS API treats a request without this header as a
        // plain browser request and rejects it -- asserted loosely against
        // the normalized headers array, since its exact shape is an HTTP
        // client internal, not a documented contract.
        $headers = $this->requests[0]['options']['headers'] ?? [];
        self::assertArrayHasKey('ocs-apirequest', $headers);
        self::assertStringContainsString('true', implode(', ', (array) $headers['ocs-apirequest']));

        $body = json_decode((string) $this->requests[0]['options']['body'], true);
        self::assertSame([
            'status' => 'published',
            'data' => ['publish_url' => 'https://publish.example.com/publish/some-site-id'],
        ], $body);
    }

    public function testNotifyFailurePostsTheErrorMessage(): void
    {
        $client = $this->capturingClient(new MockResponse());

        (new StatusNotifier($client))->notifyFailure(self::CALLBACK_URL, 'Extracting failed: bad archive');

        self::assertCount(1, $this->requests);

        $body = json_decode((string) $this->requests[0]['options']['body'], true);
        self::assertSame([
            'status' => 'failed',
            'data' => ['error_message' => 'Extracting failed: bad archive'],
        ], $body);
    }

    public function testRefusesANonHttpCallbackUrlWithoutIssuingARequest(): void
    {
        $client = $this->capturingClient(new MockResponse());

        (new StatusNotifier($client))->notifySuccess('file:///etc/passwd', 'https://publish.example.com/publish/x');

        self::assertCount(0, $this->requests);
        self::assertStringContainsString('refusing to notify', $this->log());
    }

    public function testLogsButDoesNotThrowOnATransportFailure(): void
    {
        $client = $this->capturingClient(new MockResponse('', ['error' => 'Connection refused']));

        (new StatusNotifier($client))->notifySuccess(self::CALLBACK_URL, 'https://publish.example.com/publish/x');

        self::assertStringContainsString('status callback', $this->log());
        self::assertStringContainsString('failed', $this->log());
    }

    public function testLogsButDoesNotThrowOnANonSuccessStatus(): void
    {
        $client = $this->capturingClient(new MockResponse('', ['http_code' => 500]));

        (new StatusNotifier($client))->notifySuccess(self::CALLBACK_URL, 'https://publish.example.com/publish/x');

        self::assertStringContainsString('returned HTTP 500', $this->log());
    }
}
