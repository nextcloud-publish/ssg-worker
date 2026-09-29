<?php

declare(strict_types=1);

namespace App\Job;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * PUTs a build's final result to the callback_status_url from the build request.
 * BuildJobHandler reports a published site, BuildFailureHandler reports failure.
 * The body is {"status": "published", "result": {"publish_url": ...}} or {"status": "failed", "result": {"error_message": ...}}.
 *
 * In case sending the notification to the callback_status_url is not successful depending on the http status
 * code either an \InvalidArgumentException or a \RuntimeException is thrown.
 * 
 * A normal RuntimeException will cause the message to be requeued. But an \InvalidArgumentException will not.
 * 
 * @param HttpClientInterface $httpClient The http client to use to send the notification.
 * @param int $timeoutSeconds The timeout in seconds for the http request.
 * @param int $maxDurationSeconds The maximum duration in seconds for the http request.
 * @param int $maxRedirects The maximum number of redirects for the http request.
 */
final class StatusNotifier
{
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';

    /** Same allow-list ContentDownloader applies to the inbound URL. */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * The two 4xx codes worth retrying: 408 asks us to try again, 429 asks us
     * to slow down. Every other 4xx is the caller's fault and permanent.
     */
    private const RETRYABLE_CLIENT_ERRORS = [408, 429];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly int $timeoutSeconds = 5,
        private readonly int $maxDurationSeconds = 10,
        private readonly int $maxRedirects = 0,
    ) {
    }

    /**
     * Reports a published site and the URL it is served at.
     *
     * @param string $callbackStatusUrl The url to send the notification to.
     * @param string $buildId The build id, used for the log line and error messages.
     * @param string $publishUrl The public URL of the published site.
     * @return void
     *
     * @throws \InvalidArgumentException if no retry could succeed
     * @throws \RuntimeException         if the call is worth retrying
     */
    public function notifyPublished(string $callbackStatusUrl, string $buildId, string $publishUrl): void
    {
        $this->put($callbackStatusUrl, $buildId, self::STATUS_PUBLISHED, ['publish_url' => $publishUrl]);
    }

    /**
     * Reports a failed build and why it failed.
     * $errorMessage is sent as given, so it must already be redacted.
     *
     * @param string $callbackStatusUrl The url to send the notification to.
     * @param string $buildId The build id, used for the log line and error messages.
     * @param string $errorMessage The reason the build failed.
     * @return void
     *
     * @throws \InvalidArgumentException if no retry could succeed
     * @throws \RuntimeException         if the call is worth retrying
     */
    public function notifyFailed(string $callbackStatusUrl, string $buildId, string $errorMessage): void
    {
        $this->put($callbackStatusUrl, $buildId, self::STATUS_FAILED, ['error_message' => $errorMessage]);
    }

    /**
     * PUTs {"status": $status, "result": $result} to $callbackStatusUrl.
     *
     * @param string $callbackStatusUrl The url to send the notification to.
     * @param string $buildId The build id, used for the log line and error messages.
     * @param string $status The status of the build.
     * @param array<string, string> $result The status-specific result object.
     * @return void
     *
     * @throws \InvalidArgumentException if no retry could succeed
     * @throws \RuntimeException         if the call is worth retrying
     */
    private function put(string $callbackStatusUrl, string $buildId, string $status, array $result): void
    {
        self::assertCallableUrl($callbackStatusUrl, $buildId);

        $payload = [
            'status' => $status,
            'result' => $result,
        ];

        try {
            $response = $this->httpClient->request('PUT', $callbackStatusUrl, [
                'json' => $payload,
                // The message stays unacked during the PUT, so these caps keep it
                // well inside consumer_timeout. Without them a slow endpoint
                // becomes a requeue.
                'timeout' => $this->timeoutSeconds,
                'max_duration' => $this->maxDurationSeconds,
                'max_redirects' => $this->maxRedirects,
            ]);

            // Blocks until the response headers arrive.
            $statusCode = $response->getStatusCode();
        } catch (HttpClientException $e) {
            // DNS, connect and read failures land here; all worth retrying.
            throw new \RuntimeException(
                sprintf('Status callback to %s failed: %s', $callbackStatusUrl, $e->getMessage()),
                0,
                $e,
            );
        }

        if ($statusCode >= 200 && $statusCode < 300) {
            error_log(sprintf('[INFO] reported %s for build %s to %s', $status, $buildId, $callbackStatusUrl));
            return;
        }
        if ($statusCode >= 300 && $statusCode < 400) {
            throw new \InvalidArgumentException(sprintf(
                'Status callback to %s redirected (HTTP %d); not following it.',
                $callbackStatusUrl,
                $statusCode,
            ));
        }
        if ($statusCode >= 400 && $statusCode < 500 && !\in_array($statusCode, self::RETRYABLE_CLIENT_ERRORS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Status callback to %s rejected with HTTP %d; not retrying.',
                $callbackStatusUrl,
                $statusCode,
            ));
        }
        throw new \RuntimeException(sprintf(
            'Status callback to %s failed with HTTP %d.',
            $callbackStatusUrl,
            $statusCode,
        ));
    }

    /**
     * Checks if the url is a valid http or https url and if it has a host.
     * 
     * @param string $url The url to check.
     * @param string $buildId The build id.
     * @return void
     * @throws \InvalidArgumentException if the url is not a valid http or https url or if it has no host.
     */
    public static function assertCallableUrl(string $url, string $buildId): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (!\in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Refusing to call back for build %s: only http and https are allowed.',
                $buildId,
            ));
        }
    
        if (parse_url($url, PHP_URL_HOST) === null) {
            throw new \InvalidArgumentException(sprintf(
                'Refusing to call back for build %s: the callback URL has no host.',
                $buildId,
            ));
        }
    }
}
