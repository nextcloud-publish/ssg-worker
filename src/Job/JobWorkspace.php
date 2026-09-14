<?php

declare(strict_types=1);

namespace App\Job;

/**
 * Creates a build job's input/output folder pair under its static_site_id, on
 * the volume shared with publish. Mirrors publish's App\Storage\JobWorkspace
 * (different namespace here: this worker groups its whole per-job pipeline
 * under App\Job rather than by storage/content/rendering concern).
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

        Helper::ensureDir($jobDir . '/' . self::INPUT_DIR);
        Helper::ensureDir($jobDir . '/' . self::OUTPUT_DIR);

        return $jobDir;
    }
}
