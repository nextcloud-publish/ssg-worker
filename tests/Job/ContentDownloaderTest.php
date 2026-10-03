<?php

declare(strict_types=1);

namespace App\Tests\Job;

use App\Job\ContentDownloader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Covers App\Job\ContentDownloader.
 *
 * MockHttpClient replaces the network, so every case runs offline, including the ones that
 * would otherwise need a slow, huge or broken server.
 */
final class ContentDownloaderTest extends TestCase
{
    /** Large enough that tests not about the byte limit never reach it. */
    private const CAP_MB = 8;

    /** A Collectives content download url with the structure the real one has. */
    private const CONTENT_URL = 'https://some-nextcloud.org/apps/collectives/some-collective-1234/publish/markdown_bundle';

    /** The smallest cap the class accepts, and the same value in bytes. */
    private const SMALL_CAP_MB = 1;
    private const SMALL_CAP_BYTES = 1024 * 1024;

    private string $targetDir;

    protected function setUp(): void
    {
        $this->targetDir = sys_get_temp_dir() . '/worker-download-test-' . bin2hex(random_bytes(6));
        mkdir($this->targetDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->targetDir)) {
            exec('rm -rf ' . escapeshellarg($this->targetDir));
        }
    }

    /**
     * The path the downloader writes to under $this->targetDir.
     *
     * @return string the expected archive path
     */
    private function target(): string
    {
        return $this->targetDir . '/' . ContentDownloader::FILENAME;
    }

    public function testWritesTheResponseBodyAndReturnsThePath(): void
    {
        $client = new MockHttpClient(new MockResponse('tar-gz-bytes'));

        $path = (new ContentDownloader($client, self::CAP_MB))
            ->download(self::CONTENT_URL, $this->targetDir);

        self::assertSame($this->target(), $path);
        self::assertFileExists($path);
        self::assertSame('tar-gz-bytes', file_get_contents($path));
    }

    /** The url puts a traversal attempt in its last path segment, which a naive basename() would use as the filename. */
    public function testWritesUnderAFixedFilenameNotTheUrlBasename(): void
    {
        $client = new MockHttpClient(new MockResponse('bytes'));

        $path = (new ContentDownloader($client, self::CAP_MB))->download(
            'https://some-nextcloud.org/apps/collectives/some-collective-1234/publish/evil%2F..%2Fname.tar.gz',
            $this->targetDir,
        );

        self::assertSame($this->targetDir . '/content.tar.gz', $path);
        self::assertSame(['content.tar.gz'], array_values(array_diff(scandir($this->targetDir), ['.', '..'])));
    }

    /**
     * Urls the downloader must refuse without issuing a request.
     *
     * @return array<string, array{string}> the url per case name
     */
    public static function provideUnfetchableUrls(): array
    {
        return [
            'file scheme' => ['file:///etc/passwd'],
            'ftp scheme' => ['ftp://some-nextcloud.org/apps/collectives/some-collective-1234/publish/markdown_bundle'],
            'php wrapper' => ['php://filter/read=convert.base64-encode/resource=/etc/passwd'],
            'data uri' => ['data://text/plain;base64,SSBhbSBldmls'],
            'no scheme' => ['some-nextcloud.org/apps/collectives/some-collective-1234/publish/markdown_bundle'],
            'empty' => [''],
        ];
    }

    #[DataProvider('provideUnfetchableUrls')]
    public function testRefusesUrlsThatAreNotHttpWithoutIssuingARequest(string $url): void
    {
        $client = new MockHttpClient(new MockResponse('should never be fetched'));
        $downloader = new ContentDownloader($client, self::CAP_MB);

        try {
            $downloader->download($url, $this->targetDir);
            self::fail('Expected an InvalidArgumentException for ' . $url);
        } catch (\InvalidArgumentException) {
            /** The check runs before any request is made. */
            self::assertSame(0, $client->getRequestsCount());
            self::assertFileDoesNotExist($this->target());
        }
    }

    /**
     * Response statuses the downloader must treat as a failure.
     *
     * @return array<string, array{int}> the status code per case name
     */
    public static function provideErrorStatuses(): array
    {
        return [
            '404' => [404],
            '403' => [403],
            '500' => [500],
            '503' => [503],
        ];
    }

    #[DataProvider('provideErrorStatuses')]
    public function testThrowsOnNonSuccessStatus(int $status): void
    {
        $client = new MockHttpClient(new MockResponse('nope', ['http_code' => $status]));

        try {
            (new ContentDownloader($client, self::CAP_MB))->download(self::CONTENT_URL, $this->targetDir);
            self::fail('Expected a RuntimeException for HTTP ' . $status);
        } catch (\RuntimeException $e) {
            self::assertStringContainsString((string) $status, $e->getMessage());

            /** No partial file is left that a later step could take for a real archive. */
            self::assertFileDoesNotExist($this->target());
        }
    }

    public function testRefusesAnOversizedContentLengthBeforeStreaming(): void
    {
        $declared = self::SMALL_CAP_BYTES * 100;

        $client = new MockHttpClient(new MockResponse('', [
            'response_headers' => ['content-length' => (string) $declared],
        ]));

        try {
            (new ContentDownloader($client, maxMegabytes: self::SMALL_CAP_MB))
                ->download(self::CONTENT_URL, $this->targetDir);
            self::fail('Expected a RuntimeException for an oversized Content-Length.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString((string) $declared, $e->getMessage());
            self::assertFileDoesNotExist($this->target());
        }
    }

    /** The copy reads one byte past the cap to detect an overrun, so the exact limit still has to succeed. */
    public function testAcceptsABodyExactlyOnTheLimit(): void
    {
        $client = new MockHttpClient(new MockResponse(str_repeat('x', self::SMALL_CAP_BYTES)));

        $path = (new ContentDownloader($client, maxMegabytes: self::SMALL_CAP_MB))
            ->download(self::CONTENT_URL, $this->targetDir);

        self::assertSame(self::SMALL_CAP_BYTES, filesize($path));
    }

    public function testAbortsOneByteOverTheLimit(): void
    {
        $client = new MockHttpClient(new MockResponse(str_repeat('x', self::SMALL_CAP_BYTES + 1)));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeded the ' . self::SMALL_CAP_BYTES . ' byte limit');

        (new ContentDownloader($client, maxMegabytes: self::SMALL_CAP_MB))
            ->download(self::CONTENT_URL, $this->targetDir);
    }

    /**
     * No Content-Length header here.
     * A response can omit it or declare a false value, so the overrun is only caught by counting bytes as they arrive.
     */
    public function testAbortsWhenTheBodyExceedsTheLimit(): void
    {
        $client = new MockHttpClient(new MockResponse(str_repeat('x', self::SMALL_CAP_BYTES * 2)));

        try {
            (new ContentDownloader($client, maxMegabytes: self::SMALL_CAP_MB))
                ->download(self::CONTENT_URL, $this->targetDir);
            self::fail('Expected a RuntimeException once the limit was passed.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString((string) self::SMALL_CAP_BYTES, $e->getMessage());
            self::assertFileDoesNotExist($this->target());
        }
    }

    public function testRejectsALimitBelowOneMegabyte(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 1 MB');

        new ContentDownloader(new MockHttpClient(), maxMegabytes: 0);
    }

    public function testLeavesNoPartialFileWhenTheTransferBreaksMidStream(): void
    {
        $brokenBody = (static function (): \Generator {
            yield 'first chunk';

            throw new TransportException('connection reset');
        })();

        $client = new MockHttpClient(new MockResponse($brokenBody));

        try {
            (new ContentDownloader($client, self::CAP_MB))->download(self::CONTENT_URL, $this->targetDir);
            self::fail('Expected a RuntimeException when the transfer breaks.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('connection reset', $e->getMessage());
            self::assertFileDoesNotExist($this->target());
        }
    }

    public function testThrowsWhenTheTargetDirectoryDoesNotExist(): void
    {
        $client = new MockHttpClient(new MockResponse('bytes'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Target directory does not exist');

        (new ContentDownloader($client, self::CAP_MB))
            ->download(self::CONTENT_URL, $this->targetDir . '/missing');
    }

    public function testThrowsWhenTheTargetDirectoryIsNotWritable(): void
    {
        $readOnly = $this->targetDir . '/readonly';
        mkdir($readOnly, 0o500);

        if (is_writable($readOnly)) {
            // root ignores the mode bits, and the worker container runs as root.
            self::markTestSkipped('This user bypasses directory permissions.');
        }

        $client = new MockHttpClient(new MockResponse('bytes'));

        try {
            (new ContentDownloader($client, self::CAP_MB))->download(self::CONTENT_URL, $readOnly);
            self::fail('Expected a RuntimeException for an unwritable directory.');
        } catch (\RuntimeException $e) {
            /** The filesystem's own reason travels in the message. */
            self::assertStringContainsString('Permission denied', $e->getMessage());
        } finally {
            chmod($readOnly, 0o700);
        }
    }
}
