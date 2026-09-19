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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Constructs BuildJobHandler with its real collaborators instead of mocks --
 * they are final classes, so PHP cannot mock them anyway. Rejections are proven
 * by the request never being made (getRequestsCount() === 0), not by a mock
 * expectation.
 *
 * The handler builds and, on success, publishes and reports. Every failure
 * leaves by throwing: the transport decides whether to retry, and
 * BuildFailureHandler turns the last one into a `failed` callback. So the
 * assertions here are about what is thrown and what reaches disk --
 * BuildFailureHandlerTest covers what the client is told.
 *
 * The handler takes an already-decoded BuildJob, so there are no tests here for
 * malformed JSON or missing keys -- Messenger's serializer rejects those first.
 */
final class BuildJobHandlerTest extends TestCase
{
    private const SITE_ID = '11f5b798-6f34-4951-ad8b-bfd623ded5c2';
    private const BUILD_ID = '16ef078ad37fd894';
    private const SLUG = 'integration-test-collective';
    private const TITLE = 'Integration Test Collective';

    // Mock Collective content download url for testing with correct structure.
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
        // Left uncreated here: the handler creates what it needs, so starting
        // without it is what proves that it did.
        $this->root = sys_get_temp_dir() . '/worker-job-test-' . bin2hex(random_bytes(6));

        // Must be a real archive, not a placeholder string, since the handler
        // extracts what it downloads. A factory rather than one response
        // because a retry downloads it again.
        $this->archiveBytes = $this->sampleArchiveBytes();
        $this->client = new MockHttpClient(fn (): MockResponse => new MockResponse($this->archiveBytes));

        $this->callbackClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->callbacks[] = ['url' => $url, 'body' => json_decode($options['body'] ?? '{}', true)];

            return new MockResponse('', ['http_code' => 204]);
        });

        // error_log() writes to stderr/syslog by default, neither of which a
        // test can assert against. Restored in tearDown().
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
     * A minimal replacement for legit_sample.tar.gz: pages at the archive root,
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

    private function workspace(): JobWorkspace
    {
        return new JobWorkspace($this->buildTemp(), $this->publishedRoot());
    }

    private function buildTemp(): string
    {
        return $this->root . '/build_temp';
    }

    private function publishedRoot(): string
    {
        return $this->root . '/published';
    }

    private function handler(?MockHttpClient $contentClient = null): BuildJobHandler
    {
        $workspace = $this->workspace();

        return new BuildJobHandler(
            new ContentDownloader($contentClient ?? $this->client, maxMegabytes: 1),
            new ArchiveExtractor(),
            new SiteRenderer(),
            $workspace,
            new StatusNotifier($this->callbackClient),
        );
    }

    /** A handler whose download always yields something that is not an archive. */
    private function failingHandler(): BuildJobHandler
    {
        return $this->handler(new MockHttpClient(fn (): MockResponse => new MockResponse('not an archive')));
    }

    private function jobDir(string $buildId = self::BUILD_ID): string
    {
        return $this->buildTemp() . '/' . self::SITE_ID . '/' . $buildId;
    }

    private function publishedSite(string $slug = self::SLUG): string
    {
        return $this->publishedRoot() . '/' . $slug;
    }

    /**
     * The message as Messenger hands it over, already decoded.
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
     * @return array<string, mixed>
     */
    private function onlyCallback(): array
    {
        self::assertCount(1, $this->callbacks, 'expected exactly one outcome callback');

        return $this->callbacks[0]['body'];
    }

    // --- the happy path ---------------------------------------------------

    public function testPublishesTheRenderedSiteUnderItsSlug(): void
    {
        ($this->handler())($this->job());

        self::assertFileExists($this->publishedSite() . '/index.html');
        self::assertFileExists($this->publishedSite() . '/Cats/index.html');
    }

    public function testDownloadsExtractsAndRendersBeforePublishing(): void
    {
        // Asserted mid-build, because a successful run ends with the whole job
        // tree deleted -- there is nothing left to inspect afterwards.
        $seen = [];
        $handler = $this->handler(new MockHttpClient(function () use (&$seen): MockResponse {
            $seen['input exists'] = is_dir($this->jobDir() . '/input');
            $seen['output exists'] = is_dir($this->jobDir() . '/output');

            return new MockResponse($this->archiveBytes);
        }));

        $handler($this->job());

        self::assertSame(['input exists' => true, 'output exists' => true], $seen);
    }

    public function testTheBuildTreeIsClearedOnSuccess(): void
    {
        ($this->handler())($this->job());

        // input/ holds the archive AND its fully extracted copy, by far the
        // bulkiest thing on the volume. Nothing downstream reads it.
        self::assertDirectoryDoesNotExist($this->jobDir());
        self::assertDirectoryDoesNotExist($this->buildTemp() . '/' . self::SITE_ID);
    }

    /**
     * The whole reason `title` exists as a separate field: the slug is a
     * directory name now and its allow-list excludes spaces, so it cannot also
     * be the human-readable heading.
     */
    public function testTheTitleHeadsTheSiteWhileTheSlugNamesTheDirectory(): void
    {
        ($this->handler())($this->job(slug: 'demo-site', title: 'My Team Handbook'));

        $index = $this->publishedRoot() . '/demo-site/index.html';
        self::assertFileExists($index);
        self::assertStringContainsString('My Team Handbook', (string) file_get_contents($index));
    }

    public function testReportsSuccessWithThePageCount(): void
    {
        ($this->handler())($this->job());

        $body = $this->onlyCallback();
        self::assertSame(self::CALLBACK_URL, $this->callbacks[0]['url']);
        self::assertSame('success', $body['status']);
        self::assertSame(self::BUILD_ID, $body['build_id']);
        self::assertSame(self::SLUG, $body['slug']);
        self::assertSame(2, $body['pages']);
        self::assertArrayNotHasKey('error', $body);
    }

    public function testLogsThePreparedWorkdir(): void
    {
        // The only record a job leaves behind, so the log message must name the
        // folder -- otherwise it would not say which job, or which volume.
        ($this->handler())($this->job());

        self::assertStringContainsString($this->jobDir(), (string) file_get_contents($this->logFile));
    }

    /**
     * A rebuild is a new build_id against the same slug, and it must replace
     * the live site rather than merge into it -- a page deleted from the
     * collective has to disappear.
     */
    public function testARebuildReplacesThePublishedSiteRatherThanMergingIntoIt(): void
    {
        ($this->handler())($this->job());
        file_put_contents($this->publishedSite() . '/removed-later.html', 'gone in the next build');

        ($this->handler())($this->job(buildId: 'bbbbbbbbbbbbbbbb'));

        self::assertFileExists($this->publishedSite() . '/index.html');
        self::assertFileDoesNotExist($this->publishedSite() . '/removed-later.html');
        self::assertSame(2, $this->client->getRequestsCount());
    }

    // --- failures leave by throwing ---------------------------------------

    /**
     * A retryable failure is rethrown untouched so the transport redelivers it.
     * Reporting here would POST a `failed` for a build that may still succeed;
     * BuildFailureHandler reports only once no attempt is left.
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

    /**
     * The workdir survives a retryable failure: the next attempt's reset()
     * wipes it, and clearing it here would throw away a build still in flight.
     */
    public function testARetryableFailureLeavesTheWorkdirForTheNextAttempt(): void
    {
        try {
            ($this->failingHandler())($this->job());
        } catch (\RuntimeException) {
            self::assertDirectoryExists($this->jobDir());
        }
    }

    // --- terminal failures are marked unrecoverable -----------------------

    /**
     * @return array<string, array{BuildJob}>
     */
    public static function provideTerminalJobs(): array
    {
        $base = [
            'build_id' => self::BUILD_ID,
            'static_site_id' => self::SITE_ID,
            'slug' => self::SLUG,
            'content_download_url' => self::CONTENT_URL,
            'callback_status_url' => self::CALLBACK_URL,
            'created_at' => '2026-09-03T13:00:09+00:00',
            'title' => self::TITLE,
        ];

        return [
            'empty static_site_id' => [new BuildJob(...[...$base, 'static_site_id' => ''])],
            'traversal static_site_id' => [new BuildJob(...[...$base, 'static_site_id' => '../escape'])],
            'traversal slug' => [new BuildJob(...[...$base, 'slug' => '../etc'])],
            'slug with a space' => [new BuildJob(...[...$base, 'slug' => 'My Team Handbook'])],
            'empty content_download_url' => [new BuildJob(...[...$base, 'content_download_url' => ''])],
            'unfetchable scheme' => [new BuildJob(...[...$base, 'content_download_url' => 'file:///etc/passwd'])],
        ];
    }

    /**
     * UnrecoverableMessageHandlingException is checked before the retry
     * strategy, so this is what stops an unsafe slug spending a retry and 15s
     * of backoff on an answer that cannot change.
     */
    #[DataProvider('provideTerminalJobs')]
    public function testATerminalFailureIsMarkedUnrecoverable(BuildJob $job): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);

        ($this->handler())($job);
    }

    /** The original reason has to survive the wrapping, or the callback is useless. */
    public function testTheUnrecoverableWrapperKeepsTheReason(): void
    {
        try {
            ($this->handler())($this->job(slug: 'My Team Handbook'));
            self::fail('Expected an unsafe slug to be refused.');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertStringContainsString('slug', $e->getMessage());
            self::assertInstanceOf(\InvalidArgumentException::class, $e->getPrevious());
        }
    }

    public function testAnUnsafeStaticSiteIdWritesNothingOutsideTheRoots(): void
    {
        // Duplicated from JobWorkspaceTest on purpose: only here does it prove
        // a message off the queue cannot write outside the volume.
        $escapee = \dirname($this->root) . '/worker-escaped-' . bin2hex(random_bytes(6));

        try {
            ($this->handler())($this->job(staticSiteId: '../' . basename($escapee)));
            self::fail('Expected a traversal id to be refused.');
        } catch (UnrecoverableMessageHandlingException) {
            self::assertDirectoryDoesNotExist($escapee);
            self::assertSame(0, $this->client->getRequestsCount());
        }
    }

    public function testAnUnsafeSlugPublishesNothing(): void
    {
        $escapee = \dirname($this->root) . '/worker-escaped-slug-' . bin2hex(random_bytes(6));

        try {
            ($this->handler())($this->job(slug: '../' . basename($escapee)));
            self::fail('Expected a traversal slug to be refused.');
        } catch (UnrecoverableMessageHandlingException) {
            self::assertDirectoryDoesNotExist($escapee);
            self::assertDirectoryDoesNotExist($this->publishedRoot());
        }
    }

    /**
     * An archive with no pages renders the same nothing however often it is
     * fetched, so it is terminal rather than worth a retry.
     */
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

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('no Markdown pages');

        $handler($this->job());
    }

    // --- the callback is never allowed to fail the message ----------------

    /**
     * A build nobody could be told about is not done. The notifier's exception
     * is allowed out, so the transport redelivers and the whole build re-runs
     * -- expensive, but it is what stops a transient callback failure silently
     * stranding a finished build.
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

        // The site went live regardless -- publishing happened before the POST.
        self::assertFileExists($this->publishedSite() . '/index.html');
    }

    /**
     * A 404 on the callback will not start working on the retry, and retrying
     * costs a full rebuild. Terminal, like any other InvalidArgumentException
     * out of the pipeline.
     */
    public function testAPermanentlyRejectedCallbackIsNotRetried(): void
    {
        $this->callbackClient = new MockHttpClient(new MockResponse('', ['http_code' => 404]));

        $this->expectException(UnrecoverableMessageHandlingException::class);

        ($this->handler())($this->job());
    }

    /**
     * callback_status_url is required by the API but never validated, so an
     * empty string reaches here intact. Failing the message would strand a
     * build that is otherwise complete.
     */
    public function testAnEmptyCallbackUrlStillPublishes(): void
    {
        ($this->handler())($this->job(callbackStatusUrl: ''));

        self::assertFileExists($this->publishedSite() . '/index.html');
        self::assertSame([], $this->callbacks);
    }
}
