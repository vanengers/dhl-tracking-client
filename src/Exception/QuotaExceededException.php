<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Exception;

/**
 * 429 because the daily quota is used up. DHL resets the count at 24:00 GMT; retrying earlier only fails again,
 * so this is never retried and a batch stops at the first one.
 */
final class QuotaExceededException extends RateLimitException
{
}
