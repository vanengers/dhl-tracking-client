<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Exception;

/**
 * Invalid configuration or query: nothing was sent to DHL.
 */
final class ConfigurationException extends \InvalidArgumentException implements DhlException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($errors === [] ? $message : $message . ': ' . implode('; ', $errors));
    }
}
