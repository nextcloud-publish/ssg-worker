<?php

declare(strict_types=1);

namespace App\Job;

/**
 * Moves a failed build's workspace out of the temp tree so it can be looked at,
 * instead of being deleted or left to collide with the next attempt.
 *
 * Nothing here throws for a bad or missing job directory, and that is
 * deliberate: this runs on the path whose entire purpose is telling the client
 * their build failed. Refusing to quarantine must not also suppress that
 * callback -- the filesystem is the side concern here, the notification is the
 * point.
 *
 * That guarantee does NOT extend to a real filesystem failure: a full disk or
 * an unmounted FAILED_DIR still comes out of Filesystem::moveDir() as a
 * RuntimeException. BuildJobHandler wraps the call for exactly that reason, so
 * losing the evidence can never also lose the client's failure notice.
 */
final class BuildQuarantine
{
    /** Never served, so it stays tighter than the published tree. */
    private const FAILED_DIR_MODE = 0o750;

    public function __construct(private readonly JobLayout $layout)
    {
    }

    /**
     * Moves {buildTempDir}/{site}/{build} to {failedDir}/{build}.
     *
     * @return bool whether anything was actually moved
     */
    public function quarantine(string $staticSiteId, string $buildId): bool
    {
        try {
            JobLayout::assertSafeIds($staticSiteId, $buildId);
        } catch (\InvalidArgumentException $e) {
            // Expected, not exceptional: an unsafe static_site_id is one of the
            // things a build is failed FOR, so it arrives here routinely. There
            // is no directory to move -- JobWorkspace::reset() threw before
            // creating one -- and the ids cannot be used to build a path safely.
            error_log(sprintf('[WARN] not quarantining build %s: %s', $buildId, $e->getMessage()));

            return false;
        }

        $jobDir = $this->layout->jobDir($staticSiteId, $buildId);
        $failed = $this->layout->failedJobDir($buildId);

        if (is_dir($failed)) {
            // A replay: a previous attempt quarantined it and then failed to
            // deliver the callback.
            return false;
        }

        if (!is_dir($jobDir)) {
            // Normal whenever the build failed before its directories existed --
            // an unsafe id, or a workspace that could not be created at all.
            error_log(sprintf('[INFO] nothing to quarantine for build %s at %s', $buildId, $jobDir));

            return false;
        }

        Filesystem::ensureDir(\dirname($failed), self::FAILED_DIR_MODE);

        // The temp tree and the quarantine tree are separate mounts, so this
        // copies rather than renames and is not atomic. It lands on a scratch
        // name first so a crash mid-copy leaves `<build>.partial` rather than a
        // half-copied directory at the path the replay guard above treats as
        // "already quarantined" -- which would make the next delivery skip a
        // job that was never fully moved.
        $partial = $failed . '.partial';
        Filesystem::removeDir($partial);
        Filesystem::moveDir($jobDir, $partial);
        Filesystem::rename($partial, $failed);

        error_log(sprintf('[INFO] quarantined build %s to %s', $buildId, $failed));

        $siteDir = $this->layout->siteTempDir($staticSiteId);
        if (Filesystem::isEmptyDir($siteDir)) {
            @rmdir($siteDir);
        }

        return true;
    }
}
