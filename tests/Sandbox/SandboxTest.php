<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Tests\Sandbox;

use GPWebshops\ShopHub\Dhl\Config\TrackingConfig;
use GPWebshops\ShopHub\Dhl\DhlTrackingClient;
use GPWebshops\ShopHub\Dhl\Exception\AuthenticationException;
use GPWebshops\ShopHub\Dhl\Http\Throttle;
use GPWebshops\ShopHub\Dhl\Status\DeliveryState;
use GPWebshops\ShopHub\Dhl\Status\TrackingStatus;
use GPWebshops\ShopHub\Dhl\Tests\Support\DotEnv;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Talks to DHL's sandbox (https://api-sandbox.dhl.com/track), never to production. Opt-in:
 *
 *   DHL_SANDBOX_API_KEY=... composer test:sandbox
 *
 * Calls are throttled to one per 5 seconds (DHL spike arrest) and the whole class makes 2 + N calls, N being the
 * number of DHL_SANDBOX_TRACKING_NUMBERS. Every response is written to var/sandbox/ so it can become a fixture.
 */
#[Group('sandbox')]
final class SandboxTest extends TestCase
{
    /** The example number from the API spec. */
    private const DEFAULT_NUMBER = 'GMDBD8E9CCE94842E495B7';

    private static ?Throttle $throttle = null;

    private string $apiKey;

    protected function setUp(): void
    {
        DotEnv::load(dirname(__DIR__, 2) . '/.env');
        $key = getenv('DHL_SANDBOX_API_KEY');
        if (!is_string($key) || trim($key) === '') {
            self::markTestSkipped('Set DHL_SANDBOX_API_KEY (e.g. in .env) to run the sandbox tests');
        }
        $this->apiKey = trim($key);
    }

    public function testKnownNumbersGiveAStatus(): void
    {
        $client = $this->client($this->apiKey);
        foreach (self::numbers() as $number) {
            $status = $client->status($number);
            self::record($number, $status);

            self::assertSame($number, $status->trackingNumber);
            self::assertContains($status->state, DeliveryState::cases());
            if ($status->isFound()) {
                self::assertNotNull($status->shipment);
                self::assertNotSame('', $status->reason);
            }
            self::report(sprintf('%s: %s (rule %s) - DHL %s "%s"', $number, $status->state->value, $status->reason, $status->lastEvent->rawStatusCode ?? '-', $status->description() ?? '-'));
        }
    }

    public function testUnknownNumberIsNotFoundOrMockData(): void
    {
        $status = $this->client($this->apiKey)->status('ZZ0000000000000000000');
        self::record('ZZ0000000000000000000', $status);

        // The sandbox may answer every number with mock data, so both outcomes are fine; an exception is not.
        self::assertContains($status->state, DeliveryState::cases());
        self::report('unknown number: ' . $status->state->value);
    }

    public function testWrongKeyIsAnAuthenticationError(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->client('wrong-' . bin2hex(random_bytes(8)))->status(self::DEFAULT_NUMBER);
    }

    private function client(string $apiKey): DhlTrackingClient
    {
        $config = TrackingConfig::sandbox($apiKey, ['maxAttempts' => 1]);
        // Guard: these tests must never reach production.
        self::assertTrue($config->isSandbox());
        self::$throttle ??= new Throttle($config->minInterval);

        return DhlTrackingClient::create($config, throttle: self::$throttle);
    }

    /**
     * @return list<string>
     */
    private static function numbers(): array
    {
        $env = getenv('DHL_SANDBOX_TRACKING_NUMBERS');
        $numbers = is_string($env) ? array_values(array_filter(array_map('trim', explode(',', $env)))) : [];

        return $numbers !== [] ? $numbers : [self::DEFAULT_NUMBER];
    }

    private static function record(string $number, TrackingStatus $status): void
    {
        $dir = dirname(__DIR__, 2) . '/var/sandbox';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $shipments = $status->shipment !== null ? [$status->shipment->raw] : [];
        file_put_contents(
            $dir . '/' . preg_replace('/[^A-Za-z0-9-]/', '_', $number) . '.json',
            json_encode(['shipments' => $shipments, '_classified' => $status->toArray()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        );
    }

    private static function report(string $line): void
    {
        fwrite(STDERR, "\n  [sandbox] " . $line);
    }
}
