<?php

declare(strict_types=1);

namespace App\Job;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reports a build job's outcome back to Collectives, via the
 * callback_status_url carried on the job itself.
 *
 * That URL travels from the original build request through the queue, same
 * as content_download_url, so it is treated as untrusted the same way: only
 * http/https are called. Unlike ContentDownloader, a failed notification
 * never throws -- by the time this runs the build has already succeeded or
 * failed, there is nowhere left to retry from, and a broken callback must not
 * be able to mask or override that outcome.
 *
 * Sent as PATCH with an OCS-APIREQUEST header, matching Collectives' OCS API
 * endpoint (`PATCH /ocs/v2.php/apps/collectives/api/v1.0/p/static-sites/<id>`)
 * -- without that header Nextcloud treats the request as a plain browser
 * request and rejects it.
 */
final class StatusNotifier
{
    private const ALLOWED_SCHEMES = ['http', 'https'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly int $timeoutSeconds = 10,
        private readonly int $maxRedirects = 3,
    ) {
    }

    public function notifySuccess(string $callbackUrl, string $publishUrl): void
    {
        $this->send($callbackUrl, [
            'status' => 'published',
            'data' => ['publish_url' => $publishUrl],
        ]);
    }

    public function notifyFailure(string $callbackUrl, string $errorMessage): void
    {
        $this->send($callbackUrl, [
            'status' => 'failed',
            'data' => ['error_message' => $errorMessage],
        ]);
    }

    private function send(string $callbackUrl, array $payload): void
    {
        if (!$this->isFetchableUrl($callbackUrl)) {
            error_log(sprintf('[ERROR] refusing to notify %s: only http and https are allowed', $callbackUrl));

            return;
        }

        try {
            $response = $this->httpClient->request('PATCH', $callbackUrl, [
                'json' => $payload,
                'headers' => ['OCS-APIREQUEST' => 'true'],
                'timeout' => $this->timeoutSeconds,
                'max_redirects' => $this->maxRedirects,
            ]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                error_log(sprintf('[ERROR] status callback to %s returned HTTP %d', $callbackUrl, $status));
            }
        } catch (HttpClientException $e) {
            error_log(sprintf('[ERROR] status callback to %s failed: %s', $callbackUrl, $e->getMessage()));
        }
    }

    // SECURITY
    private function isFetchableUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return \in_array($scheme, self::ALLOWED_SCHEMES, true) && parse_url($url, PHP_URL_HOST) !== null;
    }
}
