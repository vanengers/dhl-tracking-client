<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Tests\Unit\Config;

use GPWebshops\ShopHub\Dhl\Config\TrackingConfig;
use GPWebshops\ShopHub\Dhl\Exception\ConfigurationException;
use GPWebshops\ShopHub\Dhl\Query\TrackingQuery;
use PHPUnit\Framework\TestCase;

final class TrackingConfigTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
            unset($_ENV[$name]);
        }
    }

    public function testSandboxAndProduction(): void
    {
        $sandbox = TrackingConfig::sandbox('key', ['baseUri' => TrackingConfig::PRODUCTION_URI, 'language' => 'nl']);
        $production = TrackingConfig::production('key');

        self::assertSame(TrackingConfig::SANDBOX_URI, $sandbox->baseUri, 'sandbox() always wins over an override');
        self::assertTrue($sandbox->isSandbox());
        self::assertSame('nl', $sandbox->language);
        self::assertFalse($production->isSandbox());
        self::assertSame(5.0, $production->minInterval);
    }

    public function testValidation(): void
    {
        try {
            new TrackingConfig(' ', 'http://insecure.example', 'NL', 'nl', -1.0);
            self::fail('Expected ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertCount(5, $e->errors);
        }
    }

    public function testAtLeastOneAttempt(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('maxAttempts must be >= 1');
        TrackingConfig::sandbox('key', ['maxAttempts' => 0]);
    }

    public function testFromEnv(): void
    {
        $this->setEnv('DHL_TRACKING_API_KEY', 'env-key');
        $this->setEnv('DHL_TRACKING_ENVIRONMENT', 'sandbox');
        $this->setEnv('DHL_TRACKING_LANGUAGE', 'de');

        $config = TrackingConfig::fromEnv(['minInterval' => 1.0]);

        self::assertSame('env-key', $config->apiKey);
        self::assertTrue($config->isSandbox());
        self::assertSame('de', $config->language);
        self::assertSame(1.0, $config->minInterval);
    }

    public function testFromEnvRejectsUnknownEnvironment(): void
    {
        $this->setEnv('DHL_TRACKING_API_KEY', 'env-key');
        $this->setEnv('DHL_TRACKING_ENVIRONMENT', 'staging');

        $this->expectException(ConfigurationException::class);
        TrackingConfig::fromEnv();
    }

    public function testWithAndDebugInfoHideNothingButTheKey(): void
    {
        $config = TrackingConfig::sandbox('secret-key')->with(['timeout' => 3.0]);

        self::assertSame(3.0, $config->timeout);
        self::assertTrue($config->isSandbox());
        self::assertStringNotContainsString('secret-key', print_r($config, true));
    }

    public function testQueryValidationAndNormalisation(): void
    {
        $query = new TrackingQuery(' 3sabc 123 ', ' 1234 ab ', 'parcel-nl', 'NL', 'nl');

        self::assertSame('3SABC123', $query->trackingNumber);
        self::assertSame('1234AB', $query->recipientPostalCode);
        self::assertSame('nl', $query->toQuery(TrackingConfig::sandbox('k'))['language']);

        $this->expectException(ConfigurationException::class);
        new TrackingQuery('abc/../def', service: 'Parcel NL');
    }

    private function setEnv(string $name, string $value): void
    {
        $this->savedEnv[$name] ??= getenv($name);
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}
