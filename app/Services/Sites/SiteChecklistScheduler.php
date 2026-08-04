<?php

namespace App\Services\Sites;

use App\Models\Shift;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SiteChecklistScheduler
{
    public function ensureRunsForShiftLocalDay(Shift $shift): int
    {
        if (! $shift->site_id || ! $shift->starts_at) {
            return 0;
        }

        $timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $scheduledDate = $shift->starts_at->copy()->timezone($timezone)->startOfDay();
        $dateString = $scheduledDate->toDateString();
        $created = 0;

        SiteChecklistAssignment::query()
            ->where('site_id', $shift->site_id)
            ->where('is_active', true)
            ->where('start_date', '<=', $dateString)
            ->where(function ($query) use ($dateString) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $dateString);
            })
            ->get()
            ->each(function (SiteChecklistAssignment $assignment) use ($shift, $scheduledDate, $dateString, &$created): void {
                if (! $this->isDueOnDate($assignment, $scheduledDate)) {
                    return;
                }

                $run = SiteChecklistRun::firstOrCreate(
                    [
                        'assignment_id' => $assignment->id,
                        'scheduled_date' => $dateString,
                    ],
                    [
                        'site_id' => $assignment->site_id,
                        'template_id' => $assignment->template_id,
                        'assigned_to_user_id' => $shift->user_id ?: $assignment->assigned_to_user_id,
                        'status' => 'scheduled',
                    ],
                );

                if ($run->wasRecentlyCreated) {
                    $created++;
                }
            });

        return $created;
    }

    /**
     * Generate upcoming runs for all active assignments
     */
    public function generateUpcomingRuns(int $daysAhead = 30): int
    {
        $count = 0;
        $endDate = now()->addDays($daysAhead);

        SiteChecklistAssignment::with(['template', 'site'])
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            })
            ->chunk(100, function ($assignments) use ($endDate, &$count) {
                DB::transaction(function () use ($assignments, $endDate, &$count) {
                    foreach ($assignments as $assignment) {
                        $count += $this->generateRunsForAssignment($assignment, $endDate);
                    }
                });
            });

        return $count;
    }

    /**
     * Generate runs for a specific assignment
     */
    public function generateRunsForAssignment(
        SiteChecklistAssignment $assignment,
        Carbon $endDate
    ): int {
        $startDate = $assignment->start_date;

        if ($assignment->runs()->awaitingCompletion()->exists()) {
            return 0;
        }

        // Get already scheduled dates
        $scheduledDates = SiteChecklistRun::where('assignment_id', $assignment->id)
            ->pluck('scheduled_date')
            ->map(fn ($d) => $d->format('Y-m-d'))
            ->toArray();

        $currentDate = Carbon::parse($startDate);

        while ($currentDate <= $endDate) {
            if (! in_array($currentDate->format('Y-m-d'), $scheduledDates)) {
                SiteChecklistRun::create([
                    'assignment_id' => $assignment->id,
                    'site_id' => $assignment->site_id,
                    'template_id' => $assignment->template_id,
                    'scheduled_date' => $currentDate->copy(),
                    'status' => 'scheduled',
                ]);

                return 1;
            }

            // Advance based on frequency
            $currentDate = $this->advanceDate($currentDate, $assignment->frequency);
        }

        return 0;
    }

    /**
     * Advance date based on frequency
     */
    private function advanceDate(Carbon $date, string $frequency): Carbon
    {
        return match ($frequency) {
            'daily' => $date->copy()->addDay(),
            'weekly' => $date->copy()->addWeek(),
            'fortnightly' => $date->copy()->addWeeks(2),
            'monthly' => $date->copy()->addMonth(),
            'quarterly' => $date->copy()->addMonths(3),
            default => $date->copy()->addMonth(),
        };
    }

    private function isDueOnDate(SiteChecklistAssignment $assignment, Carbon $date): bool
    {
        $startDate = Carbon::parse($assignment->start_date)->startOfDay();
        $targetDate = $date->copy()->startOfDay();

        if ($targetDate->lt($startDate)) {
            return false;
        }

        if ($assignment->end_date && $targetDate->gt(Carbon::parse($assignment->end_date)->startOfDay())) {
            return false;
        }

        if ($assignment->frequency === 'custom') {
            return false;
        }

        if ($assignment->frequency === 'once') {
            return $targetDate->isSameDay($startDate);
        }

        $currentDate = $startDate->copy();
        while ($currentDate->lt($targetDate)) {
            $currentDate = $this->advanceDate($currentDate, $assignment->frequency);
        }

        return $currentDate->isSameDay($targetDate);
    }

    /**
     * Mark overdue runs
     */
    public function markOverdueRuns(): int
    {
        return SiteChecklistRun::where('status', 'scheduled')
            ->where('scheduled_date', '<', now()->toDateString())
            ->update(['status' => 'overdue']);
    }

    /**
     * Get completion stats for a site
     */
    public function getSiteStats(int $siteId, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $startDate = $startDate ?? now()->subMonth();
        $endDate = $endDate ?? now();

        $runs = SiteChecklistRun::where('site_id', $siteId)
            ->whereBetween('scheduled_date', [$startDate, $endDate])
            ->get();

        $total = $runs->count();
        $completed = $runs->where('status', 'completed')->count();
        $overdue = $runs->where('status', 'overdue')->count();
        $inProgress = $runs->where('status', 'in_progress')->count();

        $avgCompletionTime = $runs->where('status', 'completed')
            ->filter(fn ($run) => $run->completed_at && $run->started_at)
            ->avg(function ($run) {
                return $run->completed_at->diffInMinutes($run->started_at);
            });

        return [
            'total_scheduled' => $total,
            'completed' => $completed,
            'overdue' => $overdue,
            'in_progress' => $inProgress,
            'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0,
            'average_completion_minutes' => round($avgCompletionTime ?? 0, 1),
        ];
    }
}
