<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Creates a build job's input/output folder pair under its static_site_id, on
 * the volume shared with publish. Mirrors publish's App\Storage\JobWorkspace.
 */
final class JobWorkspace
{
    public const INPUT_DIR = 'input';
    public const OUTPUT_DIR = 'output';

    /**
     * static_site_id comes from an HTTP payload and becomes a directory name,
     * so it is restricted to characters that cannot escape $baseDir. Dots are
     * excluded outright, which keeps a bare ".." from passing.
     */
    private const SAFE_ID = '/^[A-Za-z0-9_-]{1,128}$/';

    public function __construct(private readonly string $baseDir)
    {
    }

    public static function isValidStaticSiteId(string $staticSiteId): bool
    {
        return preg_match(self::SAFE_ID, $staticSiteId) === 1;
    }

    /**
     * Creates {baseDir}/{staticSiteId}/input and /output. Idempotent: a
     * rebuild reuses the existing folders rather than failing.
     *
     * Separate exception types because they are separate problems: a bad id is
     * the caller's fault, an uncreatable directory the environment's.
     *
     * @return string the job directory holding the pair
     *
     * @throws \InvalidArgumentException if the static_site_id is unsafe
     * @throws \RuntimeException         if a directory cannot be created
     */
    public function createJobDirectories(string $staticSiteId): string
    {
        if (!self::isValidStaticSiteId($staticSiteId)) {
            throw new \InvalidArgumentException('Unsafe static_site_id.');
        }

        $jobDir = rtrim($this->baseDir, '/') . '/' . $staticSiteId;

        $this->ensureDir($jobDir . '/' . self::INPUT_DIR);
        $this->ensureDir($jobDir . '/' . self::OUTPUT_DIR);

        return $jobDir;
    }

    /**
     * The second is_dir() covers a concurrent build creating $dir between our
     * check and our mkdir().
     */
    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            $reason = error_get_last()['message'] ?? 'unknown error';

            throw new \RuntimeException("Could not create job directory {$dir}: {$reason}");
        }
    }
}
