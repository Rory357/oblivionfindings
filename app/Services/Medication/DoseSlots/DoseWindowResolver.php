<?php

namespace App\Services\Medication\DoseSlots;

use App\Services\Medication\DoseTimingSettings;

/**
 * The one place a dose window comes from (P01 foundation, C2).
 *
 * The general window is Medication › Settings › Rounds & timing ("can be
 * given from", "counts as late"), read through DoseTimingSettings; until
 * someone saves them they are config/medications.php's 30 minutes before and
 * 60 after. A DoseWindowOverride gives a time-critical medicine its own late
 * time. Callers ask forOrder() and nothing else hard-codes a window.
 */
final class DoseWindowResolver
{
    /**
     * @param  iterable<DoseWindowOverride>  $overrides
     */
    public function __construct(
        private readonly ?int $beforeMinutes = null,
        private readonly ?int $afterMinutes = null,
        private readonly iterable $overrides = [],
    ) {}

    public function forOrder(int $orderId): DoseWindow
    {
        $before = $this->beforeMinutes ?? app(DoseTimingSettings::class)->earlyMinutes();
        $after = $this->afterMinutes ?? app(DoseTimingSettings::class)->lateMinutes();

        foreach ($this->overrides as $override) {
            $late = $override->lateMinutesForOrder($orderId);
            if ($late !== null) {
                return new DoseWindow($before, $late, DoseWindow::SOURCE_TIME_CRITICAL);
            }
        }

        return new DoseWindow($before, $after);
    }
}
