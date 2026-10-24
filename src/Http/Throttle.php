<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Http;

/**
 * Keeps at least $minInterval seconds between the starts of two calls. Share one instance between clients that use
 * the same API key. It only works within one PHP process: parallel cron jobs need their own coordination.
 */
final class Throttle
{
    private ?float $last = null;

    public function __construct(private readonly float $minInterval, private readonly Clock $clock = new SystemClock())
    {
    }

    public function wait(): void
    {
        if ($this->last !== null && $this->minInterval > 0) {
            $wait = $this->last + $this->minInterval - $this->clock->now();
            if ($wait > 0) {
                $this->clock->sleep($wait);
            }
        }
        $this->last = $this->clock->now();
    }
}
