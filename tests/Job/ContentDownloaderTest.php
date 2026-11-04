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
 * would otherwise need a slow or broken server.
 */
final class ContentDownloaderTest extends TestCase
{
    /** A Collectives content download url with the structure the real one has. */
    private const CONTENT_URL = 'https://some-nextcloud.org/apps/collectives/some-collective-1234/publish/markdown_bundle';

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
     * Creates a downloader with the defaults from services.yaml.
     *
     * @param MockHttpClient $client The client to download with.
     * @return ContentDownloader the downloader
     */
    private function contentDownloader(MockHttpClient $client): ContentDownloader
    {
        return new ContentDownloader($client, maxDurationSeconds: 300, timeoutSeconds: 30, maxRedirects: 3);
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

        $path = $this->contentDownloader($client)
            ->download(self::CONTENT_URL, $this->targetDir);

        self::assertSame($this->target(), $path);
        self::assertFileExists($path);
        self::assertSame('tar-gz-bytes', file_get_contents($path));
    }

    /** The url puts a traversal attempt in its last path segment, which a naive basename() would use as the filename. */
    public function testWritesUnderAFixedFilenameNotTheUrlBasename(): void
    {
        $client = new MockHttpClient(new MockResponse('bytes'));

        $path = $this->contentDownloader($client)->download(
            'https://some-nextcloud.org/apps/collectives/some-collective-1234/publish/evil%2F..%2Fname.tar.gz',
            $this->targetDir,
        );

        self::assertSame($this->targetDir . '/content.tar.gz', $path);
        self::assertSame(['content.tar.gz'], array_values(array_diff(scandir($this->targetDir), ['.', '..'])));
    }

    /**
     * Response statuses the downloader must treat as a failure.
     *
     * @return array<int, array{int}> the status code, keyed by itself
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
            $this->contentDownloader($client)->download(self::CONTENT_URL, $this->targetDir);
            self::fail('Expected a RuntimeException for HTTP ' . $status);
        } catch (\RuntimeException $e) {
            self::assertStringContainsString((string) $status, $e->getMessage());

            /** No partial file is left that a later step could take for a real archive. */
            self::assertFileDoesNotExist($this->target());
        }
    }

    public function testLeavesNoPartialFileWhenTheTransferBreaksMidStream(): void
    {
        $brokenBody = (static function (): \Generator {
            yield 'first chunk';

            throw new TransportException('connection reset');
        })();

        $client = new MockHttpClient(new MockResponse($brokenBody));

        try {
            $this->contentDownloader($client)->download(self::CONTENT_URL, $this->targetDir);
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

        $this->contentDownloader($client)
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
            $this->contentDownloader($client)->download(self::CONTENT_URL, $readOnly);
            self::fail('Expected a RuntimeException for an unwritable directory.');
        } catch (\RuntimeException $e) {
            /** The filesystem's own reason travels in the message. */
            self::assertStringContainsString('Permission denied', $e->getMessage());
        } finally {
            chmod($readOnly, 0o700);
        }
    }
}
