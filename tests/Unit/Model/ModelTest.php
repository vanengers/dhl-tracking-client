<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Tests\Unit\Model;

use GPWebshops\ShopHub\Dhl\Model\DateParser;
use GPWebshops\ShopHub\Dhl\Model\StatusCode;
use GPWebshops\ShopHub\Dhl\Status\TextSignals;
use GPWebshops\ShopHub\Dhl\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModelTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function dates(): iterable
    {
        yield 'zoned Z' => ['2017-06-21T14:07:00Z', '2017-06-21T14:07:00+00:00'];
        yield 'short offset from the spec' => ['2017-06-21T14:07:17+2:00', '2017-06-21T14:07:17+02:00'];
        yield 'offset without colon' => ['2017-06-21T14:07:17+0200', '2017-06-21T14:07:17+02:00'];
        yield 'local time uses the default zone' => ['2017-06-21T14:07:17', '2017-06-21T14:07:17+02:00'];
        yield 'local time in winter' => ['2023-01-29T16:02:00', '2023-01-29T16:02:00+01:00'];
        yield 'date only' => ['2017-06-21', '2017-06-21T00:00:00+02:00'];
        yield 'fraction' => ['2017-06-21T14:07:17.123Z', '2017-06-21T14:07:17+00:00'];
        yield 'garbage' => ['tomorrow', null];
        yield 'impossible date' => ['2017-02-30T25:61:00', null];
    }

    #[DataProvider('dates')]
    public function testDateParser(string $input, ?string $expected): void
    {
        self::assertSame($expected, (new DateParser(new \DateTimeZone('Europe/Amsterdam')))->parse($input)?->format(DATE_ATOM));
    }

    public function testSpecExampleIsParsedAndEventsAreNewestFirst(): void
    {
        $shipment = Fixtures::shipments('spec-example-transit')[0];

        self::assertSame('DHL Parcel Intl Standard', $shipment->productName);
        self::assertSame('SHEFFIELD', $shipment->destination?->address?->locality);
        self::assertSame(StatusCode::Transit, $shipment->latestEvent()?->statusCode);
        self::assertSame('LABEL CREATED', $shipment->events[7]->status);
        self::assertCount(7, $shipment->eventsBefore($shipment->latestEvent()));
        self::assertSame('GMDBD8E9CCE94842E495B7', $shipment->raw['id']);
    }

    public function testDeliveryWindowAndProofOfDelivery(): void
    {
        $eta = Fixtures::shipments('parcel-nl-out-for-delivery')[0];
        $pod = Fixtures::shipments('parcel-nl-delivered-at-door')[0];

        self::assertSame('2026-10-21T13:00:00+02:00', $eta->estimatedDeliveryFrom?->format(DATE_ATOM));
        self::assertSame('2026-10-21T15:00:00+02:00', $eta->estimatedTimeOfDelivery?->format(DATE_ATOM));
        self::assertSame('2026-10-20T14:31:00+02:00', $pod->proofOfDeliveryTimestamp?->format(DATE_ATOM));
        self::assertSame(1, $pod->totalNumberOfPieces);
    }

    public function testToleratesWrongTypes(): void
    {
        $shipment = Fixtures::fromBody(['shipments' => [
            ['id' => 123, 'status' => 'delivered', 'events' => [['statusCode' => ['x'], 'timestamp' => 5, 'location' => 'here'], 'junk'], 'returnFlag' => 'true', 'details' => []],
            'not a shipment',
        ]]);

        self::assertCount(1, $shipment);
        self::assertSame('123', $shipment[0]->id);
        self::assertNull($shipment[0]->status);
        self::assertTrue($shipment[0]->returnFlag);
        self::assertCount(1, $shipment[0]->events);
        self::assertSame(StatusCode::Unknown, $shipment[0]->events[0]->statusCode);
        self::assertNull($shipment[0]->events[0]->timestamp);
    }

    public function testTextNormalisation(): void
    {
        self::assertSame('ready for pick up at the servicepoint', TextSignals::normalise("READY_FOR_PICK-UP\tat the  ServicePoint."));
        self::assertSame('rücksendung an den absender', TextSignals::normalise('Rücksendung an den Absender'));
    }
}
