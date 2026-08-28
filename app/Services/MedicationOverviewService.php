<?php

namespace App\Services;

use App\Enums\Medication\NotGivenReason;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientInrRecord;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationError;
use App\Models\MedicationReview;
use App\Models\MedicationRound;
use App\Models\MedicationSyringeDriver;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Support\Medication\MedicationStockQuantity;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Single source of truth for the eMAR home dashboard (route emar.index).
 *
 * Extracts the maths that used to live inline in EmarController::dashboard() and
 * adds the clinical-watch / ops widgets and the unified, severity-sorted
 * Action-centre feed that the merged /emar page renders. Reuses the existing
 * domain models — it does not introduce a second administration pipeline; all
 * writes still flow through EnhancedMarService via the existing POST routes.
 */
class MedicationOverviewService
{
    /** Severity rank for Action-centre ordering (lower = more urgent). */
    private const SEVERITY_RANK = ['critical' => 0, 'warning' => 1, 'info' => 2];

    /** @var array<int, int>|null */
    private ?array $readerClientIds = null;

    /** @var array<int, int>|null */
    private ?array $readerSiteIds = null;

    private bool $includeControlled = true;

    public function __construct(private readonly MedicationGovernanceScopeService $governanceScope) {}

    /**
     * Full Inertia payload for the merged eMAR home page.
     */
    public function payload(?Carbon $date = null, ?User $actor = null): array
    {
        $this->includeControlled = $actor === null
            || $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY);
        if ($actor !== null) {
            $this->readerSiteIds = $this->governanceScope->readerSiteIds(
                $actor,
                MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            );
            $this->readerClientIds = Client::query()
                ->whereIn('site_id', $this->readerSiteIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $date = ($date ?? today())->copy()->startOfDay();

        $stats = $this->stats($date);
        $trend = $this->trend($date);
        $includeControlledErrors = $this->includeControlled;

        return [
            'date' => $date->toDateString(),
            'isToday' => $date->isSameDay(today()),
            'dateTitle' => $date->translatedFormat('l j F'),
            'nowLabel' => now()->format('g:i A'),
            'stats' => $stats,
            'trend' => $trend,
            'complianceTrend' => $this->complianceTrend($trend),
            'outcomeBreakdown' => $this->outcomeBreakdown($stats),
            'codedNotGivenReasons' => $this->codedNotGivenReasons($date),
            'actionCentre' => $this->actionCentre($date, $includeControlledErrors),
            'clientBoard' => $this->clientBoard($date),
            'inrWatch' => $this->inrWatch(),
            'syringeDrivers' => $this->syringeDrivers(),
            'reviewsDue' => $this->reviewsDue(),
            'medicationErrors' => $this->medicationErrors($date, $includeControlledErrors),
            'overdueMedications' => $this->overdueMedications($date),
            'nextRound' => $this->nextRound($date),
            'recentActivity' => $this->recentActivity(),
            'activeAlertsList' => $this->activeAlertsList(),
            'compliance' => $this->complianceSnapshot(),
            'clientOptions' => $this->clientOptions(),
            'medicationOptions' => $this->medicationOptions(),
            'witnesses' => $this->witnesses(),
            'notGivenReasons' => NotGivenReason::options(),
        ];
    }

    /** @return array<int, int> */
    private function allowedClientIds(): array
    {
        return $this->readerClientIds ??= Client::query()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return array<int, int> */
    private function allowedSiteIds(): array
    {
        return $this->readerSiteIds ??= Client::query()
            ->whereIn('id', $this->allowedClientIds())
            ->pluck('site_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return Builder<*> */
    private function canonicalMedicationRows(Builder $query, bool $allowNullMedication = false): Builder
    {
        return $this->governanceScope->scopeCanonicalClientMedicationRows(
            $query,
            $this->allowedSiteIds(),
            $allowNullMedication,
        );
    }

    /** @return Builder<ClientMedicationAdministration> */
    private function effectiveAdministrationRows(Builder $query): Builder
    {
        $query = $this->canonicalMedicationRows(
            $query->effectiveClinicalEvidence(),
            false,
        );

        if (! $this->includeControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($query);
        }

        return $query;
    }

    /**
     * Active medications across the clients with a chart, for the CD/stock modal
     * pickers. Flat list the frontend filters by selected client.
     */
    public function medicationOptions(): array
    {
        return ClientMedication::active()
            ->whereIn('client_id', $this->allowedClientIds())
            ->orderBy('name')
            ->get(['id', 'client_id', 'name', 'dose_unit', 'controlled_drug'])
            ->map(fn ($med) => [
                'id' => $med->id,
                'client_id' => $med->client_id,
                'name' => $med->name,
                'unit' => $med->dose_unit,
                'controlled' => (bool) $med->controlled_drug,
            ])
            ->all();
    }

    /**
     * Staff eligible to witness a controlled-drug entry (mirrors the meds/today
     * + EmarController witness list: staff with medications.controlled.witness).
     */
    public function witnesses(): array
    {
        return $this->governanceScope->controlledWitnessPicker($this->allowedSiteIds())->all();
    }

    /**
     * Lightweight client picker list for the page's modals (clients with an
     * active medication chart). id + display name + site.
     */
    public function clientOptions(): array
    {
        return Client::query()
            ->whereIn('id', $this->allowedClientIds())
            ->whereHas('medications', fn ($q) => $q->active())
            ->with('site:id,name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'site_id'])
            ->map(fn ($client) => [
                'id' => $client->id,
                'name' => $this->clientName($client),
                'site' => $client->site?->name,
            ])
            ->all();
    }

    // ─── KPI stats ─────────────────────────────────────────

    public function stats(Carbon $date): array
    {
        $admins = $this->effectiveAdministrationRows(ClientMedicationAdministration::query())
            ->whereIn('client_id', $this->allowedClientIds())
            ->where(fn ($query) => $query
                ->whereDate('scheduled_for', $date)
                ->orWhereDate('administered_at', $date))
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'given' THEN 1 ELSE 0 END) as given,
                SUM(CASE WHEN status = 'refused' THEN 1 ELSE 0 END) as refused,
                SUM(CASE WHEN status = 'withheld' THEN 1 ELSE 0 END) as withheld,
                SUM(CASE WHEN status = 'missed' THEN 1 ELSE 0 END) as missed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
            ")->first();

        $total = (int) ($admins->total ?? 0);
        $given = (int) ($admins->given ?? 0);
        $pending = (int) ($admins->pending ?? 0);

        $overdue = $this->effectiveAdministrationRows(ClientMedicationAdministration::query())
            ->where('status', 'pending')
            ->whereIn('client_id', $this->allowedClientIds())
            ->where('scheduled_for', '<', now()->subMinutes(60))
            ->whereDate('scheduled_for', $date)
            ->count();

        $prnToday = $this->effectiveAdministrationRows(ClientMedicationAdministration::query())
            ->whereDate('administered_at', $date)
            ->whereIn('client_id', $this->allowedClientIds())
            ->where('status', 'given')
            ->whereHas('medication', fn ($q) => $q->where('is_prn', true))
            ->count();

        $cdDiscrepancies = $this->canonicalMedicationRows(ClientControlledDrugDiscrepancy::query())
            ->whereIn('client_id', $this->allowedClientIds())
            ->whereIn('status', ['open', 'under_review'])
            ->count();

        $reviewsDue = MedicationReview::due()
            ->whereIn('client_id', $this->allowedClientIds())
            ->whereDate('scheduled_date', '<=', $date->copy()->addDays(7)->toDateString())
            ->count();

        $expiringCompetencies = $this->competencyAssessmentQuery()
            ->where('status', 'passed')
            ->whereBetween('expiry_date', [today()->toDateString(), today()->copy()->addDays(30)->toDateString()])
            ->count();

        $allowedClientIds = $this->allowedClientIds();
        $stockForAllowedClients = fn () => ClientMedicationStock::whereHas(
            'medication',
            fn ($q) => $q
                ->active()
                ->whereIn('client_id', $allowedClientIds)
                ->when(! $this->includeControlled, fn ($query) => $query->where('controlled_drug', false)),
        );
        $lowStock = $stockForAllowedClients()->lowStock()->count();
        $expiringStock = $stockForAllowedClients()->expiringSoon()->count();
        $expiredStock = $stockForAllowedClients()->expired()->count();

        $trend = $this->trend($date);

        return [
            'totalToday' => $total,
            'givenToday' => $given,
            'refusedToday' => (int) ($admins->refused ?? 0),
            'withheldToday' => (int) ($admins->withheld ?? 0),
            'missedToday' => (int) ($admins->missed ?? 0),
            'pendingToday' => $pending,
            'adminRate' => $total > 0 ? round(($given / $total) * 100, 1) : 0.0,
            'dueNow' => $pending,
            'overdue' => $overdue,
            'missed' => (int) ($admins->missed ?? 0),
            'prnToday' => $prnToday,
            'controlledCount' => ClientMedication::active()->controlled()->whereIn('client_id', $allowedClientIds)->count(),
            'cdDue' => ClientMedication::active()->controlled()->whereIn('client_id', $allowedClientIds)->count(),
            'activeDiscrepancies' => $cdDiscrepancies,
            'reviewsDue' => $reviewsDue,
            'overdueReviews' => MedicationReview::overdue()->whereIn('client_id', $allowedClientIds)->count(),
            'competenciesExpiring' => $expiringCompetencies,
            'expiringCompetencies' => $expiringCompetencies,
            'stockAlerts' => $lowStock + $expiringStock + $expiredStock,
            'lowStock' => $lowStock,
            'expiringStock' => $expiringStock,
            'expiredStock' => $expiredStock,
            'activeAlerts' => $this->visibleAlertRows()
                ->whereIn('client_id', $allowedClientIds)
                ->where('status', 'active')
                ->count(),
            'activeMedications' => ClientMedication::active()
                ->whereIn('client_id', $allowedClientIds)
                ->when(! $this->includeControlled, fn ($query) => $query->where('controlled_drug', false))
                ->count(),
            'activeClients' => Client::whereIn('id', $allowedClientIds)
                ->whereHas('medications', fn ($q) => $q
                    ->active()
                    ->when(! $this->includeControlled, fn ($query) => $query->where('controlled_drug', false)))
                ->count(),
            'roundsToday' => MedicationRound::forDate($date)->whereIn('site_id', $this->allowedSiteIds())->count(),
            'roundsCompleted' => MedicationRound::forDate($date)->whereIn('site_id', $this->allowedSiteIds())->where('status', 'completed')->count(),
            'givenTrend' => array_map(fn ($d) => $d['given'], $trend),
        ];
    }

    // ─── 7-day trend ───────────────────────────────────────

    public function trend(Carbon $date): array
    {
        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = $date->copy()->subDays($i);
            $dayStats = $this->effectiveAdministrationRows(ClientMedicationAdministration::query())
                ->whereIn('client_id', $this->allowedClientIds())
                ->where(fn ($query) => $query
                    ->whereDate('scheduled_for', $day)
                    ->orWhereDate('administered_at', $day))
                ->selectRaw("
                    SUM(CASE WHEN status = 'given' THEN 1 ELSE 0 END) as given,
                    SUM(CASE WHEN status = 'refused' THEN 1 ELSE 0 END) as refused,
                    SUM(CASE WHEN status = 'missed' THEN 1 ELSE 0 END) as missed,
                    COUNT(*) as total
                ")->first();

            $given = (int) ($dayStats->given ?? 0);
            $total = (int) ($dayStats->total ?? 0);

            $trend[] = [
                'date' => $day->format('D'),
                'given' => $given,
                'refused' => (int) ($dayStats->refused ?? 0),
                'missed' => (int) ($dayStats->missed ?? 0),
                'total' => $total,
                'rate' => $total > 0 ? round(($given / $total) * 100, 1) : 0.0,
            ];
        }

        return $trend;
    }

    private function complianceTrend(array $trend): array
    {
        return array_map(fn ($d) => [
            'day' => $d['date'],
            'rate' => $d['rate'],
            'given' => $d['given'],
            'total' => $d['total'],
        ], $trend);
    }

    // ─── Outcome breakdown (today's med-pass donut) ────────

    public function outcomeBreakdown(array $stats): array
    {
        $segments = [
            ['key' => 'given', 'label' => 'Given', 'count' => $stats['givenToday'], 'tone' => 'success'],
            ['key' => 'pending', 'label' => 'Pending', 'count' => $stats['pendingToday'], 'tone' => 'muted'],
            ['key' => 'refused', 'label' => 'Refused', 'count' => $stats['refusedToday'], 'tone' => 'warning'],
            ['key' => 'missed', 'label' => 'Missed', 'count' => $stats['missedToday'], 'tone' => 'critical'],
            ['key' => 'withheld', 'label' => 'Withheld', 'count' => $stats['withheldToday'], 'tone' => 'slate'],
        ];

        $total = (int) $stats['totalToday'];

        return [
            'total' => $total,
            'givenPct' => $total > 0 ? round(($stats['givenToday'] / $total) * 100) : 0,
            'segments' => $segments,
        ];
    }

    // ─── Coded "reason not given" (last 7 days) ────────────

    public function codedNotGivenReasons(Carbon $date): array
    {
        $counts = $this->effectiveAdministrationRows(ClientMedicationAdministration::query())
            ->whereIn('status', ['refused', 'withheld', 'missed'])
            ->whereIn('client_id', $this->allowedClientIds())
            ->whereNotNull('reason_code')
            ->whereDate('scheduled_for', '>=', $date->copy()->subDays(6)->toDateString())
            ->selectRaw('reason_code, COUNT(*) as c')
            ->groupBy('reason_code')
            ->pluck('c', 'reason_code');

        return $counts
            ->map(function ($count, $code) {
                $reason = NotGivenReason::tryFrom($code);

                return [
                    'code' => $code,
                    'label' => $reason?->label() ?? ucfirst(str_replace('_', ' ', (string) $code)),
                    'count' => (int) $count,
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->take(6)
            ->all();
    }

    // ─── Unified Action-centre feed ────────────────────────

    /**
     * Normalised, severity-sorted feed of everything needing a clinician now.
     * Item shape: {id,type,category,code,severity,client,client_id,title,
     * status,summary,action,action_type,opened_at}.
     */
    public function actionCentre(Carbon $date, bool $includeControlledErrors = true): array
    {
        $items = collect()
            ->merge($this->overdueDoseItems($date))
            ->merge($this->cdDiscrepancyItems())
            ->merge($this->inrOutOfRangeItems())
            ->merge($this->overdueReviewItems())
            ->merge($this->cdBalanceCheckItems($date))
            ->merge($this->stockItems())
            ->merge($this->medicationErrorItems($includeControlledErrors));

        return $items
            ->sortBy([
                fn ($a, $b) => (self::SEVERITY_RANK[$a['severity']] ?? 9) <=> (self::SEVERITY_RANK[$b['severity']] ?? 9),
                fn ($a, $b) => ($a['opened_at'] ?? '') <=> ($b['opened_at'] ?? ''),
            ])
            ->values()
            ->all();
    }

    private function overdueDoseItems(Carbon $date): Collection
    {
        return $this->overdueMedications($date)->map(fn ($admin) => [
            'id' => 'dose-'.$admin->id,
            'type' => 'overdue_dose',
            'category' => 'doses',
            'code' => 'MED',
            'severity' => 'critical',
            'client' => $this->clientName($admin->client),
            'client_id' => $admin->client_id,
            'is_controlled' => (bool) $admin->medication?->controlled_drug,
            'title' => $this->clientName($admin->client).' — '.($admin->medication->name ?? 'Medication')
                .($admin->medication->dosage ? ' '.$admin->medication->dosage : ''),
            'status' => 'Overdue',
            'summary' => 'Scheduled '.optional($admin->scheduled_for)->format('H:i')
                .($admin->scheduled_for ? ' · '.$admin->scheduled_for->diffForHumans(now(), ['parts' => 1]) : ''),
            'action' => 'Record',
            'action_type' => 'record',
            'opened_at' => optional($admin->scheduled_for)->toIso8601String(),
            'record' => $this->buildRecordContext($admin, $date),
        ]);
    }

    private function cdDiscrepancyItems(): Collection
    {
        return $this->canonicalMedicationRows(ClientControlledDrugDiscrepancy::query())
            ->whereIn('status', ['open', 'under_review'])
            ->whereIn('client_id', $this->allowedClientIds())
            ->with(['client:id,first_name,last_name', 'medication:id,name'])
            ->latest('reported_at')
            ->limit(10)
            ->get()
            ->map(fn ($d) => [
                'id' => 'cd-'.$d->id,
                'type' => 'cd_discrepancy',
                'category' => 'controlled',
                'code' => 'CD',
                'severity' => $d->status === 'open' ? 'critical' : 'warning',
                'client' => $this->clientName($d->client),
                'client_id' => $d->client_id,
                'title' => $this->clientName($d->client).' — '.($d->medication->name ?? 'Controlled drug').' discrepancy',
                'status' => $d->status === 'open' ? 'Discrepancy' : 'Under review',
                'summary' => trim(($d->reason ? $d->reason.' · ' : '').'Difference '.$d->difference),
                'action' => 'Investigate',
                'action_type' => 'resolve',
                'opened_at' => optional($d->reported_at)->toIso8601String(),
                'meta' => ['discrepancy_id' => $d->id],
            ]);
    }

    private function inrOutOfRangeItems(): Collection
    {
        return $this->latestInrPerClient()
            ->filter(fn ($r) => $this->inrStatus($r) !== 'in_range')
            ->map(function ($r) {
                $status = $this->inrStatus($r);

                return [
                    'id' => 'inr-'.$r->id,
                    'type' => 'inr',
                    'category' => 'clinical',
                    'code' => 'INR',
                    'severity' => $status === 'above' ? 'critical' : 'warning',
                    'client' => $this->clientName($r->client),
                    'client_id' => $r->client_id,
                    'title' => $this->clientName($r->client).' — INR '.(float) $r->inr_value,
                    'status' => $status === 'above' ? 'Above range' : 'Below range',
                    'summary' => 'Target '.$this->inrTargetLabel($r)
                        .' · tested '.optional($r->tested_on)->format('j M'),
                    'action' => 'Review',
                    'action_type' => 'review',
                    'opened_at' => optional($r->tested_on)->toIso8601String(),
                ];
            })
            ->values();
    }

    private function overdueReviewItems(): Collection
    {
        return MedicationReview::overdue()
            ->whereIn('client_id', $this->allowedClientIds())
            ->with('client:id,first_name,last_name')
            ->orderBy('scheduled_date')
            ->limit(10)
            ->get()
            ->map(fn ($review) => [
                'id' => 'review-'.$review->id,
                'type' => 'review',
                'category' => 'clinical',
                'code' => 'REV',
                'severity' => 'warning',
                'client' => $this->clientName($review->client),
                'client_id' => $review->client_id,
                'title' => $this->clientName($review->client).' — '.($review->review_type ?: 'Medication review'),
                'status' => 'Review overdue',
                'summary' => 'Was due '.optional($review->scheduled_date)->format('j M')
                    .($review->scheduled_date ? ' · '.$review->scheduled_date->diffForHumans() : ''),
                'action' => 'Review',
                'action_type' => 'complete_review',
                'opened_at' => optional($review->scheduled_date)->toIso8601String(),
                'meta' => ['review_id' => $review->id],
            ]);
    }

    private function cdBalanceCheckItems(Carbon $date): Collection
    {
        // Active controlled meds whose latest balance-check CD entry is not today.
        $checkedTodayMedIds = ClientControlledDrugEntry::where('entry_type', 'balance_check')
            ->whereIn('client_id', $this->allowedClientIds())
            ->whereDate('recorded_at', $date)
            ->pluck('client_medication_id')
            ->filter()
            ->all();

        return ClientMedication::active()->controlled()
            ->whereIn('client_id', $this->allowedClientIds())
            ->whereNotIn('id', $checkedTodayMedIds)
            ->with('client:id,first_name,last_name,site_id', 'client.site:id,name')
            ->limit(8)
            ->get()
            ->map(fn ($med) => [
                'id' => 'cdbal-'.$med->id,
                'type' => 'cd_balance',
                'category' => 'controlled',
                'code' => 'CD',
                'severity' => 'info',
                'client' => $this->clientName($med->client),
                'client_id' => $med->client_id,
                'title' => ($med->client?->site?->name ?: $this->clientName($med->client)).' — CD balance check due',
                'status' => 'Balance check',
                'summary' => $med->name.' · no balance count recorded today',
                'action' => 'Start count',
                'action_type' => 'cd_balance',
                'opened_at' => $date->copy()->startOfDay()->toIso8601String(),
            ]);
    }

    private function stockItems(): Collection
    {
        return ClientMedicationStock::whereHas(
            'medication',
            fn ($q) => $q
                ->active()
                ->whereIn('client_id', $this->allowedClientIds())
                ->when(! $this->includeControlled, fn ($query) => $query->where('controlled_drug', false)),
        )
            ->where(function ($q) {
                $q->whereColumn('on_hand', '<=', 'reorder_level')
                    ->orWhere('expiry_date', '<=', today()->copy()->addDays(30)->toDateString());
            })
            ->with('medication:id,client_id,name,controlled_drug', 'medication.client:id,first_name,last_name')
            ->limit(12)
            ->get()
            ->map(function ($stock) {
                $expired = $stock->expiry_date && $stock->expiry_date->isPast();
                $expiring = $stock->expiry_date && ! $expired && $stock->expiry_date->lte(today()->copy()->addDays(30));

                return [
                    'id' => 'stock-'.$stock->id,
                    'type' => 'stock',
                    'category' => 'stock',
                    'code' => 'STK',
                    'severity' => $expired ? 'critical' : 'warning',
                    'client' => $this->clientName($stock->medication?->client),
                    'client_id' => $stock->medication?->client_id,
                    'is_controlled' => (bool) $stock->medication?->controlled_drug,
                    'title' => ($stock->medication->name ?? 'Stock').' — '.($expired ? 'expired stock' : ($expiring ? 'expiring soon' : 'low stock')),
                    'status' => $expired ? 'Expired' : ($expiring ? 'Expiring' : 'Low stock'),
                    'summary' => 'On hand '.MedicationStockQuantity::display($stock->on_hand ?? 0).($stock->unit ? ' '.$stock->unit : '')
                        .($stock->expiry_date ? ' · expires '.$stock->expiry_date->format('j M') : ''),
                    'action' => 'Order',
                    'action_type' => 'stock',
                    'opened_at' => optional($stock->expiry_date)->toIso8601String(),
                ];
            });
    }

    private function medicationErrorItems(bool $includeControlled): Collection
    {
        return $this->medicationErrorRows($includeControlled)
            ->open()
            ->whereIn('client_id', $this->allowedClientIds())
            ->with('client:id,first_name,last_name', 'medication:id,name,controlled_drug')
            ->latest('reported_at')
            ->limit(10)
            ->get()
            ->map(fn ($error) => [
                'id' => 'error-'.$error->id,
                'type' => 'error',
                'category' => 'clinical',
                'code' => 'ERR',
                'severity' => in_array($error->severity, ['critical', 'major'], true) ? 'critical' : 'warning',
                'client' => $this->clientName($error->client),
                'client_id' => $error->client_id,
                'is_controlled' => (bool) $error->medication?->controlled_drug,
                'title' => $this->clientName($error->client).' — '.$this->errorTypeLabel($error->error_type),
                'status' => ucfirst($error->status),
                'summary' => Str::limit($error->description, 80),
                'action' => 'Review',
                'action_type' => 'error',
                'opened_at' => optional($error->reported_at)->toIso8601String(),
                'meta' => ['error_id' => $error->id],
            ]);
    }

    // ─── Clinical watch widgets ────────────────────────────

    public function inrWatch(): array
    {
        return $this->latestInrPerClient()
            ->take(6)
            ->map(function ($r) {
                $status = $this->inrStatus($r);

                return [
                    'id' => $r->id,
                    'client_id' => $r->client_id,
                    'client' => $this->clientName($r->client),
                    'value' => (float) $r->inr_value,
                    'target' => $this->inrTargetLabel($r),
                    'tested_on' => optional($r->tested_on)->format('j M'),
                    'status' => $status,
                    'status_label' => match ($status) {
                        'above' => 'Above range',
                        'below' => 'Below range',
                        'in_range' => 'In range',
                        default => 'No target',
                    },
                ];
            })
            ->values()
            ->all();
    }

    public function syringeDrivers(): array
    {
        return MedicationSyringeDriver::running()
            ->whereIn('client_id', $this->allowedClientIds())
            ->whereIn('site_id', $this->allowedSiteIds())
            ->with('client:id,first_name,last_name', 'site:id,name', 'checks')
            ->orderBy('commenced_at')
            ->limit(8)
            ->get()
            ->map(function ($driver) {
                $lastCheck = $driver->checks->sortByDesc('checked_at')->first();
                $lastCheckAt = $lastCheck?->checked_at ?? $driver->commenced_at;
                // Default supported-living syringe-driver check cadence is 4-hourly.
                $nextCheckDue = $lastCheckAt?->copy()->addHours(4);
                $overdue = $nextCheckDue && $nextCheckDue->isPast();

                $contents = collect($driver->contents ?? [])
                    ->map(fn ($c) => trim(($c['name'] ?? '').' '.($c['dose'] ?? '').' '.($c['unit'] ?? '')))
                    ->filter()
                    ->implode(' + ');

                return [
                    'id' => $driver->id,
                    'client_id' => $driver->client_id,
                    'client' => $this->clientName($driver->client),
                    'site' => $driver->site?->name,
                    'contents' => $contents ?: 'Infusion',
                    'commenced_at' => optional($driver->commenced_at)->format('j M H:i'),
                    'next_check_due' => optional($nextCheckDue)->format('H:i'),
                    'overdue' => $overdue,
                    'status_label' => $overdue ? 'Check due '.optional($nextCheckDue)->format('H:i') : 'On schedule',
                ];
            })
            ->all();
    }

    public function reviewsDue(): array
    {
        return MedicationReview::due()
            ->whereIn('client_id', $this->allowedClientIds())
            ->with('client:id,first_name,last_name')
            ->whereDate('scheduled_date', '<=', today()->copy()->addDays(30)->toDateString())
            ->orderBy('scheduled_date')
            ->limit(8)
            ->get()
            ->map(function ($review) {
                $scheduled = $review->scheduled_date;
                $overdueDays = $scheduled && $scheduled->isPast() ? $scheduled->diffInDays(today()) : 0;
                $isToday = $scheduled && $scheduled->isToday();

                return [
                    'id' => $review->id,
                    'client_id' => $review->client_id,
                    'client' => $this->clientName($review->client),
                    'cadence' => $review->review_type ?: 'Review',
                    'scheduled_date' => optional($scheduled)->format('j M'),
                    'status' => $overdueDays > 0 ? 'overdue' : ($isToday ? 'today' : 'upcoming'),
                    'status_label' => $overdueDays > 0 ? $overdueDays.'d overdue' : ($isToday ? 'Today' : 'In '.(today()->diffInDays($scheduled)).'d'),
                ];
            })
            ->all();
    }

    public function medicationErrors(Carbon $date, bool $includeControlled = true): array
    {
        $open = $this->medicationErrorRows($includeControlled)
            ->open()
            ->whereIn('client_id', $this->allowedClientIds())
            ->count();

        $byType = $this->medicationErrorRows($includeControlled)
            ->open()
            ->whereIn('client_id', $this->allowedClientIds())
            ->selectRaw('error_type, COUNT(*) as c')
            ->groupBy('error_type')
            ->pluck('c', 'error_type')
            ->map(fn ($count, $type) => ['type' => $this->errorTypeLabel($type), 'count' => (int) $count])
            ->values()
            ->all();

        // 30-day daily trend of reported errors.
        $reported = $this->medicationErrorRows($includeControlled, true)
            ->whereIn('client_id', $this->allowedClientIds())
            ->where('reported_at', '>=', $date->copy()->subDays(29)->startOfDay())
            ->get(['reported_at'])
            ->groupBy(fn ($e) => optional($e->reported_at)->toDateString());

        $trend = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = $date->copy()->subDays($i)->toDateString();
            $trend[] = ['date' => $date->copy()->subDays($i)->format('j M'), 'count' => isset($reported[$day]) ? $reported[$day]->count() : 0];
        }

        return [
            'open' => $open,
            'byType' => $byType,
            'trend' => $trend,
        ];
    }

    /** @return Builder<MedicationError> */
    private function medicationErrorRows(bool $includeControlled, bool $withTrashed = false): Builder
    {
        $query = MedicationError::query();

        if ($withTrashed) {
            $query->withTrashed();
        }

        $this->canonicalMedicationRows($query, true);

        if (! $includeControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($query);
        }

        return $query;
    }

    // ─── Client board ──────────────────────────────────────

    public function clientBoard(Carbon $date): array
    {
        $dateString = $date->toDateString();

        return Client::query()
            ->whereIn('id', $this->allowedClientIds())
            ->select(['id', 'first_name', 'last_name', 'site_id'])
            ->with('site:id,name')
            ->withCount([
                'medications as active_medications_count' => fn ($q) => $q
                    ->active()
                    ->when(! $this->includeControlled, fn ($query) => $query->where('controlled_drug', false)),
                'medicationAdministrations as given_today' => fn ($q) => $this->effectiveAdministrationRows($q)->whereDate('administered_at', $dateString)->where('status', 'given'),
                'medicationAdministrations as pending_today' => fn ($q) => $this->effectiveAdministrationRows($q)->whereDate('scheduled_for', $dateString)->where('status', 'pending'),
                'medicationAdministrations as missed_today' => fn ($q) => $this->effectiveAdministrationRows($q)->whereDate('scheduled_for', $dateString)->where('status', 'missed'),
            ])
            ->having('active_medications_count', '>', 0)
            ->orderBy('last_name')
            ->limit(12)
            ->get()
            ->map(function ($client) {
                $given = (int) $client->given_today;
                $pending = (int) $client->pending_today;
                $missed = (int) $client->missed_today;
                $total = $given + $pending + $missed;
                $status = $missed > 0 ? 'attention' : ($pending === 0 && $total > 0 ? 'complete' : 'in_progress');

                return [
                    'id' => $client->id,
                    'name' => $this->clientName($client),
                    'site' => $client->site?->name,
                    'meds' => (int) $client->active_medications_count,
                    'given' => $given,
                    'pending' => $pending,
                    'missed' => $missed,
                    'total' => $total,
                    'done' => $given,
                    'percent' => $total > 0 ? (int) round(($given / $total) * 100) : 0,
                    'status' => $status,
                ];
            })
            ->all();
    }

    // ─── Reused dashboard bits ─────────────────────────────

    public function overdueMedications(Carbon $date): Collection
    {
        return $this->effectiveAdministrationRows(ClientMedicationAdministration::query())
            ->where('status', 'pending')
            ->whereIn('client_id', $this->allowedClientIds())
            ->where('scheduled_for', '<', now()->subMinutes(60))
            ->whereDate('scheduled_for', $date)
            ->with([
                'client:id,first_name,last_name,preferred_name,nhi_number,date_of_birth,site_id',
                'client.site:id,name',
                'client.medicationAllergies' => fn ($q) => $q->whereNull('deleted_at'),
                'medication:id,client_id,name,dosage,route,controlled_drug,witness_required',
            ])
            ->orderBy('scheduled_for')
            ->limit(10)
            ->get();
    }

    /**
     * Build the meds/today ScheduleRow + ClientInfo for an overdue dose so the
     * shared RecordDoseWizard can open inline on /emar (same shape WorkerMeds
     * builds — see clientsPayload()/scheduleForDate()). Writes still go through
     * POST /meds/today/record → EnhancedMarService; no second pipeline.
     */
    private function buildRecordContext(ClientMedicationAdministration $admin, Carbon $date): array
    {
        $tz = config('app.worker_timezone', config('app.timezone'));
        $client = $admin->client;
        $med = $admin->medication;
        $scheduled = $admin->scheduled_for;
        $dob = $client?->date_of_birth;

        return [
            'row' => [
                'key' => 'emar-'.$admin->id,
                'client_id' => $admin->client_id,
                'client_name' => $this->clientName($client),
                'medication_id' => $admin->client_medication_id,
                'medication_name' => $med?->name ?? 'Medication',
                'dose' => $med?->dosage,
                'route' => $med?->route,
                'is_controlled' => (bool) ($med?->controlled_drug ?? false),
                'requires_witness' => (bool) ($med?->witness_required ?? false) || (bool) ($med?->controlled_drug ?? false),
                'scheduled_for' => optional($scheduled)->toIso8601String(),
                'time' => $scheduled ? $scheduled->copy()->timezone($tz)->format('H:i') : '',
                'round_label' => '',
                'status' => 'overdue',
                'recorded' => null,
                'mar_url' => '/emar/mar?client_id='.$admin->client_id.'&date='.$date->toDateString(),
            ],
            'client' => [
                'id' => $client?->id,
                'name' => $this->clientName($client),
                'preferred' => $client?->preferred_name ?: $client?->first_name,
                'nhi' => $client?->nhi_number,
                'dob' => $dob?->format('j M Y'),
                'age' => $dob ? (int) $dob->copy()->timezone($tz)->diffInYears(now($tz)) : null,
                'site_id' => $client?->site_id,
                'site_name' => $client?->site?->name,
                'allergies' => $client
                    ? $client->medicationAllergies
                        ->map(fn ($a) => trim((string) $a->allergen))
                        ->filter()
                        ->values()
                        ->all()
                    : [],
            ],
        ];
    }

    public function nextRound(Carbon $date)
    {
        return MedicationRound::where('status', 'pending')
            ->whereIn('site_id', $this->allowedSiteIds())
            ->whereDate('round_date', $date)
            ->orderBy('scheduled_time')
            ->with('assignedTo:id,name')
            ->first();
    }

    public function recentActivity(): Collection
    {
        return $this->effectiveAdministrationRows(ClientMedicationAdministration::query())
            ->with([
                'client:id,first_name,last_name',
                'medication:id,client_id,name',
                'administeredBy:id,name',
            ])
            ->whereIn('client_id', $this->allowedClientIds())
            ->latest('administered_at')
            ->limit(20)
            ->get();
    }

    public function activeAlertsList(): Collection
    {
        return $this->visibleAlertRows()
            ->active()
            ->whereIn('client_id', $this->allowedClientIds())
            ->with([
                'client:id,first_name,last_name',
                'medication' => fn ($query) => $query
                    ->withTrashed()
                    ->select(['id', 'client_id', 'name', 'controlled_drug']),
            ])
            ->latest()
            ->limit(10)
            ->get();
    }

    /** @return Builder<MedicationDashboardAlert> */
    private function visibleAlertRows(): Builder
    {
        $query = $this->canonicalMedicationRows(MedicationDashboardAlert::query(), true);

        if (! $this->includeControlled) {
            $query->where(function (Builder $types): void {
                $types->whereNull('alert_type')
                    ->orWhere('alert_type', 'not like', 'controlled%');
            });
            $this->governanceScope->scopeWithoutControlledMedicationRows($query);
        }

        return $query;
    }

    public function complianceSnapshot(): array
    {
        return [
            'competencyExpiring' => $this->competencyAssessmentQuery()->where('expiry_date', '<=', now()->addDays(30))->where('expiry_date', '>', now())->count(),
            'competencyExpired' => $this->competencyAssessmentQuery()->where('expiry_date', '<', now())->count(),
            'pendingReviews' => MedicationReview::whereIn('client_id', $this->allowedClientIds())->where('status', 'scheduled')->where('scheduled_date', '<=', now())->count(),
            'overdueReviews' => MedicationReview::whereIn('client_id', $this->allowedClientIds())->where('status', 'overdue')->count(),
        ];
    }

    private function competencyAssessmentQuery(): Builder
    {
        $siteIds = $this->allowedSiteIds();

        return MedicationCompetencyAssessment::query()
            ->whereHas('user.hrEmployeeProfile', fn (Builder $profile) => $profile->where(function (Builder $site) use ($siteIds) {
                $site->whereIn('primary_site_id', $siteIds);
                foreach ($siteIds as $siteId) {
                    $site->orWhereJsonContains('secondary_site_ids', $siteId);
                }
            }));
    }

    // ─── Helpers ───────────────────────────────────────────

    /** Latest active INR record per client (newest first). */
    private function latestInrPerClient(): Collection
    {
        $query = ClientInrRecord::active()
            ->whereIn('client_id', $this->allowedClientIds())
            ->with('client:id,first_name,last_name');
        $this->canonicalMedicationRows($query, false);
        if (! $this->includeControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($query);
        }

        return $query
            ->orderByDesc('tested_on')
            ->orderByDesc('id')
            ->get()
            ->unique('client_id')
            ->values();
    }

    private function inrStatus(ClientInrRecord $r): string
    {
        $value = $r->inr_value !== null ? (float) $r->inr_value : null;
        $low = $r->target_range_low !== null ? (float) $r->target_range_low : null;
        $high = $r->target_range_high !== null ? (float) $r->target_range_high : null;

        if ($value === null || $low === null || $high === null) {
            return 'no_target';
        }

        if ($value > $high) {
            return 'above';
        }

        if ($value < $low) {
            return 'below';
        }

        return 'in_range';
    }

    private function inrTargetLabel(ClientInrRecord $r): string
    {
        if ($r->target_range_low === null || $r->target_range_high === null) {
            return '—';
        }

        return (float) $r->target_range_low.'–'.(float) $r->target_range_high;
    }

    private function errorTypeLabel(?string $type): string
    {
        return ucfirst(str_replace('_', ' ', (string) ($type ?: 'error')));
    }

    private function clientName(?Client $client): string
    {
        if (! $client) {
            return 'Unknown client';
        }

        return trim($client->first_name.' '.$client->last_name) ?: 'Client #'.$client->id;
    }
}
