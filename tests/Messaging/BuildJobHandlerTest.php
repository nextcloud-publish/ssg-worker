<?php

declare(strict_types=1);

namespace App\Tests\Messaging;

use App\Content\ArchiveExtractor;
use App\Content\ContentDownloader;
use App\Message\BuildJob;
use App\Messaging\BuildJobHandler;
use App\Rendering\SiteRenderer;
use App\Storage\JobWorkspace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Real collaborators rather than test doubles: they are final, and this way
 * the handler's rejections are proven by the request never being issued
 * (getRequestsCount() === 0) instead of by a mock expectation.
 *
 * The handler takes a decoded BuildJob, so there is nothing here about
 * malformed JSON or missing keys -- Messenger's serializer rejects those
 * before the handler is reached, and its transport is what logs and acks them.
 */
final class BuildJobHandlerTest extends TestCase
{
    private const SITE_ID = '11f5b798-6f34-4951-ad8b-bfd623ded5c2';

    /**
     * What a real Collectives publish endpoint looks like. Never actually
     * fetched -- MockHttpClient answers before anything leaves the process.
     */
    private const CONTENT_URL = 'https://some-nextcloud.org/apps/collectives/some-collective-1234/publish/markdown_bundle';

    private string $baseDir;
    private string $archiveBytes;
    private MockHttpClient $client;
    private string $logFile;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        // Left uncreated: the handler provisions it, so its absence up front
        // is what proves it did.
        $this->baseDir = sys_get_temp_dir() . '/worker-job-test-' . bin2hex(random_bytes(6));

        // A real archive, not a placeholder string: the handler extracts what
        // it downloads, so the response body has to be a valid tar.gz. Served
        // from a factory rather than one response: a rebuild downloads twice.
        $this->archiveBytes = $this->sampleArchiveBytes();
        $this->client = new MockHttpClient(fn (): MockResponse => new MockResponse($this->archiveBytes));

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

    /**
     * A minimal stand-in for legit_sample.tar.gz: pages at the archive root,
     * matching the shape of the real fixture.
     */
    private function sampleArchiveBytes(): string
    {
        $staging = sys_get_temp_dir() . '/worker-job-fixture-' . bin2hex(random_bytes(6));
        mkdir($staging . '/Cats', 0o755, true);
        file_put_contents($staging . '/Readme.md', '# sample');
        file_put_contents($staging . '/Cats/Readme.md', '# cats');

        $archive = $staging . '.tar.gz';
        exec(sprintf('tar -czf %s -C %s .', escapeshellarg($archive), escapeshellarg($staging)), $out, $code);
        self::assertSame(0, $code, 'fixture archive could not be built');

        $bytes = (string) file_get_contents($archive);
        exec('rm -rf ' . escapeshellarg($staging) . ' ' . escapeshellarg($archive));

        return $bytes;
    }

    private function handler(): BuildJobHandler
    {
        return new BuildJobHandler(
            new ContentDownloader($this->client, maxMegabytes: 1),
            new ArchiveExtractor(),
            new SiteRenderer(),
            new JobWorkspace($this->baseDir),
        );
    }

    private function jobDir(): string
    {
        return $this->baseDir . '/' . self::SITE_ID;
    }

    /**
     * The message as Messenger hands it over, already decoded.
     */
    private function job(
        string $staticSiteId = self::SITE_ID,
        string $slug = 'integration_test_collective',
        string $contentDownloadUrl = self::CONTENT_URL,
    ): BuildJob {
        return new BuildJob(
            build_id: '16ef078ad37fd894',
            static_site_id: $staticSiteId,
            slug: $slug,
            content_download_url: $contentDownloadUrl,
            callback_status_url: '',
            created_at: '2026-09-03T13:00:09+00:00',
        );
    }

    public function testCreatesInputAndOutputDirectoriesForTheJob(): void
    {
        self::assertDirectoryDoesNotExist($this->jobDir());

        ($this->handler())($this->job());

        self::assertDirectoryExists($this->jobDir() . '/input');
        self::assertDirectoryExists($this->jobDir() . '/output');
    }

    public function testDownloadsExtractsAndRendersIntoTheJobsFolders(): void
    {
        ($this->handler())($this->job());

        self::assertSame(1, $this->client->getRequestsCount());

        $unarchived = $this->jobDir() . '/input/' . BuildJobHandler::UNARCHIVED_DIR;

        // The archive stays in input/, its contents go one level down.
        self::assertFileExists($this->jobDir() . '/input/' . ContentDownloader::FILENAME);
        self::assertSame('# sample', file_get_contents($unarchived . '/Readme.md'));
        self::assertSame('# cats', file_get_contents($unarchived . '/Cats/Readme.md'));

        // The point of the dedicated folder: what the site generator is handed
        // contains pages only, with no archive sitting in the middle of them.
        self::assertFileDoesNotExist($unarchived . '/' . ContentDownloader::FILENAME);
        self::assertSame(
            ['Cats', 'Readme.md'],
            array_values(array_diff(scandir($unarchived), ['.', '..'])),
        );

        // And input/ itself holds exactly the archive and that one folder.
        $inInput = array_values(array_diff(scandir($this->jobDir() . '/input'), ['.', '..']));
        sort($inInput);
        self::assertSame([ContentDownloader::FILENAME, BuildJobHandler::UNARCHIVED_DIR], $inInput);

        // And the site was rendered from that folder into output/.
        $index = $this->jobDir() . '/output/index.html';
        self::assertFileExists($index);
        self::assertFileExists($this->jobDir() . '/output/Cats/index.html');

        // The slug from the message is what titles the site.
        self::assertStringContainsString(
            'integration_test_collective',
            (string) file_get_contents($index),
        );
    }

    public function testLogsThePreparedWorkdir(): void
    {
        // The only record a job leaves behind, so it has to name the folder
        // -- otherwise it says nothing about which job, or which volume.
        ($this->handler())($this->job());

        self::assertStringContainsString($this->jobDir(), (string) file_get_contents($this->logFile));
    }

    public function testIsIdempotentAcrossRebuildsOfTheSameSite(): void
    {
        // An existing workspace is the normal case, and its contents survive.
        ($this->handler())($this->job());
        file_put_contents($this->jobDir() . '/input/keep.md', '# keep');

        ($this->handler())($this->job());

        self::assertFileExists($this->jobDir() . '/input/keep.md');
        self::assertSame(2, $this->client->getRequestsCount());
        self::assertFileExists($this->jobDir() . '/output/index.html');
    }

    public function testFailsWhenTheDownloadIsNotAValidArchive(): void
    {
        $handler = new BuildJobHandler(
            new ContentDownloader(new MockHttpClient(new MockResponse('not an archive')), maxMegabytes: 1),
            new ArchiveExtractor(),
            new SiteRenderer(),
            new JobWorkspace($this->baseDir),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Extracting');

        $handler($this->job());
    }

    /**
     * BuildJob types its fields as strings but cannot require them to be
     * non-empty, so the collaborators are still the guard. These two pin that
     * the typed message did not quietly drop the checks with the JSON parsing.
     */
    public function testRejectsAnEmptyStaticSiteId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('static_site_id');

        try {
            ($this->handler())($this->job(staticSiteId: ''));
        } finally {
            self::assertDirectoryDoesNotExist($this->baseDir);
            self::assertSame(0, $this->client->getRequestsCount());
        }
    }

    public function testRejectsAnEmptyContentDownloadUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            ($this->handler())($this->job(contentDownloadUrl: ''));
        } finally {
            self::assertSame(0, $this->client->getRequestsCount());
            self::assertFileDoesNotExist($this->jobDir() . '/input/' . ContentDownloader::FILENAME);
        }
    }

    public function testAnUnsafeStaticSiteIdCreatesNothing(): void
    {
        // Duplicated from JobWorkspaceTest on purpose: only here does it
        // show a message off the queue cannot steer writes off the volume.
        $escapee = dirname($this->baseDir) . '/worker-escaped-' . bin2hex(random_bytes(6));

        try {
            ($this->handler())($this->job(staticSiteId: '../' . basename($escapee)));
            self::fail('Expected an InvalidArgumentException for a traversal id.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('static_site_id', $e->getMessage());
            self::assertDirectoryDoesNotExist($escapee);
            self::assertDirectoryDoesNotExist($this->baseDir);
            self::assertSame(0, $this->client->getRequestsCount());
        }
    }
}
