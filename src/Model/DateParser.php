<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Model;

/**
 * Parses the ISO 8601 variants DHL sends: zoned ("2017-06-21T14:07:00Z", also the short "+2:00" offset), local
 * ("2017-06-21T14:07:17", read in the default time zone) and dates ("2017-06-21", midnight). Anything else is null.
 */
final class DateParser
{
    private const PATTERN = '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{1,2}(?::?\d{2})?)?)?$/i';

    private readonly \DateTimeZone $defaultTimezone;

    public function __construct(?\DateTimeZone $defaultTimezone = null)
    {
        $this->defaultTimezone = $defaultTimezone ?? new \DateTimeZone('Europe/Amsterdam');
    }

    public function parse(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if (!preg_match(self::PATTERN, $value)) {
            return null;
        }
        if (strlen($value) === 10) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->defaultTimezone);

            return $date === false ? null : $date;
        }
        try {
            return new \DateTimeImmutable(self::normaliseOffset($value), $this->defaultTimezone);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * "+2:00" and "+2" become "+02:00"; the time part always contains a colon, so only a trailing offset matches.
     */
    private static function normaliseOffset(string $value): string
    {
        if (!preg_match('/^(.*T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)([+-])(\d{1,2})(?::?(\d{2}))?$/i', $value, $m)) {
            return $value;
        }
        $minutes = isset($m[4]) ? $m[4] : '00';

        return $m[1] . $m[2] . str_pad($m[3], 2, '0', STR_PAD_LEFT) . ':' . $minutes;
    }
}
