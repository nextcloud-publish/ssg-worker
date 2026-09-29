<?php

declare(strict_types=1);

namespace App\Job;

/**
 * Helper class providing reset and clean methods for the job workspace and publish
 * method used to publish the build out of buildTempDir to publishedDir.
 * Further it hold the folder layout for the job workspace and the published site
 * allowing access via getter methods and offers validation for the ids.
 *
 *   {buildTempDir}/                    JOB_STORAGE_DIR, worker-private
 *     {buildId}/                       buildJobDir() -- one build
 *       input/                         buildJobInputDir()
 *         content.tar.gz               the download
 *         content_unarchived/          buildJobUnarchivedDir(), the renderer reads this
 *       output/                        buildJobOutputDir(), the renderer writes this
 *
 *   {publishedDir}/                    PUBLISHED_DIR, the bind mount nginx serves
 *     .staging/                        dotted so nginx 404s it
 *       {buildId}/                     publishStagingDir(), replaces {staticSiteId}/ when publishing
 *         {slug}/                      the rendered pages
 *     {staticSiteId}/                  publishSiteDir(), holds only the current slug
 *       {slug}/                        the live site, served at publishUrl()
 *
 * @param string $buildTempDir The directory to store the build temporary files.
 * @param string $publishedDir The directory to store the published files.
 * @param string $publishBaseUrl The public URL the published directory is served at.
 */
final class JobWorkspace
{
    public const INPUT_DIR = 'input';
    public const OUTPUT_DIR = 'output';
    /** where the downloaded archive is unpacked, inside input/ */
    public const UNARCHIVED_DIR = 'content_unarchived';
    /** intermediate directory in publishedDir where a html build is copied to before being published */
    public const STAGING_DIR = '.staging';

    /**
     * regex to validate static_site_id, build_id and slug against spaces and dots
     * to avoid e.g. directory traversal attacks
     */
    private const SAFE_ID = '/^[A-Za-z0-9_-]{1,128}$/';

    /** input/ and output/ are worker-private */
    private const JOB_DIR_MODE = 0o750;

    /** the published directory is traversed by the uid that serves it */
    private const PUBLISHED_DIR_MODE = 0o755;

    /** served files need to be readable by the serving uid, nothing more */
    private const PUBLISHED_FILE_MODE = 0o644;

    public function __construct(
        private readonly string $buildTempDir,
        private readonly string $publishedDir,
        private readonly string $publishBaseUrl,
    ) {
    }

    // --- validation -------------------------------------------------------

    /**
     * Checks if the id is a valid id.
     *
     * @param string $id The id to check.
     * @return bool true if the id is valid, false otherwise.
     */
    public static function isValidId(string $id): bool
    {
        return preg_match(self::SAFE_ID, $id) === 1;
    }

    /**
     * Check ids for valid characters and length.
     * The ids are used as directory names and must be safe to use as such.
     *
     * @param string $staticSiteId The static site id to check.
     * @param string $buildId The build id to check.
     * @param string $slug The slug to check.
     * @return void
     * @throws \InvalidArgumentException if any of the three is unsafe
     */
    public static function assertSafeJob(string $staticSiteId, string $buildId, string $slug): void
    {
        !self::isValidId($staticSiteId) ? throw new \InvalidArgumentException('Unsafe static_site_id.') : null;
        !self::isValidId($buildId) ? throw new \InvalidArgumentException('Unsafe build_id.') : null;
        !self::isValidId($slug) ? throw new \InvalidArgumentException('Unsafe slug.') : null;
    }

    // --- paths ------------------------------------------------------------

    /** {buildTempDir}/{buildId} -- one build's whole workspace. */
    public function buildJobDir(string $buildId): string
    {
        return rtrim($this->buildTempDir, '/') . '/' . $buildId;
    }

    /** Location where the archive is downloaded and extracted. */
    public function buildJobInputDir(string $buildId): string
    {
        return $this->buildJobDir($buildId) . '/' . self::INPUT_DIR;
    }

    /** Location inside input/ where the downloaded archive is unpacked. */
    public function buildJobUnarchivedDir(string $buildId): string
    {
        return $this->buildJobInputDir($buildId) . '/' . self::UNARCHIVED_DIR;
    }

    /** Location where build html site is rendered to. */
    public function buildJobOutputDir(string $buildId): string
    {
        return $this->buildJobDir($buildId) . '/' . self::OUTPUT_DIR;
    }

    /** {publishedDir}/{staticSiteId} -- holds the site's one published slug directory. */
    public function publishSiteDir(string $staticSiteId): string
    {
        return rtrim($this->publishedDir, '/') . '/' . $staticSiteId;
    }

    /** Intermediate directory where build html site is copied to before being published. */
    public function publishStagingDir(string $buildId): string
    {
        return rtrim($this->publishedDir, '/') . '/' . self::STAGING_DIR . '/' . $buildId;
    }

    /** {publishBaseUrl}/{staticSiteId}/{slug}/ -- the public URL of a published site. */
    public function publishUrl(string $staticSiteId, string $slug): string
    {
        return rtrim($this->publishBaseUrl, '/') . '/' . $staticSiteId . '/' . $slug . '/';
    }

    // --- lifecycle --------------------------------------------------------

    /**
     * Creates {buildTempDir}/{buildId}/input and /output.
     * Clears the directories if they already exist from a previous build attempt.
     * Assumes assertSafeJob() has already passed for $buildId.
     *
     * @param string $buildId The build id to reset.
     * @return string the job directory path
     *
     * @throws \RuntimeException if a directory cannot be created or removed
     */
    public function reset(string $buildId): string
    {
        $jobDir = $this->buildJobDir($buildId);

        $cleared = Filesystem::removeDir($jobDir);

        if (!$cleared) {
            throw new \RuntimeException(sprintf(
                'Could not clear the previous attempt at %s', $jobDir));
        }

        Filesystem::ensureDir($this->buildJobInputDir($buildId), self::JOB_DIR_MODE);
        Filesystem::ensureDir($this->buildJobOutputDir($buildId), self::JOB_DIR_MODE);

        return $jobDir;
    }

    /**
     * Moves the rendered html pages to the published directory at {publishedDir}/{staticSiteId}/{slug}.
     * The pages are staged as {slug}/ inside a staging directory, which then replaces the whole site directory.
     * Any slug directory published earlier for the same site is removed with it.
     *
     * Assumes assertSafeJob() has already passed for the param ids.
     *
     * @param string $buildId The build id to publish.
     * @param string $staticSiteId The static site id to publish.
     * @param string $slug The slug to publish.
     * @return void
     * @throws \InvalidArgumentException if the build rendered nothing
     * @throws \RuntimeException         on a filesystem failure worth retrying
     */
    public function publish(string $buildId, string $staticSiteId, string $slug): void
    {
        $output = $this->buildJobOutputDir($buildId);
        $published = $this->publishSiteDir($staticSiteId);
        $staging = $this->publishStagingDir($buildId);

        if (!is_dir($output)) {
            throw new \RuntimeException(sprintf(
                'Nothing to publish for build %s of site %s: no build output at %s.',$buildId, $staticSiteId, $output));
        }

        if (Filesystem::isEmptyDir($output)) {
            throw new \InvalidArgumentException(
                sprintf('Refusing to publish build %s: it rendered no pages.', $buildId));
        }

        Filesystem::removeDir($staging);
        Filesystem::ensureDir($staging, self::PUBLISHED_DIR_MODE);
        Filesystem::moveDir($output, $staging . '/' . $slug);

        Filesystem::setFileAccess(
            $staging,
            dirMode: self::PUBLISHED_DIR_MODE,
            fileMode: self::PUBLISHED_FILE_MODE,
        );

        Filesystem::removeDir($published);
        Filesystem::rename($staging, $published);
    }

    /**
     * Removes the build's workspace once the job is over.
     * This removes the input/ directory and the output/ directory.
     *
     * @param string $buildId The build id to clear.
     * @return void
     */
    public function clear(string $buildId): void
    {
        // The id can be unvalidated here: a job can fail before assertSafeJob() runs.
        if (!self::isValidId($buildId)) {
            return;
        }

        $jobDir = $this->buildJobDir($buildId);
        $removed = Filesystem::removeDir($jobDir);

        if (!$removed) {
            error_log(sprintf('[WARN] could not remove the job directory at %s', $jobDir));
        }
    }
}
