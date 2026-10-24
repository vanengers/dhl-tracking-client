<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl;

use GPWebshops\ShopHub\Dhl\Exception\DhlException;
use GPWebshops\ShopHub\Dhl\Status\TrackingStatus;

/**
 * Result of DhlTrackingClient::statuses(), keyed by (normalised) tracking number.
 */
final class BatchResult
{
    /**
     * @param array<string, TrackingStatus> $statuses
     * @param array<string, DhlException>   $failures one failed lookup each; the others still ran
     * @param list<string>                  $skipped  not asked because the batch stopped (see $stoppedBy)
     */
    public function __construct(
        public readonly array $statuses,
        public readonly array $failures = [],
        public readonly array $skipped = [],
        /** Daily quota or authentication failure that made asking further pointless. */
        public readonly ?DhlException $stoppedBy = null,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->failures === [] && $this->skipped === [];
    }

    public function get(string $trackingNumber): ?TrackingStatus
    {
        return $this->statuses[strtoupper((string) preg_replace('/\s+/', '', $trackingNumber))] ?? null;
    }
}
