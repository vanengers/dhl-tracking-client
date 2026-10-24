<?php

declare(strict_types=1);

namespace GPWebshops\ShopHub\Dhl\Model;

/**
 * One tracking event (also used for the shipment's current status).
 */
final class Event
{
    /**
     * @param list<string> $pieceIds
     */
    public function __construct(
        public readonly ?\DateTimeImmutable $timestamp,
        public readonly StatusCode $statusCode,
        /** Short title, e.g. "ARRIVED AT CUSTOMS". */
        public readonly ?string $status = null,
        public readonly ?string $statusDetailed = null,
        public readonly ?string $description = null,
        public readonly ?string $remark = null,
        public readonly ?string $nextSteps = null,
        public readonly ?Place $location = null,
        public readonly array $pieceIds = [],
        /** statusCode exactly as sent, also when it is not one of the known codes. */
        public readonly ?string $rawStatusCode = null,
    ) {
    }

    public static function fromData(Data $d, DateParser $dates): self
    {
        return new self(
            $d->date('timestamp', $dates),
            StatusCode::fromApi($d->string('statusCode')),
            $d->string('status'),
            $d->string('statusDetailed'),
            $d->string('description'),
            $d->string('remark'),
            $d->string('nextSteps'),
            Place::fromData($d->object('location')),
            $d->strings('pieceIds'),
            $d->string('statusCode'),
        );
    }

    /**
     * All free text of the event, for logging.
     */
    public function text(): string
    {
        return self::join([$this->status, $this->statusDetailed, $this->description, $this->remark, $this->nextSteps]);
    }

    /**
     * The text that describes what happened. remark and nextSteps are left out: they talk about what may happen
     * later ("will be returned if not collected within 7 days") and would mislead the status rules.
     */
    public function statusText(): string
    {
        return self::join([$this->status, $this->statusDetailed, $this->description]);
    }

    /**
     * The best single line to show a person: description, else status, else the detailed status.
     */
    public function label(): ?string
    {
        return $this->description ?? $this->status ?? $this->statusDetailed;
    }

    /**
     * @param list<?string> $parts
     */
    private static function join(array $parts): string
    {
        $unique = [];
        foreach ($parts as $part) {
            if ($part !== null && !in_array($part, $unique, true)) {
                $unique[] = $part;
            }
        }

        return implode(' | ', $unique);
    }
}
