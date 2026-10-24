<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Model;

/**
 * Tolerant read access to a decoded JSON object: missing keys and wrong types read as null or empty instead of
 * failing, because the API combines many DHL backends that each fill different fields.
 *
 * @internal
 */
final class Data
{
    /**
     * @param array<array-key, mixed> $values
     */
    public function __construct(private readonly array $values)
    {
    }

    /**
     * @return array<array-key, mixed>
     */
    public function raw(): array
    {
        return $this->values;
    }

    public function string(string $key): ?string
    {
        $value = $this->values[$key] ?? null;
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public function bool(string $key): ?bool
    {
        $value = $this->values[$key] ?? null;

        return match (true) {
            is_bool($value) => $value,
            $value === 'true', $value === 1 => true,
            $value === 'false', $value === 0 => false,
            default => null,
        };
    }

    public function object(string $key): ?self
    {
        $value = $this->values[$key] ?? null;

        return is_array($value) && $value !== [] && !array_is_list($value) ? new self($value) : null;
    }

    /**
     * @return list<self>
     */
    public function objects(string $key): array
    {
        $value = $this->values[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }
        $objects = [];
        foreach ($value as $item) {
            if (is_array($item) && $item !== [] && !array_is_list($item)) {
                $objects[] = new self($item);
            }
        }

        return $objects;
    }

    /**
     * @return list<string>
     */
    public function strings(string $key): array
    {
        $value = $this->values[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }
        $strings = [];
        foreach ($value as $item) {
            if ((is_string($item) || is_int($item)) && trim((string) $item) !== '') {
                $strings[] = trim((string) $item);
            }
        }

        return $strings;
    }

    public function date(string $key, DateParser $parser): ?\DateTimeImmutable
    {
        return $parser->parse($this->string($key));
    }
}
