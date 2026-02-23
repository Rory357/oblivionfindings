<?php

namespace App\Services\Sites;

use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SiteChecklistScheduler
{
    /**
     * Generate upcoming runs for all active assignments
     */
    public function generateUpcomingRuns(int $daysAhead = 30): int
    {
        $count = 0;
        $endDate = now()->addDays($daysAhead);

        SiteChecklistAssignment::with(['template', 'site'])
            ->where('is_active', true)
            ->where(function ($q) use ($endDate) {
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
        $count = 0;
        $startDate = $assignment->start_date;
        
        // Get already scheduled dates
        $scheduledDates = SiteChecklistRun::where('assignment_id', $assignment->id)
            ->pluck('scheduled_date')
            ->map(fn($d) => $d->format('Y-m-d'))
            ->toArray();

        $currentDate = Carbon::parse($startDate);
        
        while ($currentDate <= $endDate) {
            if (!in_array($currentDate->format('Y-m-d'), $scheduledDates)) {
                SiteChecklistRun::create([
                    'assignment_id' => $assignment->id,
                    'site_id' => $assignment->site_id,
                    'tenant_id' => $assignment->tenant_id,
                    'template_id' => $assignment->template_id,
                    'scheduled_date' => $currentDate->copy(),
                    'status' => 'scheduled',
                ]);
                $count++;
            }

            // Advance based on frequency
            $currentDate = $this->advanceDate($currentDate, $assignment->frequency);
        }

        return $count;
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
