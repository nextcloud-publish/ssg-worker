<?php

declare(strict_types=1);

namespace App\Job;

/**
 * Holds the folder layout of a build job and of the published site, and resets, publishes and clears them.
 * The ids are used as directory names unchecked: publish validates them before a job is enqueued.
 *
 *   {buildTempDir}/                    JOB_STORAGE_DIR, root of all builds
 *     {buildId}/                       buildJobDir() -- one build
 *       input/                         buildJobInputDir(), 0750
 *         content.tar.gz               the download
 *         content_unarchived/          buildJobUnarchivedDir(), the archive is unpacked here and the renderer reads it
 *       output/                        buildJobOutputDir(), 0750, the renderer writes this
 *
 *   {publishedDir}/                    PUBLISHED_DIR, the bind mount nginx serves
 *     .staging/                        dotted so nginx 404s it
 *       {buildId}/                     publishStagingDir(), the build before it goes live; dirs 0755, files 0644 from here down
 *         {slug}/                      the rendered pages
 *     {staticSiteId}/                  publishSiteDir(), replaced by the staged {buildId}/, holds only the current slug
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
    public const UNARCHIVED_DIR = 'content_unarchived';
    public const STAGING_DIR = '.staging';

    private const BUILD_JOB_DIR_MODE = 0o750;
    private const PUBLISHED_DIR_MODE = 0o755;
    private const PUBLISHED_FILE_MODE = 0o644;

    public function __construct(
        private readonly string $buildTempDir,
        private readonly string $publishedDir,
        private readonly string $publishBaseUrl,
    ) {
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
     *
     * @param string $buildId The build id to reset.
     * @return string the job directory path
     *
     * @throws \RuntimeException if a directory cannot be created or removed
     */
    public function reset(string $buildId): string
    {
        $jobDir = $this->buildJobDir($buildId);

        if (!$this->clear($buildId)) {
            throw new \RuntimeException(sprintf(
                'Could not clear the previous attempt at %s', $jobDir));
        }

        Filesystem::ensureDir($this->buildJobInputDir($buildId), self::BUILD_JOB_DIR_MODE);
        Filesystem::ensureDir($this->buildJobOutputDir($buildId), self::BUILD_JOB_DIR_MODE);

        return $jobDir;
    }

    /**
     * Moves the rendered html pages to the published directory at {publishedDir}/{staticSiteId}/{slug}.
     * The pages are staged as {slug}/ inside a staging directory, which then replaces the whole site directory.
     * Any slug directory published earlier for the same site is removed with it.
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
     * Removes the build's workspace, the input/ and output/ directories.
     * Logs a warning and returns false when that fails, rather than throwing.
     *
     * @param string $buildId The build id to clear.
     * @return bool true when the workspace is removed, false when removal fails
     */
    public function clear(string $buildId): bool
    {
        $jobDir = $this->buildJobDir($buildId);
        $removed = Filesystem::removeDir($jobDir);

        if (!$removed) {
            error_log(sprintf('[WARN] could not remove the job directory at %s', $jobDir));
        }

        return $removed;
    }
}
