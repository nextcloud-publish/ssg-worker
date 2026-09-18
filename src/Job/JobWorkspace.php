<?php

declare(strict_types=1);

namespace App\Job;

/**
 * Prepares a build job's input/output folder pair on the job volume.
 *
 * Paths and the id allow-list both live in JobLayout, so there is exactly one
 * place that turns a queue payload into a filesystem path.
 */
final class JobWorkspace
{
    /**
     * output/ and input/ are worker-private: nothing serves them, and the
     * promoter widens the published copy to 0755 on its way out.
     */
    private const JOB_DIR_MODE = 0o750;

    public function __construct(private readonly JobLayout $layout)
    {
    }

    /**
     * Creates {buildTempDir}/{staticSiteId}/{buildId}/input and /output, wiping
     * anything already there.
     *
     * NOT IDEMPOTENT, and that is the point. A retry carries the same build_id,
     * and SsgLab\SiteBuilder never clears its output directory: a reused
     * output/ would republish pages that a previous attempt wrote and the
     * source no longer has. Keying on build_id is the other half -- two
     * concurrent builds of one site used to share a directory.
     *
     * Separate exception types because they are separate problems: a bad id is
     * the caller's fault, an uncreatable directory the environment's.
     *
     * @return string the job directory holding the pair
     *
     * @throws \InvalidArgumentException if the static_site_id, build_id or slug is unsafe
     * @throws \RuntimeException         if a directory cannot be created or removed
     */
    public function reset(string $staticSiteId, string $buildId, string $slug): string
    {
        JobLayout::assertSafeIds($staticSiteId, $buildId);

        // Validated HERE, before a byte is downloaded, even though nothing in
        // this method uses it: the slug names the published directory, and
        // finding out it is unusable after a five-minute render wastes the
        // attempt and delays the client's answer for nothing.
        JobLayout::assertSafeSlug($slug);

        $jobDir = $this->layout->jobDir($staticSiteId, $buildId);

        if (is_dir($jobDir) && !Filesystem::removeDir($jobDir)) {
            throw new \RuntimeException(sprintf(
                'Could not clear the previous attempt at %s',
                $jobDir,
            ));
        }

        Filesystem::ensureDir($this->layout->buildInputDir($staticSiteId, $buildId), self::JOB_DIR_MODE);
        Filesystem::ensureDir($this->layout->buildOutputDir($staticSiteId, $buildId), self::JOB_DIR_MODE);

        return $jobDir;
    }
}
