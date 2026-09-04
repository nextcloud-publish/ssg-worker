<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Content\ArchiveExtractor;
use App\Content\ContentDownloader;
use App\Storage\JobWorkspace;


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
        private readonly JobWorkspace $workspace
    ) {
    }

    /**
     * @throws \InvalidArgumentException if the static_site_id is unsafe
     * @throws \RuntimeException if the message is unusable or the download fails
     */
    public function handle(string $messageBody): void
    {
        $job = $this->decode($messageBody);

        // Names the job's folder; JobWorkspace, not this class, decides
        // whether it is safe to use as one.
        $staticSiteId = $this->requireString($job, 'static_site_id');

        $jobDir = $this->workspace->createJobDirectories($staticSiteId);

        error_log(sprintf('[INFO] prepared job workdir %s', $jobDir));

        $url = $this->requireString($job, 'content_download_url');
        $inputDir = rtrim($jobDir, '/') . '/input';
        $file = $this->downloader->download($url, $inputDir );

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
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(string $messageBody): array
    {
        try {
            $job = json_decode($messageBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Message body is not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!\is_array($job)) {
            throw new \RuntimeException('Message body is not a JSON object.');
        }

        return $job;
    }

    /**
     * @param array<string,mixed> $job
     */
    private function requireString(array $job, string $key): string
    {
        $value = $job[$key] ?? null;
        if (!\is_string($value) || $value === '') {
            throw new \RuntimeException("Message is missing a usable {$key}.");
        }

        return $value;
    }
}
