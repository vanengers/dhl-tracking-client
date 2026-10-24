<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Tests\Support;

use GPWebshops\ShopHub\Dhl\Model\Data;
use GPWebshops\ShopHub\Dhl\Model\DateParser;
use GPWebshops\ShopHub\Dhl\Model\Shipment;

/**
 * Reads tests/Fixtures/*.json (bodies of GET /shipments) and builds inline shipments for edge cases.
 */
final class Fixtures
{
    public static function read(string $name): string
    {
        $file = dirname(__DIR__) . '/Fixtures/' . $name . '.json';
        $json = file_get_contents($file);
        if ($json === false) {
            throw new \RuntimeException('Missing fixture ' . $file);
        }

        return $json;
    }

    /**
     * @return list<Shipment>
     */
    public static function shipments(string $name): array
    {
        $body = json_decode(self::read($name), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($body)) {
            throw new \RuntimeException('Fixture ' . $name . ' is not a JSON object');
        }

        return self::fromBody($body);
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return list<Shipment>
     */
    public static function fromBody(array $body): array
    {
        $dates = new DateParser(new \DateTimeZone('Europe/Amsterdam'));

        return array_map(static fn (Data $d): Shipment => Shipment::fromData($d, $dates), (new Data($body))->objects('shipments'));
    }

    /**
     * One shipment whose status is the first (newest) event.
     *
     * @param list<array<string, mixed>> $events newest first
     * @param array<string, mixed>       $extra  more shipment fields (returnFlag, destination, ...)
     *
     * @return array<string, mixed>
     */
    public static function shipment(array $events, array $extra = [], string $id = '3SABCD0123456'): array
    {
        $shipment = ['id' => $id, 'service' => 'parcel-nl', 'events' => $events];
        if ($events !== []) {
            $shipment['status'] = $events[0];
        }

        return array_merge($shipment, $extra);
    }

    /**
     * @param array<string, mixed> $extra more event fields (location, description, statusDetailed, ...)
     *
     * @return array<string, mixed>
     */
    public static function event(string $statusCode, string $status, string $timestamp, array $extra = []): array
    {
        return array_merge(['timestamp' => $timestamp, 'statusCode' => $statusCode, 'status' => $status], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    public static function atServicePoint(string $label = 'Primera Centrum'): array
    {
        return [
            'location' => [
                'address' => ['countryCode' => 'NL', 'postalCode' => '3511AB', 'addressLocality' => 'Utrecht', 'streetAddress' => 'Oudegracht 1'],
                'servicePoint' => ['label' => $label, 'url' => 'https://www.dhl.com/nl-en/home/location-finder.html?id=NL-123456'],
            ],
        ];
    }
}
