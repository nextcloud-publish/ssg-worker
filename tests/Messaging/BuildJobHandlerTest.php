<?php

declare(strict_types=1);

namespace App\Tests\Messaging;

use App\Content\ContentDownloader;
use App\Messaging\BuildJobHandler;
use App\Storage\JobWorkspace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Real collaborators rather than test doubles: both are final, and this way
 * the handler's rejections are proven by the request never being issued
 * (getRequestsCount() === 0) instead of by a mock expectation.
 */
final class BuildJobHandlerTest extends TestCase
{
    private const SITE_ID = '11f5b798-6f34-4951-ad8b-bfd623ded5c2';

    private string $baseDir;
    private MockHttpClient $client;
    private string $logFile;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        // Left uncreated: the handler provisions it, so its absence up front
        // is what proves it did.
        $this->baseDir = sys_get_temp_dir() . '/worker-job-test-' . bin2hex(random_bytes(6));

        // A factory, not one response: a rebuild downloads a second time.
        $this->client = new MockHttpClient(static fn (): MockResponse => new MockResponse('tar-gz-bytes'));

        // error_log() writes to stderr/syslog by default, neither of which
        // a test can assert against. Restored in tearDown().
        $this->logFile = sys_get_temp_dir() . '/worker-job-log-' . bin2hex(random_bytes(6)) . '.log';
        $this->previousErrorLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        @unlink($this->logFile);

        if (is_dir($this->baseDir)) {
            exec('rm -rf ' . escapeshellarg($this->baseDir));
        }
    }

    private function handler(): BuildJobHandler
    {
        return new BuildJobHandler(
            new ContentDownloader($this->client, maxMegabytes: 1),
            new JobWorkspace($this->baseDir),
        );
    }

    private function jobDir(): string
    {
        return $this->baseDir . '/' . self::SITE_ID;
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function message(array $overrides = []): string
    {
        return (string) json_encode($overrides + [
            'static_site_id' => self::SITE_ID,
            'slug' => 'integration_test_collective',
            'content_download_url' => 'http://content-host/legit_sample.tar.gz',
            'callback_status_url' => '',
            'created_at' => '2026-09-03T13:00:09+00:00',
        ]);
    }

    public function testCreatesInputAndOutputDirectoriesForTheJob(): void
    {
        self::assertDirectoryDoesNotExist($this->jobDir());

        $this->handler()->handle($this->message());

        self::assertDirectoryExists($this->jobDir() . '/input');
        self::assertDirectoryExists($this->jobDir() . '/output');
    }

    public function testDownloadsIntoTheJobsInputFolder(): void
    {
        $this->handler()->handle($this->message());

        self::assertSame(1, $this->client->getRequestsCount());
        self::assertFileExists($this->jobDir() . '/input/' . ContentDownloader::FILENAME);
        self::assertSame(
            'tar-gz-bytes',
            file_get_contents($this->jobDir() . '/input/' . ContentDownloader::FILENAME),
        );
        // output/ is the next step's business; nothing should land there yet.
        self::assertSame([], array_values(array_diff(scandir($this->jobDir() . '/output'), ['.', '..'])));
    }

    public function testLogsThePreparedWorkdir(): void
    {
        // The only record a job leaves behind, so it has to name the folder
        // -- otherwise it says nothing about which job, or which volume.
        $this->handler()->handle($this->message());

        self::assertStringContainsString($this->jobDir(), (string) file_get_contents($this->logFile));
    }

    public function testIsIdempotentAcrossRebuildsOfTheSameSite(): void
    {
        // An existing workspace is the normal case, and its contents survive.
        $this->handler()->handle($this->message());
        file_put_contents($this->jobDir() . '/input/keep.md', '# keep');

        $this->handler()->handle($this->message());

        self::assertFileExists($this->jobDir() . '/input/keep.md');
        self::assertSame(2, $this->client->getRequestsCount());
    }

    public function testRejectsMalformedJson(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');

        $this->handler()->handle('{not json');
    }

    public function testRejectsJsonThatIsNotAnObject(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a JSON object');

        $this->handler()->handle('"just a string"');
    }

    public function testRejectsAMissingContentDownloadUrl(): void
    {
        $this->assertRejects(
            (string) json_encode(['static_site_id' => self::SITE_ID]),
            'content_download_url',
        );
    }

    public function testRejectsAnEmptyContentDownloadUrl(): void
    {
        $this->assertRejects($this->message(['content_download_url' => '']), 'content_download_url');
    }

    public function testRejectsANonStringContentDownloadUrl(): void
    {
        $this->assertRejects($this->message(['content_download_url' => 42]), 'content_download_url');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideUnusableStaticSiteIds(): array
    {
        return [
            'missing' => ['{"slug":"some_collective"}'],
            'empty' => ['{"static_site_id":""}'],
            'not a string' => ['{"static_site_id":42}'],
            'null' => ['{"static_site_id":null}'],
        ];
    }

    #[DataProvider('provideUnusableStaticSiteIds')]
    public function testRejectsAMessageWithoutAUsableStaticSiteId(string $body): void
    {
        $this->assertRejects($body, 'static_site_id');

        // Checked before the workspace is provisioned, so nothing was created.
        self::assertDirectoryDoesNotExist($this->baseDir);
    }

    public function testAnUnsafeStaticSiteIdCreatesNothing(): void
    {
        // Duplicated from JobWorkspaceTest on purpose: only here does it
        // show a message off the queue cannot steer writes off the volume.
        $escapee = dirname($this->baseDir) . '/worker-escaped-' . bin2hex(random_bytes(6));

        try {
            $this->handler()->handle($this->message([
                'static_site_id' => '../' . basename($escapee),
            ]));
            self::fail('Expected an InvalidArgumentException for a traversal id.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('static_site_id', $e->getMessage());
            self::assertDirectoryDoesNotExist($escapee);
            self::assertDirectoryDoesNotExist($this->baseDir);
            self::assertSame(0, $this->client->getRequestsCount());
        }
    }

    /**
     * Every rejection must happen before anything is fetched or written. The
     * workspace itself may already exist -- static_site_id is validated first,
     * so a bad content_download_url is caught with the folders in place.
     */
    private function assertRejects(string $message, string $expectedInMessage): void
    {
        try {
            $this->handler()->handle($message);
            self::fail('Expected a RuntimeException mentioning ' . $expectedInMessage);
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($expectedInMessage, $e->getMessage());
            self::assertSame(0, $this->client->getRequestsCount());
            self::assertFileDoesNotExist($this->jobDir() . '/input/' . ContentDownloader::FILENAME);
        }
    }
}
