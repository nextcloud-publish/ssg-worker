<?php

declare(strict_types=1);

namespace App\Job;

use Symfony\Component\HttpClient\Response\StreamWrapper;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches a build job's content archive into that job's input folder.
 *
 * The URL travels from the original build request through the queue, so it is
 * treated as untrusted throughout: only http/https are fetched, redirects are
 * capped, and the response is streamed to disk under a byte limit rather than
 * buffered in memory.
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

    /** Allow http for testing */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    private readonly int $maxBytes;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        int $maxMegabytes,
        private readonly int $maxDurationSeconds = 300,
        private readonly int $timeoutSeconds = 30,
        private readonly int $maxRedirects = 3,
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
        self::assertFetchableUrl($url);

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

    /**
     * Checks if the url is a valid http or https url and if it has a host.
     * 
     * @param string $url The url to check.
     * @return void
     * @throws \InvalidArgumentException if the url is not a valid http or https url or if it has no host.
     */
    public static function assertFetchableUrl(string $url): void
    {
        // parse_url() yields null for a missing scheme and false for a URL it
        // cannot parse at all; both cast to '' and fail the allow-list.
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!\in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            // Without this, a job could name file:///etc/passwd, or any other
            // stream wrapper the client happens to support, and we would fetch it.
            throw new \InvalidArgumentException(
                "Refusing to download {$url}: only http and https are allowed.",
            );
        }

        if (parse_url($url, PHP_URL_HOST) === null) {
            throw new \InvalidArgumentException("Download URL has no host: {$url}");
        }
    }
}
