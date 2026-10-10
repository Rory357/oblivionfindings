<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ControlRoomAlert;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationReview;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Alerts\MedicationAlertSources;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationSignalService;
use App\Services\Medication\OverdueDoseAlerts;
use App\Support\Medication\MedicationStockQuantity;
use App\Support\WorkerClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
    private const CONTROLLED_ALERT_TYPES = [
        'controlled_discrepancy',
        'controlled_overdue_check',
        'controlled_loss',
    ];

    public function __construct(
        protected ?MedicationSignalService $signalService = null,
        protected ?MedicationGovernanceScopeService $governanceScope = null,
    ) {
        $this->signalService ??= app(MedicationSignalService::class);
        $this->governanceScope ??= app(MedicationGovernanceScopeService::class);
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
            // Suppressed: none of their doses is overdue, so their open
            // overdue alerts resolve (C6f).
            $this->checkOverdueDoses($client);
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

        // People are told — as-needed limits, reviews due, open discrepancies
        // — as Medication Settings › Alerts & access says (P11 B2).
        app(MedicationAlertSources::class)->forClient($client, $medications);

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

        $daysUntilDue = WorkerClock::daysUntil($latest->next_test_date);
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
            $daysUntilReview = WorkerClock::daysUntil($client->next_chart_review_date);
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

        $reviewWindowEnd = WorkerClock::today()->addDays(7)->toDateString();
        $review = MedicationReview::query()
            ->where('client_id', $client->id)
            ->whereIn('status', ['scheduled', 'overdue'])
            ->where(function ($query) use ($reviewWindowEnd) {
                $query->whereDate('scheduled_date', '<=', $reviewWindowEnd)
                    ->orWhereDate('next_review_date', '<=', $reviewWindowEnd);
            })
            ->orderByRaw('COALESCE(next_review_date, scheduled_date) asc')
            ->first();

        if ($review) {
            $dueDate = $review->next_review_date ?? $review->scheduled_date;
            $daysUntilReview = WorkerClock::daysUntil($dueDate);
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
                "{$medication->unrestrictedName()}: PRN limit reached ({$count}/{$maxPerDay})",
                [
                    'client_medication_id' => $medication->id,
                    'medication_name' => $medication->unrestrictedName(),
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
                "{$medication->name}: Medication ended on {$medication->end_date->format('d/m/Y')}",
                $medication->id
            );

            // Operational signal → Control Room
            $this->signalService->emit(
                MedicationSignalService::TYPE_EXPIRED,
                $client->id,
                'high',
                "{$medication->unrestrictedName()}: Medication ended on {$medication->end_date->format('d/m/Y')}",
                [
                    'client_medication_id' => $medication->id,
                    'medication_name' => $medication->unrestrictedName(),
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
            $daysRemaining = $medication->daysUntilEnd();
            $expires = $daysRemaining === 0
                ? 'Expires today'
                : "Expires in {$daysRemaining} ".Str::plural('day', $daysRemaining);
            $alert = MedicationDashboardAlert::createOrUpdateAlert(
                $client->id,
                'expiring_soon',
                'warning',
                "{$medication->name}: {$expires} ({$medication->end_date->format('d/m/Y')})",
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

        if (! $stock || $stock->usableQuantity() === null) {
            return null;
        }

        if (MedicationStockQuantity::equals($stock->usableQuantity(), 0)) {
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
                "{$medication->unrestrictedName()}: OUT OF STOCK — client cannot receive scheduled doses",
                [
                    'client_medication_id' => $medication->id,
                    'medication_name' => $medication->unrestrictedName(),
                    'controlled_drug' => $medication->controlled_drug,
                    'high_risk' => $medication->high_risk,
                    'site_id' => $client->site_id,
                ],
            );

            return $alert->toArray();
        }

        if ($stock->isLowStock()) {
            // Low stock is dashboard-only — NOT operational
            $alert = MedicationDashboardAlert::createOrUpdateAlert(
                $client->id,
                'stock_low',
                'warning',
                "{$medication->name}: Low stock ({$stock->available_on_hand} {$stock->unit} remaining)",
                $medication->id
            );

            return $alert->toArray();
        }

        return null;
    }

    /**
     * Check for overdue doses → always operational.
     *
     * C6f: the doses the overdue job, Meds today, My Day and the badge call
     * overdue — the dose-slot projection's window has ended with nothing
     * recorded (EM-02 (1): on the New Zealand clock) — raised as one signal
     * per dose, and the person's alerts for doses no longer overdue resolved.
     */
    private function checkOverdueDoses(Client $client): ?array
    {
        return app(OverdueDoseAlerts::class)->syncClient($client, now());
    }

    /**
     * Check for controlled drug discrepancies → always operational.
     */
    private function checkControlledDiscrepancies(Client $client): ?array
    {
        $openDiscrepancies = $this->governanceScope->scopeCanonicalClientMedicationRows(
            ClientControlledDrugDiscrepancy::query(),
            $client->site_id ? [(int) $client->site_id] : [],
            false,
        )
            ->where('client_id', $client->id)
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

        // Operational signal → Control Room. The medicine names stay on the
        // controlled-only dashboard alert above (EM-12).
        $this->signalService->emit(
            MedicationSignalService::TYPE_CONTROLLED_DISCREPANCY,
            $client->id,
            'critical',
            "Controlled drug discrepancy: {$openDiscrepancies->count()} open. Review required.",
            [
                'client_medication_id' => $openDiscrepancies->first()->client_medication_id,
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

    /**
     * @param  array<int, int>|null  $siteIds  Null is reserved for internal
     *                                         unscoped callers; an explicit empty array must return zero rows.
     */
    /**
     * @param  array<int, int>|null  $readableClientIds  The people the reader may
     *                                                    open (EA-107); null = no person rule (internal use).
     */
    public function getGlobalDashboardWidgets(
        ?int $clientId = null,
        ?array $siteIds = null,
        bool $canViewControlled = false,
        ?array $readableClientIds = null,
    ): array {
        $this->readableClientIds = $readableClientIds;
        $widgets = [
            'overdue_meds' => $this->getOverdueMedsWidget($clientId, $siteIds, $canViewControlled),
            'prn_near_limits' => $this->getPrnNearLimitsWidget($clientId, $siteIds, $canViewControlled),
            'expiring_medications' => $this->getExpiringMedicationsWidget($clientId, $siteIds, $canViewControlled),
            'high_risk_medications' => $this->getHighRiskMedicationsWidget($clientId, $siteIds, $canViewControlled),
            'todays_summary' => $this->getTodaysSummaryWidget($clientId, $siteIds, $canViewControlled),
        ];

        if ($canViewControlled) {
            $widgets['controlled_discrepancies'] = $this->getControlledDiscrepanciesWidget($clientId, $siteIds);
        }
        $this->readableClientIds = null;

        return $widgets;
    }

    /** @var array<int, int>|null The person rule for one widget build (EA-107). */
    private ?array $readableClientIds = null;

    /** One person when asked; otherwise only people the reader may open. */
    private function narrowToClients($query, ?int $clientId): void
    {
        if ($clientId) {
            $query->where('client_id', $clientId);
        }
        if ($this->readableClientIds !== null) {
            $query->whereIn('client_id', $this->readableClientIds === [] ? [0] : $this->readableClientIds);
        }
    }

    /** @param array<int, int>|null $siteIds */
    private function getOverdueMedsWidget(
        ?int $clientId = null,
        ?array $siteIds = null,
        bool $canViewControlled = false,
    ): array {
        $query = MedicationDashboardAlert::where('alert_type', 'overdue')
            ->where('status', 'active');
        if ($siteIds !== null) {
            $query = $this->governanceScope->scopeCanonicalClientMedicationRows($query, $siteIds);
        }
        if (! $canViewControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($query);
        }
        $query->with('client:id,first_name,last_name');

        $this->narrowToClients($query, $clientId);

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

    /** @param array<int, int>|null $siteIds */
    private function getPrnNearLimitsWidget(
        ?int $clientId = null,
        ?array $siteIds = null,
        bool $canViewControlled = false,
    ): array {
        $query = MedicationDashboardAlert::whereIn('alert_type', ['prn_near_limit', 'prn_over_limit'])
            ->where('status', 'active');
        if ($siteIds !== null) {
            $query = $this->governanceScope->scopeCanonicalClientMedicationRows($query, $siteIds);
        }
        if (! $canViewControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($query);
        }
        $query->with(['client:id,first_name,last_name', 'medication:id,name']);

        $this->narrowToClients($query, $clientId);

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

    /** @param array<int, int>|null $siteIds */
    private function getControlledDiscrepanciesWidget(?int $clientId = null, ?array $siteIds = null): array
    {
        $query = ClientControlledDrugDiscrepancy::whereIn('status', ['open', 'under_review']);
        if ($siteIds !== null) {
            $query = $this->governanceScope->scopeCanonicalClientMedicationRows($query, $siteIds, false);
        }
        $query->with(['client:id,first_name,last_name', 'medication:id,name']);

        $this->narrowToClients($query, $clientId);

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

    /** @param array<int, int>|null $siteIds */
    private function getExpiringMedicationsWidget(
        ?int $clientId = null,
        ?array $siteIds = null,
        bool $canViewControlled = false,
    ): array {
        $query = ClientMedication::active()
            ->when($siteIds !== null, fn ($query) => $query->whereHas(
                'client',
                fn ($client) => $client->whereIn('site_id', $siteIds),
            ))
            ->when(! $canViewControlled, fn ($query) => $query->where('controlled_drug', false))
            ->whereNotNull('end_date')
            ->where('end_date', '<=', WorkerClock::today()->addDays(14)->toDateString())
            ->where('end_date', '>=', WorkerClock::today()->toDateString())
            ->with('client:id,first_name,last_name');

        $this->narrowToClients($query, $clientId);

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
                'days_remaining' => $m->daysUntilEnd(),
            ])->toArray(),
        ];
    }

    /** @param array<int, int>|null $siteIds */
    private function getHighRiskMedicationsWidget(
        ?int $clientId = null,
        ?array $siteIds = null,
        bool $canViewControlled = false,
    ): array {
        $query = ClientMedication::active()
            ->when($siteIds !== null, fn ($query) => $query->whereHas(
                'client',
                fn ($client) => $client->whereIn('site_id', $siteIds),
            ))
            ->when(! $canViewControlled, fn ($query) => $query->where('controlled_drug', false))
            ->where('high_risk', true)
            ->with('client:id,first_name,last_name');

        $this->narrowToClients($query, $clientId);

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

    /** @param array<int, int>|null $siteIds */
    private function getTodaysSummaryWidget(
        ?int $clientId = null,
        ?array $siteIds = null,
        bool $canViewControlled = false,
    ): array {
        $today = now()->startOfDay();
        $tomorrow = $today->copy()->addDay();

        $scheduledQuery = ClientMedication::active()
            ->when($siteIds !== null, fn ($query) => $query->whereHas(
                'client',
                fn ($client) => $client->whereIn('site_id', $siteIds),
            ))
            ->when(! $canViewControlled, fn ($query) => $query->where('controlled_drug', false))
            ->where('is_prn', false)
            ->where(function ($q) use ($today) {
                $q->whereNull('start_date')->orWhere('start_date', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $today);
            });

        $this->narrowToClients($scheduledQuery, $clientId);

        $scheduledMeds = $scheduledQuery->get();
        $totalScheduled = 0;
        foreach ($scheduledMeds as $med) {
            $totalScheduled += count($med->dose_times ?? []);
        }

        $completedQuery = ClientMedicationAdministration::query()
            ->effectiveClinicalEvidence()
            ->where('status', 'given')
            ->whereNotNull('scheduled_for')
            ->whereBetween('scheduled_for', [$today, $tomorrow])
            ->whereHas('medication', function ($q) {
                $q->active()->where('is_prn', false);
            });
        if ($siteIds !== null) {
            $completedQuery = $this->governanceScope
                ->scopeCanonicalClientMedicationRows($completedQuery, $siteIds, false);
        }
        if (! $canViewControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($completedQuery);
        }

        $this->narrowToClients($completedQuery, $clientId);

        $completed = $completedQuery->distinct(['client_medication_id', 'scheduled_for'])->count();

        $refusedQuery = ClientMedicationAdministration::query()
            ->effectiveClinicalEvidence()
            ->where('status', 'refused')
            ->whereBetween('scheduled_for', [$today, $tomorrow])
            ->whereHas('medication', function ($q) {
                $q->active()->where('is_prn', false);
            });
        if ($siteIds !== null) {
            $refusedQuery = $this->governanceScope
                ->scopeCanonicalClientMedicationRows($refusedQuery, $siteIds, false);
        }
        if (! $canViewControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($refusedQuery);
        }

        $missedQuery = ClientMedicationAdministration::query()
            ->effectiveClinicalEvidence()
            ->where('status', 'missed')
            ->whereBetween('scheduled_for', [$today, $tomorrow])
            ->whereHas('medication', function ($q) {
                $q->active()->where('is_prn', false);
            });
        if ($siteIds !== null) {
            $missedQuery = $this->governanceScope
                ->scopeCanonicalClientMedicationRows($missedQuery, $siteIds, false);
        }
        if (! $canViewControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($missedQuery);
        }

        $this->narrowToClients($refusedQuery, $clientId);
        $this->narrowToClients($missedQuery, $clientId);

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

    public function acknowledgeAlert(MedicationDashboardAlert $alert, User $actor): bool
    {
        return DB::transaction(function () use ($actor, $alert): bool {
            [$lockedAlert, $lockedActor] = $this->lockCanonicalAlert($alert, $actor);

            if ($lockedAlert->status === 'acknowledged') {
                return true;
            }
            if ($lockedAlert->status !== 'active') {
                return false;
            }

            $lockedAlert->acknowledge((int) $lockedActor->id);

            return true;
        }, 3);
    }

    public function resolveAlert(
        MedicationDashboardAlert $alert,
        ?string $notes,
        User $actor,
    ): bool {
        return DB::transaction(function () use ($actor, $alert, $notes): bool {
            [$lockedAlert, $lockedActor] = $this->lockCanonicalAlert($alert, $actor);
            $normalizedNotes = filled($notes) ? trim((string) $notes) : null;

            if ($lockedAlert->status === 'resolved') {
                if (($lockedAlert->resolution_notes ?: null) !== $normalizedNotes) {
                    throw ValidationException::withMessages([
                        'resolution_notes' => 'This alert was already resolved with different resolution notes.',
                    ]);
                }

                return true;
            }
            if (! in_array($lockedAlert->status, ['active', 'acknowledged'], true)) {
                return false;
            }

            $lockedAlert->resolve($normalizedNotes);
            AuditLogger::logOrFail('medication_dashboard_alert.resolved', $lockedAlert, [
                'actor_id' => (int) $lockedActor->id,
                'resolved_by' => (int) $lockedActor->id,
            ]);

            return true;
        }, 3);
    }

    /**
     * Snapshot immutable identity without locking, then lock the shared
     * medication graph as Client, medication, current actor/RBAC/Profile,
     * active Site, constrained alert.
     *
     * @return array{0: MedicationDashboardAlert, 1: User}
     */
    private function lockCanonicalAlert(MedicationDashboardAlert $snapshot, User $actor): array
    {
        $identity = MedicationDashboardAlert::query()
            ->whereKey($snapshot->id)
            ->firstOrFail(['id', 'client_id', 'client_medication_id', 'alert_type']);
        $client = Client::query()
            ->whereKey($identity->client_id)
            ->lockForUpdate()
            ->firstOrFail();

        $medication = null;
        if ($identity->client_medication_id !== null) {
            $medication = ClientMedication::query()
                ->whereKey($identity->client_medication_id)
                ->where('client_id', $client->id)
                ->lockForUpdate()
                ->firstOrFail();
        }

        $controlled = in_array((string) $identity->alert_type, self::CONTROLLED_ALERT_TYPES, true)
            || (bool) $medication?->controlled_drug;
        $lockedActor = $this->governanceScope->lockCurrentAlertActor(
            $actor,
            (int) $client->site_id,
            $controlled,
        );

        Site::query()
            ->whereKey($client->site_id)
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->lockForUpdate()
            ->firstOrFail();

        $lockedAlertQuery = MedicationDashboardAlert::query()
            ->whereKey($snapshot->id)
            ->where('client_id', $client->id)
            ->where('alert_type', $identity->alert_type);
        if ($identity->client_medication_id === null) {
            $lockedAlertQuery->whereNull('client_medication_id');
        } else {
            $lockedAlertQuery->where('client_medication_id', $identity->client_medication_id);
        }
        $lockedAlert = $lockedAlertQuery->lockForUpdate()->firstOrFail();

        return [$lockedAlert, $lockedActor];
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
