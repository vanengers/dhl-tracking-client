<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Exception;

/**
 * Diagnostic details attached to API exceptions. The API key is sent as a header, so it is never part of the URL.
 */
final class ErrorContext
{
    public const BODY_EXCERPT_LENGTH = 500;

    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly ?int $status = null,
        public readonly ?string $bodyExcerpt = null,
        /** Value of the "correlation-id" response header; quote it when contacting DHL support. */
        public readonly ?string $correlationId = null,
        public readonly int $attempts = 1,
        public readonly ?float $durationMs = null,
    ) {
    }

    public function describe(): string
    {
        $parts = [$this->method . ' ' . $this->url];
        if ($this->status !== null) {
            $parts[] = 'status ' . $this->status;
        }
        $parts[] = $this->attempts . ' attempt' . ($this->attempts === 1 ? '' : 's');
        if ($this->durationMs !== null) {
            $parts[] = round($this->durationMs) . 'ms';
        }
        if ($this->correlationId !== null) {
            $parts[] = 'correlation-id ' . $this->correlationId;
        }

        return implode(', ', $parts);
    }
}
