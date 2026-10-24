<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Tests\Support;

use GPWebshops\ShopHub\Dhl\Http\Clock;

/**
 * Clock that never really sleeps: sleep() moves time forward and is recorded.
 */
final class FakeClock implements Clock
{
    /** @var list<float> */
    public array $sleeps = [];

    public function __construct(public float $time = 1000.0)
    {
    }

    public function now(): float
    {
        return $this->time;
    }

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = round($seconds, 3);
        $this->time += max(0.0, $seconds);
    }

    public function advance(float $seconds): void
    {
        $this->time += $seconds;
    }
}
