<?php

declare(strict_types=1);

namespace App\Callback;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * POSTs a build's terminal outcome to the callback_status_url the original
 * request supplied.
 *
 * DELIVERY IS AT-LEAST-ONCE and cannot be made otherwise. Messenger acks only
 * after the handler returns, so a transport retry, a consumer_timeout requeue,
 * or the worker being killed between the POST and the ack all deliver the same
 * callback again. The payload carries build_id and a terminal status so the
 * receiver can dedupe on the pair; that expectation is part of the published
 * contract and is documented in docs/build-pipeline.md.
 *
 * Throws along the same split as the rest of the pipeline:
 * \InvalidArgumentException for what no retry could fix (a URL we will not
 * call, an endpoint that rejects us), \RuntimeException for what might work
 * next time (a 503, a dropped connection).
 *
 * BuildJobHandler catches both and acks regardless, because replaying it means
 * replaying the whole build. The split stays here so that policy lives in one
 * visible catch there rather than being spread across this class.
 */
final class StatusNotifier
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    /** Same allow-list ContentDownloader applies to the inbound URL. */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * 4xx codes that DO justify a retry, against the general rule that 4xx is
     * the caller's fault and permanent: 408 is the server asking us to try
     * again, 429 is it asking us to slow down.
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
     * $error must already be redacted; BuildJobHandler does it.
     *
     * @throws \InvalidArgumentException if no retry could succeed
     * @throws \RuntimeException         if the call is worth retrying
     */
    public function notify(
        string $callbackStatusUrl,
        string $status,
        string $buildId,
        string $staticSiteId,
        string $slug,
        string $finishedAt,
        int $pages = 0,
        ?string $error = null,
    ): void {
        if ($callbackStatusUrl === '') {
            // Nothing to call. Not an error: the field is required by the API
            // but never validated, so an empty string reaches here intact, and
            // failing the message would strand a build that is otherwise done.
            error_log(sprintf('[WARN] build %s has no callback_status_url; not reporting %s', $buildId, $status));

            return;
        }

        $this->assertCallableUrl($callbackStatusUrl, $buildId);

        $payload = [
            'build_id' => $buildId,
            'static_site_id' => $staticSiteId,
            'slug' => $slug,
            'status' => $status,
            'finished_at' => $finishedAt,
        ];

        if ($status === self::STATUS_SUCCESS) {
            $payload['pages'] = $pages;
        }

        if ($error !== null) {
            $payload['error'] = $error;
        }

        try {
            $response = $this->httpClient->request('POST', $callbackStatusUrl, [
                'json' => $payload,
                // The POST is the only part of the handler with unbounded wall
                // time, and the message stays unacked throughout it. These caps
                // keep it far inside rabbitmq.conf's consumer_timeout, which
                // would otherwise requeue the message mid-call and turn a slow
                // endpoint into a redelivery.
                'timeout' => $this->timeoutSeconds,
                'max_duration' => $this->maxDurationSeconds,
                // A status callback that redirects has moved; following it
                // would post a build result to somewhere the client did not
                // nominate.
                'max_redirects' => $this->maxRedirects,
            ]);

            // Blocks until the response headers arrive.
            $statusCode = $response->getStatusCode();
        } catch (HttpClientException $e) {
            // DNS, connect and read failures land here: all transient by
            // nature, so they are worth retrying.
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

        if ($statusCode >= 400 && $statusCode < 500 && !\in_array($statusCode, self::RETRYABLE_CLIENT_ERRORS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Status callback to %s rejected with HTTP %d; not retrying.',
                $callbackStatusUrl,
                $statusCode,
            ));
        }

        // 3xx reaches here because max_redirects is 0, and it is as permanent
        // as a 4xx: the endpoint moved and the client has to give us the new one.
        if ($statusCode >= 300 && $statusCode < 400) {
            throw new \InvalidArgumentException(sprintf(
                'Status callback to %s redirected (HTTP %d); not following it.',
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
     * SECURITY. callback_status_url comes from the API caller and is validated
     * nowhere upstream, and this is an outbound POST with a body -- a better
     * SSRF primitive than the inbound download. Without the scheme check a job
     * could name file:// or any other stream wrapper.
     *
     * Private address ranges are the other half, blocked by decorating the
     * client with NoPrivateNetworkHttpClient in services.yaml so a redirect or
     * a DNS answer cannot get around it.
     */
    private function assertCallableUrl(string $url, string $buildId): void
    {
        // parse_url() yields null for a missing scheme and false for a URL it
        // cannot parse at all; both cast to '' and fail the allow-list.
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
