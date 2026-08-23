<?php

namespace App\Console\Commands;

use App\Domain\Clinical\Services\ClinicalProtocolService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ReconcileClinicalProtocolSchedules extends Command
{
    protected $signature = 'clinical:reconcile-protocol-schedules
        {--at= : Effective instant for deterministic recovery}
        {--timezone= : Protocol date timezone (defaults to the application timezone)}';

    protected $description = 'Extend active clinical protocol schedules through the rolling materialization horizon';

    public function handle(ClinicalProtocolService $protocols): int
    {
        $at = trim((string) $this->option('at'));
        $timezone = trim((string) $this->option('timezone'));
        $result = $protocols->reconcileScheduledHorizon(
            $at !== '' ? CarbonImmutable::parse($at, 'UTC') : null,
            $timezone !== '' ? $timezone : null,
        );

        $this->info(sprintf(
            'Reconciled %d clinical protocols across %d bounded occurrences.',
            $result['protocol_count'],
            $result['occurrence_count'],
        ));

        return self::SUCCESS;
    }
}
