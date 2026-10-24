<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Status;

/**
 * Where a delivered parcel ended up.
 */
enum DeliveryPlace: string
{
    /** At the delivery address; also the default when DHL names no other place. */
    case Address = 'address';
    case Neighbour = 'neighbour';
    case Mailbox = 'mailbox';
    case SafePlace = 'safe_place';
    /** The recipient collected it from a service point, locker or post office. */
    case PickupPoint = 'pickup_point';
}
