<?php

declare(strict_types=1);

namespace App\Job;

/**
 * Every path the worker touches, in one place, plus the allow-list that makes
 * a queue payload safe to use as one.
 *
 * JobWorkspace creates {buildTempDir}/{staticSiteId}/{buildId}/input and
 * /output; BuildPromoter reads that tree and knows where a finished build goes;
 * BuildQuarantine knows where a failed one goes. All three ask this class.
 *
 * The three roots are deliberately on SEPARATE MOUNTS in the dev stack: the
 * build temp tree is a named volume, the published tree is a bind mount so an
 * external nginx can serve it. That means a move between them cannot be a
 * rename() -- rename(2) rejects `old_path.mnt != new_path.mnt` with EXDEV
 * before it looks at the superblock, and PHP has no directory fallback -- so
 * BuildPromoter goes through Filesystem::moveDir(), which copies.
 *
 * What stays atomic is the part that matters: the swap of the live site. Both
 * the staging directory and the retiring directory live INSIDE publishedDir, so
 * publishing is two renames within one mount, and a reader sees the old site or
 * the new one and never half of either.
 */
final class JobLayout
{
    public const INPUT_DIR = 'input';
    public const OUTPUT_DIR = 'output';

    /**
     * Where a build is assembled before the final swap, and where the outgoing
     * site is parked during it. Inside publishedDir so both renames are
     * guaranteed same-mount without a fourth environment variable; the leading
     * dot keeps it out of anything serving the published tree (nginx is
     * configured to 404 dotfiles, and a half-promoted build must never be
     * reachable).
     */
    public const STAGING_DIR = '.staging';

    /**
     * static_site_id, build_id and slug all arrive from an HTTP payload, cross
     * a queue, and become directory names. A queue is not a trust boundary:
     * publish validates them too, but this is the check that actually stands
     * between a payload and the filesystem. Dots are excluded outright, which
     * keeps a bare ".." from passing.
     */
    private const SAFE_ID = '/^[A-Za-z0-9_-]{1,128}$/';

    public function __construct(
        private readonly string $buildTempDir,
        private readonly string $publishedDir,
        private readonly string $failedDir,
    ) {
    }

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
     * Separate from assertSafeIds() because the slug guards a different tree
     * and not every caller has one -- BuildQuarantine never touches the
     * published directory, so it must not be made to care about the slug.
     *
     * @throws \InvalidArgumentException if the slug is unsafe
     */
    public static function assertSafeSlug(string $slug): void
    {
        if (!self::isValidId($slug)) {
            throw new \InvalidArgumentException('Unsafe slug.');
        }
    }

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

    /** The rendered site, before promotion. */
    public function buildOutputDir(string $staticSiteId, string $buildId): string
    {
        return $this->jobDir($staticSiteId, $buildId) . '/' . self::OUTPUT_DIR;
    }

    /**
     * The live site. This is what the external nginx serves.
     *
     * Keyed on the SLUG, not the static_site_id, so the published path is the
     * readable name the client chose. Nothing enforces slug uniqueness yet --
     * two sites claiming one slug take the tree from each other, and that is
     * accepted until the publish API tracks slug ownership in Postgres. See
     * docs/roadmap.md.
     */
    public function publishedSiteDir(string $slug): string
    {
        return rtrim($this->publishedDir, '/') . '/' . $slug;
    }

    public function stagingRoot(): string
    {
        return rtrim($this->publishedDir, '/') . '/' . self::STAGING_DIR;
    }

    /**
     * A COMPLETE release, waiting for the swap. Its existence is load-bearing:
     * the promoter treats this path as "the copy finished" and will publish it
     * without re-checking, which is why a copy in progress uses the partial
     * name below and is renamed here only once it is whole.
     */
    public function stagingDir(string $buildId): string
    {
        return $this->stagingRoot() . '/' . $buildId;
    }

    /**
     * Where a cross-mount copy assembles before it is known to be complete. A
     * crash mid-copy leaves this behind, and it is never publishable.
     */
    public function partialStagingDir(string $buildId): string
    {
        return $this->stagingRoot() . '/' . $buildId . '.partial';
    }

    /** Where the outgoing site is parked so the swap never deletes before it publishes. */
    public function retiringDir(string $buildId): string
    {
        return $this->stagingRoot() . '/' . $buildId . '.old';
    }

    /** Keyed on build_id alone, so two failures of one site cannot collide. */
    public function failedJobDir(string $buildId): string
    {
        return rtrim($this->failedDir, '/') . '/' . $buildId;
    }
}
