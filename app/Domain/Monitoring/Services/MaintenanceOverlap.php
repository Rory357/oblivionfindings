<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Same-scope active rules must have disjoint, half-open occurrences. */
final class MaintenanceOverlap
{
    public function __construct(private readonly MaintenanceSchedule $schedule = new MaintenanceSchedule) {}

    /** Called inside the owner's Site write mutex. Locking reads avoid an older transaction snapshot.
     * @param  array<string, mixed>  $candidate
     */
    public function assertAvailable(array $candidate, ?int $exceptId = null): void
    {
        $start = $candidate['starts_at'];
        $end = $candidate['recurrence_until'] ?? $candidate['ends_at'];
        $existing = MonitoringMaintenanceWindow::query()
            ->where('site_id', $candidate['site_id'])
            ->where('status', 'active')
            ->where('monitor_id', $candidate['monitor_id'] ?? null)
            ->where('device_id', $candidate['device_id'] ?? null)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->where('starts_at', '<', $end)
            ->where(fn ($query) => $query
                ->where(fn ($oneOff) => $oneOff->whereNull('recurrence')->where('ends_at', '>', $start))
                ->orWhere(fn ($series) => $series->whereNotNull('recurrence')
                    ->where(fn ($limit) => $limit->whereNull('recurrence_until')->orWhere('recurrence_until', '>', $start))))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        foreach ($existing as $window) {
            try {
                $overlap = $this->firstOverlap($candidate, $this->values($window));
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['starts_at' => ['An existing maintenance rule at this scope has unresolved timing. Review that rule before saving another window.']]);
            }
            if ($overlap !== null) {
                throw ValidationException::withMessages(['starts_at' => ['Another active maintenance rule at this scope overlaps this schedule. Change its times, edit the existing rule, or cancel it before saving.']]);
            }
        }
    }

    /** @param array<string, mixed> $a
     * @param  array<string, mixed>  $b
     * @return array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}|null
     */
    public function firstOverlap(array $a, array $b): ?array
    {
        $left = array_values(array_filter($this->schedule->occurrences($a), fn (array $row): bool => $row['state'] === 'ready'));
        $right = array_values(array_filter($this->schedule->occurrences($b), fn (array $row): bool => $row['state'] === 'ready'));
        $i = $j = 0;
        while (isset($left[$i], $right[$j])) {
            $start = $left[$i]['starts_at']->max($right[$j]['starts_at']);
            $end = $left[$i]['ends_at']->min($right[$j]['ends_at']);
            if ($start < $end) {
                return ['starts_at' => $start, 'ends_at' => $end];
            }
            if ($left[$i]['ends_at'] <= $right[$j]['ends_at']) {
                $i++;
            } else {
                $j++;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function values(MonitoringMaintenanceWindow $window): array
    {
        return $window->only(['starts_at', 'ends_at', 'recurrence', 'recurrence_until', 'timezone', 'dst_overlap', 'dst_gap']);
    }
}
