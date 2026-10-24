<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Exception;

/**
 * 401/403: the API key is missing, wrong, or not subscribed to the tracking API in this environment.
 */
final class AuthenticationException extends HttpException
{
}
