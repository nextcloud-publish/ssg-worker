<?php

declare(strict_types=1);

namespace App\Tests\Callback;

use App\Callback\ErrorRedactor;
use PHPUnit\Framework\TestCase;

/**
 * The messages fed in here are real ones from the pipeline, not invented
 * strings: every RuntimeException in src/Job embeds an absolute path on
 * purpose, and callback_status_url points wherever the API caller chose.
 */
final class ErrorRedactorTest extends TestCase
{
    private ErrorRedactor $redactor;

    protected function setUp(): void
    {
        $this->redactor = new ErrorRedactor(
            '/opt/ssg/build_temp',
            '/opt/ssg/published',
        );
    }

    public function testNamesTheRootsInsteadOfDisclosingThem(): void
    {
        $redacted = $this->redactor->redact(
            'Extracting /opt/ssg/build_temp/site-42/abc123/input/content.tar.gz failed (exit 2): '
            . 'tar: unexpected EOF in archive',
        );

        // The layout is gone...
        self::assertStringNotContainsString('/opt/ssg', $redacted);
        // ...but which tree, which stage, and tar's own reason all survive.
        self::assertStringContainsString('<build>', $redacted);
        self::assertStringContainsString('content.tar.gz', $redacted);
        self::assertStringContainsString('tar: unexpected EOF in archive', $redacted);
    }

    public function testNamesThePublishedRootToo(): void
    {
        $redacted = $this->redactor->redact(
            'Could not move /opt/ssg/build_temp/site-42/abc/output to /opt/ssg/published/.staging/abc: No space left on device',
        );

        self::assertStringContainsString('<build>', $redacted);
        self::assertStringContainsString('<published>', $redacted);
        self::assertStringContainsString('No space left on device', $redacted);
        self::assertStringNotContainsString('/opt/ssg', $redacted);
    }

    /** An absolute path from somewhere we do not have a name for still goes. */
    public function testCollapsesAnUnknownAbsolutePath(): void
    {
        $redacted = $this->redactor->redact('Could not create /var/lib/private/secrets/db: Permission denied');

        self::assertStringNotContainsString('/var/lib/private/secrets/db', $redacted);
        self::assertStringContainsString('<path>', $redacted);
        self::assertStringContainsString('Permission denied', $redacted);
    }

    /**
     * The client's own download URL is the one path-looking thing that should
     * stay readable -- it is what they gave us, and it is how they identify
     * which job failed.
     */
    public function testLeavesTheClientsOwnUrlIntact(): void
    {
        $redacted = $this->redactor->redact(
            'Download failed for https://cloud.example.org/collectives/export/1234.tar.gz: HTTP 404',
        );

        self::assertStringContainsString('https://cloud.example.org/collectives/export/1234.tar.gz', $redacted);
    }

    /**
     * If the caller embedded a token in the download URL, we must not repeat it
     * into a DIFFERENT endpoint's request log.
     */
    public function testStripsCredentialsFromAUrl(): void
    {
        $redacted = $this->redactor->redact(
            'Download failed for https://user:s3cr3t-token@cloud.example.org/export.tar.gz: HTTP 500',
        );

        self::assertStringNotContainsString('s3cr3t-token', $redacted);
        self::assertStringNotContainsString('user:', $redacted);
        self::assertStringContainsString('cloud.example.org', $redacted);
    }

    /**
     * tar prints one line per member. A raw NUL is not legal in a JSON string,
     * and a newline would break the single-line log entry this also feeds.
     */
    public function testFlattensControlCharacters(): void
    {
        $redacted = $this->redactor->redact("first line\nsecond line\ttabbed\0nul");

        self::assertStringNotContainsString("\n", $redacted);
        self::assertStringNotContainsString("\t", $redacted);
        self::assertStringNotContainsString("\0", $redacted);
        self::assertStringContainsString('second line', $redacted);
    }

    /**
     * An unbounded message does not just make a long callback body: it becomes
     * an AMQP header on the retry republish, and the broker's default frame_max
     * is 128 KiB.
     */
    public function testTruncatesARunawayMessage(): void
    {
        $redacted = $this->redactor->redact(str_repeat('tar: cannot extract member ', 500));

        self::assertLessThan(600, mb_strlen($redacted));
        self::assertStringContainsString('truncated', $redacted);
    }

    public function testLeavesAnOrdinaryMessageAlone(): void
    {
        $message = 'The archive contains no Markdown pages.';

        self::assertSame($message, $this->redactor->redact($message));
    }

    /**
     * The published tree normally sits under the same parent as the build tree,
     * which makes one root a prefix of the other. The longest has to win, or a
     * published path would be reported as a build path.
     */
    public function testANestedRootIsNamedByItsOwnLabel(): void
    {
        $redactor = new ErrorRedactor('/opt/ssg', '/opt/ssg/published');

        self::assertStringContainsString('<published>', $redactor->redact('at /opt/ssg/published/demo'));
        self::assertStringContainsString('<build>', $redactor->redact('at /opt/ssg/build_temp/demo'));
    }
}
