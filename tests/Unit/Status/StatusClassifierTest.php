<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Tests\Unit\Status;

use GPWebshops\ShopHub\Dhl\Status\DeliveryPlace;
use GPWebshops\ShopHub\Dhl\Status\DeliveryState;
use GPWebshops\ShopHub\Dhl\Status\Keywords;
use GPWebshops\ShopHub\Dhl\Status\PickupPointType;
use GPWebshops\ShopHub\Dhl\Status\StatusClassifier;
use GPWebshops\ShopHub\Dhl\Status\TextSignals;
use GPWebshops\ShopHub\Dhl\Status\TrackingStatus;
use GPWebshops\ShopHub\Dhl\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatusClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, DeliveryState, ?DeliveryPlace, ?PickupPointType}>
     */
    public static function fixtures(): iterable
    {
        yield 'spec example: in transit' => ['spec-example-transit', 'GMDBD8E9CCE94842E495B7', DeliveryState::InTransit, null, null];
        yield 'delivered at the door' => ['parcel-nl-delivered-at-door', 'JVGL06191357001234567890', DeliveryState::Delivered, DeliveryPlace::Address, null];
        yield 'delivered at service point = ready for pickup' => ['parcel-nl-servicepoint-ready', 'JVGL06191357009876543210', DeliveryState::ReadyForPickup, null, PickupPointType::ServicePoint];
        yield 'collected from service point' => ['parcel-nl-servicepoint-collected', 'JVGL06191357009876543210', DeliveryState::Delivered, DeliveryPlace::PickupPoint, PickupPointType::ServicePoint];
        yield 'plain delivered after ready for pickup' => ['parcel-nl-delivered-after-ready', 'JVGL06191357005555555555', DeliveryState::Delivered, DeliveryPlace::PickupPoint, PickupPointType::ServicePoint];
        yield 'packstation ready' => ['parcel-de-packstation-ready', '00340434292135100186', DeliveryState::ReadyForPickup, null, PickupPointType::Locker];
        yield 'Filiale ready (German text)' => ['parcel-de-filiale-ready-german', '00340434292135100200', DeliveryState::ReadyForPickup, null, PickupPointType::PostOffice];
        yield 'delivered to neighbour' => ['parcel-de-neighbour', '00340434292135100300', DeliveryState::Delivered, DeliveryPlace::Neighbour, null];
        yield 'mailbox (Dutch text)' => ['parcel-nl-mailbox-dutch', 'JVGL06191357004444444444', DeliveryState::Delivered, DeliveryPlace::Mailbox, null];
        yield 'out for delivery' => ['parcel-nl-out-for-delivery', 'JVGL06191357003333333333', DeliveryState::OutForDelivery, null, null];
        yield 'pre-transit' => ['parcel-nl-pre-transit', 'JVGL06191357002222222222', DeliveryState::PreTransit, null, null];
        yield 'sender drop-off at service point is not ready for pickup' => ['parcel-nl-sender-dropoff-at-servicepoint', 'JVGL06191357001111111111', DeliveryState::InTransit, null, null];
        yield 'on its way to service point is not ready yet' => ['parcel-nl-on-way-to-servicepoint', 'JVGL06191357006666666666', DeliveryState::InTransit, null, null];
        yield 'failed delivery attempt' => ['parcel-de-failed-attempt', '00340434292135100400', DeliveryState::DeliveryFailed, null, null];
        yield 'failed attempt, waits at retail outlet' => ['parcel-de-failed-then-at-retail-outlet', '00340434292135100500', DeliveryState::ReadyForPickup, null, PickupPointType::PostOffice];
        yield 'not collected from service point' => ['parcel-nl-not-collected', 'JVGL06191357007777777777', DeliveryState::DeliveryFailed, null, PickupPointType::ServicePoint];
        yield 'returned to sender' => ['parcel-nl-returned', 'JVGL06191357008888888888', DeliveryState::Returned, null, null];
        yield 'real: collected from a DHL Locker' => ['real-parcel-nl-locker-collected', 'JVGL08900000000000000001', DeliveryState::Delivered, DeliveryPlace::PickupPoint, PickupPointType::Locker];
        yield 'several shipments: exact id wins' => ['multiple-shipments', '1234567890', DeliveryState::Delivered, DeliveryPlace::Address, null];
    }

    #[DataProvider('fixtures')]
    public function testFixture(string $fixture, string $number, DeliveryState $state, ?DeliveryPlace $place, ?PickupPointType $pointType): void
    {
        $status = (new StatusClassifier())->classify($number, Fixtures::shipments($fixture));

        $why = sprintf('%s (rule %s, text "%s")', $status->state->value, $status->reason, $status->lastEvent?->statusText());
        self::assertSame($state, $status->state, $why);
        self::assertSame($place, $status->deliveryPlace, $why);
        self::assertSame($pointType, $status->pickupPoint?->type, $why);
    }

    public function testReadyForPickupCarriesTheServicePointDetails(): void
    {
        $status = (new StatusClassifier())->classify('JVGL06191357009876543210', Fixtures::shipments('parcel-nl-servicepoint-ready'));

        self::assertTrue($status->isReadyForPickup());
        self::assertFalse($status->isFinal());
        self::assertNotNull($status->pickupPoint);
        self::assertSame('Primera Centrum', $status->pickupPoint->label);
        self::assertSame('https://www.dhl.com/nl-en/home/location-finder.html?id=NL-123456', $status->pickupPoint->url);
        self::assertSame('Oudegracht 1, 3511AB Utrecht, NL', $status->pickupPoint->address?->oneLine());
        self::assertSame('The shipment has been delivered at the DHL ServicePoint', $status->description());
        self::assertNull($status->deliveredAt);
    }

    public function testDeliveredHasTimestampAndIsFinal(): void
    {
        $status = (new StatusClassifier())->classify('JVGL06191357001234567890', Fixtures::shipments('parcel-nl-delivered-at-door'));

        self::assertTrue($status->isDelivered());
        self::assertTrue($status->isFinal());
        self::assertSame('2026-10-20T14:31:00+02:00', $status->deliveredAt?->format(DATE_ATOM));
        self::assertSame('delivered', $status->reason);
    }

    public function testNoShipmentsIsNotFound(): void
    {
        $status = (new StatusClassifier())->classify('X1', []);

        self::assertSame(DeliveryState::NotFound, $status->state);
        self::assertFalse($status->isFound());
        self::assertNull($status->shipment);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, DeliveryState, ?DeliveryPlace, ?PickupPointType, string}>
     */
    public static function edgeCases(): iterable
    {
        $sp = Fixtures::atServicePoint();

        yield 'transit scan at service point after the parcel moved = ready' => [
            Fixtures::shipment([
                Fixtures::event('transit', 'Scan', '2026-10-21T10:00:00+02:00', $sp),
                Fixtures::event('transit', 'Sorted', '2026-10-20T20:00:00+02:00'),
            ]),
            DeliveryState::ReadyForPickup, null, PickupPointType::ServicePoint, 'transit:ready-for-pickup',
        ];
        yield 'first transit scan at service point = sender drop-off' => [
            Fixtures::shipment([
                Fixtures::event('transit', 'Scan', '2026-10-21T10:00:00+02:00', $sp),
                Fixtures::event('pre-transit', 'Data received', '2026-10-20T20:00:00+02:00'),
            ]),
            DeliveryState::InTransit, null, null, 'transit',
        ];
        yield 'future text never means ready' => [
            Fixtures::shipment([Fixtures::event('transit', 'Your parcel will be delivered to a DHL ServicePoint', '2026-10-21T10:00:00+02:00')]),
            DeliveryState::InTransit, null, null, 'transit',
        ];
        yield 'nextSteps about a return do not make a ready parcel returned' => [
            Fixtures::shipment([Fixtures::event('transit', 'The shipment is ready for pickup at the DHL ServicePoint', '2026-10-21T10:00:00+02:00', [
                'nextSteps' => 'If it is not collected within 7 days it will be returned to the sender.',
            ])]),
            DeliveryState::ReadyForPickup, null, PickupPointType::ServicePoint, 'transit:ready-for-pickup',
        ];
        yield 'ready with code-style statusDetailed' => [
            Fixtures::shipment([Fixtures::event('transit', 'Update', '2026-10-21T10:00:00+02:00', ['statusDetailed' => 'READY_FOR_PICKUP'])]),
            DeliveryState::ReadyForPickup, null, PickupPointType::Unknown, 'transit:ready-for-pickup',
        ];
        yield 'destination service point, first delivered scan = ready' => [
            Fixtures::shipment([Fixtures::event('delivered', 'Delivered', '2026-10-21T10:00:00+02:00')], ['destination' => $sp['location']]),
            DeliveryState::ReadyForPickup, null, PickupPointType::ServicePoint, 'delivered:destination-is-service-point',
        ];
        yield 'destination service point, second delivered scan = collected' => [
            Fixtures::shipment([
                Fixtures::event('delivered', 'Delivered', '2026-10-23T10:00:00+02:00'),
                Fixtures::event('delivered', 'Delivered', '2026-10-21T10:00:00+02:00'),
            ], ['destination' => $sp['location']]),
            DeliveryState::Delivered, DeliveryPlace::PickupPoint, PickupPointType::ServicePoint, 'delivered:second-scan-at-destination-service-point',
        ];
        yield 'unknown code with delivered text' => [
            Fixtures::shipment([Fixtures::event('unknown', 'The shipment has been delivered', '2026-10-21T10:00:00+02:00')]),
            DeliveryState::Delivered, DeliveryPlace::Address, null, 'delivered',
        ];
        yield 'unknown code after transit events' => [
            Fixtures::shipment([
                Fixtures::event('unknown', 'CLOSE BAG', '2026-10-21T10:00:00+02:00'),
                Fixtures::event('transit', 'PROCESSED', '2026-10-20T10:00:00+02:00'),
            ]),
            DeliveryState::InTransit, null, null, 'unknown:after-transit',
        ];
        yield 'unknown code, label created' => [
            Fixtures::shipment([Fixtures::event('unknown', 'LABEL CREATED', '2026-10-21T10:00:00+02:00')]),
            DeliveryState::PreTransit, null, null, 'unknown:pre-transit-text',
        ];
        yield 'unknown code, nothing to go on' => [
            Fixtures::shipment([Fixtures::event('unknown', 'Update', '2026-10-21T10:00:00+02:00')]),
            DeliveryState::Unknown, null, null, 'unknown',
        ];
        yield 'status code DHL may add later reads as unknown' => [
            Fixtures::shipment([Fixtures::event('ready-for-pickup', 'Ready for pick-up', '2026-10-21T10:00:00+02:00')]),
            DeliveryState::ReadyForPickup, null, PickupPointType::Unknown, 'unknown:ready-for-pickup',
        ];
        yield 'no status object: newest event is used' => [
            ['id' => '3SABCD0123456', 'events' => [
                Fixtures::event('transit', 'Sorted', '2026-10-20T10:00:00+02:00'),
                Fixtures::event('delivered', 'The shipment has been delivered', '2026-10-21T10:00:00+02:00'),
            ]],
            DeliveryState::Delivered, DeliveryPlace::Address, null, 'delivered',
        ];
        yield 'no events at all' => [
            ['id' => '3SABCD0123456', 'service' => 'parcel-nl'],
            DeliveryState::Unknown, null, null, 'no-events',
        ];
        yield 'safe place' => [
            Fixtures::shipment([Fixtures::event('delivered', 'Delivered at the agreed safe place', '2026-10-21T10:00:00+02:00')]),
            DeliveryState::Delivered, DeliveryPlace::SafePlace, null, 'delivered:safe_place',
        ];
        yield 'returned text without the flag' => [
            Fixtures::shipment([Fixtures::event('transit', 'Retour afzender', '2026-10-21T10:00:00+02:00')]),
            DeliveryState::Returned, null, null, 'returned:text',
        ];
        yield 'out for delivery, German' => [
            Fixtures::shipment([Fixtures::event('transit', 'Die Sendung befindet sich in Zustellung', '2026-10-21T10:00:00+02:00')]),
            DeliveryState::OutForDelivery, null, null, 'transit:out-for-delivery',
        ];
    }

    /**
     * @param array<string, mixed> $shipment
     */
    #[DataProvider('edgeCases')]
    public function testEdgeCase(array $shipment, DeliveryState $state, ?DeliveryPlace $place, ?PickupPointType $pointType, string $reason): void
    {
        $status = $this->classify($shipment);

        self::assertSame([$state, $place, $pointType, $reason], [$status->state, $status->deliveryPlace, $status->pickupPoint?->type, $status->reason]);
    }

    /**
     * A real parcel-nl response, replayed: what each check would have said after every event.
     *
     * @return iterable<string, array{int, DeliveryState, string, ?PickupPointType}>
     */
    public static function realLockerTimeline(): iterable
    {
        yield 'sorted at hub' => [15, DeliveryState::InTransit, 'transit', null];
        yield 'pre-notification after the hub scan' => [14, DeliveryState::InTransit, 'pre-transit:after-transit', null];
        yield 'receiver asks for a service point' => [12, DeliveryState::InTransit, 'unknown:after-transit', null];
        yield 'address changed to service point' => [9, DeliveryState::InTransit, 'unknown:after-transit', null];
        yield 'new delivery attempt' => [8, DeliveryState::InTransit, 'transit', null];
        yield 'out for delivery' => [3, DeliveryState::OutForDelivery, 'transit:out-for-delivery', null];
        yield 'internal event: not ready for collection' => [2, DeliveryState::InTransit, 'transit', null];
        yield 'in the locker, ready for collection' => [1, DeliveryState::ReadyForPickup, 'transit:ready-for-pickup', PickupPointType::Locker];
        yield 'collected from the locker' => [0, DeliveryState::Delivered, 'delivered:collected', PickupPointType::Locker];
    }

    #[DataProvider('realLockerTimeline')]
    public function testRealLockerParcelAtEveryCheck(int $from, DeliveryState $state, string $reason, ?PickupPointType $pointType): void
    {
        $body = json_decode(Fixtures::read('real-parcel-nl-locker-collected'), true, 512, JSON_THROW_ON_ERROR);
        $shipment = $body['shipments'][0];
        $shipment['events'] = array_slice($shipment['events'], $from);
        $shipment['status'] = $shipment['events'][0];

        $status = $this->classify($shipment);

        self::assertSame([$state, $reason, $pointType], [$status->state, $status->reason, $status->pickupPoint?->type]);
    }

    public function testExtraKeywordsExtendTheRules(): void
    {
        $shipment = Fixtures::shipment([Fixtures::event('transit', 'Pakket staat voor je klaar bij de Primera', '2026-10-21T10:00:00+02:00')]);

        self::assertSame(DeliveryState::InTransit, $this->classify($shipment)->state);

        $classifier = new StatusClassifier(new TextSignals([Keywords::READY => ['staat voor je klaar'], Keywords::SERVICE_POINT => ['primera']]));
        $status = $classifier->classify('3SABCD0123456', Fixtures::fromBody(['shipments' => [$shipment]]));

        self::assertSame(DeliveryState::ReadyForPickup, $status->state);
        self::assertSame(PickupPointType::ServicePoint, $status->pickupPoint?->type);
    }

    public function testToArrayIsFlat(): void
    {
        $row = (new StatusClassifier())->classify('JVGL06191357009876543210', Fixtures::shipments('parcel-nl-servicepoint-ready'))->toArray();

        self::assertSame('ready_for_pickup', $row['state']);
        self::assertSame('service_point', $row['pickup_point_type']);
        self::assertSame('Primera Centrum', $row['pickup_point_label']);
        self::assertSame('delivered', $row['dhl_status_code']);
        self::assertSame('DELIVERED_AT_SERVICEPOINT', $row['dhl_event']);
        self::assertSame('parcel-nl', $row['service']);
        self::assertNull($row['delivered_at']);
    }

    /**
     * @param array<string, mixed> $shipment
     */
    private function classify(array $shipment): TrackingStatus
    {
        return (new StatusClassifier())->classify('3SABCD0123456', Fixtures::fromBody(['shipments' => [$shipment]]));
    }
}
