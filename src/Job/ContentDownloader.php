<?php

declare(strict_types=1);

namespace App\Job;

use Symfony\Component\HttpClient\Response\StreamWrapper;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches a build job's content archive into that job's input folder.
 * Redirects are capped, and the response is streamed to disk rather than buffered in memory.
 * publish has already checked that the URL is http or https with a host.
 *
 * @param HttpClientInterface $httpClient The http client to use to download the content.
 * @param int $maxDurationSeconds The maximum duration in seconds for the http request.
 * @param int $timeoutSeconds The timeout in seconds for the http request.
 * @param int $maxRedirects The maximum number of redirects for the http request.
 */
final class ContentDownloader
{
    /** Fixed filename for the content archive in the input folder */
    public const FILENAME = 'content.tar.gz';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly int $maxDurationSeconds,
        private readonly int $timeoutSeconds,
        private readonly int $maxRedirects,
    ) {
    }

    /**
     * Streams $url into $targetDir.
     *
     * @param string $url The url to download the content from.
     * @param string $targetDir The directory to download the content to.
     * @return string the path of the written file
     * @throws \RuntimeException if the download fails or the target directory does not exist or
     * cannot be opened for writing
     */
    public function download(string $url, string $targetDir): string
    {
        if (!is_dir($targetDir)) {
            throw new \RuntimeException("Target directory does not exist: {$targetDir}");
        }

        $archiveFile = rtrim($targetDir, '/') . '/' . self::FILENAME;

        $archiveHandle = @fopen($archiveFile, 'wb');
        if ($archiveHandle === false) {
            throw new \RuntimeException(sprintf(
                'Could not open %s for writing: %s',
                $archiveFile,
                error_get_last()['message'] ?? 'unknown error',
            ));
        }

        $completed = false;
        try {
            $this->streamTo($url, $archiveHandle);
            $completed = true;
        } catch (HttpClientException $e) {
            throw new \RuntimeException("Download failed for {$url}: " . $e->getMessage(), 0, $e);
        } finally {
            fclose($archiveHandle);

            if (!$completed) {
                @unlink($archiveFile); // Deletes the incomplete download.
            }
        }

        return $archiveFile;
    }

    /**
     * Streams the content from the url to the handle.
     *
     * @param string $url The url to stream the content from.
     * @param resource $archiveHandle The handle to stream the content to.
     * @return int The number of bytes written.
     * @throws \RuntimeException if the download fails.
     */
    private function streamTo(string $url, $archiveHandle): int
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

        // Casting the response to a plain stream hands the read/write loop to stream_copy_to_stream().
        $responseStream = StreamWrapper::createResource($response, $this->httpClient);
        $writtenBytes = @stream_copy_to_stream($responseStream, $archiveHandle);

        if ($writtenBytes === false) {
            $reason = error_get_last()['message'] ?? 'unknown error';
            throw new \RuntimeException("Could not write the download: {$reason}");
        }

        return $writtenBytes;
    }
}
