<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationDashboardAlert;
use Carbon\Carbon;

class MedicationAlertService
{
    /**
     * Get active alerts for a client from database
     */
    public function getActiveAlertsForClient(int $clientId): array
    {
        return MedicationDashboardAlert::where('client_id', $clientId)
            ->where('status', 'active')
            ->orderByDesc('severity')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'alert_type' => $a->alert_type,
                'severity' => $a->severity,
                'message' => $a->message,
                'medication_name' => $a->medication?->name,
                'created_at' => $a->created_at?->toIso8601String(),
            ])
            ->toArray();
    }

    /**
     * Generate all dashboard alerts for a client
     */
    public function generateClientAlerts(Client $client): array
    {
        $alerts = [];

        // Get active medications
        $medications = $client->medications()->active()->get();

        foreach ($medications as $medication) {
            // Check PRN limits
            if ($medication->is_prn && $medication->max_per_day) {
                $prnAlert = $this->checkPrnAlert($client, $medication);
                if ($prnAlert) {
                    $alerts[] = $prnAlert;
                }
            }

            // Check expiry
            $expiryAlert = $this->checkExpiryAlert($client, $medication);
            if ($expiryAlert) {
                $alerts[] = $expiryAlert;
            }

            // Check stock levels
            $stockAlert = $this->checkStockAlert($client, $medication);
            if ($stockAlert) {
                $alerts[] = $stockAlert;
            }
        }

        // Check for overdue scheduled doses
        $overdueAlert = $this->checkOverdueDoses($client);
        if ($overdueAlert) {
            $alerts[] = $overdueAlert;
        }

        // Check for controlled discrepancies
        $discrepancyAlert = $this->checkControlledDiscrepancies($client);
        if ($discrepancyAlert) {
            $alerts[] = $discrepancyAlert;
        }

        return $alerts;
    }

    /**
     * Check PRN alert
     */
    private function checkPrnAlert(Client $client, ClientMedication $medication): ?array
    {
        $count = $medication->prnCountLast24Hours;
        $maxPerDay = (int) filter_var($medication->max_per_day, FILTER_SANITIZE_NUMBER_INT);

        if ($maxPerDay <= 0) {
            return null;
        }

        if ($count >= $maxPerDay) {
            $alert = MedicationDashboardAlert::createOrUpdateAlert(
                $client->id,
                'prn_over_limit',
                'critical',
                "{$medication->name}: PRN limit reached ({$count}/{$maxPerDay})",
                $medication->id
            );
            return $alert->toArray();
        }

        if ($count >= ($maxPerDay * 0.75)) {
            $alert = MedicationDashboardAlert::createOrUpdateAlert(
                $client->id,
                'prn_near_limit',
                'warning',
                "{$medication->name}: PRN near limit ({$count}/{$maxPerDay})",
                $medication->id
            );
            return $alert->toArray();
        }

        return null;
    }

    /**
     * Check expiry alert
     */
    private function checkExpiryAlert(Client $client, ClientMedication $medication): ?array
    {
        if (!$medication->end_date) {
            return null;
        }

        if ($medication->isExpired()) {
            $alert = MedicationDashboardAlert::createOrUpdateAlert(
                $client->id,
                'expired',
                'critical',
                "{$medication->name}: Medication expired on {$medication->end_date->format('d/m/Y')}",
                $medication->id
            );
            return $alert->toArray();
        }

        if ($medication->isExpiringSoon(7)) {
            $daysRemaining = $medication->end_date->diffInDays(now());
            $alert = MedicationDashboardAlert::createOrUpdateAlert(
                $client->id,
                'expiring_soon',
                'warning',
                "{$medication->name}: Expires in {$daysRemaining} days ({$medication->end_date->format('d/m/Y')})",
                $medication->id
            );
            return $alert->toArray();
        }

        return null;
    }

    /**
     * Check stock alert
     */
    private function checkStockAlert(Client $client, ClientMedication $medication): ?array
    {
        $stock = $medication->stock;
        
        if (!$stock || $stock->on_hand === null) {
            return null;
        }

        if ($stock->on_hand === 0) {
            $alert = MedicationDashboardAlert::createOrUpdateAlert(
                $client->id,
                'stock_low',
                'critical',
                "{$medication->name}: OUT OF STOCK",
                $medication->id
            );
            return $alert->toArray();
        }

        if ($stock->reorder_level && $stock->on_hand <= $stock->reorder_level) {
            $alert = MedicationDashboardAlert::createOrUpdateAlert(
                $client->id,
                'stock_low',
                'warning',
                "{$medication->name}: Low stock ({$stock->on_hand} {$stock->unit} remaining)",
                $medication->id
            );
            return $alert->toArray();
        }

        return null;
    }

    /**
     * Check for overdue doses
     */
    private function checkOverdueDoses(Client $client): ?array
    {
        $now = now();
        $cutoff = $now->copy()->subHours(3);

        // Find medications with scheduled times that have passed and no administration
        $medications = $client->medications()
            ->active()
            ->where('is_prn', false)
            ->get();

        $overdueCount = 0;
        $overdueMeds = [];

        foreach ($medications as $medication) {
            $doseTimes = $medication->dose_times ?? [];
            
            foreach ($doseTimes as $time) {
                $scheduledTime = $now->copy()->setTimeFromTimeString($time);
                
                // If scheduled time has passed and within the last 3 hours
                if ($scheduledTime->isPast() && $scheduledTime->greaterThan($cutoff)) {
                    // Check if already recorded
                    $recorded = ClientMedicationAdministration::where('client_medication_id', $medication->id)
                        ->whereBetween('scheduled_for', [
                            $scheduledTime->copy()->subMinute(),
                            $scheduledTime->copy()->addMinute(),
                        ])
                        ->exists();

                    if (!$recorded) {
                        $overdueCount++;
                        $overdueMeds[] = $medication->name;
                    }
                }
            }
        }

        if ($overdueCount > 0) {
            $alert = MedicationDashboardAlert::createOrUpdateAlert(
                $client->id,
                'overdue',
                'critical',
                "{$overdueCount} overdue dose(s): " . implode(', ', array_unique($overdueMeds)),
                null
            );
            return $alert->toArray();
        }

        return null;
    }

    /**
     * Check for controlled drug discrepancies
     */
    private function checkControlledDiscrepancies(Client $client): ?array
    {
        $openDiscrepancies = ClientControlledDrugDiscrepancy::where('client_id', $client->id)
            ->whereIn('status', ['open', 'under_review'])
            ->with('medication:id,name')
            ->get();

        if ($openDiscrepancies->isEmpty()) {
            return null;
        }

        $medNames = $openDiscrepancies->pluck('medication.name')->filter()->unique()->implode(', ');

        $alert = MedicationDashboardAlert::createOrUpdateAlert(
            $client->id,
            'controlled_discrepancy',
            'critical',
            "Controlled drug discrepancy: {$medNames}. Review required.",
            $openDiscrepancies->first()->client_medication_id
        );

        return $alert->toArray();
    }

    /**
     * Get global dashboard widgets data
     */
    public function getGlobalDashboardWidgets(?int $clientId = null): array
    {
        return [
            'overdue_meds' => $this->getOverdueMedsWidget($clientId),
            'prn_near_limits' => $this->getPrnNearLimitsWidget($clientId),
            'controlled_discrepancies' => $this->getControlledDiscrepanciesWidget($clientId),
            'expiring_medications' => $this->getExpiringMedicationsWidget($clientId),
            'high_risk_medications' => $this->getHighRiskMedicationsWidget($clientId),
            'todays_summary' => $this->getTodaysSummaryWidget($clientId),
        ];
    }

    /**
     * Get overdue medications widget
     */
    private function getOverdueMedsWidget(?int $clientId = null): array
    {
        $query = MedicationDashboardAlert::where('alert_type', 'overdue')
            ->where('status', 'active')
            ->with('client:id,first_name,last_name');

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        $alerts = $query->orderByDesc('created_at')->limit(10)->get();

        return [
            'title' => 'Overdue Medications',
            'count' => $alerts->count(),
            'severity' => 'critical',
            'items' => $alerts->map(fn ($a) => [
                'id' => $a->id,
                'client' => $a->client ? trim("{$a->client->first_name} {$a->client->last_name}") : 'Unknown',
                'client_id' => $a->client_id,
                'message' => $a->message,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->toArray(),
        ];
    }

    /**
     * Get PRN near limits widget
     */
    private function getPrnNearLimitsWidget(?int $clientId = null): array
    {
        $query = MedicationDashboardAlert::whereIn('alert_type', ['prn_near_limit', 'prn_over_limit'])
            ->where('status', 'active')
            ->with(['client:id,first_name,last_name', 'medication:id,name']);

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        $alerts = $query->orderByDesc('severity')->orderByDesc('created_at')->limit(10)->get();

        return [
            'title' => 'PRN Near/Over Limits',
            'count' => $alerts->count(),
            'severity' => $alerts->contains('severity', 'critical') ? 'critical' : 'warning',
            'items' => $alerts->map(fn ($a) => [
                'id' => $a->id,
                'client' => $a->client ? trim("{$a->client->first_name} {$a->client->last_name}") : 'Unknown',
                'client_id' => $a->client_id,
                'medication' => $a->medication?->name,
                'message' => $a->message,
                'severity' => $a->severity,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->toArray(),
        ];
    }

    /**
     * Get controlled discrepancies widget
     */
    private function getControlledDiscrepanciesWidget(?int $clientId = null): array
    {
        $query = ClientControlledDrugDiscrepancy::whereIn('status', ['open', 'under_review'])
            ->with(['client:id,first_name,last_name', 'medication:id,name']);

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        $discrepancies = $query->orderByDesc('reported_at')->limit(10)->get();

        return [
            'title' => 'Controlled Drug Discrepancies',
            'count' => $discrepancies->count(),
            'severity' => 'critical',
            'items' => $discrepancies->map(fn ($d) => [
                'id' => $d->id,
                'client' => $d->client ? trim("{$d->client->first_name} {$d->client->last_name}") : 'Unknown',
                'client_id' => $d->client_id,
                'medication' => $d->medication?->name ?? 'Unknown',
                'difference' => $d->difference,
                'status' => $d->status,
                'reported_at' => $d->reported_at?->toIso8601String(),
            ])->toArray(),
        ];
    }

    /**
     * Get expiring medications widget
     */
    private function getExpiringMedicationsWidget(?int $clientId = null): array
    {
        $query = ClientMedication::active()
            ->whereNotNull('end_date')
            ->where('end_date', '<=', now()->addDays(14))
            ->where('end_date', '>=', now())
            ->with('client:id,first_name,last_name');

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        $medications = $query->orderBy('end_date')->limit(10)->get();

        return [
            'title' => 'Expiring Medications',
            'count' => $medications->count(),
            'severity' => 'warning',
            'items' => $medications->map(fn ($m) => [
                'id' => $m->id,
                'client' => $m->client ? trim("{$m->client->first_name} {$m->client->last_name}") : 'Unknown',
                'client_id' => $m->client_id,
                'medication' => $m->name,
                'expiry_date' => $m->end_date?->toDateString(),
                'days_remaining' => $m->end_date?->diffInDays(now()),
            ])->toArray(),
        ];
    }

    /**
     * Get high risk medications widget
     */
    private function getHighRiskMedicationsWidget(?int $clientId = null): array
    {
        $query = ClientMedication::active()
            ->where('high_risk', true)
            ->with('client:id,first_name,last_name');

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        $medications = $query->orderByDesc('created_at')->limit(10)->get();

        return [
            'title' => 'High Risk Medications',
            'count' => $medications->count(),
            'severity' => 'caution',
            'items' => $medications->map(fn ($m) => [
                'id' => $m->id,
                'client' => $m->client ? trim("{$m->client->first_name} {$m->client->last_name}") : 'Unknown',
                'client_id' => $m->client_id,
                'medication' => $m->name,
                'dosage' => $m->dosage,
                'instructions' => $m->instructions,
            ])->toArray(),
        ];
    }

    /**
     * Get today's summary widget
     */
    private function getTodaysSummaryWidget(?int $clientId = null): array
    {
        $today = now()->startOfDay();
        $tomorrow = $today->copy()->addDay();

        // Get scheduled medications (non-PRN, active today)
        $scheduledQuery = ClientMedication::active()
            ->where('is_prn', false)
            ->where(function ($q) use ($today) {
                $q->whereNull('start_date')->orWhere('start_date', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $today);
            });

        if ($clientId) {
            $scheduledQuery->where('client_id', $clientId);
        }

        $scheduledMeds = $scheduledQuery->get();
        
        // Calculate total scheduled doses for today
        $totalScheduled = 0;
        foreach ($scheduledMeds as $med) {
            $totalScheduled += count($med->dose_times ?? []);
        }

        // Count completed administrations for scheduled medications only
        // Must have scheduled_for today and status = given
        $completedQuery = ClientMedicationAdministration::where('status', 'given')
            ->whereNotNull('scheduled_for')
            ->whereBetween('scheduled_for', [$today, $tomorrow])
            ->whereHas('medication', function ($q) {
                $q->active()->where('is_prn', false);
            });
        
        if ($clientId) {
            $completedQuery->where('client_id', $clientId);
        }
        
        // Count unique scheduled slots (not duplicate administrations)
        $completed = $completedQuery->distinct(['client_medication_id', 'scheduled_for'])->count();

        // Count refused and missed for scheduled medications
        $refusedQuery = ClientMedicationAdministration::where('status', 'refused')
            ->whereBetween('scheduled_for', [$today, $tomorrow])
            ->whereHas('medication', function ($q) {
                $q->active()->where('is_prn', false);
            });

        $missedQuery = ClientMedicationAdministration::where('status', 'missed')
            ->whereBetween('scheduled_for', [$today, $tomorrow])
            ->whereHas('medication', function ($q) {
                $q->active()->where('is_prn', false);
            });
        
        if ($clientId) {
            $refusedQuery->where('client_id', $clientId);
            $missedQuery->where('client_id', $clientId);
        }
        
        $refused = $refusedQuery->count();
        $missed = $missedQuery->count();

        // Calculate completion percentage (cap at 100%)
        $completionPercentage = $totalScheduled > 0 
            ? min(100, round(($completed / $totalScheduled) * 100, 1))
            : 0;

        return [
            'title' => "Today's Medications",
            'total_scheduled' => $totalScheduled,
            'completed' => $completed,
            'refused' => $refused,
            'missed' => $missed,
            'remaining' => max(0, $totalScheduled - $completed - $refused - $missed),
            'completion_percentage' => $completionPercentage,
        ];
    }

    /**
     * Acknowledge an alert
     */
    public function acknowledgeAlert(int $alertId, int $userId): bool
    {
        $alert = MedicationDashboardAlert::find($alertId);
        
        if (!$alert || $alert->status !== 'active') {
            return false;
        }

        $alert->acknowledge($userId);
        return true;
    }

    /**
     * Resolve an alert
     */
    public function resolveAlert(int $alertId, ?string $notes = null): bool
    {
        $alert = MedicationDashboardAlert::find($alertId);
        
        if (!$alert) {
            return false;
        }

        $alert->resolve($notes);
        return true;
    }

    /**
     * Clear stale alerts
     */
    public function clearStaleAlerts(): int
    {
        $count = 0;

        // Clear resolved PRN alerts when count drops
        $prnAlerts = MedicationDashboardAlert::where('alert_type', 'like', 'prn_%')
            ->where('status', 'active')
            ->get();

        foreach ($prnAlerts as $alert) {
            $medication = ClientMedication::find($alert->client_medication_id);
            if ($medication && !$medication->isPrnNearLimit()) {
                $alert->resolve('Auto-resolved: PRN count below threshold');
                $count++;
            }
        }

        // Clear expired medication alerts for ceased medications
        $expiryAlerts = MedicationDashboardAlert::whereIn('alert_type', ['expired', 'expiring_soon'])
            ->where('status', 'active')
            ->get();

        foreach ($expiryAlerts as $alert) {
            $medication = ClientMedication::find($alert->client_medication_id);
            if (!$medication || $medication->state === 'ceased' || $medication->superseded_by) {
                $alert->resolve('Auto-resolved: Medication ceased or updated');
                $count++;
            }
        }

        return $count;
    }
}
