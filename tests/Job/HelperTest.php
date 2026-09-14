<?php

declare(strict_types=1);

namespace App\Tests\Job;

use App\Job\Helper;
use PHPUnit\Framework\TestCase;

final class HelperTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/ssg-worker-helper-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            exec('rm -rf ' . escapeshellarg($this->tmp));
        }
    }

    public function testCreatesTheDirectoryAndAnyMissingParents(): void
    {
        $dir = $this->tmp . '/nested/deeper';

        Helper::ensureDir($dir);

        self::assertDirectoryExists($dir);
        self::assertDirectoryIsWritable($dir);
    }

    public function testIsIdempotentWhenTheDirectoryAlreadyExists(): void
    {
        // A rebuild reuses the folder and leaves its contents alone.
        Helper::ensureDir($this->tmp);
        file_put_contents($this->tmp . '/keep.md', '# keep');

        Helper::ensureDir($this->tmp);

        self::assertFileExists($this->tmp . '/keep.md');
    }

    public function testThrowsWithTheRealReasonWhenBlockedByAFile(): void
    {
        // A regular file where a parent directory should be.
        $blocked = $this->tmp . '/a-file';
        mkdir($this->tmp, 0o750, true);
        file_put_contents($blocked, 'not a directory');

        try {
            Helper::ensureDir($blocked . '/child');
            self::fail('Expected a RuntimeException for an uncreatable directory.');
        } catch (\RuntimeException $e) {
            // The filesystem's own reason is the actionable half of the message.
            self::assertStringContainsString('Could not create', $e->getMessage());
            self::assertStringContainsString('Not a directory', $e->getMessage());
            self::assertStringNotContainsString('unknown error', $e->getMessage());
            // And the path, so it is clear which directory failed.
            self::assertStringContainsString($blocked, $e->getMessage());
        }
    }
}
