<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Http;

/**
 * Time source for throttling and retry waits, so tests can run without real sleeping.
 */
interface Clock
{
    /** Monotonic seconds; only differences are meaningful. */
    public function now(): float;

    public function sleep(float $seconds): void;
}
