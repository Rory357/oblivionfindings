<?php

namespace App\Services\Medication\DoseSlots;

use Carbon\CarbonImmutable;

/**
 * One scheduled dose obligation: an order version's dose time on one New
 * Zealand calendar day. PRN doses are never slots.
 *
 * The slot's identity is the order, the NZ day and the dose time as ordered
 * ({@see key()}), not the instant: on the day the clocks go forward a
 * 02:00–02:59 dose is due at 03:00, the same instant as a 03:00 dose, and
 * both are still owed.
 */
final class DoseSlot
{
    /** The ordered time fell in the hour the clocks skipped; due at the first minute that exists. */
    public const DST_GAP = 'dst_gap';

    /** The ordered time happens twice as the clocks go back; due at its first occurrence. */
    public const DST_REPEAT = 'dst_repeat';

    public function __construct(
        public readonly int $orderId,
        public readonly string $versionKey,
        public readonly string $nzDate,
        public readonly string $doseTime,
        public readonly CarbonImmutable $dueAt,
        public readonly ?string $dstAdjustment = null,
        public readonly bool $orderChangePending = false,
        public readonly bool $selfManaged = false,
        public readonly bool $lastDay = false,
    ) {}

    public function key(): string
    {
        return $this->orderId.':'.$this->nzDate.':'.$this->doseTime;
    }
}
