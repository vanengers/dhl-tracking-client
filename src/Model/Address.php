<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Model;

final class Address
{
    public function __construct(
        public readonly ?string $countryCode = null,
        public readonly ?string $postalCode = null,
        public readonly ?string $locality = null,
        public readonly ?string $localityServicing = null,
        public readonly ?string $region = null,
        public readonly ?string $streetAddress = null,
        public readonly ?string $addressLine = null,
    ) {
    }

    public static function fromData(?Data $d): ?self
    {
        if ($d === null) {
            return null;
        }
        $address = new self(
            $d->string('countryCode'),
            $d->string('postalCode'),
            $d->string('addressLocality'),
            $d->string('addressLocalityServicing'),
            $d->string('addressRegion'),
            $d->string('streetAddress'),
            $d->string('addressLine'),
        );

        return $address->oneLine() === '' ? null : $address;
    }

    /**
     * "Batman Avenue 1040, 3rd Floor, 14000 Prague, CZ"
     */
    public function oneLine(): string
    {
        $parts = [$this->streetAddress, $this->addressLine, trim(($this->postalCode ?? '') . ' ' . ($this->locality ?? '')), $this->countryCode];

        return implode(', ', array_filter($parts, static fn (?string $part): bool => $part !== null && $part !== ''));
    }
}
