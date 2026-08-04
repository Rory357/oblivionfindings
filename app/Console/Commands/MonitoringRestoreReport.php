<?php

namespace App\Console\Commands;

use App\Domain\Monitoring\Services\MonitoringRestoreReconciliationService;
use Illuminate\Console\Command;

final class MonitoringRestoreReport extends Command
{
    protected $signature = 'monitoring:reconcile-restore {--json : Emit one value-free JSON report}';

    protected $description = 'Read-only reconciliation of a restored native monitoring runtime';

    public function handle(MonitoringRestoreReconciliationService $reconciliation): int
    {
        $report = $reconciliation->report();
        $failures = collect($report)
            ->except('checked_at')
            ->sum(fn (mixed $value): int => (int) $value);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));
        } else {
            $this->table(
                ['Check', 'Count'],
                collect($report)->except('checked_at')->map(fn (mixed $value, string $key): array => [$key, $value])->values()->all(),
            );
            $this->info('Checked at '.$report['checked_at']);
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
