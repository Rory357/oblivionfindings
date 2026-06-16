<?php

namespace App\Console\Commands;

use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\MedicationDashboardAlert;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Background escalation for the controlled-drug reconciliation cadence: raises a
 * dashboard alert for any active controlled drug with no balance check in the
 * last N days (default 7, matching the page's overdue_check threshold). The
 * alert is cleared when a balance check is next recorded (see storeBalanceCheck).
 */
class EscalateOverdueControlledChecks extends Command
{
    protected $signature = 'emar:escalate-overdue-cd-checks {--days=7}';

    protected $description = 'Raise a dashboard alert for controlled drugs with no balance check in the last N days (default 7).';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $medications = ClientMedication::query()
            ->active()
            ->controlled()
            ->with('client:id,first_name,last_name')
            ->get();

        // Last balance check per controlled drug (one grouped query).
        $lastChecks = ClientControlledDrugEntry::query()
            ->where('entry_type', 'balance_check')
            ->whereIn('client_medication_id', $medications->pluck('id')->all())
            ->selectRaw('client_medication_id, MAX(recorded_at) as last_at')
            ->groupBy('client_medication_id')
            ->pluck('last_at', 'client_medication_id');

        $raised = 0;

        foreach ($medications as $med) {
            if (! $med->client_id) {
                continue;
            }

            $lastAt = $lastChecks[$med->id] ?? null;
            $overdue = $lastAt === null || Carbon::parse($lastAt)->lt($cutoff);

            if (! $overdue) {
                continue;
            }

            $clientName = $med->client ? trim($med->client->first_name.' '.$med->client->last_name) : 'Unknown';
            $when = $lastAt ? Carbon::parse($lastAt)->diffForHumans() : 'never';

            // createOrUpdateAlert is idempotent per (client, med, type) active alert.
            MedicationDashboardAlert::createOrUpdateAlert(
                clientId: $med->client_id,
                alertType: 'controlled_overdue_check',
                severity: 'warning',
                message: "{$med->name} for {$clientName}: controlled-drug balance check overdue (last checked {$when}).",
                medicationId: $med->id,
            );

            $raised++;
        }

        $this->info("Overdue CD balance-check escalation complete. {$raised} alert(s) raised/updated (threshold {$days}d).");

        return self::SUCCESS;
    }
}
