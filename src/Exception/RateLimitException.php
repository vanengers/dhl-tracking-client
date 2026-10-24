<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Exception;

/**
 * 429: too many requests (DHL "spike arrest": too many calls per second). retryAfter is the Retry-After header in
 * seconds, when sent. The client already waited and retried once before throwing this.
 */
class RateLimitException extends HttpException
{
    public function __construct(
        string $message,
        public readonly ?float $retryAfter = null,
        ?string $title = null,
        ?string $detail = null,
        ?ErrorContext $context = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 429, $title, $detail, $context, $previous);
    }
}
