<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Exception;

/**
 * 400: DHL rejected the query (for example an unknown service or a malformed tracking number).
 */
final class BadRequestException extends HttpException
{
}
