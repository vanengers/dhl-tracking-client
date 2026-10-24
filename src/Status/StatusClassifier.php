<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Status;

use GPWebshops\ShopHub\Dhl\Model\Event;
use GPWebshops\ShopHub\Dhl\Model\Shipment;
use GPWebshops\ShopHub\Dhl\Model\StatusCode;

/**
 * Turns DHL's tracking data into a DeliveryState. DHL's statusCode has no "ready for pickup", so the rules also read
 * the event text (status, statusDetailed, description), the event location (a servicePoint) and earlier events.
 *
 * Rules for the newest event, in order:
 *  1. returnFlag or "returned to sender" text                                  -> Returned
 *  2. statusCode "delivered" (or "unknown" with "has been delivered" text):
 *     at a service point / locker / post office, not yet collected            -> ReadyForPickup
 *     "collected by the recipient"                                             -> Delivered, place PickupPoint
 *     neighbour / mailbox / safe place named                                   -> Delivered, that place
 *     an earlier event was ready-for-pickup                                    -> Delivered, place PickupPoint
 *     destination is a service point: first delivered scan -> ReadyForPickup, a later one -> Delivered (PickupPoint)
 *     otherwise                                                                -> Delivered, place Address
 *  3. statusCode "failure": "not collected" -> DeliveryFailed; "can be picked up at ..." -> ReadyForPickup;
 *     otherwise DeliveryFailed
 *  4. other codes: "ready for pickup" text, or "arrived at the <pickup point>" text, or a transit scan at a service
 *     point after the parcel already moved (not the sender's drop-off)         -> ReadyForPickup
 *     "out for delivery" text, not on the way to a pickup point                -> OutForDelivery
 *     transit -> InTransit, pre-transit -> PreTransit, unknown -> guessed from text and earlier events
 * Texts about the future ("will be delivered to a ServicePoint") or about the sender never mean ReadyForPickup.
 */
final class StatusClassifier
{
    public function __construct(private readonly TextSignals $signals = new TextSignals())
    {
    }

    /**
     * @param list<Shipment> $shipments what DHL returned for $trackingNumber
     */
    public function classify(string $trackingNumber, array $shipments): TrackingStatus
    {
        $shipment = self::select($trackingNumber, $shipments);
        if ($shipment === null) {
            return TrackingStatus::notFound($trackingNumber);
        }
        $event = $shipment->latestEvent();
        $verdict = $event === null ? new Verdict(DeliveryState::Unknown, 'no-events') : $this->decide($shipment, $event);

        return TrackingStatus::fromVerdict($trackingNumber, $shipment, $event, $verdict);
    }

    private function decide(Shipment $shipment, Event $event): Verdict
    {
        $text = TextSignals::normalise($event->statusText());
        $older = $shipment->eventsBefore($event);
        if ($shipment->returnFlag) {
            return new Verdict(DeliveryState::Returned, 'returned:flag');
        }
        if ($this->signals->has(Keywords::RETURNED, $text)) {
            return new Verdict(DeliveryState::Returned, 'returned:text');
        }
        $code = $event->statusCode;
        if ($code === StatusCode::Unknown && $this->signals->saysDelivered($text)) {
            $code = StatusCode::Delivered;
        }

        return match ($code) {
            StatusCode::Delivered => $this->delivered($shipment, $event, $text, $older),
            StatusCode::Failure => $this->failure($shipment, $event, $text, $older),
            default => $this->underway($shipment, $event, $code, $text, $older),
        };
    }

    /**
     * @param list<Event> $older
     */
    private function delivered(Shipment $shipment, Event $event, string $text, array $older): Verdict
    {
        $point = $this->readyAt($shipment, $event, StatusCode::Delivered, $text, $older);
        if ($point !== null) {
            return new Verdict(DeliveryState::ReadyForPickup, 'delivered:at-pickup-point', null, $point);
        }
        if ($this->signals->isCollected($text)) {
            $point = $this->namedPoint($shipment, $event, $text) ?? $this->earlierReadyPoint($shipment, $older);

            return new Verdict(DeliveryState::Delivered, 'delivered:collected', DeliveryPlace::PickupPoint, $point);
        }
        $place = $this->signals->alternativePlace($text);
        if ($place !== null) {
            return new Verdict(DeliveryState::Delivered, 'delivered:' . $place->value, $place);
        }
        $point = $this->earlierReadyPoint($shipment, $older);
        if ($point !== null) {
            return new Verdict(DeliveryState::Delivered, 'delivered:after-ready-for-pickup', DeliveryPlace::PickupPoint, $point);
        }
        if ($shipment->destination?->servicePoint !== null) {
            $point = PickupPoint::fromDestination($shipment);

            return self::hasCode($older, StatusCode::Delivered)
                ? new Verdict(DeliveryState::Delivered, 'delivered:second-scan-at-destination-service-point', DeliveryPlace::PickupPoint, $point)
                : new Verdict(DeliveryState::ReadyForPickup, 'delivered:destination-is-service-point', null, $point);
        }

        return new Verdict(DeliveryState::Delivered, 'delivered', DeliveryPlace::Address);
    }

    /**
     * @param list<Event> $older
     */
    private function failure(Shipment $shipment, Event $event, string $text, array $older): Verdict
    {
        if ($this->signals->has(Keywords::NOT_COLLECTED, $text)) {
            return new Verdict(DeliveryState::DeliveryFailed, 'failure:not-collected', null, $this->earlierReadyPoint($shipment, $older));
        }
        $point = $this->readyAt($shipment, $event, StatusCode::Failure, $text, $older);

        return $point !== null
            ? new Verdict(DeliveryState::ReadyForPickup, 'failure:ready-for-pickup', null, $point)
            : new Verdict(DeliveryState::DeliveryFailed, 'failure');
    }

    /**
     * @param list<Event> $older
     */
    private function underway(Shipment $shipment, Event $event, StatusCode $code, string $text, array $older): Verdict
    {
        $point = $this->readyAt($shipment, $event, $code, $text, $older);
        if ($point !== null) {
            return new Verdict(DeliveryState::ReadyForPickup, $code->value . ':ready-for-pickup', null, $point);
        }
        // On the way to a pickup point is not "out for delivery" for the customer.
        if ($code !== StatusCode::PreTransit && $this->signals->has(Keywords::OUT_FOR_DELIVERY, $text)
            && !$this->signals->has(Keywords::SENDER, $text) && $this->signals->pickupPointType($text) === null) {
            return new Verdict(DeliveryState::OutForDelivery, $code->value . ':out-for-delivery');
        }

        return match ($code) {
            StatusCode::Transit => new Verdict(DeliveryState::InTransit, 'transit'),
            StatusCode::PreTransit => new Verdict(DeliveryState::PreTransit, 'pre-transit'),
            default => $this->unknown($text, $older),
        };
    }

    /**
     * @param list<Event> $older
     */
    private function unknown(string $text, array $older): Verdict
    {
        if ($this->signals->has(Keywords::PRE_TRANSIT, $text)) {
            return new Verdict(DeliveryState::PreTransit, 'unknown:pre-transit-text');
        }
        if (self::hasCode($older, StatusCode::Transit) || self::hasCode($older, StatusCode::Delivered) || self::hasCode($older, StatusCode::Failure)) {
            return new Verdict(DeliveryState::InTransit, 'unknown:after-transit');
        }
        if (self::hasCode($older, StatusCode::PreTransit)) {
            return new Verdict(DeliveryState::PreTransit, 'unknown:after-pre-transit');
        }

        return new Verdict(DeliveryState::Unknown, 'unknown');
    }

    /**
     * The pickup point when this event says the parcel waits there for the recipient; null otherwise.
     *
     * @param list<Event> $older events before $event
     */
    private function readyAt(Shipment $shipment, Event $event, StatusCode $code, string $text, array $older): ?PickupPoint
    {
        if ($code === StatusCode::PreTransit || $this->signals->has(Keywords::NOT_COLLECTED, $text) || $this->signals->isCollected($text)) {
            return null;
        }
        if ($code !== StatusCode::Delivered && ($this->signals->has(Keywords::SENDER, $text) || $this->signals->has(Keywords::FUTURE, $text))) {
            return null;
        }
        $type = $this->signals->pickupPointType($text);
        $hasServicePoint = $event->location?->servicePoint !== null;

        $ready = $this->signals->has(Keywords::READY, $text);
        $atPoint = $type !== null && $type !== PickupPointType::Depot
            && ($code === StatusCode::Delivered || $this->signals->has(Keywords::ARRIVED, $text));
        // A transit scan at a service point is the sender's drop-off when the parcel has not moved before.
        $servicePointArrival = $hasServicePoint
            && ($code === StatusCode::Delivered || ($code === StatusCode::Transit && self::hasCode($older, StatusCode::Transit)));
        if (!$ready && !$atPoint && !$servicePointArrival) {
            return null;
        }

        return PickupPoint::fromEvent($type ?? ($hasServicePoint ? PickupPointType::ServicePoint : PickupPointType::Unknown), $event, $shipment);
    }

    private function namedPoint(Shipment $shipment, Event $event, string $text): ?PickupPoint
    {
        $type = $this->signals->pickupPointType($text) ?? ($event->location?->servicePoint !== null ? PickupPointType::ServicePoint : null);

        return $type !== null ? PickupPoint::fromEvent($type, $event, $shipment) : null;
    }

    /**
     * @param list<Event> $older newest first
     */
    private function earlierReadyPoint(Shipment $shipment, array $older): ?PickupPoint
    {
        foreach ($older as $index => $event) {
            $text = TextSignals::normalise($event->statusText());
            $point = $this->readyAt($shipment, $event, $event->statusCode, $text, array_slice($older, $index + 1));
            if ($point !== null) {
                return $point;
            }
        }

        return null;
    }

    /**
     * @param list<Event> $events
     */
    private static function hasCode(array $events, StatusCode $code): bool
    {
        foreach ($events as $event) {
            if ($event->statusCode === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * DHL may return several shipments for one number (different services). Prefer the exact id, then the newest.
     *
     * @param list<Shipment> $shipments
     */
    private static function select(string $trackingNumber, array $shipments): ?Shipment
    {
        $normalise = static fn (string $id): string => strtoupper((string) preg_replace('/\s+/', '', $id));
        $wanted = $normalise($trackingNumber);
        $matching = array_values(array_filter($shipments, static fn (Shipment $s): bool => $normalise($s->id) === $wanted));
        $candidates = $matching !== [] ? $matching : $shipments;
        usort($candidates, static function (Shipment $a, Shipment $b): int {
            $ta = $a->latestEvent()?->timestamp;
            $tb = $b->latestEvent()?->timestamp;
            if ($ta === null || $tb === null) {
                return ($ta === null ? 1 : 0) - ($tb === null ? 1 : 0);
            }

            return $tb <=> $ta;
        });

        return $candidates[0] ?? null;
    }
}
