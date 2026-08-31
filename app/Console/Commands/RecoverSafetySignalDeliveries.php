<?php

namespace App\Console\Commands;

use App\Services\ControlRoom\SafetySignalDeliveryRecoveryService;
use Illuminate\Console\Command;

class RecoverSafetySignalDeliveries extends Command
{
    protected $signature = 'safety-signals:recover
        {--limit=100 : Maximum rows per source to reconcile and dispatch}
        {--report-only : Report persisted failures without dispatching}';

    protected $description = 'Recover stranded fleet, shift, device-event, incident-lifecycle, and Facility Control Room signal deliveries';

    public function handle(SafetySignalDeliveryRecoveryService $recovery): int
    {
        $result = $recovery->recover(
            (int) $this->option('limit'),
            (bool) $this->option('report-only'),
        );

        foreach (['fleet', 'shift', 'device', 'incident', 'facility'] as $source) {
            $this->line(sprintf(
                '%s: %d reconciled, %d queued, %d failed/dead-letter/unroutable',
                $source,
                $result['reconciled'][$source],
                $result['queued'][$source],
                $result['failures'][$source],
            ));
        }

        if ($result['failure_rows'] !== []) {
            $this->newLine();
            $this->table(
                ['Source', 'Outbox', 'Status', 'Attempts', 'Last attempt', 'Error'],
                array_map(fn (array $row): array => [
                    $row['source'],
                    $row['id'],
                    $row['status'],
                    $row['attempts'],
                    $row['last_attempt_at'] ?? 'never',
                    $row['last_error'] ?? '',
                ], $result['failure_rows']),
            );
        }

        return self::SUCCESS;
    }
}
