<?php

namespace App\Services\Medication\DoseSlots;

/**
 * The one place a dose window comes from (P01 foundation, C2).
 *
 * Today it reads config/medications.php (30 minutes before, 60 after). P11's
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
        $before = $this->beforeMinutes ?? (int) config('medications.mar.window_before_minutes', 30);
        $after = $this->afterMinutes ?? (int) config('medications.mar.window_after_minutes', 60);

        foreach ($this->overrides as $override) {
            $late = $override->lateMinutesForOrder($orderId);
            if ($late !== null) {
                return new DoseWindow($before, $late, DoseWindow::SOURCE_TIME_CRITICAL);
            }
        }

        return new DoseWindow($before, $after);
    }
}
