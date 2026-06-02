<?php

namespace App\Console\Commands;

use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationRound;
use App\Models\User;
use App\Notifications\MedicationCompetencyExpiringNotification;
use App\Notifications\MedicationOverdueNotification;
use App\Notifications\MedicationRefusalClusterNotification;
use App\Notifications\MedicationStockLowNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendMedicationAlerts extends Command
{
    protected $signature = 'emar:send-alerts';

    protected $description = 'Check for overdue medications, low stock, expiring competencies, and refusal clusters and send notifications';

    public function handle(): int
    {
        $this->info('Running eMAR medication alerts...');

        $this->checkOverdueMedications();
        $this->checkLowStock();
        $this->checkExpiringCompetencies();
        $this->checkRefusalClusters();

        $this->info('eMAR medication alerts complete.');

        return self::SUCCESS;
    }

    /**
     * Check for overdue medications: administrations where scheduled_for < now() - window_minutes
     * AND status = 'pending'. Notify the assigned round staff.
     */
    protected function checkOverdueMedications(): void
    {
        $this->info('Checking for overdue medications...');

        $overdueAdministrations = ClientMedicationAdministration::where('status', 'pending')
            ->where('scheduled_for', '<', now())
            ->with(['medication.client', 'round.assignedTo'])
            ->get()
            ->filter(function ($admin) {
                $client = $admin->medication?->client ?? $admin->client;
                if ($client?->suppress_med_admin_alerts) {
                    return false;
                }

                // Respect the round's window_minutes if available
                if ($admin->round && $admin->round->window_minutes) {
                    return now()->gt($admin->scheduled_for->addMinutes($admin->round->window_minutes));
                }
                // Default: overdue if scheduled_for is in the past
                return true;
            });

        $count = 0;
        foreach ($overdueAdministrations as $admin) {
            $staff = $admin->round?->assignedTo;
            if (!$staff) {
                continue;
            }

            $medicationName = $admin->medication?->name ?? 'Unknown medication';
            $clientName = $admin->medication?->client?->name ?? $admin->client?->name ?? 'Unknown client';
            $clientId = $admin->client_id;
            $scheduledTime = $admin->scheduled_for->format('H:i');

            $staff->notify(new MedicationOverdueNotification(
                medication: $medicationName,
                clientName: $clientName,
                scheduledTime: $scheduledTime,
                clientId: $clientId,
            ));
            $count++;
        }

        $this->info("Sent {$count} overdue medication alerts.");
    }

    /**
     * Check for low stock: stocks where on_hand <= reorder_level
     * AND last_reorder_alert_at is null or > 24h ago.
     * Notify users with 'medications.view' permission.
     */
    protected function checkLowStock(): void
    {
        $this->info('Checking for low medication stock...');

        $lowStocks = ClientMedicationStock::whereColumn('on_hand', '<=', 'reorder_level')
            ->where('reorder_level', '>', 0)
            ->where(function ($query) {
                $query->whereNull('last_reorder_alert_at')
                    ->orWhere('last_reorder_alert_at', '<', now()->subHours(24));
            })
            ->with('medication.client')
            ->get();

        if ($lowStocks->isEmpty()) {
            $this->info('No low stock alerts to send.');
            return;
        }

        // Find users with medications.view permission
        $users = User::whereHas('roles', function ($q) {
            $q->whereHas('permissions', fn ($p) => $p->where('key', 'medications.view'));
        })->get();

        $count = 0;
        foreach ($lowStocks as $stock) {
            $medicationName = $stock->medication?->name ?? 'Unknown medication';
            $clientName = $stock->medication?->client?->name ?? 'Unknown client';

            foreach ($users as $user) {
                $user->notify(new MedicationStockLowNotification(
                    medication: $medicationName,
                    clientName: $clientName,
                    count: (int) $stock->on_hand,
                    unit: $stock->unit ?? 'units',
                    reorderLevel: (int) $stock->reorder_level,
                ));
            }

            // Update the last alert timestamp to prevent re-alerting within 24 hours
            $stock->update(['last_reorder_alert_at' => now()]);
            $count++;
        }

        $this->info("Sent low stock alerts for {$count} medications.");
    }

    /**
     * Check for expiring competency: assessments where expiry_date <= today + 30 days.
     * Notify the assessed user.
     */
    protected function checkExpiringCompetencies(): void
    {
        $this->info('Checking for expiring medication competencies...');

        $expiringAssessments = MedicationCompetencyAssessment::expiringSoon(30)
            ->with('user')
            ->get();

        $count = 0;
        foreach ($expiringAssessments as $assessment) {
            $user = $assessment->user;
            if (!$user) {
                continue;
            }

            $user->notify(new MedicationCompetencyExpiringNotification(
                staffName: $user->name,
                expiryDate: $assessment->expiry_date->format('d/m/Y'),
                assessmentId: $assessment->id,
            ));
            $count++;
        }

        $this->info("Sent {$count} competency expiry alerts.");
    }

    /**
     * Check for refusal clusters: clients with 3+ refusals of the same medication
     * in the last 7 days. Notify team leaders.
     */
    protected function checkRefusalClusters(): void
    {
        $this->info('Checking for medication refusal clusters...');

        $clusters = ClientMedicationAdministration::where('status', 'refused')
            ->where('scheduled_for', '>=', now()->subDays(7))
            ->select('client_id', 'client_medication_id', DB::raw('COUNT(*) as refusal_count'))
            ->groupBy('client_id', 'client_medication_id')
            ->having('refusal_count', '>=', 3)
            ->get();

        if ($clusters->isEmpty()) {
            $this->info('No refusal clusters detected.');
            return;
        }

        // Notify team leaders (users with team_leader role)
        $teamLeaders = User::whereHas('roles', function ($q) {
            $q->where('slug', 'team-leader')
                ->orWhere('name', 'Team Leader');
        })->get();

        $count = 0;
        foreach ($clusters as $cluster) {
            $medication = \App\Models\ClientMedication::with('client')->find($cluster->client_medication_id);
            if (!$medication) {
                continue;
            }

            $clientName = $medication->client?->name ?? 'Unknown client';
            $medicationName = $medication->name ?? 'Unknown medication';

            foreach ($teamLeaders as $leader) {
                $leader->notify(new MedicationRefusalClusterNotification(
                    clientName: $clientName,
                    medication: $medicationName,
                    count: (int) $cluster->refusal_count,
                    clientId: $cluster->client_id,
                ));
            }
            $count++;
        }

        $this->info("Sent refusal cluster alerts for {$count} medication/client combinations.");
    }
}
