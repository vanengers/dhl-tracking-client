<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Tests\Unit\Http;

use GPWebshops\ShopHub\Dhl\Http\Throttle;
use GPWebshops\ShopHub\Dhl\Tests\Support\FakeClock;
use PHPUnit\Framework\TestCase;

final class ThrottleTest extends TestCase
{
    public function testFirstCallNeverWaits(): void
    {
        $clock = new FakeClock();
        (new Throttle(5.0, $clock))->wait();

        self::assertSame([], $clock->sleeps);
    }

    public function testWaitsOnlyTheRemainder(): void
    {
        $clock = new FakeClock();
        $throttle = new Throttle(5.0, $clock);

        $throttle->wait();
        $clock->advance(2.0);
        $throttle->wait();
        $clock->advance(6.0);
        $throttle->wait();

        self::assertSame([3.0], $clock->sleeps);
    }

    public function testZeroDisablesThrottling(): void
    {
        $clock = new FakeClock();
        $throttle = new Throttle(0.0, $clock);

        $throttle->wait();
        $throttle->wait();

        self::assertSame([], $clock->sleeps);
    }
}
