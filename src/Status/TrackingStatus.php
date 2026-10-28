<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Status;

use GPWebshops\ShopHub\Dhl\Model\Event;
use GPWebshops\ShopHub\Dhl\Model\Shipment;
use GPWebshops\ShopHub\Dhl\Model\StatusCode;

/**
 * The answer to "where is this parcel?" for one tracking number.
 *
 *   match ($status->state) {
 *       DeliveryState::Delivered      => ..., // $status->deliveryPlace: Address, Neighbour, Mailbox, SafePlace, PickupPoint
 *       DeliveryState::ReadyForPickup => ..., // $status->pickupPoint: type (ServicePoint, Locker, PostOffice, ...), label, address
 *       ...
 *   };
 */
final class TrackingStatus
{
    public function __construct(
        public readonly string $trackingNumber,
        public readonly DeliveryState $state,
        /** Which rule decided (see StatusClassifier); log it next to the DHL text when a status looks wrong. */
        public readonly string $reason,
        /** Only for Delivered. */
        public readonly ?DeliveryPlace $deliveryPlace = null,
        /** For ReadyForPickup; also for Delivered when it was collected from a pickup point and DHL said where. */
        public readonly ?PickupPoint $pickupPoint = null,
        public readonly ?Event $lastEvent = null,
        public readonly ?\DateTimeImmutable $deliveredAt = null,
        public readonly ?\DateTimeImmutable $estimatedDelivery = null,
        public readonly ?Shipment $shipment = null,
    ) {
    }

    public static function notFound(string $trackingNumber): self
    {
        return new self($trackingNumber, DeliveryState::NotFound, 'not-found');
    }

    public static function fromVerdict(string $trackingNumber, Shipment $shipment, ?Event $event, Verdict $verdict): self
    {
        $delivered = $verdict->state === DeliveryState::Delivered;

        return new self(
            $trackingNumber,
            $verdict->state,
            $verdict->reason,
            $delivered ? $verdict->place : null,
            $verdict->pickupPoint,
            $event,
            $delivered ? ($event->timestamp ?? $shipment->proofOfDeliveryTimestamp) : null,
            $shipment->estimatedTimeOfDelivery ?? $shipment->estimatedDeliveryThrough ?? $shipment->estimatedDeliveryFrom,
            $shipment,
        );
    }

    public function isFound(): bool
    {
        return $this->state !== DeliveryState::NotFound;
    }

    public function isDelivered(): bool
    {
        return $this->state === DeliveryState::Delivered;
    }

    public function isReadyForPickup(): bool
    {
        return $this->state === DeliveryState::ReadyForPickup;
    }

    /**
     * Delivered or returned: the status will not change for the customer any more, so stop polling.
     */
    public function isFinal(): bool
    {
        return $this->state->isFinal();
    }

    /**
     * DHL's own status code, unchanged.
     */
    public function statusCode(): StatusCode
    {
        return $this->lastEvent->statusCode ?? StatusCode::Unknown;
    }

    /**
     * DHL's text for the latest event, for showing to a customer.
     */
    public function description(): ?string
    {
        return $this->lastEvent?->label();
    }

    public function timestamp(): ?\DateTimeImmutable
    {
        return $this->lastEvent?->timestamp;
    }

    /**
     * Flat summary for logs, queues or a database row.
     *
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'tracking_number' => $this->trackingNumber,
            'state' => $this->state->value,
            'reason' => $this->reason,
            'delivery_place' => $this->deliveryPlace?->value,
            'pickup_point_type' => $this->pickupPoint?->type->value,
            'pickup_point_label' => $this->pickupPoint?->label,
            'pickup_point_address' => $this->pickupPoint?->address?->oneLine(),
            'dhl_status_code' => $this->lastEvent?->rawStatusCode,
            // DHL's own event code, e.g. COLLECTED_AT_PARCELSTATION: the same in every language, so best to search on.
            'dhl_event' => $this->lastEvent?->status,
            'dhl_status' => $this->description(),
            'timestamp' => $this->timestamp()?->format(\DateTimeInterface::ATOM),
            'delivered_at' => $this->deliveredAt?->format(\DateTimeInterface::ATOM),
            'estimated_delivery' => $this->estimatedDelivery?->format(\DateTimeInterface::ATOM),
            'service' => $this->shipment?->service,
        ];
    }
}
