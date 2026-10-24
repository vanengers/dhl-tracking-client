<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Config;

use GPWebshops\ShopHub\Dhl\Exception\ConfigurationException;

/**
 * Immutable client configuration. Build with sandbox(), production(), fromEnv() or named arguments.
 *
 * Rate limits: a new DHL app gets 250 calls per day plus a per-second "spike arrest". minInterval keeps the client
 * below the spike limit within one process; the daily quota is up to the caller (cache statuses, skip final ones).
 */
final class TrackingConfig
{
    public const PRODUCTION_URI = 'https://api-eu.dhl.com/track';
    public const SANDBOX_URI = 'https://api-sandbox.dhl.com/track';
    public const SANDBOX_HOST = 'api-sandbox.dhl.com';

    /**
     * @param positive-int $maxAttempts
     */
    public function __construct(
        public readonly string $apiKey,
        public readonly string $baseUri = self::PRODUCTION_URI,
        /** ISO 639-1 response language. The status rules know English, Dutch and German wording; English is the most reliable. */
        public readonly string $language = 'en',
        /** Optional ISO 3166-1 alpha-2 country of the API consumer; DHL uses it to optimise the response. */
        public readonly ?string $requesterCountryCode = null,
        /** Minimum seconds between two calls from this client (DHL spike arrest). 0 disables throttling. */
        public readonly float $minInterval = 5.0,
        /** Attempts per lookup, including the first. Each attempt counts against the daily quota. */
        public readonly int $maxAttempts = 2,
        public readonly float $retryBaseDelay = 2.0,
        /** A Retry-After above this many seconds is not waited for; the RateLimitException is thrown instead. */
        public readonly float $maxRetryAfter = 30.0,
        public readonly float $connectTimeout = 5.0,
        public readonly float $timeout = 20.0,
        /** Time zone for timestamps without an offset (DHL sends them in the local time of the event). */
        public readonly string $timezone = 'Europe/Amsterdam',
        public readonly string $userAgent = 'gpwebshops-dhl-tracking/1.0',
    ) {
        $errors = [];
        if (trim($apiKey) === '') {
            $errors[] = 'apiKey is required';
        }
        if (!preg_match('#^https://[^/\s]+#i', $baseUri)) {
            $errors[] = 'baseUri must be an https URL';
        }
        if (!preg_match('/^[a-z]{2}$/', $language)) {
            $errors[] = 'language must be a 2-letter lowercase ISO 639-1 code';
        }
        if ($requesterCountryCode !== null && !preg_match('/^[A-Z]{2}$/', $requesterCountryCode)) {
            $errors[] = 'requesterCountryCode must be a 2-letter uppercase ISO 3166-1 code';
        }
        if ($maxAttempts < 1) {
            $errors[] = 'maxAttempts must be >= 1';
        }
        foreach (['minInterval' => $minInterval, 'retryBaseDelay' => $retryBaseDelay, 'maxRetryAfter' => $maxRetryAfter] as $name => $value) {
            if ($value < 0) {
                $errors[] = $name . ' must be >= 0';
            }
        }
        if ($connectTimeout <= 0 || $timeout <= 0) {
            $errors[] = 'timeouts must be > 0';
        }
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true) && $timezone !== 'UTC') {
            $errors[] = 'timezone must be a valid time zone identifier';
        }
        if ($errors !== []) {
            throw new ConfigurationException('Invalid DHL tracking configuration', $errors);
        }
    }

    /**
     * @param array<string, mixed> $overrides named constructor arguments
     */
    public static function sandbox(string $apiKey, array $overrides = []): self
    {
        return new self(...array_merge(['apiKey' => $apiKey], $overrides, ['baseUri' => self::SANDBOX_URI]));
    }

    /**
     * @param array<string, mixed> $overrides named constructor arguments
     */
    public static function production(string $apiKey, array $overrides = []): self
    {
        return new self(...array_merge(['apiKey' => $apiKey], $overrides, ['baseUri' => self::PRODUCTION_URI]));
    }

    /**
     * Reads DHL_TRACKING_API_KEY, DHL_TRACKING_ENVIRONMENT (production|sandbox) and DHL_TRACKING_LANGUAGE.
     *
     * @param array<string, mixed> $overrides named constructor arguments that win over the environment
     */
    public static function fromEnv(array $overrides = []): self
    {
        $env = static function (string $name): ?string {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        };

        $environment = strtolower($env('DHL_TRACKING_ENVIRONMENT') ?? 'production');
        if (!in_array($environment, ['production', 'sandbox'], true)) {
            throw new ConfigurationException('DHL_TRACKING_ENVIRONMENT must be "production" or "sandbox"');
        }
        $args = array_filter([
            'apiKey' => $env('DHL_TRACKING_API_KEY') ?? '',
            'baseUri' => $environment === 'sandbox' ? self::SANDBOX_URI : self::PRODUCTION_URI,
            'language' => $env('DHL_TRACKING_LANGUAGE'),
        ], static fn ($v) => $v !== null);

        return new self(...array_merge($args, $overrides));
    }

    /**
     * Returns a copy with the given named arguments replaced.
     *
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    public function isSandbox(): bool
    {
        return strtolower((string) parse_url($this->baseUri, PHP_URL_HOST)) === self::SANDBOX_HOST;
    }

    /**
     * Keeps the API key out of var_dump() and print_r() output.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['apiKey' => '***'] + get_object_vars($this);
    }
}
