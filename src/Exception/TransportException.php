<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Exception;

/**
 * No HTTP response: DNS, connection or TLS failure, or a timeout ($timedOut).
 */
final class TransportException extends AbstractDhlException
{
    public function __construct(string $message, public readonly bool $timedOut = false, ?ErrorContext $context = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $context, $previous);
    }
}
