<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Status;

/**
 * What a web shop wants to know about a parcel. Derived from DHL's statusCode plus the event text and location,
 * because DHL's own codes (pre-transit, transit, delivered, failure, unknown) do not tell "delivered at the door"
 * apart from "waiting at a service point".
 */
enum DeliveryState: string
{
    /** DHL does not know the tracking number (yet). Normal for a few hours after the label is made. */
    case NotFound = 'not_found';
    /** Label/data received, DHL does not have the parcel yet. */
    case PreTransit = 'pre_transit';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    /** At a service point, parcel locker, post office or depot, waiting for the recipient. See TrackingStatus::$pickupPoint. */
    case ReadyForPickup = 'ready_for_pickup';
    /** Handed over: at the address, to a neighbour, in the mailbox, or collected from a pickup point. See TrackingStatus::$deliveryPlace. */
    case Delivered = 'delivered';
    /** A delivery attempt failed or something went wrong; DHL usually tries again or redirects. */
    case DeliveryFailed = 'delivery_failed';
    /** On its way back to (or back at) the sender. */
    case Returned = 'returned';
    case Unknown = 'unknown';

    /**
     * True when the status will not change for the customer any more: stop polling.
     */
    public function isFinal(): bool
    {
        return $this === self::Delivered || $this === self::Returned;
    }
}
