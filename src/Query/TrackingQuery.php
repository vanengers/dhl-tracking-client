<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Query;

use GPWebshops\ShopHub\Dhl\Config\TrackingConfig;
use GPWebshops\ShopHub\Dhl\Exception\ConfigurationException;

/**
 * One tracking lookup. DHL accepts a single tracking number per call.
 *
 * Pass the recipient's postal code when you have it: parcel-nl and parcel-de only return the full set of data
 * (including service point details) with it.
 */
final class TrackingQuery
{
    public readonly string $trackingNumber;

    public readonly ?string $recipientPostalCode;

    public function __construct(
        string $trackingNumber,
        ?string $recipientPostalCode = null,
        /** Service hint, e.g. "parcel-nl", "parcel-de", "express" (see Service in the API spec). */
        public readonly ?string $service = null,
        /** ISO 3166-1 alpha-2 origin country, to tell apart equal numbers from different countries. */
        public readonly ?string $originCountryCode = null,
        /** Overrides TrackingConfig::$language for this lookup. */
        public readonly ?string $language = null,
    ) {
        $this->trackingNumber = strtoupper((string) preg_replace('/\s+/', '', $trackingNumber));
        $postal = $recipientPostalCode !== null ? strtoupper((string) preg_replace('/\s+/', '', $recipientPostalCode)) : null;
        $this->recipientPostalCode = $postal === '' ? null : $postal;

        $errors = [];
        if (!preg_match('/^[A-Z0-9-]{1,64}$/', $this->trackingNumber)) {
            $errors[] = 'trackingNumber must be 1-64 letters, digits or dashes';
        }
        if ($service !== null && !preg_match('/^[a-z][a-z-]*$/', $service)) {
            $errors[] = 'service must be a lowercase DHL service name such as "parcel-nl"';
        }
        if ($originCountryCode !== null && !preg_match('/^[A-Z]{2}$/', $originCountryCode)) {
            $errors[] = 'originCountryCode must be a 2-letter uppercase ISO 3166-1 code';
        }
        if ($language !== null && !preg_match('/^[a-z]{2}$/', $language)) {
            $errors[] = 'language must be a 2-letter lowercase ISO 639-1 code';
        }
        if ($errors !== []) {
            throw new ConfigurationException('Invalid tracking query', $errors);
        }
    }

    public static function of(string|self $query): self
    {
        return $query instanceof self ? $query : new self($query);
    }

    /**
     * @return array<string, string>
     */
    public function toQuery(TrackingConfig $config): array
    {
        return array_filter([
            'trackingNumber' => $this->trackingNumber,
            'service' => $this->service,
            'recipientPostalCode' => $this->recipientPostalCode,
            'originCountryCode' => $this->originCountryCode,
            'requesterCountryCode' => $config->requesterCountryCode,
            'language' => $this->language ?? $config->language,
        ], static fn (?string $v): bool => $v !== null);
    }
}
