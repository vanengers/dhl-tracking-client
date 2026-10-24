<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Status;

/**
 * Finds the Keywords phrase groups in event text.
 *
 * Extra phrases can be added per group without changing the library:
 *   new TextSignals([Keywords::READY => ['klaar voor afhalen']])
 */
final class TextSignals
{
    /** Exact (normalised) texts that mean "delivered" when DHL sends statusCode "unknown". */
    private const DELIVERED_EXACT = ['delivered', 'bezorgd', 'afgeleverd', 'zugestellt'];

    /** @var array<string, list<string>> */
    private array $phrases = [];

    /**
     * @param array<string, list<string>> $extra phrases to add, keyed by a Keywords group constant
     */
    public function __construct(array $extra = [])
    {
        foreach (array_merge_recursive(Keywords::defaults(), $extra) as $group => $phrases) {
            $this->phrases[$group] = array_values(array_unique(array_filter(array_map(self::normalise(...), $phrases))));
        }
    }

    /**
     * Lowercase, punctuation and underscores as spaces, single spaces: "PICK-UP_Point." -> "pick up point".
     */
    public static function normalise(string $text): string
    {
        return trim((string) preg_replace(['/[^\p{L}\p{N}]+/u', '/\s+/'], ' ', mb_strtolower($text, 'UTF-8')));
    }

    /**
     * @param string $text normalised text
     */
    public function has(string $group, string $text): bool
    {
        if ($text === '') {
            return false;
        }
        $haystack = ' ' . $text . ' ';
        foreach ($this->phrases[$group] ?? [] as $phrase) {
            if (str_contains($haystack, ' ' . $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Collected by the recipient", but not "not collected" and not "can be collected at" (that is READY).
     */
    public function isCollected(string $text): bool
    {
        return $this->has(Keywords::COLLECTED, $text)
            && !$this->has(Keywords::NOT_COLLECTED, $text)
            && !$this->has(Keywords::READY, $text);
    }

    public function saysDelivered(string $text): bool
    {
        return in_array($text, self::DELIVERED_EXACT, true) || $this->has(Keywords::DELIVERED, $text);
    }

    /**
     * The kind of pickup point the text names, most specific first (a "parcel locker" is not a "parcel shop").
     */
    public function pickupPointType(string $text): ?PickupPointType
    {
        return match (true) {
            $this->has(Keywords::LOCKER, $text) => PickupPointType::Locker,
            $this->has(Keywords::POST_OFFICE, $text) => PickupPointType::PostOffice,
            $this->has(Keywords::SERVICE_POINT, $text) => PickupPointType::ServicePoint,
            $this->has(Keywords::DEPOT, $text) => PickupPointType::Depot,
            default => null,
        };
    }

    /**
     * A delivery place other than the address or a pickup point, when the text names one.
     */
    public function alternativePlace(string $text): ?DeliveryPlace
    {
        return match (true) {
            $this->has(Keywords::NEIGHBOUR, $text) => DeliveryPlace::Neighbour,
            $this->has(Keywords::MAILBOX, $text) => DeliveryPlace::Mailbox,
            $this->has(Keywords::SAFE_PLACE, $text) => DeliveryPlace::SafePlace,
            default => null,
        };
    }
}
