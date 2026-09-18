<?php

declare(strict_types=1);

namespace App\Tests\Callback;

use App\Callback\StatusNotifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Which exception is thrown matters as much as whether one is, and most of this
 * file is about getting that split right: \InvalidArgumentException for what no
 * retry could fix, \RuntimeException for what might work next time.
 *
 * BuildJobHandler catches both and acks either way -- replaying this handler
 * would replay the whole build -- but the split is kept so the policy stays in
 * one visible catch there rather than being flattened away here.
 *
 * Refusals are proven by the request never being made
 * (getRequestsCount() === 0), not by a mock expectation -- the same idiom
 * ContentDownloaderTest uses.
 */
final class StatusNotifierTest extends TestCase
{
    private const URL = 'https://cloud.example.org/collectives/publish/1234-5678';
    private const BUILD = '16ef078ad37fd894';
    private const SITE = 'site-42';
    private const SLUG = 'demo-site';
    private const FINISHED_AT = '2026-09-16T12:00:00+00:00';

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

    private function notify(
        MockHttpClient $client,
        string $url = self::URL,
        string $status = StatusNotifier::STATUS_SUCCESS,
        ?string $error = null,
        int $pages = 0,
    ): void {
        (new StatusNotifier($client))->notify(
            callbackStatusUrl: $url,
            status: $status,
            buildId: self::BUILD,
            staticSiteId: self::SITE,
            slug: self::SLUG,
            finishedAt: self::FINISHED_AT,
            pages: $pages,
            error: $error,
        );
    }

    public function testPostsTheAgreedPayloadOnSuccess(): void
    {
        $seen = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('', ['http_code' => 200]);
        });

        $this->notify($client, pages: 7);

        self::assertSame('POST', $seen['method']);
        self::assertSame(self::URL, $seen['url']);
        self::assertContains('Content-Type: application/json', $seen['options']['headers']);

        // The published contract, documented in docs/build-pipeline.md.
        // build_id plus a terminal status is what lets the receiver dedupe,
        // which it has to do: delivery is at-least-once.
        self::assertSame([
            'build_id' => self::BUILD,
            'static_site_id' => self::SITE,
            'slug' => self::SLUG,
            'status' => 'success',
            'finished_at' => self::FINISHED_AT,
            'pages' => 7,
        ], json_decode($seen['options']['body'], true));
    }

    public function testIncludesTheReasonOnFailure(): void
    {
        $seen = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options;

            return new MockResponse('', ['http_code' => 200]);
        });

        $this->notify($client, status: StatusNotifier::STATUS_FAILED, error: 'Extracting failed: not in gzip format');

        $body = json_decode($seen['body'], true);
        self::assertSame('failed', $body['status']);
        self::assertSame('Extracting failed: not in gzip format', $body['error']);
        // A page count on a failed build would be meaningless.
        self::assertArrayNotHasKey('pages', $body);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function provideSuccessCodes(): array
    {
        return ['200' => [200], '201' => [201], '202' => [202], '204' => [204]];
    }

    #[DataProvider('provideSuccessCodes')]
    public function testAnyTwoHundredIsAcceptance(int $code): void
    {
        $this->notify(new MockHttpClient(new MockResponse('', ['http_code' => $code])));

        self::assertStringContainsString('reported success', (string) file_get_contents($this->logFile));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function provideRetryableCodes(): array
    {
        return [
            'server error' => [500],
            'bad gateway' => [502],
            'unavailable' => [503],
            'gateway timeout' => [504],
            // 4xx, but both are the endpoint asking us to come back.
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
     * @return array<string, array{int}>
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

    #[DataProvider('providePermanentCodes')]
    public function testPermanentClientErrorsParkImmediately(int $code): void
    {
        // Spending five attempts and two minutes on a URL that is simply wrong
        // helps nobody, and delays every other callback behind it.
        $this->expectException(\InvalidArgumentException::class);

        $this->notify(new MockHttpClient(new MockResponse('', ['http_code' => $code])));
    }

    public function testARedirectIsPermanentBecauseWeDoNotFollowIt(): void
    {
        // max_redirects is 0: a status callback that moved has to be re-supplied
        // by the client, not chased to wherever it now points.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('redirected');

        $this->notify(new MockHttpClient(new MockResponse('', ['http_code' => 301])));
    }

    public function testATransportFailureIsRetryable(): void
    {
        // DNS, connect and read failures are transient by nature.
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
     * BuildController requires callback_status_url but never validates it, so an
     * empty string reaches here intact. Failing the message would strand a build
     * that is otherwise complete.
     */
    public function testAnEmptyCallbackUrlIsSkippedWithoutFailingTheMessage(): void
    {
        $client = new MockHttpClient();

        $this->notify($client, url: '');

        self::assertSame(0, $client->getRequestsCount());
        self::assertStringContainsString('no callback_status_url', (string) file_get_contents($this->logFile));
    }

    /**
     * @return array<string, array{string}>
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
        ];
    }

    #[DataProvider('provideUncallableUrls')]
    public function testRefusesUrlsItWillNotCall(string $url): void
    {
        // SECURITY: this is an outbound POST with a body, aimed at a URL the
        // original API caller supplied and nothing upstream validated.
        $client = new MockHttpClient();

        try {
            $this->notify($client, url: $url);
            self::fail('Expected ' . $url . ' to be refused.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $client->getRequestsCount());
        }
    }
}
