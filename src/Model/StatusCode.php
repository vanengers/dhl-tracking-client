<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Model;

/**
 * DHL's high-level status code. It has no "ready for pickup" or "out for delivery" (yet): DeliveryState derives those.
 */
enum StatusCode: string
{
    case PreTransit = 'pre-transit';
    case Transit = 'transit';
    case Delivered = 'delivered';
    case Failure = 'failure';
    case Unknown = 'unknown';

    public static function fromApi(?string $value): self
    {
        return $value === null ? self::Unknown : (self::tryFrom(strtolower(trim($value))) ?? self::Unknown);
    }
}
