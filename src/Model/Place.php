<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Model;

/**
 * A location (SecuredPlace in the spec): an address and/or a service point.
 */
final class Place
{
    public function __construct(public readonly ?Address $address = null, public readonly ?ServicePoint $servicePoint = null)
    {
    }

    public static function fromData(?Data $d): ?self
    {
        if ($d === null) {
            return null;
        }
        $address = Address::fromData($d->object('address'));
        $servicePoint = ServicePoint::fromData($d->object('servicePoint'));

        return $address === null && $servicePoint === null ? null : new self($address, $servicePoint);
    }
}
