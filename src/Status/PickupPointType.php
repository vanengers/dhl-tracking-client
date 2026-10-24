<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Status;

enum PickupPointType: string
{
    /** DHL ServicePoint / ParcelShop / Paketshop: a shop that holds parcels. */
    case ServicePoint = 'service_point';
    /** Packstation, parcel locker, pakketautomaat. */
    case Locker = 'locker';
    /** Post office / Postfiliale / retail outlet. */
    case PostOffice = 'post_office';
    /** A DHL depot or service centre. */
    case Depot = 'depot';
    /** DHL says it can be picked up but not where. */
    case Unknown = 'unknown';
}
