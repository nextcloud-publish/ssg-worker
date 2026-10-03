<?php

declare(strict_types=1);

namespace App\Tests\Job;

use App\Job\JobWorkspace;
use PHPUnit\Framework\TestCase;

/**
 * Covers JobWorkspace::publish().
 *
 * Kept apart from JobWorkspaceTest because publishing a site and managing a build's scratch
 * directories are distinct enough that one file covering both would be harder to read.
 *
 * A real temp tree stands in for the volumes. Most of this file puts both roots in one temp
 * directory, so Filesystem::moveDir() takes its rename() path and the tests exercise the logic
 * rather than the transfer. The tests at the bottom force the copy path with /dev/shm, because
 * the operator decides how the two roots are mounted.
 */
final class JobWorkspacePublishTest extends TestCase
{
    private const SITE = '11f5b798-6f34-4951-ad8b-bfd623ded5c2';
    private const BUILD = '0199a1b2-3c4d-7e5f-8a9b-0c1d2e3f4a5b';
    private const SLUG = 'demo-site';

    private string $root;
    private ?string $publishedOnOtherMount = null;
    private JobWorkspace $workspace;
    private string $logFile;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/worker-publish-test-' . bin2hex(random_bytes(6));

        $this->workspace = new JobWorkspace(
            $this->root . '/build_temp',
            $this->root . '/published',
            'http://sites.test',
        );

        $this->logFile = sys_get_temp_dir() . '/worker-publish-log-' . bin2hex(random_bytes(6)) . '.log';
        $this->previousErrorLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
        @unlink($this->logFile);

        foreach ([$this->root, $this->publishedOnOtherMount] as $dir) {
            if ($dir !== null && is_dir($dir)) {
                exec('rm -rf ' . escapeshellarg($dir));
            }
        }
    }

    /**
     * Builds the tree JobWorkspace::reset() leaves behind, at the same 0750 mode it uses.
     * That mode is what publish() has to widen on the way out.
     *
     * @param array<string, string> $files The output files, keyed by path relative to output/.
     * @param string $build The build id to create the tree under.
     * @return string the build output directory
     */
    private function givenBuildOutput(array $files = ['index.html' => '<h1>hello</h1>'], string $build = self::BUILD): string
    {
        $output = $this->workspace->buildJobOutputDir($build);
        mkdir($output, 0o750, true);
        mkdir($this->workspace->buildJobDir($build) . '/input', 0o750, true);
        file_put_contents($this->workspace->buildJobDir($build) . '/input/content.tar.gz', 'archive');

        foreach ($files as $path => $contents) {
            $full = $output . '/' . $path;
            if (!is_dir(\dirname($full))) {
                mkdir(\dirname($full), 0o755, true);
            }
            file_put_contents($full, $contents);
        }

        return $output;
    }

    /**
     * The live slug directory for the site under test.
     *
     * @return string the published slug path
     */
    private function publishedSite(): string
    {
        return $this->workspace->publishSiteDir(self::SITE) . '/' . self::SLUG;
    }

    public function testPublishesTheRenderedSite(): void
    {
        $this->givenBuildOutput();

        $this->workspace->publish(self::BUILD, self::SITE, self::SLUG);

        self::assertFileExists($this->publishedSite() . '/index.html');
        self::assertSame('<h1>hello</h1>', file_get_contents($this->publishedSite() . '/index.html'));
    }

    /**
     * The output is moved, not copied, so the next publish of the same build cannot ship a stale tree.
     * Retiring the rest of the job directory is JobWorkspace::clear()'s job.
     */
    public function testTakesTheBuildOutputWithIt(): void
    {
        $this->givenBuildOutput();

        $this->workspace->publish(self::BUILD, self::SITE, self::SLUG);

        self::assertDirectoryDoesNotExist($this->workspace->buildJobOutputDir(self::BUILD));

        /** The rest of the job tree is untouched: the handler clears it. */
        self::assertFileExists($this->workspace->buildJobDir(self::BUILD) . '/input/content.tar.gz');
    }

    /**
     * reset() creates output/ at 0750 and the worker runs as root.
     * Renamed in unchanged, that is a directory the serving uid cannot traverse, which is a 403 on every page.
     */
    public function testThePublishedSiteIsTraversableByOtherUsers(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('running as root: permission bits are not enforced');
        }

        $this->givenBuildOutput();

        $this->workspace->publish(self::BUILD, self::SITE, self::SLUG);

        foreach ([$this->workspace->publishSiteDir(self::SITE), $this->publishedSite()] as $dir) {
            $mode = fileperms($dir) & 0o777;
            self::assertSame(0o755, $mode, sprintf('%s is mode %o, not 0755', $dir, $mode));
        }
    }

    /**
     * Not just the root: a page or an attachment the serving uid cannot read is a broken site too.
     * An attachment keeps whatever mode the tarball gave it all the way through the render.
     */
    public function testEveryPublishedPathGetsAServableMode(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('running as root: permission bits are not enforced');
        }

        $this->givenBuildOutput([
            'index.html' => 'root page',
            'Cats/index.html' => 'nested page',
        ]);

        /** As if it came out of a tarball owner-only. */
        chmod($this->workspace->buildJobOutputDir(self::BUILD) . '/index.html', 0o600);

        $this->workspace->publish(self::BUILD, self::SITE, self::SLUG);

        self::assertSame(0o755, fileperms($this->publishedSite() . '/Cats') & 0o777);
        self::assertSame(0o644, fileperms($this->publishedSite() . '/index.html') & 0o777);
        self::assertSame(0o644, fileperms($this->publishedSite() . '/Cats/index.html') & 0o777);
    }

    /**
     * rename() onto an existing non-empty directory fails with ENOTEMPTY and never merges.
     * A page deleted from the collective has to disappear from the published site.
     */
    public function testARepublishReplacesTheSiteRatherThanMergingIntoIt(): void
    {
        $this->givenBuildOutput([
            'index.html' => 'first build',
            'removed-later.html' => 'this page goes away',
        ]);
        $this->workspace->publish(self::BUILD, self::SITE, self::SLUG);

        $secondBuild = 'bbbbbbbbbbbbbbbb';
        $this->givenBuildOutput(['index.html' => 'second build'], build: $secondBuild);
        $this->workspace->publish($secondBuild, self::SITE, self::SLUG);

        self::assertSame('second build', file_get_contents($this->publishedSite() . '/index.html'));
        self::assertFileDoesNotExist($this->publishedSite() . '/removed-later.html');
    }

    /**
     * The build output is the only thing publish() will publish.
     * It never inspects the staging or published directories to work out how far a previous attempt got,
     * so with no output there is nothing to do but rebuild.
     */
    public function testPublishingAgainWithNoBuildOutputRebuildsRatherThanSkipping(): void
    {
        $this->givenBuildOutput();
        $this->workspace->publish(self::BUILD, self::SITE, self::SLUG);

        /** The site is live, and the output tree moved with it. */
        self::assertSame('<h1>hello</h1>', file_get_contents($this->publishedSite() . '/index.html'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nothing to publish');

        $this->workspace->publish(self::BUILD, self::SITE, self::SLUG);
    }

    /**
     * Staging is scratch space, not a record of progress.
     * A tree left there by a crashed attempt is cleared, because merging would ship a mix of two builds.
     */
    public function testAStagedTreeFromACrashedAttemptIsDiscardedNotMerged(): void
    {
        $staging = $this->workspace->publishStagingDir(self::BUILD);
        mkdir($staging, 0o755, true);
        file_put_contents($staging . '/stale.html', 'from a crashed attempt');

        $this->givenBuildOutput(['index.html' => 'the real build']);

        $this->workspace->publish(self::BUILD, self::SITE, self::SLUG);

        self::assertSame('the real build', file_get_contents($this->publishedSite() . '/index.html'));
        self::assertFileDoesNotExist($this->workspace->publishSiteDir(self::SITE) . '/stale.html');
    }

    /** Staging is emptied on the way out, so it never accumulates. */
    public function testStagingIsLeftEmptyAfterPublishing(): void
    {
        $this->givenBuildOutput();

        $this->workspace->publish(self::BUILD, self::SITE, self::SLUG);

        self::assertDirectoryDoesNotExist($this->workspace->publishStagingDir(self::BUILD));
    }

    /** The swap removes the live site first, so publishing an empty build would replace a working site with nothing. */
    public function testRefusesToPublishAnEmptyBuild(): void
    {
        $this->givenBuildOutput([]);

        /** Terminal, not retryable: re-rendering the same archive produces the same nothing. */
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rendered no pages');

        $this->workspace->publish(self::BUILD, self::SITE, self::SLUG);
    }

    public function testAnEmptyBuildLeavesTheLiveSiteAlone(): void
    {
        $this->givenBuildOutput(['index.html' => 'the good site']);
        $this->workspace->publish(self::BUILD, self::SITE, self::SLUG);

        $this->givenBuildOutput([], build: 'cccccccccccccccc');

        try {
            $this->workspace->publish('cccccccccccccccc', self::SITE, self::SLUG);
            self::fail('Expected the empty build to be refused.');
        } catch (\InvalidArgumentException) {
            self::assertSame('the good site', file_get_contents($this->publishedSite() . '/index.html'));
        }
    }

    /** With no build output there is nothing to publish, and an already-published site must not be taken for success. */
    public function testFailsWhenThereIsNothingToPublish(): void
    {
        /** RuntimeException, not InvalidArgumentException: a retry re-downloads and re-renders. */
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nothing to publish');

        $this->workspace->publish(self::BUILD, self::SITE, self::SLUG);
    }

    public function testTheSiteIsPublishedUnderItsStaticSiteIdThenItsSlug(): void
    {
        $this->givenBuildOutput();

        $this->workspace->publish(self::BUILD, self::SITE, self::SLUG);

        self::assertFileExists($this->root . '/published/' . self::SITE . '/' . self::SLUG . '/index.html');
        self::assertSame([self::SLUG], array_values(array_diff(scandir($this->root . '/published/' . self::SITE), ['.', '..'])));
    }

    /** A site whose slug changed must stop being served under the old one. */
    public function testPublishingANewSlugRemovesTheSitesOldSlug(): void
    {
        $this->givenBuildOutput(['index.html' => 'under the old slug']);
        $this->workspace->publish(self::BUILD, self::SITE, 'old-slug');

        $secondBuild = '0199a1b2-3c4d-7e5f-8a9b-bbbbbbbbbbbb';
        $this->givenBuildOutput(['index.html' => 'under the new slug'], build: $secondBuild);
        $this->workspace->publish($secondBuild, self::SITE, self::SLUG);

        self::assertDirectoryDoesNotExist($this->workspace->publishSiteDir(self::SITE) . '/old-slug');
        self::assertSame('under the new slug', file_get_contents($this->publishedSite() . '/index.html'));
    }

    /** The published tree is keyed on static_site_id, so two sites may use the same slug without touching each other. */
    public function testTwoSitesWithTheSameSlugCoexist(): void
    {
        $this->givenBuildOutput(['index.html' => 'the first site']);
        $this->workspace->publish(self::BUILD, self::SITE, self::SLUG);

        $otherSite = '4b6c1e2a-9d3f-4a7b-8c5e-2f1d0a9b8c7d';
        $otherBuild = '0199a1b2-3c4d-7e5f-8a9b-dddddddddddd';
        $this->givenBuildOutput(['index.html' => 'the second site'], build: $otherBuild);
        $this->workspace->publish($otherBuild, $otherSite, self::SLUG);

        self::assertSame('the first site', file_get_contents($this->publishedSite() . '/index.html'));
        self::assertSame(
            'the second site',
            file_get_contents($this->workspace->publishSiteDir($otherSite) . '/' . self::SLUG . '/index.html'),
        );
    }

    // --- the cross-mount reality of the dev stack -------------------------

    /**
     * A workspace whose published root is on a different mount point than its build root.
     * In the real stack the build temp tree is a named volume and the published tree is a bind mount,
     * so every publish goes through the copy path instead of rename().
     *
     * @return JobWorkspace the workspace spanning two mounts
     */
    private function workspaceAcrossMounts(): JobWorkspace
    {
        if (!is_dir('/dev/shm') || !is_writable('/dev/shm')) {
            self::markTestSkipped('no second mount point available to force EXDEV');
        }

        $this->publishedOnOtherMount = '/dev/shm/worker-publish-' . bin2hex(random_bytes(6));
        mkdir($this->publishedOnOtherMount, 0o755, true);

        if (stat($this->root)['dev'] === stat($this->publishedOnOtherMount)['dev']) {
            self::markTestSkipped('/dev/shm shares a device with the temp dir');
        }

        $this->workspace = new JobWorkspace(
            $this->root . '/build_temp',
            $this->publishedOnOtherMount,
            'http://sites.test',
        );

        return $this->workspace;
    }

    public function testPublishesAcrossAMountBoundary(): void
    {
        $workspace = $this->workspaceAcrossMounts();
        $this->givenBuildOutput(['index.html' => 'copied across mounts']);

        $workspace->publish(self::BUILD, self::SITE, self::SLUG);

        self::assertSame('copied across mounts', file_get_contents($this->publishedSite() . '/index.html'));
        self::assertDirectoryDoesNotExist($this->workspace->buildJobOutputDir(self::BUILD));
    }

    public function testARepublishAcrossAMountBoundaryStillReplaces(): void
    {
        $workspace = $this->workspaceAcrossMounts();

        $this->givenBuildOutput(['index.html' => 'first', 'gone-later.html' => 'x']);
        $workspace->publish(self::BUILD, self::SITE, self::SLUG);

        $second = 'bbbbbbbbbbbbbbbb';
        $this->givenBuildOutput(['index.html' => 'second'], build: $second);
        $workspace->publish($second, self::SITE, self::SLUG);

        self::assertSame('second', file_get_contents($this->publishedSite() . '/index.html'));
        self::assertFileDoesNotExist($this->publishedSite() . '/gone-later.html');
    }
}
