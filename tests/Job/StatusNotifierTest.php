<?php

declare(strict_types=1);

namespace App\Tests\Job;

use App\Job\StatusNotifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Covers App\Job\StatusNotifier.
 *
 * Which exception is thrown matters as much as whether one is, and most of this file is about
 * that split: \InvalidArgumentException for what no retry could fix, \RuntimeException for what
 * might work next time.
 *
 * BuildJobHandler turns \InvalidArgumentException into UnrecoverableMessageHandlingException and
 * lets \RuntimeException propagate, so a retryable callback failure replays the whole build.
 *
 * A refusal is proven by the request never being made (getRequestsCount() === 0), the same idiom
 * ContentDownloaderTest uses.
 */
final class StatusNotifierTest extends TestCase
{
    private const URL = 'https://cloud.example.org/collectives/publish/1234-5678';
    private const BUILD = '16ef078ad37fd894';
    private const PUBLISH_URL = 'https://sites.example.org/11f5b798-6f34-4951-ad8b-bfd623ded5c2/demo-site/';

    private string $logFile;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/worker-notify-log-' . bin2hex(random_bytes(6)) . '.log';
        $this->previousErrorLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        @unlink($this->logFile);
    }

    /**
     * Reports a published site, filling the build id and publish url from the constants above.
     *
     * @param MockHttpClient $client The client to send the notification with.
     * @param string $url The callback url to post to.
     * @return void
     * @throws \InvalidArgumentException if no retry could succeed
     * @throws \RuntimeException         if the call is worth retrying
     */
    private function notify(MockHttpClient $client, string $url = self::URL): void
    {
        (new StatusNotifier($client))->notifyPublished(
            callbackStatusUrl: $url,
            buildId: self::BUILD,
            publishUrl: self::PUBLISH_URL,
        );
    }

    /**
     * Returns a client that records the options of the one request it receives into $seen.
     *
     * @param ?array<string, mixed> $seen Receives the method, url and options of the request.
     * @return MockHttpClient the recording client
     */
    private function recordingClient(?array &$seen): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('', ['http_code' => 200]);
        });
    }

    /** The published contract, specified in docs/build-pipeline.md; assertSame so no extra key can slip in. */
    public function testPostsStatusAndPublishUrlWhenPublished(): void
    {
        $seen = null;

        $this->notify($this->recordingClient($seen));

        self::assertSame('PUT', $seen['method']);
        self::assertSame(self::URL, $seen['url']);
        self::assertContains('Content-Type: application/json', $seen['options']['headers']);
        self::assertSame([
            'status' => 'published',
            'result' => ['publish_url' => self::PUBLISH_URL],
        ], json_decode($seen['options']['body'], true));
    }

    public function testPostsStatusAndErrorMessageWhenFailed(): void
    {
        $seen = null;

        (new StatusNotifier($this->recordingClient($seen)))->notifyFailed(
            callbackStatusUrl: self::URL,
            buildId: self::BUILD,
            errorMessage: 'Extracting failed: not in gzip format',
        );

        self::assertSame([
            'status' => 'failed',
            'result' => ['error_message' => 'Extracting failed: not in gzip format'],
        ], json_decode($seen['options']['body'], true));
    }

    /** Both methods share one guard, so a failure report is refused on the same terms. */
    public function testRefusesAnUncallableUrlWhenReportingAFailure(): void
    {
        $client = new MockHttpClient();

        try {
            (new StatusNotifier($client))->notifyFailed('file:///etc/passwd', self::BUILD, 'boom');
            self::fail('Expected file:///etc/passwd to be refused.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $client->getRequestsCount());
        }
    }

    /**
     * Response codes that count as the callback being accepted.
     *
     * @return array<string, array{int}> the status code per case name
     */
    public static function provideSuccessCodes(): array
    {
        return ['200' => [200], '201' => [201], '202' => [202], '204' => [204]];
    }

    #[DataProvider('provideSuccessCodes')]
    public function testAnyTwoHundredIsAcceptance(int $code): void
    {
        $this->notify(new MockHttpClient(new MockResponse('', ['http_code' => $code])));

        self::assertStringContainsString('reported published', (string) file_get_contents($this->logFile));
    }

    /**
     * Response codes worth another attempt.
     *
     * @return array<string, array{int}> the status code per case name
     */
    public static function provideRetryableCodes(): array
    {
        return [
            'server error' => [500],
            'bad gateway' => [502],
            'unavailable' => [503],
            'gateway timeout' => [504],

            /** 4xx, but both are the endpoint asking us to come back. */
            'request timeout' => [408],
            'too many requests' => [429],
        ];
    }

    #[DataProvider('provideRetryableCodes')]
    public function testRetryableResponsesThrowSomethingRetryable(int $code): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage((string) $code);

        try {
            $this->notify(new MockHttpClient(new MockResponse('', ['http_code' => $code])));
        } catch (\InvalidArgumentException $e) {
            self::fail(sprintf('HTTP %d must be retried, but was parked: %s', $code, $e->getMessage()));
        }
    }

    /**
     * Response codes that no retry could fix.
     *
     * @return array<string, array{int}> the status code per case name
     */
    public static function providePermanentCodes(): array
    {
        return [
            'bad request' => [400],
            'unauthorized' => [401],
            'forbidden' => [403],
            'not found' => [404],
            'gone' => [410],
            'unprocessable' => [422],
        ];
    }

    /** Every 4xx outside RETRYABLE_CLIENT_ERRORS is the caller's fault, so another attempt would fail the same way. */
    #[DataProvider('providePermanentCodes')]
    public function testPermanentClientErrorsParkImmediately(int $code): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->notify(new MockHttpClient(new MockResponse('', ['http_code' => $code])));
    }

    /** max_redirects is 0: a callback that moved has to be re-supplied by the client, not followed to wherever it now points. */
    public function testARedirectIsPermanentBecauseWeDoNotFollowIt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('redirected');

        $this->notify(new MockHttpClient(new MockResponse('', ['http_code' => 301])));
    }

    /** DNS, connect and read failures are transient, so they are worth another attempt. */
    public function testATransportFailureIsRetryable(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('connection refused');
        });

        $this->expectException(\RuntimeException::class);

        try {
            $this->notify($client);
        } catch (\InvalidArgumentException $e) {
            self::fail('A transport failure must be retried, but was parked: ' . $e->getMessage());
        }
    }

    /**
     * Urls the notifier must refuse without issuing a request.
     *
     * @return array<string, array{string}> the url per case name
     */
    public static function provideUncallableUrls(): array
    {
        return [
            'file scheme' => ['file:///etc/passwd'],
            'ftp scheme' => ['ftp://example.org/x'],
            'php wrapper' => ['php://input'],
            'no scheme' => ['cloud.example.org/status'],
            'no host' => ['https:///status'],
            'not a url' => ['not a url at all'],

            /** BuildController requires callback_status_url but never validates it, so an empty string reaches here intact. */
            'empty' => [''],
        ];
    }

    /**
     * This is an outbound PUT with a body, aimed at a url the original API caller supplied.
     * Nothing upstream validates it, so the scheme and host check here is the only one.
     */
    #[DataProvider('provideUncallableUrls')]
    public function testRefusesUrlsItWillNotCall(string $url): void
    {
        $client = new MockHttpClient();

        try {
            $this->notify($client, url: $url);
            self::fail('Expected ' . $url . ' to be refused.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $client->getRequestsCount());
        }
    }
}
