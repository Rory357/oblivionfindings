<?php

namespace App\Services\Medication\DoseSlots;

/**
 * The time-critical hook: a medicine whose dose counts as late sooner (or
 * later) than every other. P11's Rounds & timing settings (Lane A, chunk 4)
 * implement it; until then nothing is registered.
 */
interface DoseWindowOverride
{
    /** Minutes after the due time before a dose of this order is late, or null for no override. */
    public function lateMinutesForOrder(int $orderId): ?int;
}
