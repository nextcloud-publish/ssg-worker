<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Content\ArchiveExtractor;
use App\Content\ContentDownloader;
use App\Message\BuildJob;
use App\Rendering\SiteRenderer;
use App\Storage\JobWorkspace;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Turns one q.builds message into a built site: provisions the job's folders,
 * downloads its content archive, extracts it and renders the pages.
 *
 * Takes an already-decoded BuildJob: Messenger's serializer owns parsing the
 * body and resolving it to a class, so a message that does not deserialize
 * never reaches here.
 *
 * Throwing is how a bad job is reported: with max_retries: 0 on the transport,
 * Messenger logs and acks rather than redelivering one that cannot succeed.
 *
 * error_log() rather than an injected PSR logger, for now -- a real logger
 * setup (channel, formatting, destination) is a separate decision to make
 * later, not a byproduct of this transport migration.
 */
#[AsMessageHandler]
final class BuildJobHandler
{
    /**
     * Where the archive is unpacked, relative to the job's input/ folder.
     * Kept out of the archive's own directory so that whatever renders the
     * site is handed a path containing nothing but the page tree.
     */
    public const UNARCHIVED_DIR = 'content_unarchived';

    public function __construct(
        private readonly ContentDownloader $downloader,
        private readonly ArchiveExtractor $extractor,
        private readonly SiteRenderer $renderer,
        private readonly JobWorkspace $workspace,
    ) {
    }

    /**
     * @throws \InvalidArgumentException if the static_site_id is unsafe
     * @throws \RuntimeException         if any step fails
     */
    public function __invoke(BuildJob $message): void
    {
        // Names the job's folder; JobWorkspace, not this class, decides
        // whether it is safe to use as one.
        $jobDir = $this->workspace->createJobDirectories($message->static_site_id);

        error_log(sprintf('[INFO] prepared job workdir %s', $jobDir));

        $url = $message->content_download_url;
        $inputDir = $jobDir . '/' . JobWorkspace::INPUT_DIR;
        $file = $this->downloader->download($url, $inputDir);

        error_log(sprintf(
            '[INFO] downloaded %s to %s (%d bytes)',
            $url,
            $file,
            (int) @filesize($file),
        ));

        // Into its own folder rather than next to the archive: this is the
        // path the site generator gets handed, and it must contain only pages.
        $unarchivedDir = $inputDir . '/' . self::UNARCHIVED_DIR;
        $this->extractor->extract($file, $unarchivedDir);

        error_log(sprintf('[INFO] extracted %s into %s', $file, $unarchivedDir));

        // The slug is rendered as the header link on every generated page.
        $outputDir = $jobDir . '/' . JobWorkspace::OUTPUT_DIR;
        $pages = $this->renderer->render($unarchivedDir, $outputDir, $message->slug);

        error_log(sprintf('[INFO] rendered %d page(s) into %s', $pages, $outputDir));
    }
}
