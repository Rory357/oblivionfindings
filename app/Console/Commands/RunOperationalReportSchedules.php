<?php

namespace App\Console\Commands;

use App\Models\OperationalReport;
use App\Models\OperationalReportRun;
use App\Models\OperationalReportSubscription;
use App\Models\User;
use App\Services\Reporting\ReportRuns;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RunOperationalReportSchedules extends Command
{
    protected $signature = 'reports:run-schedules';

    protected $description = 'Queue due private report subscriptions and remove expired report contents';

    public function handle(ReportRuns $runs): int
    {
        OperationalReportRun::where('expires_at', '<=', now())->whereNotNull('payload')->update(['payload' => null, 'status' => 'expired']);
        OperationalReportSubscription::where('active', true)->where('next_run_at', '<=', now())->orderBy('id')->chunkById(50, function ($subscriptions) use ($runs) {
            foreach ($subscriptions as $candidate) {
                DB::transaction(function () use ($candidate, $runs) {
                    $subscription = OperationalReportSubscription::whereKey($candidate->id)->lockForUpdate()->first();
                    if (! $subscription?->active || $subscription->next_run_at->isFuture()) {
                        return;
                    }
                    $report = OperationalReport::find($subscription->report_id);
                    $actor = User::find($subscription->user_id);
                    if (! $report || $report->archived_at || ! $actor || (int) $report->user_id !== (int) $actor->id) {
                        $subscription->update(['active' => false]);

                        return;
                    }
                    $definition = $report->definition;
                    $days = CarbonImmutable::parse($definition['date_from'])->diffInDays(CarbonImmutable::parse($definition['date_to'])) + 1;
                    $end = CarbonImmutable::now('Pacific/Auckland')->subDay();
                    $definition['date_to'] = $end->toDateString();
                    $definition['date_from'] = $end->subDays($days - 1)->toDateString();
                    try {
                        $runs->queue($actor, $definition, $subscription->reason, $report, true);
                    } catch (\Throwable $exception) {
                        $subscription->update(['active' => false]);
                        report($exception);

                        return;
                    }
                    $next = match ($subscription->frequency) {
                        'daily' => CarbonImmutable::now('Pacific/Auckland')->addDay(),
                        'weekly' => CarbonImmutable::now('Pacific/Auckland')->addWeek(),
                        'monthly' => CarbonImmutable::now('Pacific/Auckland')->addMonthNoOverflow(),
                    };
                    $subscription->update(['next_run_at' => $next->startOfDay()->addHours(7)->utc()]);
                });
            }
        });

        return self::SUCCESS;
    }
}
