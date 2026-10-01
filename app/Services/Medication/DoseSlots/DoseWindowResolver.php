<?php

namespace App\Services\Medication\DoseSlots;

use App\Services\Medication\DoseTimingSettings;

/**
 * The one place a dose window comes from (P01 foundation, C2).
 *
 * Today it reads DoseTimingSettings (P11 Rounds & timing; config 30/60 until saved). P11's
 * Dose timing settings (Lane A, chunk 4) replace the defaults and register a
 * DoseWindowOverride for time-critical medicines; callers keep asking
 * forOrder() and nothing else hard-codes a window.
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
