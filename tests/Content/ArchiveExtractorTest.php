<?php

declare(strict_types=1);

namespace App\Tests\Content;

use App\Content\ArchiveExtractor;
use PHPUnit\Framework\TestCase;

/**
 * The fixtures are built with tar in setUp() rather than committed as binary
 * blobs, so what they contain is readable in this file.
 */
final class ArchiveExtractorTest extends TestCase
{
    private string $tmp;
    private string $targetDir;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/worker-extract-test-' . bin2hex(random_bytes(6));
        $this->targetDir = $this->tmp . '/target';
        mkdir($this->targetDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            exec('rm -rf ' . escapeshellarg($this->tmp));
        }
    }

    /**
     * Builds a .tar.gz from a path => contents map and returns its path.
     *
     * @param array<string,string> $files
     */
    private function makeArchive(array $files): string
    {
        $staging = $this->tmp . '/staging';
        mkdir($staging, 0o755, true);

        foreach ($files as $path => $contents) {
            $full = $staging . '/' . $path;
            @mkdir(\dirname($full), 0o755, true);
            file_put_contents($full, $contents);
        }

        $archive = $this->tmp . '/content.tar.gz';
        exec(sprintf(
            'tar -czf %s -C %s .',
            escapeshellarg($archive),
            escapeshellarg($staging),
        ), $out, $code);

        self::assertSame(0, $code, 'fixture archive could not be built');
        exec('rm -rf ' . escapeshellarg($staging));

        return $archive;
    }

    public function testExtractsFilesIntoTheTargetDirectory(): void
    {
        $archive = $this->makeArchive(['Readme.md' => '# hello']);

        (new ArchiveExtractor())->extract($archive, $this->targetDir);

        self::assertFileExists($this->targetDir . '/Readme.md');
        self::assertSame('# hello', file_get_contents($this->targetDir . '/Readme.md'));
    }

    public function testKeepsNestedDirectories(): void
    {
        $archive = $this->makeArchive([
            'Cats/Readme.md' => '# cats',
            'Cats/.attachments.13283723/paw.jpg' => 'JPEGDATA',
        ]);

        (new ArchiveExtractor())->extract($archive, $this->targetDir);

        self::assertSame('# cats', file_get_contents($this->targetDir . '/Cats/Readme.md'));
        // Dot-prefixed dirs are how Collectives stores inline media, and they
        // are easy to lose to a glob that skips hidden entries.
        self::assertFileExists($this->targetDir . '/Cats/.attachments.13283723/paw.jpg');
    }

    public function testSurvivesNonAsciiFilenames(): void
    {
        // The case that ruled PharData out: it throws on the sample corpus's
        // "Gorila_de_montaña_(...).jpg" while merely iterating the archive.
        $name = 'Gorila_de_montaña_(Gorilla_beringei), Uganda, DD_80.jpg';
        $archive = $this->makeArchive([$name => 'JPEGDATA']);

        (new ArchiveExtractor())->extract($archive, $this->targetDir);

        self::assertFileExists($this->targetDir . '/' . $name);
        self::assertSame('JPEGDATA', file_get_contents($this->targetDir . '/' . $name));
    }

    public function testThrowsOnSomethingThatIsNotAnArchive(): void
    {
        $notAnArchive = $this->tmp . '/content.tar.gz';
        file_put_contents($notAnArchive, 'definitely not a gzipped tar');

        try {
            (new ArchiveExtractor())->extract($notAnArchive, $this->targetDir);
            self::fail('Expected a RuntimeException for a non-archive.');
        } catch (\RuntimeException $e) {
            // tar's own complaint is the actionable part; without it the
            // message would be a bare exit code.
            self::assertStringContainsString($notAnArchive, $e->getMessage());
            self::assertMatchesRegularExpression('/gzip|tar|format|magic/i', $e->getMessage());
        }
    }

    public function testThrowsWhenTheArchiveDoesNotExist(): void
    {
        $this->expectException(\RuntimeException::class);

        (new ArchiveExtractor())->extract($this->tmp . '/missing.tar.gz', $this->targetDir);
    }

    public function testCreatesTheTargetDirectoryWhenItDoesNotExist(): void
    {
        // Nothing else creates content_unarchived/ -- publish only provisions
        // input/ and output/ -- so extract() has to make its own destination.
        $archive = $this->makeArchive(['Readme.md' => '# hello']);
        $fresh = $this->targetDir . '/content_unarchived';

        self::assertDirectoryDoesNotExist($fresh);

        (new ArchiveExtractor())->extract($archive, $fresh);

        self::assertFileExists($fresh . '/Readme.md');
    }

    public function testThrowsWhenTheTargetDirectoryCannotBeCreated(): void
    {
        $archive = $this->makeArchive(['Readme.md' => '# hello']);

        $blocked = $this->tmp . '/a-file';
        file_put_contents($blocked, 'not a directory');

        try {
            (new ArchiveExtractor())->extract($archive, $blocked . '/content_unarchived');
            self::fail('Expected a RuntimeException for an uncreatable target.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Could not create', $e->getMessage());
            // The filesystem's reason is the actionable half of the message.
            self::assertStringContainsString('Not a directory', $e->getMessage());
        }
    }
}
