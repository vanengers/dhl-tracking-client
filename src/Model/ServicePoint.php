<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Model;

/**
 * A DHL service point as the API names it: a label (usually the shop name) and a link to its page.
 */
final class ServicePoint
{
    public function __construct(public readonly ?string $label = null, public readonly ?string $url = null)
    {
    }

    public static function fromData(?Data $d): ?self
    {
        if ($d === null) {
            return null;
        }
        $label = $d->string('label');
        $url = $d->string('url');

        return $label === null && $url === null ? null : new self($label, $url);
    }
}
