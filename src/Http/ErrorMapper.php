<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Http;

use GPWebshops\ShopHub\Dhl\Exception\AuthenticationException;
use GPWebshops\ShopHub\Dhl\Exception\BadRequestException;
use GPWebshops\ShopHub\Dhl\Exception\ErrorContext;
use GPWebshops\ShopHub\Dhl\Exception\HttpException;
use GPWebshops\ShopHub\Dhl\Exception\QuotaExceededException;
use GPWebshops\ShopHub\Dhl\Exception\RateLimitException;
use GPWebshops\ShopHub\Dhl\Exception\ServerException;
use GPWebshops\ShopHub\Dhl\Exception\TransportException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * Maps error responses (problem+json) and Guzzle transfer errors onto the DhlException hierarchy.
 */
final class ErrorMapper
{
    private const CURLE_OPERATION_TIMEDOUT = 28;

    public static function fromResponse(ResponseInterface $response, string $body, ErrorContext $context): HttpException
    {
        $status = $response->getStatusCode();
        [$title, $detail] = self::problem($body);
        $summary = trim(($title ?? '') . ($detail !== null && $detail !== $title ? ': ' . $detail : ''), ': ');
        $message = sprintf('DHL tracking API returned %d %s%s', $status, $response->getReasonPhrase(), $summary !== '' ? ' (' . $summary . ')' : '');

        return match (true) {
            $status === 400 => new BadRequestException($message, $status, $title, $detail, $context),
            $status === 401, $status === 403 => new AuthenticationException($message . ' - check the API key and that the app has access to Shipment Tracking - Unified in this environment', $status, $title, $detail, $context),
            $status === 429 && self::isQuota($body) => new QuotaExceededException($message . ' - daily quota used up, it resets at 24:00 GMT', self::retryAfterSeconds($response), $title, $detail, $context),
            $status === 429 => new RateLimitException($message, self::retryAfterSeconds($response), $title, $detail, $context),
            $status >= 500 => new ServerException($message, $status, $title, $detail, $context),
            default => new HttpException($message, $status, $title, $detail, $context),
        };
    }

    public static function fromTransfer(\Throwable $e, ErrorContext $context): TransportException
    {
        $handlerContext = $e instanceof ConnectException || $e instanceof RequestException ? $e->getHandlerContext() : [];
        $errno = isset($handlerContext['errno']) && is_int($handlerContext['errno']) ? $handlerContext['errno'] : null;
        $text = trim((string) preg_replace('/\s*\(see https?:\/\/curl\.haxx\.se[^)]*\)|\s+for https?:\/\/\S+$/', '', $e->getMessage()));
        $timedOut = $errno === self::CURLE_OPERATION_TIMEDOUT || stripos($text, 'timed out') !== false;

        return new TransportException(($timedOut ? 'DHL tracking request timed out: ' : 'DHL tracking connection failed: ') . $text, $timedOut, $context, $e);
    }

    public static function retryAfterSeconds(ResponseInterface $response): ?float
    {
        $header = trim($response->getHeaderLine('Retry-After'));
        if ($header === '') {
            return null;
        }
        if (is_numeric($header)) {
            return max(0.0, (float) $header);
        }
        $time = strtotime($header);

        return $time === false ? null : max(0.0, (float) ($time - time()));
    }

    /**
     * @return array{?string, ?string} title and detail of a problem+json body
     */
    private static function problem(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return [null, null];
        }
        $text = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return [$text($data['title'] ?? null), $text($data['detail'] ?? null)];
    }

    /**
     * DHL's gateway reports the daily limit as a "quota" violation and the per-second limit as a "spike arrest".
     */
    private static function isQuota(string $body): bool
    {
        $body = strtolower($body);

        return !str_contains($body, 'spike') && (str_contains($body, 'quota') || str_contains($body, 'daily') || str_contains($body, 'per day'));
    }
}
