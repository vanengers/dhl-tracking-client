<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Status;

/**
 * Outcome of the status rules for one event.
 *
 * @internal
 */
final class Verdict
{
    public function __construct(
        public readonly DeliveryState $state,
        /** Which rule decided, e.g. "delivered:collected"; for logs and when tuning the rules. */
        public readonly string $reason,
        public readonly ?DeliveryPlace $place = null,
        public readonly ?PickupPoint $pickupPoint = null,
    ) {
    }
}
