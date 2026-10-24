<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Exception;

/**
 * A 2xx response that is not the JSON shape the API spec promises.
 */
final class ResponseFormatException extends AbstractDhlException
{
}
