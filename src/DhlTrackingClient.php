<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl;

use GPWebshops\ShopHub\Dhl\Config\TrackingConfig;
use GPWebshops\ShopHub\Dhl\Exception\AuthenticationException;
use GPWebshops\ShopHub\Dhl\Exception\ConfigurationException;
use GPWebshops\ShopHub\Dhl\Exception\DhlException;
use GPWebshops\ShopHub\Dhl\Exception\QuotaExceededException;
use GPWebshops\ShopHub\Dhl\Exception\ResponseFormatException;
use GPWebshops\ShopHub\Dhl\Http\Clock;
use GPWebshops\ShopHub\Dhl\Http\SystemClock;
use GPWebshops\ShopHub\Dhl\Http\Throttle;
use GPWebshops\ShopHub\Dhl\Http\Transport;
use GPWebshops\ShopHub\Dhl\Model\Data;
use GPWebshops\ShopHub\Dhl\Model\DateParser;
use GPWebshops\ShopHub\Dhl\Model\Shipment;
use GPWebshops\ShopHub\Dhl\Query\TrackingQuery;
use GPWebshops\ShopHub\Dhl\Status\StatusClassifier;
use GPWebshops\ShopHub\Dhl\Status\TrackingStatus;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Psr\Log\LoggerInterface;

/**
 * DHL Shipment Tracking - Unified API client.
 *
 *   $client = DhlTrackingClient::create(TrackingConfig::fromEnv(), $logger);
 *   $status = $client->status(new TrackingQuery('JVGL0123456789', recipientPostalCode: '1234AB'));
 *   if ($status->isReadyForPickup()) { ... $status->pickupPoint->label ... }
 */
final class DhlTrackingClient
{
    private readonly DateParser $dates;

    public function __construct(
        private readonly Transport $transport,
        private readonly TrackingConfig $config,
        private readonly StatusClassifier $classifier = new StatusClassifier(),
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->dates = new DateParser(new \DateTimeZone($config->timezone));
    }

    /**
     * @param callable|null $handler  Guzzle handler (tests: MockHandler); default curl/stream
     * @param Throttle|null $throttle share one between clients that use the same API key
     */
    public static function create(
        TrackingConfig $config,
        ?LoggerInterface $logger = null,
        ?callable $handler = null,
        ?Clock $clock = null,
        ?Throttle $throttle = null,
        ?StatusClassifier $classifier = null,
    ): self {
        $clock ??= new SystemClock();
        $http = new Client(['handler' => HandlerStack::create($handler)]);
        $transport = new Transport($http, $config, $throttle ?? new Throttle($config->minInterval, $clock), $clock, $logger);

        return new self($transport, $config, $classifier ?? new StatusClassifier(), $logger);
    }

    /**
     * The shipments DHL knows for this tracking number (usually one); [] when DHL does not know it.
     *
     * @return list<Shipment>
     */
    public function shipments(string|TrackingQuery $query): array
    {
        $query = TrackingQuery::of($query);
        $body = $this->transport->shipments($query->toQuery($this->config));
        if ($body === null) {
            return [];
        }
        if (!isset($body['shipments']) || !is_array($body['shipments'])) {
            throw new ResponseFormatException('DHL tracking API: response for ' . $query->trackingNumber . ' has no "shipments" list');
        }

        return array_map(fn (Data $d): Shipment => Shipment::fromData($d, $this->dates), (new Data($body))->objects('shipments'));
    }

    /**
     * The delivery status for one tracking number. One API call (plus at most maxAttempts - 1 retries).
     */
    public function status(string|TrackingQuery $query): TrackingStatus
    {
        $query = TrackingQuery::of($query);
        $status = $this->classifier->classify($query->trackingNumber, $this->shipments($query));
        $this->logger?->debug('DHL tracking: {number} is {state} ({reason}): {text}', [
            'number' => $query->trackingNumber,
            'state' => $status->state->value,
            'reason' => $status->reason,
            'text' => $status->lastEvent?->text(),
        ]);

        return $status;
    }

    /**
     * Statuses for many tracking numbers, one call each (throttled). A failed lookup does not stop the others; the
     * daily quota or a bad API key does, and the rest is listed as skipped. Duplicates are asked once.
     *
     * @param iterable<string|TrackingQuery> $queries
     */
    public function statuses(iterable $queries): BatchResult
    {
        $statuses = [];
        $failures = [];
        $skipped = [];
        $stoppedBy = null;
        foreach ($queries as $query) {
            try {
                $query = TrackingQuery::of($query);
            } catch (ConfigurationException $e) {
                $failures[is_string($query) ? $query : '?'] = $e;
                continue;
            }
            $number = $query->trackingNumber;
            if (isset($statuses[$number]) || isset($failures[$number]) || in_array($number, $skipped, true)) {
                continue;
            }
            if ($stoppedBy !== null) {
                $skipped[] = $number;
                continue;
            }
            try {
                $statuses[$number] = $this->status($query);
            } catch (QuotaExceededException|AuthenticationException $e) {
                $failures[$number] = $e;
                $stoppedBy = $e;
            } catch (DhlException $e) {
                $failures[$number] = $e;
            }
        }

        return new BatchResult($statuses, $failures, $skipped, $stoppedBy);
    }
}
