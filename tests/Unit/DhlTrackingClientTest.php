<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Tests\Unit;

use GPWebshops\ShopHub\Dhl\Exception\AuthenticationException;
use GPWebshops\ShopHub\Dhl\Exception\ConfigurationException;
use GPWebshops\ShopHub\Dhl\Exception\QuotaExceededException;
use GPWebshops\ShopHub\Dhl\Exception\ServerException;
use GPWebshops\ShopHub\Dhl\Query\TrackingQuery;
use GPWebshops\ShopHub\Dhl\Status\DeliveryState;
use GPWebshops\ShopHub\Dhl\Status\PickupPointType;
use GPWebshops\ShopHub\Dhl\Tests\Support\MockApi;
use PHPUnit\Framework\TestCase;

final class DhlTrackingClientTest extends TestCase
{
    public function testShipmentsAreParsed(): void
    {
        $api = new MockApi([MockApi::fixture('spec-example-transit')]);

        $shipments = $api->client->shipments('GMDBD8E9CCE94842E495B7');

        self::assertCount(1, $shipments);
        self::assertSame('GMDBD8E9CCE94842E495B7', $shipments[0]->id);
        self::assertSame('ecommerce', $shipments[0]->service);
        self::assertCount(8, $shipments[0]->events);
        self::assertSame('ARRIVED AT CUSTOMS', $shipments[0]->status?->status);
    }

    public function testStatusForServicePoint(): void
    {
        $api = new MockApi([MockApi::fixture('parcel-nl-servicepoint-ready')]);

        $status = $api->client->status(new TrackingQuery('JVGL06191357009876543210', '3511 AB'));

        self::assertSame(DeliveryState::ReadyForPickup, $status->state);
        self::assertSame(PickupPointType::ServicePoint, $status->pickupPoint?->type);
        self::assertSame('Primera Centrum', $status->pickupPoint->label);
    }

    public function testBatchKeepsGoingAfterAFailureAndSkipsDuplicates(): void
    {
        $api = new MockApi([
            MockApi::fixture('parcel-nl-delivered-at-door'),
            MockApi::problem(500, 'Internal error', 'Boom'),
            MockApi::problem(404, 'No result found', 'No shipment with given tracking number found.'),
        ]);

        $result = $api->client->statuses(['JVGL06191357001234567890', 'jvgl 0619 1357 0012 3456 7890', 'BROKEN1', 'UNKNOWN1']);

        self::assertCount(3, $api->history);
        self::assertSame(['JVGL06191357001234567890', 'UNKNOWN1'], array_keys($result->statuses));
        self::assertSame(DeliveryState::Delivered, $result->get('JVGL06191357001234567890')?->state);
        self::assertSame(DeliveryState::NotFound, $result->get('UNKNOWN1')?->state);
        self::assertInstanceOf(ServerException::class, $result->failures['BROKEN1']);
        self::assertSame([], $result->skipped);
        self::assertNull($result->stoppedBy);
        self::assertFalse($result->isComplete());
    }

    public function testBatchStopsOnDailyQuota(): void
    {
        $api = new MockApi([
            MockApi::fixture('parcel-nl-pre-transit'),
            MockApi::json(['fault' => ['faultstring' => 'Rate limit quota violation. Quota limit exceeded.']], 429),
        ]);

        $result = $api->client->statuses(['JVGL06191357002222222222', 'NEXT1', 'NEXT2', 'NEXT3']);

        self::assertCount(2, $api->history);
        self::assertSame(['JVGL06191357002222222222'], array_keys($result->statuses));
        self::assertInstanceOf(QuotaExceededException::class, $result->failures['NEXT1']);
        self::assertSame(['NEXT2', 'NEXT3'], $result->skipped);
        self::assertInstanceOf(QuotaExceededException::class, $result->stoppedBy);
    }

    public function testBatchStopsOnBadKey(): void
    {
        $api = new MockApi([MockApi::problem(401, 'Unauthorized', 'Access to the resource is not allowed.')]);

        $result = $api->client->statuses(['A1', 'A2']);

        self::assertInstanceOf(AuthenticationException::class, $result->stoppedBy);
        self::assertSame(['A2'], $result->skipped);
    }

    public function testBatchReportsInvalidNumbersWithoutCalling(): void
    {
        $api = new MockApi([]);

        $result = $api->client->statuses(['not valid!']);

        self::assertCount(0, $api->history);
        self::assertInstanceOf(ConfigurationException::class, $result->failures['not valid!']);
    }
}
