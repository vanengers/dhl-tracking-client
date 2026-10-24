<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Exception;

/**
 * 5xx: DHL had a problem. 502/503/504 are retried before this is thrown.
 */
final class ServerException extends HttpException
{
}
