<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Status;

use GPWebshops\ShopHub\Dhl\Model\Address;
use GPWebshops\ShopHub\Dhl\Model\Event;
use GPWebshops\ShopHub\Dhl\Model\Shipment;

/**
 * Where the parcel waits (or waited) for the recipient. label/url/address are whatever DHL sent, often partial.
 */
final class PickupPoint
{
    public function __construct(
        public readonly PickupPointType $type,
        public readonly ?string $label = null,
        public readonly ?string $url = null,
        public readonly ?Address $address = null,
    ) {
    }

    /**
     * Details from the event's location, falling back to the shipment destination when that is a service point.
     */
    public static function fromEvent(PickupPointType $type, Event $event, Shipment $shipment): self
    {
        $destination = $shipment->destination?->servicePoint !== null ? $shipment->destination : null;
        $servicePoint = $event->location->servicePoint ?? $destination?->servicePoint;

        return new self($type, $servicePoint?->label, $servicePoint?->url, $event->location->address ?? $destination?->address);
    }

    public static function fromDestination(Shipment $shipment): self
    {
        $destination = $shipment->destination;

        return new self(PickupPointType::ServicePoint, $destination?->servicePoint?->label, $destination?->servicePoint?->url, $destination?->address);
    }
}
