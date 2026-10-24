<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Http;

use GPWebshops\ShopHub\Dhl\Config\TrackingConfig;
use GPWebshops\ShopHub\Dhl\Exception\DhlException;
use GPWebshops\ShopHub\Dhl\Exception\ErrorContext;
use GPWebshops\ShopHub\Dhl\Exception\QuotaExceededException;
use GPWebshops\ShopHub\Dhl\Exception\RateLimitException;
use GPWebshops\ShopHub\Dhl\Exception\ResponseFormatException;
use GPWebshops\ShopHub\Dhl\Exception\ServerException;
use GPWebshops\ShopHub\Dhl\Exception\TransportException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends GET /shipments with the API key header. Every attempt waits for the throttle. Retries (within maxAttempts):
 * 429 spike arrest, 502/503/504 and connection errors. Never retried: the daily quota, 4xx and malformed bodies.
 */
final class Transport
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly TrackingConfig $config,
        private readonly Throttle $throttle,
        private readonly Clock $clock = new SystemClock(),
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Returns the decoded body, or null when DHL answers 404 (no shipment for this tracking number).
     *
     * @param array<string, string> $query
     *
     * @return array<array-key, mixed>|null
     */
    public function shipments(array $query): ?array
    {
        $url = rtrim($this->config->baseUri, '/') . '/shipments';
        $logUrl = $url . '?' . http_build_query($query);
        $attempt = 0;
        while (true) {
            ++$attempt;
            $this->throttle->wait();
            $start = $this->clock->now();
            try {
                $response = $this->http->request('GET', $url, $this->options($query));
            } catch (GuzzleException $e) {
                $this->retryOrThrow(ErrorMapper::fromTransfer($e, $this->context($logUrl, null, '', $attempt, $start)), $attempt, $logUrl);
                continue;
            }

            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
            $context = $this->context($logUrl, $response, $body, $attempt, $start);
            if ($status === 404) {
                $this->logger?->info('DHL tracking: no shipment found for {url}', ['url' => $logUrl, 'correlation_id' => $context->correlationId]);

                return null;
            }
            if ($status >= 200 && $status < 300) {
                $this->logger?->debug('DHL tracking: {url} -> {status} in {ms}ms', ['url' => $logUrl, 'status' => $status, 'ms' => (int) round($context->durationMs ?? 0)]);

                return $this->decode($body, $context);
            }
            $this->retryOrThrow(ErrorMapper::fromResponse($response, $body, $context), $attempt, $logUrl);
        }
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function options(array $query): array
    {
        return [
            'http_errors' => false,
            'query' => $query,
            'headers' => [
                'DHL-API-Key' => $this->config->apiKey,
                'Accept' => 'application/json',
                'User-Agent' => $this->config->userAgent,
            ],
            'connect_timeout' => $this->config->connectTimeout,
            'timeout' => $this->config->timeout,
        ];
    }

    private function retryOrThrow(DhlException $error, int $attempt, string $url): void
    {
        $delay = $attempt < $this->config->maxAttempts ? $this->retryDelay($error, $attempt) : null;
        if ($delay === null) {
            $this->logger?->error('DHL tracking: {url} failed: {error}', ['url' => $url, 'error' => $error->getMessage(), 'exception_class' => $error::class]);

            throw $error;
        }
        $this->logger?->warning('DHL tracking: retrying {url} after attempt {attempt} in {delay}s: {error}', [
            'url' => $url,
            'attempt' => $attempt,
            'delay' => round($delay, 1),
            'error' => $error->getMessage(),
        ]);
        $this->clock->sleep($delay);
    }

    private function retryDelay(DhlException $error, int $attempt): ?float
    {
        if ($error instanceof QuotaExceededException) {
            return null;
        }
        if ($error instanceof RateLimitException) {
            $wait = $error->retryAfter ?? max(1.0, $this->config->minInterval);

            return $wait > $this->config->maxRetryAfter ? null : $wait;
        }
        $backoff = $this->config->retryBaseDelay * (2 ** ($attempt - 1));
        if ($error instanceof ServerException) {
            return in_array($error->status, [502, 503, 504], true) ? $backoff : null;
        }

        return $error instanceof TransportException ? $backoff : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(string $body, ErrorContext $context): array
    {
        $trimmed = ltrim($body);
        if ($trimmed === '' || $trimmed[0] !== '{') {
            $what = $trimmed === '' ? 'Empty response body' : ($trimmed[0] === '<' ? 'HTML response' : 'Non-JSON response');
            throw new ResponseFormatException('DHL tracking API: ' . $what . ' where a JSON object was expected', $context);
        }
        try {
            $data = json_decode($body, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ResponseFormatException('DHL tracking API: malformed JSON: ' . $e->getMessage(), $context, $e);
        }
        if (!is_array($data)) {
            throw new ResponseFormatException('DHL tracking API: unexpected JSON shape', $context);
        }

        return $data;
    }

    private function context(string $url, ?ResponseInterface $response, string $body, int $attempt, float $start): ErrorContext
    {
        $correlationId = $response?->getHeaderLine('correlation-id');

        return new ErrorContext(
            'GET',
            $url,
            $response?->getStatusCode(),
            $body !== '' ? substr($body, 0, ErrorContext::BODY_EXCERPT_LENGTH) : null,
            $correlationId !== null && $correlationId !== '' ? $correlationId : null,
            $attempt,
            ($this->clock->now() - $start) * 1000,
        );
    }
}
