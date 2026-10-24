<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Exception;

/**
 * DHL answered with an unexpected HTTP status. title and detail come from the problem+json body, when sent.
 */
class HttpException extends AbstractDhlException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $title = null,
        public readonly ?string $detail = null,
        ?ErrorContext $context = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $context, $previous);
    }
}
