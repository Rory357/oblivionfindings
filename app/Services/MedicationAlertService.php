<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ControlRoomAlert;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationReview;
use App\Services\Medication\MedicationSignalService;

/**
 * Medication alert generation and dashboard widget service.
 *
 * ARCHITECTURAL NOTE (PR3):
 * This service serves TWO purposes:
 *
 * 1. OPERATIONAL ALERTS — emitted via MedicationSignalService into the canonical
 *    Control Room pipeline. These are the source of truth for triage, SLA,
 *    escalation, and operational response. Only safety-critical events
 *    (overdue, missed dose, PRN over-limit, controlled discrepancy, expired,
 *    out of stock) emit signals.
 *
 * 2. DASHBOARD DISPLAY — writes to MedicationDashboardAlert for medication-specific
 *    UI widgets, counts, and acknowledge/resolve actions. This is a COMPATIBILITY
 *    LAYER only. MedicationDashboardAlert is NOT the operational source of truth.
 *    It will be eliminated in a future PR once medication UI reads from ControlRoomAlert.
 *
 * Do NOT add new operational logic that depends on MedicationDashboardAlert status.
 * Do NOT treat MedicationDashboardAlert as the authority for whether an alert
 * has been triaged, escalated, or resolved — that lives on ControlRoomAlert.
 *
 * @see MedicationSignalService — canonical signal emission
 * @see ControlRoomAlert — canonical operational alert record
 */
class MedicationAlertService
{
    public function __construct(
        protected ?MedicationSignalService $signalService = null,
    ) {
        $this->signalService ??= app(MedicationSignalService::class);
    }

    /**
     * Get active alerts for a client from database.
     * Reads from MedicationDashboardAlert (display convenience layer).
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
     * Generate all dashboard alerts for a client.
     * Creates MedicationDashboardAlert records for UI AND emits signals for operational alerts.
     */
    public function generateClientAlerts(Client $client): array
    {
        $alerts = [];
        $medications = $client->medications()->active()->get();

        foreach ($this->checkClientAttentionAlerts($client) as $attentionAlert) {
            $alerts[] = $attentionAlert;
        }

        foreach ($medications as $medication) {
            if ($medication->is_prn && $medication->max_per_day) {
                $prnAlert = $this->checkPrnAlert($client, $medication);
                if ($prnAlert) {
                    $alerts[] = $prnAlert;
                }
            }

            $expiryAlert = $this->checkExpiryAlert($client, $medication);
            if ($expiryAlert) {
                $alerts[] = $expiryAlert;
            }

            $stockAlert = $this->checkStockAlert($client, $medication);
            if ($stockAlert) {
                $alerts[] = $stockAlert;
            }

            if ($this->isWarfarinMedication($medication)) {
                $inrAlert = $this->checkInrAlert($client, $medication);
                if ($inrAlert) {
                    $alerts[] = $inrAlert;
                }
            }
        }

        if (! $client->suppress_med_admin_alerts) {
            MedicationDashboardAlert::query()
                ->where('client_id', $client->id)
                ->where('alert_type', 'med_admin_alerts_suppressed')
                ->where('status', 'active')
                ->update([
                    'status' => 'resolved',
                    'resolved_at' => now(),
                ]);

            $overdueAlert = $this->checkOverdueDoses($client);
            if ($overdueAlert) {
                $alerts[] = $overdueAlert;
            }
        } else {
            $alerts[] = MedicationDashboardAlert::createOrUpdateAlert(
                $client->id,
                'med_admin_alerts_suppressed',
                'info',
                trim('Medication administration due alerts are suppressed'.($client->med_alerts_suppressed_reason ? ': '.$client->med_alerts_suppressed_reason : '.')),
            )->toArray();
        }

        foreach ($this->checkReviewAlerts($client) as $reviewAlert) {
            $alerts[] = $reviewAlert;
        }

        $discrepancyAlert = $this->checkControlledDiscrepancies($client);
        if ($discrepancyAlert) {
            $alerts[] = $discrepancyAlert;
        }

        return $alerts;
    }

    /**
     * Mirror client-level attention bar entries into the dashboard widget layer.
     *
     * @return array<int, array<string, mixed>>
     */
    private function checkClientAttentionAlerts(Client $client): array
    {
        return $client->medicationAlerts()
            ->enabled()
            ->unresolved()
            ->get()
            ->map(function ($clientAlert) use ($client) {
                $type = match ($clientAlert->type) {
                    'paper_prescription' => 'paper_prescription',
                    'warfarin' => 'warfarin',
                    default => 'chart_warning',
                };

                $severity = $clientAlert->prompt_on_open ? 'warning' : 'info';
                $message = trim($clientAlert->title.($clientAlert->detail ? ': '.$clientAlert->detail : ''));

                return MedicationDashboardAlert::createOrUpdateAlert(
                    $client->id,
                    $type,
                    $severity,
                    $message,
                )->toArray();
            })
            ->all();
    }

    private function isWarfarinMedication(ClientMedication $medication): bool
    {
        return str_contains(strtolower($medication->name), 'warfarin');
    }

    private function checkInrAlert(Client $client, ClientMedication $medication): ?array
    {
        $latest = $client->inrRecords()
            ->active()
            ->where(function ($query) use ($medication) {
                $query->whereNull('client_medication_id')
                    ->orWhere('client_medication_id', $medication->id);
            })
            ->latest('tested_on')
            ->first();

        if (! $latest) {
            return MedicationDashboardAlert::createOrUpdateAlert(
                $client->id,
                'inr_due',
                'critical',
                "{$medication->name}: INR has not been recorded.",
                $medication->id,
            )->toArray();
        }

        if (! $latest->next_test_date) {
            return null;
        }

        $daysUntilDue = now()->startOfDay()->diffInDays($latest->next_test_date->copy()->startOfDay(), false);
        if ($daysUntilDue > 3) {
            return null;
        }

        $severity = $daysUntilDue < 0 ? 'critical' : 'warning';
        $message = $daysUntilDue < 0
            ? "{$medication->name}: INR overdue since {$latest->next_test_date->format('d/m/Y')}."
            : "{$medication->name}: INR due by {$latest->next_test_date->format('d/m/Y')}.";

        return MedicationDashboardAlert::createOrUpdateAlert(
            $client->id,
            'inr_due',
            $severity,
            $message,
            $medication->id,
        )->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function checkReviewAlerts(Client $client): array
    {
        $alerts = [];

        if ($client->next_chart_review_date) {
            $daysUntilReview = now()->startOfDay()->diffInDays($client->next_chart_review_date->copy()->startOfDay(), false);
            if ($daysUntilReview <= 7) {
                $alerts[] = MedicationDashboardAlert::createOrUpdateAlert(
                    $client->id,
                    'chart_review_due',
                    $daysUntilReview < 0 ? 'critical' : 'warning',
                    $daysUntilReview < 0
                        ? "Medication chart review overdue since {$client->next_chart_review_date->format('d/m/Y')}."
                        : "Medication chart review due by {$client->next_chart_review_date->format('d/m/Y')}.",
                )->toArray();
            }
        }

        $review = MedicationReview::query()
            ->where('client_id', $client->id)
            ->whereIn('status', ['scheduled', 'overdue'])
            ->where(function ($query) {
                $query->whereDate('scheduled_date', '<=', now()->addDays(7)->toDateString())
                    ->orWhereDate('next_review_date', '<=', now()->addDays(7)->toDateString());
            })
            ->orderByRaw('COALESCE(next_review_date, scheduled_date) asc')
            ->first();

        if ($review) {
            $dueDate = $review->next_review_date ?? $review->scheduled_date;
            $daysUntilReview = now()->startOfDay()->diffInDays($dueDate->copy()->startOfDay(), false);
            $alerts[] = MedicationDashboardAlert::createOrUpdateAlert(
                $client->id,
                'medication_review_due',
                $daysUntilReview < 0 ? 'critical' : 'warning',
                $daysUntilReview < 0
                    ? "Medication review overdue since {$dueDate->format('d/m/Y')}."
                    : "Medication review due by {$dueDate->format('d/m/Y')}.",
            )->toArray();
        }

        return $alerts;
    }

    /**
     * Check PRN alert.
     * Over-limit → operational signal (critical). Near-limit → dashboard only (not operational).
     */
    private function checkPrnAlert(Client $client, ClientMedication $medication): ?array
    {
        $count = $medication->prnCountLast24Hours;
        $maxPerDay = (int) filter_var($medication->max_per_day, FILTER_SANITIZE_NUMBER_INT);

        if ($maxPerDay <= 0) {
            return null;
        }

        if ($count >= $maxPerDay) {
            // Dashboard alert (UI compat)
            $alert = MedicationDashboardAlert::createOrUpdateAlert(
                $client->id,
                'prn_over_limit',
                'critical',
                "{$medication->name}: PRN limit reached ({$count}/{$maxPerDay})",
                $medication->id
            );

            // Operational signal → Control Room
            $this->signalService->emit(
                MedicationSignalService::TYPE_PRN_OVER_LIMIT,
                $client->id,
                'critical',
                "{$medication->name}: PRN limit reached ({$count}/{$maxPerDay})",
                [
                    'client_medication_id' => $medication->id,
                    'medication_name' => $medication->name,
                    'prn_count_24h' => $count,
                    'max_per_day' => $maxPerDay,
                    'controlled_drug' => $medication->controlled_drug,
                    'high_risk' => $medication->high_risk,
                    'site_id' => $client->site_id,
                ],
            );

            return $alert->toArray();
        }

        if ($count >= ($maxPerDay * 0.75)) {
            // Near-limit is dashboard-only — NOT operational
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
     * Check expiry alert.
     * Expired → operational signal. Expiring soon → dashboard only.
     */
    private function checkExpiryAlert(Client $client, ClientMedication $medication): ?array
    {
        if (! $medication->end_date) {
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

            // Operational signal → Control Room
            $this->signalService->emit(
                MedicationSignalService::TYPE_EXPIRED,
                $client->id,
                'high',
                "{$medication->name}: Medication expired on {$medication->end_date->format('d/m/Y')}",
                [
                    'client_medication_id' => $medication->id,
                    'medication_name' => $medication->name,
                    'expiry_date' => $medication->end_date->toDateString(),
                    'controlled_drug' => $medication->controlled_drug,
                    'high_risk' => $medication->high_risk,
                    'site_id' => $client->site_id,
                ],
            );

            return $alert->toArray();
        }

        if ($medication->isExpiringSoon(7)) {
            // Expiring soon is dashboard-only — NOT operational
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
     * Check stock alert.
     * Out of stock → operational signal. Low stock → dashboard only.
     */
    private function checkStockAlert(Client $client, ClientMedication $medication): ?array
    {
        $stock = $medication->stock;

        if (! $stock || $stock->on_hand === null) {
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

            // Operational signal → Control Room (out of stock is operational)
            $this->signalService->emit(
                MedicationSignalService::TYPE_STOCK_OUT,
                $client->id,
                'high',
                "{$medication->name}: OUT OF STOCK — client cannot receive scheduled doses",
                [
                    'client_medication_id' => $medication->id,
                    'medication_name' => $medication->name,
                    'controlled_drug' => $medication->controlled_drug,
                    'high_risk' => $medication->high_risk,
                    'site_id' => $client->site_id,
                ],
            );

            return $alert->toArray();
        }

        if ($stock->reorder_level && $stock->on_hand <= $stock->reorder_level) {
            // Low stock is dashboard-only — NOT operational
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
     * Check for overdue doses → always operational.
     */
    private function checkOverdueDoses(Client $client): ?array
    {
        $now = now();
        $cutoff = $now->copy()->subHours(3);

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

                if ($scheduledTime->isPast() && $scheduledTime->greaterThan($cutoff)) {
                    $recorded = ClientMedicationAdministration::where('client_medication_id', $medication->id)
                        ->whereBetween('scheduled_for', [
                            $scheduledTime->copy()->subMinute(),
                            $scheduledTime->copy()->addMinute(),
                        ])
                        ->exists();

                    if (! $recorded) {
                        $overdueCount++;
                        $overdueMeds[] = $medication->name;
                    }
                }
            }
        }

        if ($overdueCount > 0) {
            $message = "{$overdueCount} overdue dose(s): ".implode(', ', array_unique($overdueMeds));

            // Dashboard alert (UI compat)
            $alert = MedicationDashboardAlert::createOrUpdateAlert(
                $client->id,
                'overdue',
                'critical',
                $message,
                null
            );

            // Operational signal → Control Room
            $this->signalService->emit(
                MedicationSignalService::TYPE_OVERDUE,
                $client->id,
                'high',
                $message,
                [
                    'overdue_count' => $overdueCount,
                    'medication_names' => array_unique($overdueMeds),
                    'site_id' => $client->site_id,
                ],
            );

            return $alert->toArray();
        }

        return null;
    }

    /**
     * Check for controlled drug discrepancies → always operational.
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

        // Dashboard alert (UI compat)
        $alert = MedicationDashboardAlert::createOrUpdateAlert(
            $client->id,
            'controlled_discrepancy',
            'critical',
            "Controlled drug discrepancy: {$medNames}. Review required.",
            $openDiscrepancies->first()->client_medication_id
        );

        // Operational signal → Control Room
        $this->signalService->emit(
            MedicationSignalService::TYPE_CONTROLLED_DISCREPANCY,
            $client->id,
            'critical',
            "Controlled drug discrepancy: {$medNames}. Review required.",
            [
                'client_medication_id' => $openDiscrepancies->first()->client_medication_id,
                'medication_names' => $medNames,
                'discrepancy_count' => $openDiscrepancies->count(),
                'discrepancy_ids' => $openDiscrepancies->pluck('id')->toArray(),
                'site_id' => $client->site_id,
            ],
        );

        return $alert->toArray();
    }

    // -----------------------------------------------------------------------
    // Dashboard widgets — unchanged, read from MedicationDashboardAlert / domain models
    // -----------------------------------------------------------------------

    public function getGlobalDashboardWidgets(?int $clientId = null, bool $canViewControlled = false): array
    {
        $widgets = [
            'overdue_meds' => $this->getOverdueMedsWidget($clientId),
            'prn_near_limits' => $this->getPrnNearLimitsWidget($clientId),
            'expiring_medications' => $this->getExpiringMedicationsWidget($clientId),
            'high_risk_medications' => $this->getHighRiskMedicationsWidget($clientId),
            'todays_summary' => $this->getTodaysSummaryWidget($clientId),
        ];

        if ($canViewControlled) {
            $widgets['controlled_discrepancies'] = $this->getControlledDiscrepanciesWidget($clientId);
        }

        return $widgets;
    }

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

    private function getTodaysSummaryWidget(?int $clientId = null): array
    {
        $today = now()->startOfDay();
        $tomorrow = $today->copy()->addDay();

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
        $totalScheduled = 0;
        foreach ($scheduledMeds as $med) {
            $totalScheduled += count($med->dose_times ?? []);
        }

        $completedQuery = ClientMedicationAdministration::where('status', 'given')
            ->whereNotNull('scheduled_for')
            ->whereBetween('scheduled_for', [$today, $tomorrow])
            ->whereHas('medication', function ($q) {
                $q->active()->where('is_prn', false);
            });

        if ($clientId) {
            $completedQuery->where('client_id', $clientId);
        }

        $completed = $completedQuery->distinct(['client_medication_id', 'scheduled_for'])->count();

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

    // -----------------------------------------------------------------------
    // Alert management — retained for MedicationDashboardAlert UI compat
    // -----------------------------------------------------------------------

    public function acknowledgeAlert(int $alertId, int $userId): bool
    {
        $alert = MedicationDashboardAlert::find($alertId);

        if (! $alert || $alert->status !== 'active') {
            return false;
        }

        $alert->acknowledge($userId);

        return true;
    }

    public function resolveAlert(int $alertId, ?string $notes = null): bool
    {
        $alert = MedicationDashboardAlert::find($alertId);

        if (! $alert) {
            return false;
        }

        $alert->resolve($notes);

        return true;
    }

    public function clearStaleAlerts(): int
    {
        $count = 0;

        $prnAlerts = MedicationDashboardAlert::where('alert_type', 'like', 'prn_%')
            ->where('status', 'active')
            ->get();

        foreach ($prnAlerts as $alert) {
            $medication = ClientMedication::find($alert->client_medication_id);
            if ($medication && ! $medication->isPrnNearLimit()) {
                $alert->resolve('Auto-resolved: PRN count below threshold');
                $count++;
            }
        }

        $expiryAlerts = MedicationDashboardAlert::whereIn('alert_type', ['expired', 'expiring_soon'])
            ->where('status', 'active')
            ->get();

        foreach ($expiryAlerts as $alert) {
            $medication = ClientMedication::find($alert->client_medication_id);
            if (! $medication || $medication->state === 'ceased' || $medication->superseded_by) {
                $alert->resolve('Auto-resolved: Medication ceased or updated');
                $count++;
            }
        }

        return $count;
    }
}
