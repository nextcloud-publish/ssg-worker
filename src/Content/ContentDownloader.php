<?php

declare(strict_types=1);

namespace App\Content;

use Symfony\Component\HttpClient\Response\StreamWrapper;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches a build job's content archive into that job's input/ folder.
 *
 * The URL travels from the original build request through the queue, so it is
 * treated as untrusted throughout: only http/https are fetched, redirects are
 * capped, and the response is streamed to disk under a byte limit rather than
 * buffered in memory.
 */
final class ContentDownloader
{
    /**
     * Fixed rather than taken from the URL: the URL is attacker-influenced and
     * must not get to choose filenames on a shared volume. It also records the
     * assumption that what arrives is a gzipped tar -- sniffing the real format
     * is a problem for the step that unpacks it.
     */
    public const FILENAME = 'content.tar.gz';

    # TODO: This is a bit tricky with our integration test which does not use HTTPS.
    private const ALLOWED_SCHEMES = ['http', 'https'];

    private readonly int $maxBytes;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        // Ceiling on a single download, injected from MAX_DOWNLOAD_MB by
        // config/services.yaml. Taken in megabytes because that is the unit
        // whoever sets the limit actually thinks in; everything below this
        // line works in bytes, so it is converted once, here.
        int $maxMegabytes,
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
     * @return string the path of the written file
     *
     * @throws \InvalidArgumentException if the URL is not one we are willing to fetch
     * @throws \RuntimeException         if the transfer or the write fails
     */
    public function download(string $url, string $targetDir): string
    {
        $this->assertFetchableUrl($url);

        if (!is_dir($targetDir)) {
            throw new \RuntimeException("Target directory does not exist: {$targetDir}");
        }

        $target = rtrim($targetDir, '/') . '/' . self::FILENAME;

        $handle = @fopen($target, 'wb');
        if ($handle === false) {
            $reason = error_get_last()['message'] ?? 'unknown error';

            throw new \RuntimeException("Could not open {$target} for writing: {$reason}");
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
                @unlink($target); # Deletes the incomplete download
            }
        }

        return $target;
    }

    /**
     * @param resource $handle
     *
     * @return int bytes written
     */
    private function streamTo(string $url, $handle): int
    {
        $response = $this->httpClient->request('GET', $url, [
            'max_duration' => 300,   // whole transfer, so a slow drip cannot hang the worker
            'timeout' => 30,         // idle time between chunks
            'max_redirects' => 3,
            'buffer' => false,       // stream it; never hold the archive in memory
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

    // SECURITY
    private function assertFetchableUrl(string $url): void
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
