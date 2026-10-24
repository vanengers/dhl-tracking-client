<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Model;

/**
 * A tracked shipment (TrackingShipment in the spec), limited to the fields that matter for a web shop.
 * Everything else is still available in $raw.
 */
final class Shipment
{
    /**
     * @param list<Event>             $events newest first
     * @param array<array-key, mixed> $raw    the shipment object exactly as DHL sent it
     */
    public function __construct(
        public readonly string $id,
        /** The DHL service that answered, e.g. "parcel-nl", "parcel-de", "express", "ecommerce". */
        public readonly ?string $service,
        public readonly ?Event $status,
        public readonly array $events = [],
        public readonly ?Place $origin = null,
        public readonly ?Place $destination = null,
        public readonly ?\DateTimeImmutable $estimatedTimeOfDelivery = null,
        public readonly ?\DateTimeImmutable $estimatedDeliveryFrom = null,
        public readonly ?\DateTimeImmutable $estimatedDeliveryThrough = null,
        public readonly ?string $estimatedTimeOfDeliveryRemark = null,
        public readonly bool $returnFlag = false,
        public readonly ?string $productName = null,
        public readonly ?string $deliveryMethodRemark = null,
        public readonly ?string $proofOfDeliveryUrl = null,
        public readonly ?\DateTimeImmutable $proofOfDeliveryTimestamp = null,
        public readonly ?int $totalNumberOfPieces = null,
        public readonly ?string $serviceUrl = null,
        public readonly array $raw = [],
    ) {
    }

    public static function fromData(Data $d, DateParser $dates): self
    {
        $details = $d->object('details');
        $product = $details?->object('product');
        $pod = $details?->object('proofOfDelivery');
        $timeFrame = $d->object('estimatedDeliveryTimeFrame');
        $statusData = $d->object('status');
        $pieces = $details?->string('totalNumberOfPieces');

        return new self(
            $d->string('id') ?? '',
            $d->string('service'),
            $statusData !== null ? Event::fromData($statusData, $dates) : null,
            self::sortedEvents($d, $dates),
            Place::fromData($d->object('origin')),
            Place::fromData($d->object('destination')),
            $d->date('estimatedTimeOfDelivery', $dates),
            $timeFrame?->date('estimatedFrom', $dates),
            $timeFrame?->date('estimatedThrough', $dates),
            $d->string('estimatedTimeOfDeliveryRemark'),
            $d->bool('returnFlag') ?? false,
            $product?->string('productName'),
            $product?->string('deliveryMethodRemark'),
            $pod?->string('documentUrl'),
            $pod?->date('timestamp', $dates),
            $pieces !== null && ctype_digit($pieces) ? (int) $pieces : null,
            $d->string('serviceUrl'),
            $d->raw(),
        );
    }

    /**
     * The current status: the status object, or the newest event when DHL left the status out.
     */
    public function latestEvent(): ?Event
    {
        return $this->status ?? ($this->events[0] ?? null);
    }

    /**
     * Events that happened before $event, newest first.
     *
     * @return list<Event>
     */
    public function eventsBefore(Event $event): array
    {
        foreach ($this->events as $index => $candidate) {
            if ($candidate === $event) {
                return array_slice($this->events, $index + 1);
            }
        }
        // $event is the status object, which is a copy of the newest event.
        $older = [];
        foreach ($this->events as $candidate) {
            $isOlder = $event->timestamp !== null && $candidate->timestamp !== null
                ? $candidate->timestamp < $event->timestamp
                : $candidate->text() !== $event->text();
            if ($isOlder) {
                $older[] = $candidate;
            }
        }

        return $older;
    }

    /**
     * @return list<Event>
     */
    private static function sortedEvents(Data $d, DateParser $dates): array
    {
        $events = array_map(static fn (Data $e): Event => Event::fromData($e, $dates), $d->objects('events'));
        // Newest first, events without a timestamp last; usort is stable, so DHL's order wins on equal times.
        usort($events, static function (Event $a, Event $b): int {
            if ($a->timestamp === null || $b->timestamp === null) {
                return ($a->timestamp === null ? 1 : 0) - ($b->timestamp === null ? 1 : 0);
            }

            return $b->timestamp <=> $a->timestamp;
        });

        return $events;
    }
}
