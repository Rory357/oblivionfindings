<?php

namespace App\Services\Medication\DoseSlots;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Everything about one medication order that decides its dose slots: its
 * schedule versions (oldest first), the periods it was paused, and when it
 * was ceased.
 */
final class DoseOrderTimeline
{
    /**
     * @param  list<DoseOrderVersion>  $versions  in the order they were written
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable|null}>  $pauses  [paused at, resumed at) — null = still paused
     * @param  CarbonImmutable|null  $enteredAt  when the order was entered: nothing due before it is owed
     */
    public function __construct(
        public readonly int $orderId,
        public readonly array $versions,
        public readonly array $pauses = [],
        public readonly ?CarbonImmutable $ceasedAt = null,
        public readonly ?CarbonImmutable $enteredAt = null,
    ) {
        $previous = null;
        foreach ($versions as $version) {
            if (! $version instanceof DoseOrderVersion) {
                throw new InvalidArgumentException('A timeline holds DoseOrderVersion values.');
            }
            if ($previous !== null && $version->changedAt->lessThan($previous->changedAt)) {
                throw new InvalidArgumentException('Versions must be listed in the order they were written.');
            }
            $previous = $version;
        }
        foreach ($pauses as $pause) {
            if (! is_array($pause) || ! ($pause[0] ?? null) instanceof CarbonImmutable
                || (($pause[1] ?? null) !== null && ! $pause[1] instanceof CarbonImmutable)) {
                throw new InvalidArgumentException('A pause is [paused at, resumed at or null].');
            }
        }
    }

    /**
     * The version in effect at an instant: the most recently written version
     * that had been verified by then. Null before the first verification.
     */
    public function versionInEffectAt(CarbonImmutable $at): ?DoseOrderVersion
    {
        $inEffect = null;
        foreach ($this->versions as $version) {
            if ($version->isVerifiedBy($at)) {
                $inEffect = $version;
            }
        }

        return $inEffect;
    }

    /**
     * A later change to the order was written but not yet verified at this
     * instant, so a dose due then still follows the version in effect and is
     * flagged "Order changed — check the new instructions" (P01 v2 change 5).
     */
    public function hasPendingChangeAt(DoseOrderVersion $inEffect, CarbonImmutable $at): bool
    {
        foreach ($this->versions as $version) {
            if ($version->changedAt->greaterThan($inEffect->changedAt) && $version->isPendingAt($at)) {
                return true;
            }
        }

        return false;
    }

    public function isPausedAt(CarbonImmutable $at): bool
    {
        foreach ($this->pauses as [$from, $until]) {
            if ($from->lessThanOrEqualTo($at) && ($until === null || $at->lessThan($until))) {
                return true;
            }
        }

        return false;
    }

    /** A dose due at or after the moment the order was ceased is not owed. */
    public function isCeasedBy(CarbonImmutable $at): bool
    {
        return $this->ceasedAt !== null && $this->ceasedAt->lessThanOrEqualTo($at);
    }

    /**
     * A dose due before the order was entered is not owed (Main, 2 Oct): an
     * order entered at 10:00 — a replacement, say — owes nothing due earlier
     * that day, so it never doubles the doses its predecessor already owed.
     */
    public function isBeforeEntry(CarbonImmutable $at): bool
    {
        return $this->enteredAt !== null && $at->lessThan($this->enteredAt);
    }
}
