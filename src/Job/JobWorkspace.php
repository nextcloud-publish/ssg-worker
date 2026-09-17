<?php

declare(strict_types=1);

namespace App\Job;

/**
 * Creates a build job's input/output folder pair under
 * {staticSiteId}/{buildId}, on the volume shared with publish. The same layout
 * is read from the other side by publish's App\Storage\JobLayout, which
 * promotes output/ once the build succeeds -- the two are a hand-synced
 * contract, so a change here needs the same change there.
 */
final class JobWorkspace
{
    public const INPUT_DIR = 'input';
    public const OUTPUT_DIR = 'output';

    /**
     * static_site_id and build_id both come from an HTTP payload and both
     * become directory names, so they are restricted to characters that cannot
     * escape $baseDir. Dots are excluded outright, which keeps a bare ".."
     * from passing. build_id is bin2hex(random_bytes(8)) on publish's side and
     * passes unchanged.
     */
    private const SAFE_ID = '/^[A-Za-z0-9_-]{1,128}$/';

    public function __construct(private readonly string $baseDir)
    {
    }

    public static function isValidStaticSiteId(string $staticSiteId): bool
    {
        return preg_match(self::SAFE_ID, $staticSiteId) === 1;
    }

    public static function isValidBuildId(string $buildId): bool
    {
        return preg_match(self::SAFE_ID, $buildId) === 1;
    }

    /**
     * Creates {baseDir}/{staticSiteId}/{buildId}/input and /output. Idempotent:
     * a redelivery of the same build reuses the existing folders rather than
     * failing.
     *
     * Keyed on build_id as well as static_site_id for two independent reasons.
     * The result worker uses "the temp directory is gone" as its duplicate
     * guard, and keyed on the site alone that cannot tell "already promoted"
     * from "the next build of this site is mid-flight". And SiteBuilder never
     * clears its output directory, so a shared per-site output/ would keep
     * pages deleted from the collective and publish them forever.
     *
     * Separate exception types because they are separate problems: a bad id is
     * the caller's fault, an uncreatable directory the environment's.
     *
     * @return string the job directory holding the pair
     *
     * @throws \InvalidArgumentException if either id is unsafe
     * @throws \RuntimeException         if a directory cannot be created
     */
    public function createJobDirectories(string $staticSiteId, string $buildId): string
    {
        if (!self::isValidStaticSiteId($staticSiteId)) {
            throw new \InvalidArgumentException('Unsafe static_site_id.');
        }

        if (!self::isValidBuildId($buildId)) {
            throw new \InvalidArgumentException('Unsafe build_id.');
        }

        $jobDir = rtrim($this->baseDir, '/') . '/' . $staticSiteId . '/' . $buildId;

        Helper::ensureDir($jobDir . '/' . self::INPUT_DIR);
        Helper::ensureDir($jobDir . '/' . self::OUTPUT_DIR);

        return $jobDir;
    }
}
