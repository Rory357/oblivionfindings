<?php

namespace App\Console\Commands;

use App\Models\RosterPeriod;
use App\Models\Shift;
use App\Models\Timesheet;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class ArchiveCompletedRosterPeriods extends Command
{
    protected $signature = 'rostering:archive-completed-periods {--weeks=2 : Archive periods ending at least this many weeks ago} {--dry-run : Report eligible periods without changing them}';

    protected $description = 'Archive published roster periods after payroll-relevant shifts have approved timesheets.';

    protected $aliases = ['roster:archive-completed-periods'];

    public function handle(): int
    {
        $weeks = max(1, (int) $this->option('weeks'));
        $cutoff = now((string) config('app.worker_timezone', 'Pacific/Auckland'))
            ->subWeeks($weeks)
            ->startOfDay();
        $dryRun = (bool) $this->option('dry-run');
        $archived = 0;

        RosterPeriod::query()
            ->whereIn('status', [
                RosterPeriod::STATUS_PUBLISHED,
                RosterPeriod::STATUS_CHANGED_AFTER_PUBLISH,
            ])
            ->where(function (Builder $query) use ($cutoff): void {
                $query->whereDate('week_end', '<=', $cutoff->toDateString())
                    ->orWhere(function (Builder $fallback) use ($cutoff): void {
                        $fallback->whereNull('week_end')
                            ->whereDate('week_start', '<=', $cutoff->copy()->subDays(7)->toDateString());
                    });
            })
            ->with(['shifts.timesheets:id,shift_id,status'])
            ->orderBy('week_start')
            ->chunkById(100, function ($periods) use ($dryRun, &$archived): void {
                foreach ($periods as $period) {
                    if (! $this->hasApprovedPayrollFootprint($period)) {
                        continue;
                    }

                    $archived++;

                    if ($dryRun) {
                        $this->line("Would archive roster period {$period->id}.");
                        continue;
                    }

                    $period->forceFill([
                        'status' => RosterPeriod::STATUS_ARCHIVED,
                        'archived_at' => now(),
                        'archive_reason' => 'payroll_cutoff_completed',
                        'locked_at' => now(),
                    ])->save();
                }
            });

        $verb = $dryRun ? 'Eligible' : 'Archived';
        $this->info("{$verb} roster period(s): {$archived}.");

        return self::SUCCESS;
    }

    private function hasApprovedPayrollFootprint(RosterPeriod $period): bool
    {
        if ($period->shifts->isEmpty()) {
            return false;
        }

        return $period->shifts->every(function (Shift $shift): bool {
            if ($shift->status === 'cancelled') {
                return true;
            }

            if (! in_array($shift->status, ['completed', 'clocked_out', 'finished'], true)) {
                return false;
            }

            return $shift->timesheets->contains(
                fn (Timesheet $timesheet): bool => $timesheet->status === 'approved',
            );
        });
    }
}
