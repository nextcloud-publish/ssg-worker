<?php

declare(strict_types=1);

namespace App\Job;

/**
 * The filesystem side of a build: every path the worker uses, the allow-list
 * that makes a queue payload safe to use as one, and the lifecycle of a build's
 * directories -- reset() before the work, publish() on success, clear() at the
 * end either way.
 *
 * THE TWO ROOTS MAY OR MAY NOT SHARE A MOUNT. How JOB_STORAGE_DIR and
 * PUBLISHED_DIR are mounted is the operator's choice, so no method here may
 * assume. rename(2) rejects a cross-mount move with EXDEV and PHP has no
 * directory fallback, so moves between the roots go through
 * Filesystem::moveDir(), which falls back to a copy.
 *
 * The swap in publish() is exempt: staging lives inside publishedDir, so it is
 * a same-mount rename whatever the operator mounted.
 *
 * NOTHING HERE TRACKS ITS OWN PROGRESS. No method inspects the filesystem to
 * work out how far a previous attempt got; a retry rebuilds from scratch. Build
 * state belongs in a database -- see docs/roadmap.md.
 */
final class JobWorkspace
{
    public const INPUT_DIR = 'input';
    public const OUTPUT_DIR = 'output';

    /**
     * Where a build is assembled before the swap. Inside publishedDir so the
     * swap is a same-mount rename; dot-prefixed so nginx's `location ~ /\.`
     * rule keeps a build still being copied unreachable.
     */
    public const STAGING_DIR = '.staging';

    /**
     * static_site_id, build_id and slug arrive from an HTTP payload, cross a
     * queue, and become directory names. The publish API validates them too,
     * but a queue is not a trust boundary and this is the check that actually
     * stands between a payload and the filesystem. Dots are excluded outright,
     * so a bare ".." cannot pass.
     */
    private const SAFE_ID = '/^[A-Za-z0-9_-]{1,128}$/';

    /** input/ and output/ are worker-private; nothing serves them. */
    private const JOB_DIR_MODE = 0o750;

    /** The published tree is traversed by the uid that serves it, not ours. */
    private const PUBLISHED_DIR_MODE = 0o755;

    public function __construct(
        private readonly string $buildTempDir,
        private readonly string $publishedDir,
    ) {
    }

    // --- validation -------------------------------------------------------

    public static function isValidId(string $id): bool
    {
        return preg_match(self::SAFE_ID, $id) === 1;
    }

    /**
     * @throws \InvalidArgumentException if either id is unsafe
     */
    public static function assertSafeIds(string $staticSiteId, string $buildId): void
    {
        if (!self::isValidId($staticSiteId)) {
            throw new \InvalidArgumentException('Unsafe static_site_id.');
        }

        if (!self::isValidId($buildId)) {
            throw new \InvalidArgumentException('Unsafe build_id.');
        }
    }

    /**
     * Separate from assertSafeIds() because the slug guards a different tree and
     * not every caller has one: clear() touches only the build temp tree.
     *
     * @throws \InvalidArgumentException if the slug is unsafe
     */
    public static function assertSafeSlug(string $slug): void
    {
        if (!self::isValidId($slug)) {
            throw new \InvalidArgumentException('Unsafe slug.');
        }
    }

    // --- paths ------------------------------------------------------------

    /** {buildTempDir}/{staticSiteId} -- the parent of every build of one site. */
    public function siteTempDir(string $staticSiteId): string
    {
        return rtrim($this->buildTempDir, '/') . '/' . $staticSiteId;
    }

    /** {buildTempDir}/{staticSiteId}/{buildId} -- one build's whole workspace. */
    public function jobDir(string $staticSiteId, string $buildId): string
    {
        return $this->siteTempDir($staticSiteId) . '/' . $buildId;
    }

    /** Where the archive is downloaded and extracted. */
    public function buildInputDir(string $staticSiteId, string $buildId): string
    {
        return $this->jobDir($staticSiteId, $buildId) . '/' . self::INPUT_DIR;
    }

    /** The rendered site, before it is published. */
    public function buildOutputDir(string $staticSiteId, string $buildId): string
    {
        return $this->jobDir($staticSiteId, $buildId) . '/' . self::OUTPUT_DIR;
    }

    /**
     * The live site, served externally.
     *
     * Keyed on the slug so the URL carries the readable name the client chose.
     * Slug uniqueness is enforced nowhere: two sites claiming one slug take the
     * tree from each other. See docs/roadmap.md.
     */
    public function publishedSiteDir(string $slug): string
    {
        return rtrim($this->publishedDir, '/') . '/' . $slug;
    }

    public function stagingRoot(): string
    {
        return rtrim($this->publishedDir, '/') . '/' . self::STAGING_DIR;
    }

    /** Keyed on build_id so two builds staging at once cannot collide. */
    public function stagingDir(string $buildId): string
    {
        return $this->stagingRoot() . '/' . $buildId;
    }

    // --- lifecycle --------------------------------------------------------

    /**
     * Creates {buildTempDir}/{staticSiteId}/{buildId}/input and /output, wiping
     * anything already there.
     *
     * The wipe is required, not hygiene: a retry carries the same build_id, and
     * SsgLab\SiteBuilder never clears its output directory, so a reused output/
     * would republish pages the source no longer has.
     *
     * @return string the job directory holding the pair
     *
     * @throws \InvalidArgumentException if the static_site_id, build_id or slug is unsafe
     * @throws \RuntimeException         if a directory cannot be created or removed
     */
    public function reset(string $staticSiteId, string $buildId, string $slug): string
    {
        self::assertSafeIds($staticSiteId, $buildId);

        // Checked here, before a byte is downloaded, although nothing below
        // uses it: rejecting the slug after a five-minute render would waste
        // the attempt and delay the client's answer for nothing.
        self::assertSafeSlug($slug);

        $jobDir = $this->jobDir($staticSiteId, $buildId);

        if (is_dir($jobDir) && !Filesystem::removeDir($jobDir)) {
            throw new \RuntimeException(sprintf(
                'Could not clear the previous attempt at %s',
                $jobDir,
            ));
        }

        Filesystem::ensureDir($this->buildInputDir($staticSiteId, $buildId), self::JOB_DIR_MODE);
        Filesystem::ensureDir($this->buildOutputDir($staticSiteId, $buildId), self::JOB_DIR_MODE);

        return $jobDir;
    }

    /**
     * Publishes the rendered output as the live site at {publishedDir}/{slug}:
     * stage it beside the published tree, then swap it in.
     *
     * A republish is briefly a 404 -- the swap deletes the live site before
     * renaming the new one in -- and a crash in that window leaves the site
     * down until the next build.
     *
     * @throws \InvalidArgumentException if an id or the slug is unsafe, or the build rendered nothing
     * @throws \RuntimeException         on a filesystem failure worth retrying
     */
    public function publish(string $staticSiteId, string $buildId, string $slug): void
    {
        self::assertSafeIds($staticSiteId, $buildId);
        self::assertSafeSlug($slug);

        $output = $this->buildOutputDir($staticSiteId, $buildId);
        $published = $this->publishedSiteDir($slug);
        $staging = $this->stagingDir($buildId);

        if (!is_dir($output)) {
            // Retryable: a retry re-downloads and re-renders.
            throw new \RuntimeException(sprintf(
                'Nothing to publish for build %s of site %s: no build output at %s.',
                $buildId,
                $staticSiteId,
                $output,
            ));
        }

        // The swap below deletes the live site first, so publishing an empty
        // build would take a working site down and leave a 404 in its place.
        // Terminal: re-rendering the same archive renders the same nothing.
        if (Filesystem::isEmptyDir($output)) {
            throw new \InvalidArgumentException(
                sprintf('Refusing to publish build %s: it rendered no pages.', $buildId),
            );
        }

        Filesystem::ensureDir($this->stagingRoot(), self::PUBLISHED_DIR_MODE);

        // Cleared, not merged into: a retry reuses the build_id, so anything a
        // previous attempt left here would ship as a mix of two builds.
        Filesystem::removeDir($staging);

        // Rename or recursive copy depending on the mount layout. Either way
        // this is the slow step, which is why it lands in staging and not at
        // the live path.
        Filesystem::moveDir($output, $staging);

        // output/ arrives at 0750 and the worker runs as root: left alone, the
        // published tree is one the serving uid cannot traverse, which is a 403
        // on every page that no unit test would catch.
        @chmod($staging, self::PUBLISHED_DIR_MODE);

        // The delete is not optional: rename() onto a non-empty directory fails
        // with ENOTEMPTY and never merges, so a site would publish once and
        // fail on every rebuild after.
        Filesystem::removeDir($published);
        Filesystem::rename($staging, $published);
    }

    /**
     * Removes the build's workspace once the job is over, whichever way it
     * ended. This is what retires input/: the downloaded archive plus its fully
     * extracted copy, the bulkiest thing on the volume.
     *
     * NEVER THROWS. On the failure path the caller's next act is telling the
     * client their build failed, and on the success path the site is already
     * live -- a cleanup problem must cost neither.
     */
    public function clear(string $staticSiteId, string $buildId): void
    {
        try {
            self::assertSafeIds($staticSiteId, $buildId);
        } catch (\InvalidArgumentException) {
            // Reached routinely: an unsafe id is one of the things a build is
            // failed for. reset() threw before creating anything, and the ids
            // cannot be turned into a path safely, so doing nothing is the only
            // safe move.
            return;
        }

        $jobDir = $this->jobDir($staticSiteId, $buildId);

        if (!Filesystem::removeDir($jobDir)) {
            error_log(sprintf('[WARN] could not remove the job directory at %s', $jobDir));
        }

        // Only once the site has no other build: another may be in flight.
        $siteDir = $this->siteTempDir($staticSiteId);
        if (Filesystem::isEmptyDir($siteDir)) {
            @rmdir($siteDir);
        }
    }
}
