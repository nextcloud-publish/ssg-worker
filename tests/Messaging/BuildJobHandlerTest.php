<?php

declare(strict_types=1);

namespace App\Tests\Messaging;

use App\Messaging\BuildJobHandler;
use App\Storage\JobWorkspace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A real JobWorkspace over a temp directory rather than a test double: it is
 * final, and what matters here is what ends up on disk.
 */
final class BuildJobHandlerTest extends TestCase
{
    private const SITE_ID = '11f5b798-6f34-4951-ad8b-bfd623ded5c2';

    private string $baseDir;
    private string $logFile;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/ssg-worker-handler-test-' . bin2hex(random_bytes(6));

        // error_log() writes to stderr/syslog by default, neither of which
        // a test can assert against. Restored in tearDown().
        $this->logFile = sys_get_temp_dir() . '/ssg-worker-test-' . bin2hex(random_bytes(6)) . '.log';
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
        return new BuildJobHandler(new JobWorkspace($this->baseDir));
    }

    /**
     * The shape publish puts on q.builds. Only static_site_id is read; the
     * rest keeps the fixture realistic.
     *
     * @param array<string,mixed> $overrides
     */
    private function message(array $overrides = []): string
    {
        return (string) json_encode($overrides + [
            'build_id' => '16ef078ad37fd894',
            'static_site_id' => self::SITE_ID,
            'slug' => 'integration_test_collective',
            'content_download_url' => 'https://some-nextcloud.org/apps/collectives/some-collective-1234/publish/markdown_bundle',
            'callback_status_url' => 'https://example.org/callback',
            'created_at' => '2026-09-03T13:00:09+00:00',
        ]);
    }

    public function testCreatesInputAndOutputDirectoriesForTheJob(): void
    {
        $this->handler()->handle($this->message());

        self::assertDirectoryExists($this->baseDir . '/' . self::SITE_ID . '/input');
        self::assertDirectoryExists($this->baseDir . '/' . self::SITE_ID . '/output');
    }

    public function testLogsThePreparedWorkdir(): void
    {
        // The only record a job leaves behind, so it has to name the folder
        // -- otherwise it says nothing about which job, or which volume.
        $this->handler()->handle($this->message());

        self::assertStringContainsString(
            $this->baseDir . '/' . self::SITE_ID,
            (string) file_get_contents($this->logFile),
        );
    }

    public function testIsIdempotentAcrossRebuildsOfTheSameSite(): void
    {
        // An existing workspace is the normal case, and its contents survive.
        $this->handler()->handle($this->message());
        file_put_contents($this->baseDir . '/' . self::SITE_ID . '/input/keep.md', '# keep');

        $this->handler()->handle($this->message());

        self::assertFileExists($this->baseDir . '/' . self::SITE_ID . '/input/keep.md');
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
        try {
            $this->handler()->handle($body);
            self::fail('Expected a RuntimeException mentioning static_site_id.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('static_site_id', $e->getMessage());
            self::assertDirectoryDoesNotExist($this->baseDir);
        }
    }

    public function testAnUnsafeStaticSiteIdCreatesNothing(): void
    {
        // Duplicated from JobWorkspaceTest on purpose: only here does it
        // show a message off the queue cannot steer writes off the volume.
        $escapee = dirname($this->baseDir) . '/ssg-worker-escaped-' . bin2hex(random_bytes(6));

        try {
            $this->handler()->handle($this->message([
                'static_site_id' => '../' . basename($escapee),
            ]));
            self::fail('Expected an InvalidArgumentException for a traversal id.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('static_site_id', $e->getMessage());
            self::assertDirectoryDoesNotExist($escapee);
            self::assertDirectoryDoesNotExist($this->baseDir);
        }
    }
}
