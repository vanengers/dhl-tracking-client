<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Exception;

abstract class AbstractDhlException extends \RuntimeException implements DhlException
{
    public function __construct(string $message, private readonly ?ErrorContext $context = null, ?\Throwable $previous = null)
    {
        if ($context !== null) {
            $message .= ' [' . $context->describe() . ']';
        }
        parent::__construct($message, 0, $previous);
    }

    public function getContext(): ?ErrorContext
    {
        return $this->context;
    }
}
