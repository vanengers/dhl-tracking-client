<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Exception;

/**
 * Marker for every exception this library throws. Catch this to handle all DHL tracking failures.
 */
interface DhlException extends \Throwable
{
}
