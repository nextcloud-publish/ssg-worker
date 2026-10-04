<?php

declare(strict_types=1);

namespace App\Tests\Message;

use App\Job\ArchiveExtractor;
use App\Job\ContentDownloader;
use App\Job\JobWorkspace;
use App\Job\SiteRenderer;
use App\Job\StatusNotifier;
use App\Message\BuildJob;
use App\Message\BuildJobHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Covers App\Message\BuildJobHandler with its real collaborators, which are final classes and
 * so cannot be mocked.
 *
 * The handler builds and, on success, publishes and reports. Every failure leaves by throwing:
 * the transport decides whether to retry, and BuildFailureHandler turns the last one into a
 * `failed` callback. So the assertions here are about what is thrown and what reaches disk,
 * while BuildFailureHandlerTest covers what the client is told.
 *
 * The handler takes an already-decoded BuildJob, so there are no tests here for malformed JSON
 * or missing keys. Messenger's serializer rejects those first.
 */
final class BuildJobHandlerTest extends TestCase
{
    private const SITE_ID = '11f5b798-6f34-4951-ad8b-bfd623ded5c2';
    private const BUILD_ID = '0199a1b2-3c4d-7e5f-8a9b-0c1d2e3f4a5b';
    private const SLUG = 'integration-test-collective';
    private const TITLE = 'Integration Test Collective';

    /** A Collectives content download url with the structure the real one has. */
    private const CONTENT_URL = 'https://some-nextcloud.org/apps/collectives/some-collective-1234/publish/markdown_bundle';

    private const CALLBACK_URL = 'https://cloud.example.org/collectives/publish/1234';

    private string $root;
    private string $archiveBytes;
    private MockHttpClient $client;
    private MockHttpClient $callbackClient;

    /** @var list<array{url: string, body: array<string, mixed>}> */
    private array $callbacks = [];
    private string $logFile;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        /** Left uncreated, so that the handler creating what it needs is what the tests prove. */
        $this->root = sys_get_temp_dir() . '/worker-job-test-' . bin2hex(random_bytes(6));

        /**
         * A real archive rather than a placeholder string, because the handler extracts what it downloads.
         * A factory rather than one response, because a retry downloads it again.
         */
        $this->archiveBytes = $this->sampleArchiveBytes();
        $this->client = new MockHttpClient(fn (): MockResponse => new MockResponse($this->archiveBytes));

        $this->callbackClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->callbacks[] = ['url' => $url, 'body' => json_decode($options['body'] ?? '{}', true)];

            return new MockResponse('', ['http_code' => 204]);
        });

        // error_log() writes to stderr or syslog by default, neither of which a test can assert against.
        $this->logFile = sys_get_temp_dir() . '/worker-job-log-' . bin2hex(random_bytes(6)) . '.log';
        $this->previousErrorLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        @unlink($this->logFile);

        if (is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    /**
     * A minimal replacement for legit_sample.tar.gz, with pages at the archive root like the real fixture.
     *
     * @return string the gzipped tar as raw bytes
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

    /**
     * A workspace on this test's two roots.
     *
     * @return JobWorkspace the workspace the handler builds through
     */
    private function workspace(): JobWorkspace
    {
        return new JobWorkspace($this->buildTemp(), $this->publishedRoot(), 'http://sites.test');
    }

    /**
     * The build scratch root for this test.
     *
     * @return string the JOB_STORAGE_DIR equivalent
     */
    private function buildTemp(): string
    {
        return $this->root . '/build_temp';
    }

    /**
     * The published root for this test.
     *
     * @return string the PUBLISHED_DIR equivalent
     */
    private function publishedRoot(): string
    {
        return $this->root . '/published';
    }

    /**
     * The handler under test, wired to real collaborators.
     *
     * @param ?MockHttpClient $contentClient The client to download with, or null for the sample-archive one.
     * @return BuildJobHandler the handler under test
     */
    private function handler(?MockHttpClient $contentClient = null): BuildJobHandler
    {
        $workspace = $this->workspace();

        return new BuildJobHandler(
            new ContentDownloader($contentClient ?? $this->client, maxMegabytes: 1, maxDurationSeconds: 300, timeoutSeconds: 30, maxRedirects: 3),
            new ArchiveExtractor(),
            new SiteRenderer(),
            $workspace,
            new StatusNotifier($this->callbackClient, timeoutSeconds: 5, maxDurationSeconds: 10, maxRedirects: 0),
        );
    }

    /**
     * A handler whose download always yields something that is not an archive.
     *
     * @return BuildJobHandler a handler that fails during extraction
     */
    private function failingHandler(): BuildJobHandler
    {
        return $this->handler(new MockHttpClient(fn (): MockResponse => new MockResponse('not an archive')));
    }

    /**
     * The job directory for a build under this test's roots.
     *
     * @param string $buildId The build id to resolve.
     * @return string the job directory path
     */
    private function buildJobDir(string $buildId = self::BUILD_ID): string
    {
        return $this->buildTemp() . '/' . $buildId;
    }

    /**
     * The live slug directory for this test's site under this test's roots.
     *
     * @param string $slug The slug to resolve.
     * @return string the published slug path
     */
    private function publishedSite(string $slug = self::SLUG): string
    {
        return $this->publishedRoot() . '/' . self::SITE_ID . '/' . $slug;
    }

    /**
     * The message as Messenger hands it over, already decoded.
     *
     * @param string $staticSiteId The static site id to put on the job.
     * @param string $slug The slug to put on the job.
     * @param string $contentDownloadUrl The archive url to put on the job.
     * @param string $buildId The build id to put on the job.
     * @param string $title The site title to put on the job.
     * @param string $callbackStatusUrl The callback url to put on the job.
     * @return BuildJob the message under test
     */
    private function job(
        string $staticSiteId = self::SITE_ID,
        string $slug = self::SLUG,
        string $contentDownloadUrl = self::CONTENT_URL,
        string $buildId = self::BUILD_ID,
        string $title = self::TITLE,
        string $callbackStatusUrl = self::CALLBACK_URL,
    ): BuildJob {
        return new BuildJob(
            build_id: $buildId,
            static_site_id: $staticSiteId,
            slug: $slug,
            content_download_url: $contentDownloadUrl,
            callback_status_url: $callbackStatusUrl,
            created_at: '2026-09-03T13:00:09+00:00',
            title: $title,
        );
    }

    /**
     * Asserts exactly one callback was sent and returns its body.
     *
     * @return array<string, mixed> the decoded callback body
     */
    private function onlyCallback(): array
    {
        self::assertCount(1, $this->callbacks, 'expected exactly one outcome callback');

        return $this->callbacks[0]['body'];
    }

    // --- the happy path ---------------------------------------------------

    public function testPublishesTheRenderedSiteUnderItsSiteIdAndSlug(): void
    {
        ($this->handler())($this->job());

        self::assertFileExists($this->publishedSite() . '/index.html');
        self::assertFileExists($this->publishedSite() . '/Cats/index.html');
    }

    /** Asserted mid-build, because a successful run ends with the whole job tree deleted. */
    public function testDownloadsExtractsAndRendersBeforePublishing(): void
    {
        $seen = [];
        $handler = $this->handler(new MockHttpClient(function () use (&$seen): MockResponse {
            $seen['input exists'] = is_dir($this->buildJobDir() . '/input');
            $seen['output exists'] = is_dir($this->buildJobDir() . '/output');

            return new MockResponse($this->archiveBytes);
        }));

        $handler($this->job());

        self::assertSame(['input exists' => true, 'output exists' => true], $seen);
    }

    /** input/ holds the archive and its fully extracted copy, the bulkiest thing on the volume, and nothing reads it again. */
    public function testTheBuildTreeIsClearedOnSuccess(): void
    {
        ($this->handler())($this->job());

        self::assertDirectoryDoesNotExist($this->buildJobDir());
        self::assertSame([], array_values(array_diff(scandir($this->buildTemp()), ['.', '..'])));
    }

    /**
     * Why `title` exists as a separate field.
     * The slug is a directory name and its allow-list excludes spaces, so it cannot also be the heading.
     */
    public function testTheTitleHeadsTheSiteWhileTheSlugNamesTheDirectory(): void
    {
        ($this->handler())($this->job(slug: 'demo-site', title: 'My Team Handbook'));

        $index = $this->publishedSite('demo-site') . '/index.html';
        self::assertFileExists($index);
        self::assertStringContainsString('My Team Handbook', (string) file_get_contents($index));
    }

    public function testReportsPublishedWithThePublishUrl(): void
    {
        ($this->handler())($this->job());

        self::assertSame(self::CALLBACK_URL, $this->callbacks[0]['url']);
        self::assertSame([
            'status' => 'published',
            'result' => ['publish_url' => 'http://sites.test/' . self::SITE_ID . '/' . self::SLUG . '/'],
        ], $this->onlyCallback());
    }

    /** The log is the only record a successful job leaves, so it has to name the folder, or it says neither which job nor which volume. */
    public function testLogsThePreparedWorkdir(): void
    {
        ($this->handler())($this->job());

        self::assertStringContainsString($this->buildJobDir(), (string) file_get_contents($this->logFile));
    }

    /**
     * A rebuild is a new build_id against the same site and slug, and it replaces the live site rather than merging into it.
     * A page deleted from the collective has to disappear.
     */
    public function testARebuildReplacesThePublishedSiteRatherThanMergingIntoIt(): void
    {
        ($this->handler())($this->job());
        file_put_contents($this->publishedSite() . '/removed-later.html', 'gone in the next build');

        ($this->handler())($this->job(buildId: '0199a1b2-3c4d-7e5f-8a9b-bbbbbbbbbbbb'));

        self::assertFileExists($this->publishedSite() . '/index.html');
        self::assertFileDoesNotExist($this->publishedSite() . '/removed-later.html');
        self::assertSame(2, $this->client->getRequestsCount());
    }

    // --- failures leave by throwing ---------------------------------------

    /**
     * A retryable failure is rethrown untouched so the transport redelivers it.
     * Reporting here would PUT a failure for a build that may still succeed, so BuildFailureHandler does it instead.
     */
    public function testARetryableFailureIsRethrownAndReportsNothing(): void
    {
        try {
            ($this->failingHandler())($this->job());
            self::fail('Expected the failure to be rethrown so Messenger can redeliver it.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Extracting', $e->getMessage());
            self::assertNotInstanceOf(UnrecoverableMessageHandlingException::class, $e);
        }

        self::assertSame([], $this->callbacks, 'the handler never reports an outcome itself');
    }

    /** The next attempt's reset() wipes the workdir, and clearing it here would throw away a build still in flight. */
    public function testARetryableFailureLeavesTheWorkdirForTheNextAttempt(): void
    {
        try {
            ($this->failingHandler())($this->job());
        } catch (\RuntimeException) {
            self::assertDirectoryExists($this->buildJobDir());
        }
    }

    // --- terminal failures are marked unrecoverable -----------------------

    /** An archive with no pages renders the same nothing however often it is fetched, so it is terminal rather than worth a retry. */
    public function testAnArchiveWithNoPagesIsTerminal(): void
    {
        $empty = sys_get_temp_dir() . '/worker-empty-fixture-' . bin2hex(random_bytes(6));
        mkdir($empty . '/nothing', 0o755, true);
        file_put_contents($empty . '/nothing/notes.txt', 'not markdown');
        $archive = $empty . '.tar.gz';
        exec(sprintf('tar -czf %s -C %s .', escapeshellarg($archive), escapeshellarg($empty)));
        $bytes = (string) file_get_contents($archive);
        exec('rm -rf ' . escapeshellarg($empty) . ' ' . escapeshellarg($archive));

        $handler = $this->handler(new MockHttpClient(fn (): MockResponse => new MockResponse($bytes)));

        try {
            $handler($this->job());
            self::fail('Expected an archive with no pages to be refused.');
        } catch (UnrecoverableMessageHandlingException $e) {
            /** The original reason has to survive the wrapping, or the callback is useless. */
            self::assertStringContainsString('no .md file found', $e->getMessage());
            self::assertInstanceOf(\InvalidArgumentException::class, $e->getPrevious());
        }
    }

    // --- the callback is never allowed to fail the message ----------------

    /**
     * A build nobody could be told about is not done.
     * The notifier's exception is allowed out, so the transport redelivers and the whole build re-runs.
     * That is expensive, and it is what stops a transient callback failure stranding a finished build.
     */
    public function testARetryableCallbackFailureFailsTheBuild(): void
    {
        $this->callbackClient = new MockHttpClient(new MockResponse('', ['http_code' => 503]));

        try {
            ($this->handler())($this->job());
            self::fail('Expected an unreachable callback to fail the message.');
        } catch (\RuntimeException $e) {
            self::assertNotInstanceOf(UnrecoverableMessageHandlingException::class, $e);
            self::assertStringContainsString('503', $e->getMessage());
        }

        /** The site went live regardless, because publishing happens before the PUT. */
        self::assertFileExists($this->publishedSite() . '/index.html');
    }

    /** A 404 on the callback will not start working on the retry, and retrying costs a full rebuild. */
    public function testAPermanentlyRejectedCallbackIsNotRetried(): void
    {
        $this->callbackClient = new MockHttpClient(new MockResponse('', ['http_code' => 404]));

        $this->expectException(UnrecoverableMessageHandlingException::class);

        ($this->handler())($this->job());
    }
}
