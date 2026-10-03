<?php

declare(strict_types=1);

namespace App\Tests\Message;

use App\Job\JobWorkspace;
use App\Job\StatusNotifier;
use App\Message\BuildFailureHandler;
use App\Message\BuildJob;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;

/**
 * Covers App\Message\BuildFailureHandler: what the client is told when a build runs out of
 * attempts, and what is left on disk afterwards.
 *
 * Real collaborators throughout, and a real WorkerMessageFailedEvent rather than a double.
 * willRetry() decides everything this class does, and a double would let it be asserted
 * without ever being exercised.
 */
final class BuildFailureHandlerTest extends TestCase
{
    private const SITE_ID = '11f5b798-6f34-4951-ad8b-bfd623ded5c2';
    private const BUILD_ID = '0199a1b2-3c4d-7e5f-8a9b-0c1d2e3f4a5b';
    private const SLUG = 'demo-site';
    private const CALLBACK_URL = 'https://cloud.example.org/collectives/publish/1234';

    private string $root;
    private MockHttpClient $callbackClient;

    /** @var list<array<string, mixed>> */
    private array $callbacks = [];
    private string $logFile;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/worker-failure-test-' . bin2hex(random_bytes(6));

        $this->callbackClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->callbacks[] = json_decode($options['body'] ?? '{}', true);

            return new MockResponse('', ['http_code' => 204]);
        });

        $this->logFile = sys_get_temp_dir() . '/worker-failure-log-' . bin2hex(random_bytes(6)) . '.log';
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
     * The build scratch root for this test.
     *
     * @return string the JOB_STORAGE_DIR equivalent
     */
    private function buildTemp(): string
    {
        return $this->root . '/build_temp';
    }

    /**
     * A workspace on this test's two roots.
     *
     * @return JobWorkspace the workspace the handler clears through
     */
    private function workspace(): JobWorkspace
    {
        return new JobWorkspace($this->buildTemp(), $this->root . '/published', 'http://sites.test');
    }

    /**
     * The handler under test, wired to the same two roots.
     *
     * @param ?MockHttpClient $callbackClient The client to post callbacks with, or null for the recording one.
     * @return BuildFailureHandler the handler under test
     */
    private function reporter(?MockHttpClient $callbackClient = null): BuildFailureHandler
    {
        return new BuildFailureHandler(
            $this->workspace(),
            new StatusNotifier($callbackClient ?? $this->callbackClient),
            $this->buildTemp(),
            $this->root . '/published',
        );
    }

    /**
     * A build job, filled from the constants above.
     *
     * @param string $staticSiteId The static site id to put on the job.
     * @param string $callbackStatusUrl The callback url to put on the job.
     * @return BuildJob the message under test
     */
    private function job(string $staticSiteId = self::SITE_ID, string $callbackStatusUrl = self::CALLBACK_URL): BuildJob
    {
        return new BuildJob(
            build_id: self::BUILD_ID,
            static_site_id: $staticSiteId,
            slug: self::SLUG,
            content_download_url: 'https://cloud.example.org/export.tar.gz',
            callback_status_url: $callbackStatusUrl,
            created_at: '2026-09-17T10:00:00+00:00',
            title: 'Demo Site',
        );
    }

    /**
     * The event the worker dispatches when a delivery fails.
     *
     * @param \Throwable $error The failure to carry on the event.
     * @param bool $willRetry Whether another delivery is coming.
     * @param ?object $message The failed message, or null for a BuildJob.
     * @return WorkerMessageFailedEvent the event to hand the subscriber
     */
    private function failedEvent(
        \Throwable $error,
        bool $willRetry = false,
        ?object $message = null,
    ): WorkerMessageFailedEvent {
        $event = new WorkerMessageFailedEvent(
            new Envelope($message ?? $this->job()),
            'builds',
            $error,
        );

        if ($willRetry) {
            /** Exactly what SendFailedMessageForRetryListener does at priority 100, before this subscriber runs. */
            $event->setForRetry();
        }

        return $event;
    }

    /**
     * Leaves a job tree behind, as a failed attempt would.
     *
     * @return string the job directory that was created
     */
    private function givenAJobTree(): string
    {
        $jobDir = $this->workspace()->buildJobDir(self::BUILD_ID);
        mkdir($jobDir . '/input', 0o750, true);
        file_put_contents($jobDir . '/input/content.tar.gz', 'half a download');

        return $jobDir;
    }

    // --- only terminal failures are an outcome ----------------------------

    /** Another attempt is coming, so nothing has been decided. Reporting here would PUT a failure for a build that may still succeed. */
    public function testSaysNothingWhenAnotherAttemptIsComing(): void
    {
        $jobDir = $this->givenAJobTree();

        $this->reporter()->onMessageFailed(
            $this->failedEvent(new \RuntimeException('Extracting failed'), willRetry: true),
        );

        self::assertSame([], $this->callbacks);

        /** The retry's reset() wipes this; clearing it now would throw away a build still in flight. */
        self::assertDirectoryExists($jobDir);
    }

    /** The subscriber is global, so anything that is not a build is not ours. */
    public function testIgnoresAMessageThatIsNotABuildJob(): void
    {
        $this->reporter()->onMessageFailed(
            $this->failedEvent(new \RuntimeException('unrelated'), message: new \stdClass()),
        );

        self::assertSame([], $this->callbacks);
    }

    // --- what the client is told ------------------------------------------

    public function testReportsTheFailureWithItsReason(): void
    {
        $this->reporter()->onMessageFailed(
            $this->failedEvent(new \RuntimeException('tar: unexpected EOF in archive')),
        );

        self::assertSame([[
            'status' => 'failed',
            'result' => ['error_message' => 'tar: unexpected EOF in archive'],
        ]], $this->callbacks);
    }

    /** The throwable arrives in-process, so the reason is whatever actually failed: no stamp, no serializer, no round trip. */
    public function testTheReasonComesFromTheThrowableItself(): void
    {
        $this->reporter()->onMessageFailed(
            $this->failedEvent(new \InvalidArgumentException('Unsafe slug.')),
        );

        self::assertSame('Unsafe slug.', $this->callbacks[0]['result']['error_message']);
    }

    // --- redaction --------------------------------------------------------
    //
    // The pipeline's exceptions embed absolute paths for the operator's log. The callback goes
    // to a url the API caller chose and must not hand them the volume layout. These go through
    // the event rather than calling the private redact() directly, because what reaches the
    // client is what matters.

    /**
     * Reports $raw as a failure and returns what the client received.
     *
     * @param string $raw The unredacted error message.
     * @return string the redacted error from the callback body
     */
    private function reportedErrorFor(string $raw): string
    {
        $this->reporter()->onMessageFailed($this->failedEvent(new \RuntimeException($raw)));

        return $this->callbacks[0]['result']['error_message'];
    }

    public function testTheReportedReasonCarriesNoFilesystemPaths(): void
    {
        $raw = 'Extracting ' . $this->buildTemp() . '/11f5b798-6f34-4951-ad8b-bfd623ded5c2/abc/input/content.tar.gz failed (exit 2): '
            . 'tar: unexpected EOF in archive';

        $error = $this->reportedErrorFor($raw);

        /** The layout is gone. */
        self::assertStringNotContainsString($this->buildTemp(), $error);

        /** Which tree, which stage and tar's own reason all survive. */
        self::assertStringContainsString('<build>', $error);
        self::assertStringContainsString('content.tar.gz', $error);
        self::assertStringContainsString('tar: unexpected EOF in archive', $error);

        /** The operator's copy keeps the real path. */
        self::assertStringContainsString($this->buildTemp(), (string) file_get_contents($this->logFile));
    }

    public function testTheReportedReasonNamesThePublishedRootToo(): void
    {
        $error = $this->reportedErrorFor(
            'Could not move ' . $this->buildTemp() . '/a/b/output to '
            . $this->root . '/published/.staging/b: No space left on device',
        );

        self::assertStringContainsString('<build>', $error);
        self::assertStringContainsString('<published>', $error);
        self::assertStringContainsString('No space left on device', $error);
        self::assertStringNotContainsString($this->root, $error);
    }

    /** An absolute path from somewhere we have no name for still goes. */
    public function testTheReportedReasonCollapsesAnUnknownAbsolutePath(): void
    {
        $error = $this->reportedErrorFor('Could not create /var/lib/private/secrets/db: Permission denied');

        self::assertStringNotContainsString('/var/lib/private/secrets/db', $error);
        self::assertStringContainsString('<path>', $error);
        self::assertStringContainsString('Permission denied', $error);
    }

    /** The client's own download url stays readable: they supplied it, and it is how they identify the job. */
    public function testTheReportedReasonLeavesTheClientsOwnUrlIntact(): void
    {
        $error = $this->reportedErrorFor(
            'Download failed for https://cloud.example.org/collectives/export/1234.tar.gz: HTTP 404',
        );

        self::assertStringContainsString('https://cloud.example.org/collectives/export/1234.tar.gz', $error);
    }

    /** A token embedded in the download url must not be repeated into a different endpoint's request log. */
    public function testTheReportedReasonStripsCredentialsFromAUrl(): void
    {
        $error = $this->reportedErrorFor(
            'Download failed for https://user:s3cr3t-token@cloud.example.org/export.tar.gz: HTTP 500',
        );

        self::assertStringNotContainsString('s3cr3t-token', $error);
        self::assertStringNotContainsString('user:', $error);
        self::assertStringContainsString('cloud.example.org', $error);
    }

    /**
     * Tar prints one line per member.
     * A raw NUL is not legal in a JSON string, and a newline would break the single-line log entry beside it.
     */
    public function testTheReportedReasonFlattensControlCharacters(): void
    {
        $error = $this->reportedErrorFor("first line\nsecond line\ttabbed\0nul");

        self::assertStringNotContainsString("\n", $error);
        self::assertStringNotContainsString("\t", $error);
        self::assertStringNotContainsString("\0", $error);
        self::assertStringContainsString('second line', $error);
    }

    /** A pathological archive must not produce a megabyte-long callback body. */
    public function testTheReportedReasonIsTruncated(): void
    {
        $error = $this->reportedErrorFor(str_repeat('tar: cannot extract member ', 500));

        self::assertLessThan(600, mb_strlen($error));
        self::assertStringContainsString('truncated', $error);
    }

    public function testAnOrdinaryReasonIsPassedThroughUnchanged(): void
    {
        self::assertSame(
            'The archive contains no Markdown pages.',
            $this->reportedErrorFor('The archive contains no Markdown pages.'),
        );
    }

    /**
     * The published tree normally sits under the same parent as the build tree, so one root is a prefix of the other.
     * The longest has to win, or a published path would be reported as a build path.
     */
    public function testANestedRootIsNamedByItsOwnLabel(): void
    {
        $handler = new BuildFailureHandler(
            $this->workspace(),
            new StatusNotifier($this->callbackClient),
            '/opt/ssg',
            '/opt/ssg/published',
        );

        $handler->onMessageFailed($this->failedEvent(new \RuntimeException('at /opt/ssg/published/demo')));

        self::assertStringContainsString('<published>', $this->callbacks[0]['result']['error_message']);
    }

    // --- what is left on disk ---------------------------------------------

    /** A failed build keeps nothing: the redacted error in the callback and the log line are the whole record. */
    public function testDeletesTheFailedBuildsWorkspace(): void
    {
        $jobDir = $this->givenAJobTree();

        $this->reporter()->onMessageFailed($this->failedEvent(new \RuntimeException('Download failed')));

        self::assertDirectoryDoesNotExist($jobDir);

        /** The site's parent goes too once it holds no other build. */
        self::assertDirectoryDoesNotExist($this->buildTemp() . '/' . self::SITE_ID);
    }

    /**
     * Ids that cannot be turned into a path.
     *
     * @return array<string, array{string}> the id per case name
     */
    public static function provideUnsafeIds(): array
    {
        return [
            'parent traversal' => ['../escape'],
            'absolute path' => ['/etc/cron.d'],
            'empty' => [''],
        ];
    }

    /**
     * An unsafe id is one of the things a build is failed for, so it reaches here routinely.
     * Nothing was created for it and it cannot be turned into a path safely, but the client still has to be told.
     */
    #[DataProvider('provideUnsafeIds')]
    public function testAnUnsafeIdStillReportsAndDeletesNothing(string $unsafeId): void
    {
        $escapee = \dirname($this->root) . '/worker-failure-escaped-' . bin2hex(random_bytes(6));
        mkdir($escapee, 0o750, true);
        file_put_contents($escapee . '/keep.md', 'must survive');

        try {
            $this->reporter()->onMessageFailed(
                $this->failedEvent(new \InvalidArgumentException('Unsafe static_site_id.'), message: $this->job($unsafeId)),
            );

            self::assertFileExists($escapee . '/keep.md');
            self::assertSame('failed', $this->callbacks[0]['status']);
        } finally {
            exec('rm -rf ' . escapeshellarg($escapee));
        }
    }

    // --- nothing here may escape ------------------------------------------

    /** An exception out of this subscriber surfaces inside Worker::ack(), where nothing catches it and the consumer dies. */
    public function testAFailingCallbackDoesNotEscape(): void
    {
        $reporter = $this->reporter(new MockHttpClient(new MockResponse('', ['http_code' => 500])));

        $reporter->onMessageFailed($this->failedEvent(new \RuntimeException('Download failed')));

        self::assertStringContainsString(
            '[ERROR] could not report failed',
            (string) file_get_contents($this->logFile),
        );
    }

    /**
     * An empty callback_status_url is refused by the notifier, so this is the failure path where the client cannot be told at all.
     * The cleanup still has to run and nothing may escape.
     */
    public function testAFailureWithNowhereToReportStillClearsTheWorkspace(): void
    {
        $jobDir = $this->givenAJobTree();

        $this->reporter()->onMessageFailed(
            $this->failedEvent(new \RuntimeException('Download failed'), message: $this->job(callbackStatusUrl: '')),
        );

        self::assertSame([], $this->callbacks);

        /** The cleanup still happened. */
        self::assertDirectoryDoesNotExist($jobDir);
    }

    // --- wiring -----------------------------------------------------------

    /**
     * Priority must stay below SendFailedMessageForRetryListener's.
     * Otherwise willRetry() is read before anything has set it, and every retryable failure would be reported as terminal.
     */
    public function testRunsAfterTheRetryListener(): void
    {
        $ours = BuildFailureHandler::getSubscribedEvents()[WorkerMessageFailedEvent::class][1];
        $retry = SendFailedMessageForRetryListener::getSubscribedEvents()[WorkerMessageFailedEvent::class][1];

        self::assertLessThan($retry, $ours);
    }
}
