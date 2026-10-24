# DHL tracking client

A PHP client for the DHL **Shipment Tracking - Unified API** (`GET /track/shipments`). It is a framework-agnostic Composer library (`gpwebshops/dhl-tracking-client`, namespace `GPWebshops\ShopHub\Dhl`) for PrestaShop modules and cron jobs.

Its main job is to answer one question: **where is this parcel?** It tells these apart:

- delivered at the address;
- delivered to a neighbour, a mailbox or a safe place;
- waiting at a service point, parcel locker or post office (**ready for pickup**);
- collected from a pickup point.

The API spec is in `docs/dhl-tracking-unified.yaml`.

## Requirements

- PHP 8.1+ with `ext-json` and `ext-mbstring`.
- Composer dependencies: `guzzlehttp/guzzle ^7.4` and `psr/log` 1–3.

## Quick start

```php
use GPWebshops\ShopHub\Dhl\Config\TrackingConfig;
use GPWebshops\ShopHub\Dhl\DhlTrackingClient;
use GPWebshops\ShopHub\Dhl\Query\TrackingQuery;
use GPWebshops\ShopHub\Dhl\Status\DeliveryState;

$client = DhlTrackingClient::create(TrackingConfig::fromEnv(), $logger);   // logger: any PSR-3 logger, optional

// Pass the postal code when you have it: parcel-nl and parcel-de only return full data with it.
$status = $client->status(new TrackingQuery('JVGL06191357001234567890', recipientPostalCode: '1234AB'));

match ($status->state) {
    DeliveryState::Delivered      => $status->deliveryPlace,   // Address, Neighbour, Mailbox, SafePlace, PickupPoint
    DeliveryState::ReadyForPickup => $status->pickupPoint,     // type (ServicePoint, Locker, PostOffice, Depot, Unknown), label, url, address
    default                       => $status->description(),   // DHL's own text of the latest event
};
```

| Environment variable | Meaning |
|---|---|
| `DHL_TRACKING_API_KEY` | API key of your app on developer.dhl.com |
| `DHL_TRACKING_ENVIRONMENT` | `production` (default, `https://api-eu.dhl.com/track`) or `sandbox` (`https://api-sandbox.dhl.com/track`) |
| `DHL_TRACKING_LANGUAGE` | Response language, default `en`. The status rules know English, Dutch and German; English is the most reliable. |

You can also skip the environment: `TrackingConfig::sandbox($key)`, `TrackingConfig::production($key)`, or named arguments. Every other setting is a named argument of `TrackingConfig`: timeouts, `minInterval`, `maxAttempts`, `timezone` and so on.

## Methods

| Method | Returns |
|---|---|
| `status($numberOrQuery)` | `TrackingStatus`. If DHL does not know the number, you get the state `NotFound`, not an exception. |
| `statuses(iterable)` | `BatchResult`: the statuses, failures per number, and skipped numbers. It asks each number once and stops on the daily quota or a bad key. |
| `shipments($numberOrQuery)` | The parsed `Shipment` objects: events, origin and destination, ETA, proof of delivery, and the `raw` JSON. |

`TrackingStatus` also gives you:
- `isDelivered()`, `isReadyForPickup()` and `isFound()`;
- `isFinal()`: true when it was delivered or returned, so you can stop polling;
- `deliveredAt`, `estimatedDelivery`, `lastEvent` and `shipment`;
- `reason`: the rule that decided;
- `toArray()`: a flat row for a database or log.

## Delivery states

DHL's own `statusCode` only has `pre-transit`, `transit`, `delivered`, `failure` and `unknown`. DHL lists "Ready for pick-up" and "Out for delivery" as planned, but they are not available yet. "Delivered" can also mean "delivered **at** the service point" (waiting there for the customer). So the client also reads three other things:
- the event text (`status`, `statusDetailed`, `description`);
- the event location (a `servicePoint` object);
- the earlier events.

| State | Meaning |
|---|---|
| `NotFound` | DHL does not know the number (yet). This is normal for a few hours after the label is made. |
| `PreTransit` | Data received; DHL does not have the parcel yet. |
| `InTransit` | On its way, including on its way **to** a service point. |
| `OutForDelivery` | With the courier, on the way to the address. |
| `ReadyForPickup` | Waiting at a service point, locker, post office or depot. See `pickupPoint`. |
| `Delivered` | Handed over. See `deliveryPlace`; `PickupPoint` means the customer collected it. |
| `DeliveryFailed` | A delivery attempt failed, or the parcel was not collected in time. |
| `Returned` | Going back to the sender: `returnFlag`, or "returned to sender" in the text. |
| `Unknown` | Nothing to go on. |

The full rule order is in the doc comment of `src/Status/StatusClassifier.php`. A few rules guard against false positives:

- A sender dropping the parcel off at a service point is **not** "ready for pickup". This is either the sender wording, or the first transit scan of a parcel that has not moved yet.
- Future texts ("will be delivered to a ServicePoint", "on its way to …") are never "ready for pickup".
- `remark` and `nextSteps` are ignored, because they say what *may* happen ("will be returned if not collected within 7 days").

**The texts are a best effort.** DHL documents no list of event texts and has no sample tracking numbers. The phrases in `src/Status/Keywords.php` come from DHL's public tracking wording, and the fixtures are hand-made in the shape of the spec. When a real parcel is classified wrongly, check the logged `reason` and the DHL text, and add the phrase. You can add phrases without changing the library:

```php
$classifier = new StatusClassifier(new TextSignals([Keywords::READY => ['staat voor je klaar']]));
$client = DhlTrackingClient::create($config, classifier: $classifier);
```

## Rate limits

A new DHL app gets **250 calls per day** plus a per-second "spike arrest". Both answer 429. The daily count resets at 24:00 GMT. DHL accepts **one tracking number per call**.

- The client keeps `minInterval` (default 5 s) between calls in one process. Share one `Throttle` between clients that use the same key.
- On a spike-arrest 429, it waits (`Retry-After`, or `minInterval`) and retries once.
- A daily-quota 429 throws `QuotaExceededException` and is never retried. A batch stops there and lists the rest under `skipped`.
- 502/503/504 and connection errors are retried within `maxAttempts` (default 2). Every attempt counts against the quota.

To save quota:
- stop polling once `isFinal()` is true;
- don't ask before the label has had time to show up;
- keep the last status in your own database.

## Errors

Everything implements `GPWebshops\ShopHub\Dhl\Exception\DhlException`:

| Exception | When |
|---|---|
| `ConfigurationException` | Invalid config or tracking number; nothing was sent. |
| `AuthenticationException` | 401/403: wrong key, or the app has no access in this environment. |
| `BadRequestException` | 400. |
| `RateLimitException` / `QuotaExceededException` | 429 per second / per day. |
| `ServerException` | 5xx. |
| `TransportException` | DNS, connection or TLS failure, or a timeout (`timedOut`). |
| `ResponseFormatException` | 2xx without the JSON the spec promises (HTML, malformed). |

API exceptions carry an `ErrorContext` with:
- the URL (the API key is a header, never in the URL);
- the status and a body excerpt;
- the number of attempts and the duration;
- DHL's `correlation-id`, which you quote to DHL support.

## Tests

```bash
composer install
composer test           # unit tests: offline, Guzzle MockHandler, fake clock (no real waiting)
composer stan           # PHPStan level 8
composer test:sandbox   # opt-in: calls DHL's sandbox, needs DHL_SANDBOX_API_KEY in .env
```

- **Unit tests** (`tests/Unit`) cover:
  - the status rules, against fixtures for each scenario (`tests/Fixtures`, see its README) and inline edge cases;
  - HTTP behaviour: headers, 404, 401, quota versus spike arrest, retries, throttling, malformed bodies;
  - batches, config, dates and tolerant parsing.
- **Sandbox tests** (`tests/Sandbox`, group `sandbox`, excluded by default):
  - They only reach `api-sandbox.dhl.com`; `TrackingConfig::sandbox()` forces that URL and the test asserts it.
  - They make 2 + N throttled calls (N = `DHL_SANDBOX_TRACKING_NUMBERS`, default the spec's example number).
  - They write each response to `var/sandbox/` so you can turn it into a fixture.
  - There are **no tests against production**, because of the rate limit.
