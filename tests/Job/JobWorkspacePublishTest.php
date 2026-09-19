<?php

declare(strict_types=1);

namespace App\Tests\Job;

use App\Job\JobWorkspace;
use PHPUnit\Framework\TestCase;

/**
 * JobWorkspace::publish(). Kept apart from JobWorkspaceTest because publishing a
 * site and managing a build's scratch directories are distinct enough that one
 * file covering both would be harder to read.
 *
 * A real temp tree stands in for the volumes. Most of this file puts both roots
 * in one temp directory, so Filesystem::moveDir() takes its rename() fast path.
 * A deployment may or may not do that -- the roots are mounted however the
 * operator needs -- so the tests under "the cross-mount reality" at the bottom
 * force the copy path with /dev/shm. Everything above them exercises the logic
 * rather than the transfer.
 */
final class JobWorkspacePublishTest extends TestCase
{
    private const SITE = 'site-42';
    private const BUILD = '16ef078ad37fd894';
    /** The published tree is keyed on the slug, not the site id. */
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
     * Builds the tree JobWorkspace::reset() leaves behind, at the same 0750
     * mode it uses -- which is exactly what publish() has to correct on the way
     * out, or the published site 403s for whatever uid serves it.
     *
     * @param array<string, string> $files relative path => contents
     */
    private function givenBuildOutput(array $files = ['index.html' => '<h1>hello</h1>'], string $build = self::BUILD): string
    {
        $output = $this->workspace->buildOutputDir(self::SITE, $build);
        mkdir($output, 0o750, true);
        mkdir($this->workspace->jobDir(self::SITE, $build) . '/input', 0o750, true);
        file_put_contents($this->workspace->jobDir(self::SITE, $build) . '/input/content.tar.gz', 'archive');

        foreach ($files as $path => $contents) {
            $full = $output . '/' . $path;
            if (!is_dir(\dirname($full))) {
                mkdir(\dirname($full), 0o755, true);
            }
            file_put_contents($full, $contents);
        }

        return $output;
    }

    private function publishedSite(): string
    {
        return $this->workspace->publishedSiteDir(self::SLUG);
    }

    public function testPublishesTheRenderedSite(): void
    {
        $this->givenBuildOutput();

        $this->workspace->publish(self::SITE, self::BUILD, self::SLUG);

        self::assertFileExists($this->publishedSite() . '/index.html');
        self::assertSame('<h1>hello</h1>', file_get_contents($this->publishedSite() . '/index.html'));
    }

    /**
     * The output is moved, not copied: leaving it behind would mean the next
     * publish of the same build could ship a stale tree. Retiring what is
     * left of the job directory is JobWorkspace::clear()'s job, not this one's.
     */
    public function testTakesTheBuildOutputWithIt(): void
    {
        $this->givenBuildOutput();

        $this->workspace->publish(self::SITE, self::BUILD, self::SLUG);

        self::assertDirectoryDoesNotExist($this->workspace->buildOutputDir(self::SITE, self::BUILD));
        // The rest of the job tree is untouched -- the handler clears it.
        self::assertFileExists($this->workspace->jobDir(self::SITE, self::BUILD) . '/input/content.tar.gz');
    }

    /**
     * reset() creates output/ at 0750 and the worker runs as root. Renamed in
     * unchanged, that is a directory whatever serves the site cannot traverse
     * -- a 403 on every page, invisible to anything but an end-to-end check.
     */
    public function testThePublishedSiteIsTraversableByOtherUsers(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('running as root: permission bits are not enforced');
        }

        $this->givenBuildOutput();

        $this->workspace->publish(self::SITE, self::BUILD, self::SLUG);

        $mode = fileperms($this->publishedSite()) & 0o777;
        self::assertSame(0o755, $mode, sprintf('published site is mode %o, not 0755', $mode));
    }

    /**
     * The republish case, and the single most likely thing to get wrong:
     * rename() onto an existing non-empty directory fails with ENOTEMPTY and
     * never merges. A page deleted from the collective must disappear.
     */
    public function testARepublishReplacesTheSiteRatherThanMergingIntoIt(): void
    {
        $this->givenBuildOutput([
            'index.html' => 'first build',
            'removed-later.html' => 'this page goes away',
        ]);
        $this->workspace->publish(self::SITE, self::BUILD, self::SLUG);

        $secondBuild = 'bbbbbbbbbbbbbbbb';
        $this->givenBuildOutput(['index.html' => 'second build'], build: $secondBuild);
        $this->workspace->publish(self::SITE, $secondBuild, self::SLUG);

        self::assertSame('second build', file_get_contents($this->publishedSite() . '/index.html'));
        self::assertFileDoesNotExist($this->publishedSite() . '/removed-later.html');
    }

    /**
     * The build output is the only thing publish() will publish. It does not
     * look at the staging or published directories to work out how far a
     * previous attempt got -- build state is not encoded in the filesystem --
     * so with no output there is nothing to do but rebuild.
     */
    public function testPublishingAgainWithNoBuildOutputRebuildsRatherThanSkipping(): void
    {
        $this->givenBuildOutput();
        $this->workspace->publish(self::SITE, self::BUILD, self::SLUG);

        // The site is live, and the output tree moved with it.
        self::assertSame('<h1>hello</h1>', file_get_contents($this->publishedSite() . '/index.html'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nothing to publish');

        $this->workspace->publish(self::SITE, self::BUILD, self::SLUG);
    }

    /**
     * Staging is scratch space, not a record of progress: a tree left there by
     * a crashed attempt is cleared, never published and never merged into.
     * Merging would ship a mix of two builds.
     */
    public function testAStagedTreeFromACrashedAttemptIsDiscardedNotMerged(): void
    {
        $staging = $this->workspace->stagingDir(self::BUILD);
        mkdir($staging, 0o755, true);
        file_put_contents($staging . '/stale.html', 'from a crashed attempt');

        $this->givenBuildOutput(['index.html' => 'the real build']);

        $this->workspace->publish(self::SITE, self::BUILD, self::SLUG);

        self::assertSame('the real build', file_get_contents($this->publishedSite() . '/index.html'));
        self::assertFileDoesNotExist($this->publishedSite() . '/stale.html');
    }

    /** Staging is emptied on the way out, so it never accumulates. */
    public function testStagingIsLeftEmptyAfterPublishing(): void
    {
        $this->givenBuildOutput();

        $this->workspace->publish(self::SITE, self::BUILD, self::SLUG);

        self::assertDirectoryDoesNotExist($this->workspace->stagingDir(self::BUILD));
    }

    /**
     * The swap removes the live site, so publishing an empty build would take a
     * working site down and replace it with nothing -- strictly worse than
     * failing the message.
     */
    public function testRefusesToPublishAnEmptyBuild(): void
    {
        $this->givenBuildOutput([]);

        // Terminal, not retryable: re-rendering the same archive produces the
        // same nothing.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rendered no pages');

        $this->workspace->publish(self::SITE, self::BUILD, self::SLUG);
    }

    public function testAnEmptyBuildLeavesTheLiveSiteAlone(): void
    {
        $this->givenBuildOutput(['index.html' => 'the good site']);
        $this->workspace->publish(self::SITE, self::BUILD, self::SLUG);

        $this->givenBuildOutput([], build: 'cccccccccccccccc');

        try {
            $this->workspace->publish(self::SITE, 'cccccccccccccccc', self::SLUG);
            self::fail('Expected the empty build to be refused.');
        } catch (\InvalidArgumentException) {
            self::assertSame('the good site', file_get_contents($this->publishedSite() . '/index.html'));
        }
    }

    /**
     * With no build output there is nothing to publish, and publish() must say
     * so rather than treat an already-published site as success.
     */
    public function testFailsWhenThereIsNothingToPublish(): void
    {
        // RuntimeException, not InvalidArgumentException: a retry re-downloads
        // and re-renders, so this IS a state a retry can escape.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nothing to publish');

        $this->workspace->publish(self::SITE, self::BUILD, self::SLUG);
    }

    public function testTheSiteIsPublishedUnderItsSlugNotItsStaticSiteId(): void
    {
        $this->givenBuildOutput();

        $this->workspace->publish(self::SITE, self::BUILD, self::SLUG);

        self::assertFileExists($this->root . '/published/' . self::SLUG . '/index.html');
        self::assertDirectoryDoesNotExist($this->root . '/published/' . self::SITE);
    }

    /**
     * Nothing enforces slug uniqueness -- publish has no store to enforce it
     * with -- so a build claiming a slug another site already published takes
     * it over. Accepted until the API tracks slug ownership; see
     * docs/roadmap.md. Pinned here so the day it changes, it changes
     * deliberately.
     */
    public function testADifferentSiteClaimingTheSameSlugTakesItOver(): void
    {
        $this->givenBuildOutput(['index.html' => 'the first site']);
        $this->workspace->publish(self::SITE, self::BUILD, self::SLUG);

        $otherSite = 'site-99';
        $otherBuild = 'dddddddddddddddd';
        $output = $this->workspace->buildOutputDir($otherSite, $otherBuild);
        mkdir($output, 0o750, true);
        file_put_contents($output . '/index.html', 'the second site');

        $this->workspace->publish($otherSite, $otherBuild, self::SLUG);

        self::assertSame('the second site', file_get_contents($this->publishedSite() . '/index.html'));
    }

    // --- the cross-mount reality of the dev stack -------------------------

    /**
     * Everything above shares one temp directory, so rename() succeeds and the
     * copy path never runs. In the real stack the build temp tree is a named
     * volume and the published tree is a bind mount, which is a different mount
     * point -- so every publish goes through the copy instead. /dev/shm is
     * the only way to reproduce that here.
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
        );

        return $this->workspace;
    }

    public function testPublishesAcrossAMountBoundary(): void
    {
        $workspace = $this->workspaceAcrossMounts();
        $this->givenBuildOutput(['index.html' => 'copied across mounts']);

        $workspace->publish(self::SITE, self::BUILD, self::SLUG);

        self::assertSame('copied across mounts', file_get_contents($this->publishedSite() . '/index.html'));
        self::assertDirectoryDoesNotExist($this->workspace->buildOutputDir(self::SITE, self::BUILD));
    }

    public function testARepublishAcrossAMountBoundaryStillReplaces(): void
    {
        $workspace = $this->workspaceAcrossMounts();

        $this->givenBuildOutput(['index.html' => 'first', 'gone-later.html' => 'x']);
        $workspace->publish(self::SITE, self::BUILD, self::SLUG);

        $second = 'bbbbbbbbbbbbbbbb';
        $this->givenBuildOutput(['index.html' => 'second'], build: $second);
        $workspace->publish(self::SITE, $second, self::SLUG);

        self::assertSame('second', file_get_contents($this->publishedSite() . '/index.html'));
        self::assertFileDoesNotExist($this->publishedSite() . '/gone-later.html');
    }


}
