<?php

declare(strict_types=1);

namespace App\Job;

use Symfony\Component\HttpClient\Response\StreamWrapper;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches a build job's content archive into that job's input folder.
 * Redirects are capped, and the response is streamed to disk under a byte limit rather than buffered in memory.
 * publish has already checked that the URL is http or https with a host.
 *
 * @param HttpClientInterface $httpClient The http client to use to download the content.
 * @param int $maxMegabytes The maximum size of the content in megabytes.
 * @param int $maxDurationSeconds The maximum duration in seconds for the http request.
 * @param int $timeoutSeconds The timeout in seconds for the http request.
 * @param int $maxRedirects The maximum number of redirects for the http request.
 */
final class ContentDownloader
{
    /** Fixed filename for the content archive in the input folder */
    public const FILENAME = 'content.tar.gz';

    private readonly int $maxBytes;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        int $maxMegabytes,
        private readonly int $maxDurationSeconds,
        private readonly int $timeoutSeconds,
        private readonly int $maxRedirects,
    ) {
        if ($maxMegabytes < 1) {
            throw new \InvalidArgumentException(
                "Download limit must be at least 1 MB, got {$maxMegabytes}.",
            );
        }

        $this->maxBytes = $maxMegabytes * 1024 * 1024;
    }

    /**
     * Streams $url into $targetDir.
     *
     * @param string $url The url to download the content from.
     * @param string $targetDir The directory to download the content to.
     * @return string the path of the written file
     * @throws \RuntimeException if the download fails or the content is too large or the target
     * directory does not exist or cannot be opened for writing
     */
    public function download(string $url, string $targetDir): string
    {
        if (!is_dir($targetDir)) {
            throw new \RuntimeException("Target directory does not exist: {$targetDir}");
        }

        $target = rtrim($targetDir, '/') . '/' . self::FILENAME;

        $handle = @fopen($target, 'wb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf(
                'Could not open %s for writing: %s',
                $target,
                error_get_last()['message'] ?? 'unknown error',
            ));
        }

        $completed = false;
        try {
            $this->streamTo($url, $handle);
            $completed = true;
        } catch (HttpClientException $e) {
            throw new \RuntimeException("Download failed for {$url}: " . $e->getMessage(), 0, $e);
        } finally {
            fclose($handle);

            if (!$completed) {
                @unlink($target); // Deletes the incomplete download.
            }
        }

        return $target;
    }

    /**
     * Streams the content from the url to the handle.
     * 
     * @param string $url The url to stream the content from.
     * @param resource $handle The handle to stream the content to.
     * @return int The number of bytes written.
     * @throws \RuntimeException if the download fails or the content is too large.
     */
    private function streamTo(string $url, $handle): int
    {
        $response = $this->httpClient->request('GET', $url, [
            'max_duration' => $this->maxDurationSeconds,  // whole transfer, so a slow drip cannot hang the worker
            'timeout' => $this->timeoutSeconds,            // idle time between chunks
            'max_redirects' => $this->maxRedirects,
            'buffer' => false,                             // stream it; never hold the archive in memory
        ]);

        // Blocks until the headers land.
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("Download failed with HTTP {$status}: {$url}");
        }

        // Only short-circuits a body that announces itself as too big; an
        // absent header casts to 0 and falls through to the real cap below.
        $declared = (int) ($response->getHeaders(false)['content-length'][0] ?? 0);
        if ($declared > $this->maxBytes) {
            throw new \RuntimeException("Refusing {$url}: declared size {$declared} exceeds the {$this->maxBytes} byte limit.");
        }

        // Casting the response to a plain stream hands the read/write loop to
        // stream_copy_to_stream(). Asking for one byte past the cap is what
        // enforces it -- that byte arriving proves the body was over, so a
        // missing or lying Content-Length cannot get past this.
        $source = StreamWrapper::createResource($response, $this->httpClient);
        $written = @stream_copy_to_stream($source, $handle, $this->maxBytes + 1);

        if ($written === false) {
            $reason = error_get_last()['message'] ?? 'unknown error';
            throw new \RuntimeException("Could not write the download: {$reason}");
        }

        if ($written > $this->maxBytes) {
            throw new \RuntimeException("Aborted {$url}: exceeded the {$this->maxBytes} byte limit.");
        }

        return $written;
    }
}
