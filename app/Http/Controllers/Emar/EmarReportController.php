<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Concerns\SanitizesCsvOutput;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationDestruction;
use App\Models\MedicationError;
use App\Models\MedicationRound;
use App\Models\Site;
use App\Services\GuidedRoundService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\MedicationReportingService;
use App\Support\Medication\MedicationStockQuantity;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmarReportController extends Controller
{
    use SanitizesCsvOutput;

    public function __construct(private readonly MedicationGovernanceScopeService $governanceScope) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'client_id' => ['nullable', 'integer'],
            'site_id' => ['nullable', 'integer'],
            'care_level' => ['nullable', 'string', 'max:60'],
            'report_type' => ['nullable', 'string'],
        ]);
        $canViewControlled = $request->user()?->canDo('medications.controlled.view') ?? false;
        if (($filters['report_type'] ?? null) === 'controlled') {
            abort_unless($canViewControlled, 403);
        }

        $siteId = $filters['site_id'] ?? null;
        $clientId = $filters['client_id'] ?? null;
        $actor = $request->user();
        abort_unless($actor !== null, 403);
        $accessibleSiteIds = $this->governanceScope->reportSiteIds($actor);
        $readerSiteIds = $this->governanceScope->reportSiteIds(
            $actor,
            $siteId !== null ? (int) $siteId : null,
            $clientId !== null ? (int) $clientId : null,
            ($filters['report_type'] ?? null) === 'controlled',
        );
        $bySite = fn ($query) => $query->whereHas('client', fn ($client) => $client->whereIn('site_id', $readerSiteIds));

        $dateFrom = isset($filters['date_from']) && $filters['date_from']
            ? Carbon::parse($filters['date_from'])->startOfDay()
            : now()->subDays(30)->startOfDay();
        $dateTo = isset($filters['date_to']) && $filters['date_to']
            ? Carbon::parse($filters['date_to'])->endOfDay()
            : now()->endOfDay();

        $careLevel = $filters['care_level'] ?? null;

        // ─── Administration Summary ──────────────────────────
        $adminQuery = $this->governanceScope->scopeCanonicalClientMedicationRows(
            ClientMedicationAdministration::query()->effectiveClinicalEvidence(),
            $readerSiteIds,
            false,
        )
            ->whereBetween('administered_at', [$dateFrom, $dateTo]);
        if (! $canViewControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($adminQuery);
        }
        if ($clientId) {
            $adminQuery->where('client_id', $clientId);
        }
        if ($careLevel) {
            $adminQuery->whereHas('client', fn ($query) => $query->where('care_level', $careLevel));
        }

        $adminTotals = (clone $adminQuery)->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN status = 'given' THEN 1 ELSE 0 END) as given_count,
            SUM(CASE WHEN status = 'refused' THEN 1 ELSE 0 END) as refused_count,
            SUM(CASE WHEN status = 'withheld' THEN 1 ELSE 0 END) as withheld_count,
            SUM(CASE WHEN status = 'missed' THEN 1 ELSE 0 END) as missed_count
        ")->first();

        $adminSummary = [
            'total' => (int) ($adminTotals->total ?? 0),
            'given' => (int) ($adminTotals->given_count ?? 0),
            'refused' => (int) ($adminTotals->refused_count ?? 0),
            'withheld' => (int) ($adminTotals->withheld_count ?? 0),
            'missed' => (int) ($adminTotals->missed_count ?? 0),
            'compliance_rate' => $adminTotals->total > 0
                ? round(($adminTotals->given_count / $adminTotals->total) * 100, 1)
                : 0,
        ];

        // Daily breakdown for chart
        $dailyAdmin = (clone $adminQuery)
            ->selectRaw("
                DATE(administered_at) as date,
                SUM(CASE WHEN status = 'given' THEN 1 ELSE 0 END) as given_count,
                SUM(CASE WHEN status = 'refused' THEN 1 ELSE 0 END) as refused_count,
                SUM(CASE WHEN status = 'missed' THEN 1 ELSE 0 END) as missed_count,
                COUNT(*) as total
            ")
            ->groupByRaw('DATE(administered_at)')
            ->orderByRaw('DATE(administered_at)')
            ->get()
            ->map(fn ($row) => [
                'date' => Carbon::parse($row->date)->format('M d'),
                'given' => (int) $row->given_count,
                'refused' => (int) $row->refused_count,
                'missed' => (int) $row->missed_count,
                'total' => (int) $row->total,
            ])
            ->values();

        // Breakdown by client
        $clientBreakdown = (clone $adminQuery)
            ->join('clients', 'clients.id', '=', 'client_medication_administrations.client_id')
            ->selectRaw("
                client_medication_administrations.client_id,
                CONCAT(clients.first_name, ' ', clients.last_name) as client_name,
                COUNT(*) as total,
                SUM(CASE WHEN client_medication_administrations.status = 'given' THEN 1 ELSE 0 END) as given_count,
                SUM(CASE WHEN client_medication_administrations.status = 'refused' THEN 1 ELSE 0 END) as refused_count,
                SUM(CASE WHEN client_medication_administrations.status = 'withheld' THEN 1 ELSE 0 END) as withheld_count,
                SUM(CASE WHEN client_medication_administrations.status = 'missed' THEN 1 ELSE 0 END) as missed_count
            ")
            ->groupBy('client_medication_administrations.client_id', 'clients.first_name', 'clients.last_name')
            ->orderByDesc('total')
            ->limit(50)
            ->get()
            ->map(fn ($row) => [
                'client_id' => $row->client_id,
                'client_name' => $row->client_name,
                'total' => (int) $row->total,
                'given' => (int) $row->given_count,
                'refused' => (int) $row->refused_count,
                'withheld' => (int) $row->withheld_count,
                'missed' => (int) $row->missed_count,
                'compliance' => $row->total > 0 ? round(($row->given_count / $row->total) * 100, 1) : 0,
            ])
            ->values();

        // ─── Reason not given (coded reasons) ────────────────
        $classOf = ['refused' => 'refusal', 'withheld' => 'clinical', 'missed' => 'omission'];
        $reasonRows = (clone $adminQuery)
            ->whereIn('status', ['refused', 'withheld', 'missed'])
            ->selectRaw('status, reason_code, COUNT(*) as count')
            ->groupBy('status', 'reason_code')
            ->get();
        $reasonBreakdown = [
            'codes' => $reasonRows->map(fn ($r) => [
                'code' => $r->reason_code ?: '—',
                'class' => $classOf[$r->status] ?? 'refusal',
                'count' => (int) $r->count,
            ])->sortByDesc('count')->values(),
            'by_class' => collect(['refusal', 'clinical', 'omission'])->mapWithKeys(fn ($c) => [
                $c => (int) $reasonRows->filter(fn ($r) => ($classOf[$r->status] ?? '') === $c)->sum('count'),
            ]),
        ];

        // ─── PRN Usage ──────────────────────────────────────
        $prnQuery = $this->governanceScope->scopeCanonicalClientMedicationRows(
            ClientMedicationAdministration::query()->effectiveClinicalEvidence(),
            $readerSiteIds,
            false,
        );
        if (! $canViewControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($prnQuery);
        }
        $prnQuery
            ->join('client_medications', 'client_medications.id', '=', 'client_medication_administrations.client_medication_id')
            ->where('client_medications.is_prn', true)
            ->whereBetween('client_medication_administrations.administered_at', [$dateFrom, $dateTo]);
        if ($clientId) {
            $prnQuery->where('client_medication_administrations.client_id', $clientId);
        }
        if ($careLevel) {
            $prnQuery
                ->join('clients as prn_clients', 'prn_clients.id', '=', 'client_medication_administrations.client_id')
                ->where('prn_clients.care_level', $careLevel);
        }

        $topPrnMeds = (clone $prnQuery)
            ->selectRaw('client_medications.name as medication_name, COUNT(*) as usage_count')
            ->groupBy('client_medications.name')
            ->orderByDesc('usage_count')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'medication' => $row->medication_name,
                'count' => (int) $row->usage_count,
            ])
            ->values();

        $dayCount = max(1, $dateFrom->diffInDays($dateTo));

        $prnByClient = (clone $prnQuery)
            ->join('clients', 'clients.id', '=', 'client_medication_administrations.client_id')
            ->selectRaw("
                client_medication_administrations.client_id,
                CONCAT(clients.first_name, ' ', clients.last_name) as client_name,
                client_medications.name as medication_name,
                COUNT(*) as usage_count
            ")
            ->groupBy('client_medication_administrations.client_id', 'clients.first_name', 'clients.last_name', 'client_medications.name')
            ->orderByDesc('usage_count')
            ->limit(50)
            ->get()
            ->map(fn ($row) => [
                'client_name' => $row->client_name,
                'medication' => $row->medication_name,
                'count' => (int) $row->usage_count,
                'avg_per_day' => round($row->usage_count / $dayCount, 1),
            ])
            ->values();

        // ─── Controlled Drugs ────────────────────────────────
        $cdAdminCount = 0;
        $destructionsCount = 0;
        $discrepancyCount = 0;
        $cdByMedication = collect();
        if ($canViewControlled) {
            $cdAdminCount = $this->governanceScope->scopeCanonicalClientMedicationRows(
                ClientMedicationAdministration::query()->effectiveClinicalEvidence(),
                $readerSiteIds,
                false,
            )
                ->join('client_medications', 'client_medications.id', '=', 'client_medication_administrations.client_medication_id')
                ->where('client_medications.controlled_drug', true)
                ->whereBetween('client_medication_administrations.administered_at', [$dateFrom, $dateTo])
                ->when($clientId, fn ($q) => $q->where('client_medication_administrations.client_id', $clientId))
                ->when($careLevel, fn ($q) => $q->whereHas('client', fn ($clientQuery) => $clientQuery->where('care_level', $careLevel)))
                ->count();

            $destructionsCount = $this->governanceScope->scopeCanonicalClientMedicationRows(
                MedicationDestruction::query(),
                $readerSiteIds,
            )
                ->where('is_controlled_drug', true)
                ->whereBetween('destroyed_at', [$dateFrom, $dateTo])
                ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
                ->when($careLevel, fn ($q) => $q->whereHas('client', fn ($clientQuery) => $clientQuery->where('care_level', $careLevel)))
                ->count();

            $discrepancyQuery = $this->governanceScope->scopeCanonicalClientMedicationRows(
                ClientControlledDrugDiscrepancy::query(),
                $readerSiteIds,
                false,
            )
                ->whereBetween('reported_at', [$dateFrom, $dateTo])
                ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
                ->when($careLevel, fn ($q) => $q->whereHas('client', fn ($clientQuery) => $clientQuery->where('care_level', $careLevel)));

            $discrepancyCount = (clone $discrepancyQuery)->count();

            $cdByMedication = $this->governanceScope->scopeCanonicalClientMedicationRows(
                ClientMedicationAdministration::query()->effectiveClinicalEvidence(),
                $readerSiteIds,
                false,
            )
                ->join('client_medications', 'client_medications.id', '=', 'client_medication_administrations.client_medication_id')
                ->where('client_medications.controlled_drug', true)
                ->whereBetween('client_medication_administrations.administered_at', [$dateFrom, $dateTo])
                ->when($clientId, fn ($q) => $q->where('client_medication_administrations.client_id', $clientId))
                ->when($careLevel, fn ($q) => $q->whereHas('client', fn ($clientQuery) => $clientQuery->where('care_level', $careLevel)))
                ->selectRaw('client_medications.name as medication_name, COUNT(*) as admin_count')
                ->groupBy('client_medications.name')
                ->orderByDesc('admin_count')
                ->limit(20)
                ->get()
                ->map(fn ($row) => [
                    'medication' => $row->medication_name,
                    'administrations' => (int) $row->admin_count,
                ])
                ->values();
        }

        // ─── Staff Compliance ────────────────────────────────
        $today = Carbon::today();

        $competencyQuery = MedicationCompetencyAssessment::query()
            ->whereHas('user.hrEmployeeProfile', fn ($profile) => $profile->where(function ($site) use ($readerSiteIds) {
                $site->whereIn('primary_site_id', $readerSiteIds);
                foreach ($readerSiteIds as $readerSiteId) {
                    $site->orWhereJsonContains('secondary_site_ids', $readerSiteId);
                }
            }));

        $currentCompetency = (clone $competencyQuery)->where('status', 'passed')
            ->where('expiry_date', '>', $today)
            ->where('expiry_date', '>', $today->copy()->addDays(30))
            ->count();

        $expiringCompetency = (clone $competencyQuery)->where('status', 'passed')
            ->where('expiry_date', '>', $today)
            ->where('expiry_date', '<=', $today->copy()->addDays(30))
            ->count();

        $expiredCompetency = (clone $competencyQuery)->where('status', 'passed')
            ->where('expiry_date', '<=', $today)
            ->count();

        $staffCompetencyList = (clone $competencyQuery)
            ->with('user:id,name')
            ->whereIn('status', ['passed', 'failed'])
            ->orderByDesc('assessment_date')
            ->limit(50)
            ->get()
            ->map(function ($a) use ($today) {
                $daysUntilExpiry = $a->expiry_date ? $today->diffInDays($a->expiry_date, false) : null;
                $status = 'current';
                if ($a->status === 'failed') {
                    $status = 'failed';
                } elseif ($a->expiry_date && $a->expiry_date->lte($today)) {
                    $status = 'expired';
                } elseif ($daysUntilExpiry !== null && $daysUntilExpiry <= 30) {
                    $status = 'expiring';
                }

                return [
                    'staff_name' => $a->user?->name ?? 'Unknown',
                    'assessment_date' => $a->assessment_date?->toDateString(),
                    'expiry_date' => $a->expiry_date?->toDateString(),
                    'status' => $status,
                    'days_until_expiry' => $daysUntilExpiry !== null ? (int) $daysUntilExpiry : null,
                ];
            })
            ->values();

        // ─── Stock Status ────────────────────────────────────
        $stockQuery = ClientMedicationStock::query()
            ->whereHas('medication', fn ($medication) => $medication
                ->when(! $canViewControlled, fn ($query) => $query->where('controlled_drug', false))
                ->whereHas('client', fn ($client) => $client->whereIn('site_id', $readerSiteIds)));
        $totalStockItems = (clone $stockQuery)->count();

        $lowStockCount = (clone $stockQuery)->whereColumn('on_hand', '<=', 'reorder_level')->count();

        $expiringStockCount = (clone $stockQuery)->whereNotNull('expiry_date')
            ->where('expiry_date', '>', $today)
            ->where('expiry_date', '<=', $today->copy()->addDays(30))
            ->count();

        $expiredStockCount = (clone $stockQuery)->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', $today)
            ->count();

        $activeMediactions = ClientMedication::where('active', true)
            ->when(! $canViewControlled, fn ($medications) => $medications->where('controlled_drug', false))
            ->whereHas('client', fn ($client) => $client->whereIn('site_id', $readerSiteIds))
            ->count();

        $stockList = (clone $stockQuery)
            ->with(['medication:id,client_id,name', 'medication.client:id,first_name,last_name'])
            ->orderByRaw('CASE WHEN on_hand <= reorder_level THEN 0 ELSE 1 END')
            ->orderBy('expiry_date')
            ->limit(50)
            ->get()
            ->map(function ($s) use ($today) {
                $status = 'ok';
                if ($s->expiry_date && $s->expiry_date->lte($today)) {
                    $status = 'expired';
                } elseif ($s->expiry_date && $s->expiry_date->lte($today->copy()->addDays(30))) {
                    $status = 'expiring';
                } elseif ($s->isLowStock()) {
                    $status = 'low';
                }

                $client = $s->medication?->client;
                $clientName = $client ? trim("{$client->first_name} {$client->last_name}") : '';

                return [
                    'medication' => $s->medication?->name ?? 'Unknown',
                    'client' => $clientName,
                    'on_hand' => MedicationStockQuantity::toFloat($s->on_hand ?? 0),
                    'reorder_level' => (int) $s->reorder_level,
                    'expiry_date' => $s->expiry_date?->toDateString(),
                    'status' => $status,
                ];
            })
            ->values();

        // ─── Round Completion ────────────────────────────────
        $roundQuery = MedicationRound::query()
            ->whereIn('site_id', $readerSiteIds)
            ->whereBetween('round_date', [$dateFrom->toDateString(), $dateTo->toDateString()]);

        $roundTotals = (clone $roundQuery)->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'completed' AND completed_at <= DATE_ADD(CONCAT(round_date, ' ', scheduled_time), INTERVAL window_minutes MINUTE) THEN 1 ELSE 0 END) as on_time,
            SUM(CASE WHEN status = 'completed' AND completed_at > DATE_ADD(CONCAT(round_date, ' ', scheduled_time), INTERVAL window_minutes MINUTE) THEN 1 ELSE 0 END) as late,
            SUM(CASE WHEN status = 'missed' THEN 1 ELSE 0 END) as missed
        ")->first();

        $totalRounds = (int) ($roundTotals->total ?? 0);
        $completedRounds = (int) ($roundTotals->completed ?? 0);
        $onTimeRounds = (int) ($roundTotals->on_time ?? 0);
        $lateRounds = (int) ($roundTotals->late ?? 0);
        $missedRounds = (int) ($roundTotals->missed ?? 0);

        $roundSummary = [
            'total' => $totalRounds,
            'completed' => $completedRounds,
            'on_time_pct' => $totalRounds > 0 ? round(($onTimeRounds / $totalRounds) * 100, 1) : 0,
            'late_pct' => $totalRounds > 0 ? round(($lateRounds / $totalRounds) * 100, 1) : 0,
            'missed_pct' => $totalRounds > 0 ? round(($missedRounds / $totalRounds) * 100, 1) : 0,
        ];

        $dailyRounds = (clone $roundQuery)
            ->selectRaw("
                round_date as date,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'missed' THEN 1 ELSE 0 END) as missed
            ")
            ->groupBy('round_date')
            ->orderBy('round_date')
            ->get()
            ->map(fn ($row) => [
                'date' => Carbon::parse($row->date)->format('M d'),
                'total' => (int) $row->total,
                'completed' => (int) $row->completed,
                'missed' => (int) $row->missed,
            ])
            ->values();

        // ─── Error Summary ───────────────────────────────────
        $errorQuery = $this->governanceScope->scopeCanonicalClientMedicationRows(
            MedicationError::query(),
            $readerSiteIds,
            true,
        )
            ->whereBetween('reported_at', [$dateFrom, $dateTo])
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->when($careLevel, fn ($q) => $q->whereHas('client', fn ($clientQuery) => $clientQuery->where('care_level', $careLevel)));
        if (! $canViewControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($errorQuery);
        }

        $errorTotals = (clone $errorQuery)->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical,
            SUM(CASE WHEN status IN ('reported', 'investigating') THEN 1 ELSE 0 END) as open_count,
            SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved
        ")->first();

        $errorsByType = (clone $errorQuery)
            ->selectRaw('error_type, COUNT(*) as count')
            ->groupBy('error_type')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'type' => $row->error_type,
                'count' => (int) $row->count,
            ])
            ->values();

        $errorList = (clone $errorQuery)
            ->with(['client:id,first_name,last_name'])
            ->orderByDesc('reported_at')
            ->limit(50)
            ->get()
            ->map(fn ($e) => [
                'date' => $e->reported_at?->toDateString(),
                'client' => $e->client ? trim("{$e->client->first_name} {$e->client->last_name}") : '',
                'type' => $e->error_type,
                'severity' => $e->severity,
                'status' => $e->status,
            ])
            ->values();

        // ─── Client list for filter ──────────────────────────
        $clients = Client::query()
            ->whereIn('site_id', $accessibleSiteIds)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'care_level'])
            ->map(fn (Client $c) => [
                'id' => $c->id,
                'name' => trim("{$c->first_name} {$c->last_name}"),
                'care_level' => $c->care_level,
            ])
            ->values();

        $careLevels = Client::query()
            ->whereIn('site_id', $accessibleSiteIds)
            ->whereNotNull('care_level')
            ->distinct()
            ->orderBy('care_level')
            ->pluck('care_level')
            ->values();

        // Controlled medications in the CdMedication shape, so the Controlled-drugs
        // tab can reuse the shared ReportLossDialog (Page 6) for "Report CD loss".
        $cdMedications = $canViewControlled
            ? ClientMedication::query()
                ->active()
                ->controlled()
                ->whereHas('client', fn ($client) => $client->whereIn('site_id', $readerSiteIds))
                ->with(['client:id,first_name,last_name', 'stock'])
                ->orderBy('name')
                ->get()
                ->map(fn (ClientMedication $m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'controlled_drug' => (bool) $m->controlled_drug,
                    'client_id' => $m->client_id,
                    'client_name' => $m->client ? trim($m->client->first_name.' '.$m->client->last_name) : 'Unknown',
                    'stock' => $m->stock ? [
                        'on_hand' => $m->stock->on_hand !== null
                            ? MedicationStockQuantity::toFloat($m->stock->on_hand)
                            : null,
                        'unit' => $m->stock->unit,
                        'last_counted_at' => $m->stock->last_counted_at instanceof \DateTimeInterface ? $m->stock->last_counted_at->toIso8601String() : null,
                    ] : null,
                ])
                ->values()
            : collect();

        $sites = $this->governanceScope->sitePicker($accessibleSiteIds);
        $activeSite = $siteId ? $sites->firstWhere('id', (int) $siteId) : null;

        return Inertia::render('emar/Reports', [
            'filters' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'client_id' => $clientId ? (int) $clientId : null,
                'site_id' => $siteId ? (int) $siteId : null,
                'care_level' => $careLevel,
                'report_type' => $filters['report_type'] ?? null,
            ],
            'clients' => $clients,
            'careLevels' => $careLevels,
            'cdMedications' => $cdMedications,
            'can_view_controlled' => $canViewControlled,
            'can_record_controlled' => $request->user()?->canDo('medications.controlled.record') ?? false,
            'reasonBreakdown' => $reasonBreakdown,
            'sites' => $sites->map(fn (Site $site) => $site->only(['id', 'name']))->values(),
            'active_site' => $activeSite ? ['id' => $activeSite->id, 'name' => $activeSite->name] : null,
            'site_brand_colour' => $activeSite?->brand_colour,
            'adminSummary' => $adminSummary,
            'dailyAdmin' => $dailyAdmin,
            'clientBreakdown' => $clientBreakdown,
            'topPrnMeds' => $topPrnMeds,
            'prnByClient' => $prnByClient,
            'controlledDrugs' => [
                'administrations' => $cdAdminCount,
                'destructions' => $destructionsCount,
                'discrepancies' => $discrepancyCount,
                'byMedication' => $cdByMedication,
            ],
            'staffCompliance' => [
                'current' => $currentCompetency,
                'expiring' => $expiringCompetency,
                'expired' => $expiredCompetency,
                'list' => $staffCompetencyList,
            ],
            'stockStatus' => [
                'total' => $totalStockItems,
                'low' => $lowStockCount,
                'expiring' => $expiringStockCount,
                'expired' => $expiredStockCount,
                'active_medications' => $activeMediactions,
                'list' => $stockList,
            ],
            'roundCompletion' => [
                'summary' => $roundSummary,
                'daily' => $dailyRounds,
            ],
            'errorSummary' => [
                'total' => (int) ($errorTotals->total ?? 0),
                'critical' => (int) ($errorTotals->critical ?? 0),
                'open' => (int) ($errorTotals->open_count ?? 0),
                'resolved' => (int) ($errorTotals->resolved ?? 0),
                'byType' => $errorsByType,
                'list' => $errorList,
            ],
        ]);
    }

    public function export(Request $request)
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'client_id' => ['nullable', 'integer'],
            'site_id' => ['nullable', 'integer'],
            'care_level' => ['nullable', 'string', 'max:60'],
            'report_type' => ['nullable', 'string', 'in:administration,prn,controlled,rounds,errors,regular,short_course,observations,chart_reviews,syringe_drivers'],
        ]);

        $dateFrom = isset($filters['date_from']) && $filters['date_from']
            ? Carbon::parse($filters['date_from'])->startOfDay()
            : now()->subDays(30)->startOfDay();
        $dateTo = isset($filters['date_to']) && $filters['date_to']
            ? Carbon::parse($filters['date_to'])->endOfDay()
            : now()->endOfDay();

        if ($dateFrom->diffInDays($dateTo) > 93) {
            $dateFrom = $dateTo->copy()->subMonthsNoOverflow(3)->startOfDay();
        }

        $clientId = $filters['client_id'] ?? null;
        $siteId = $filters['site_id'] ?? null;
        $careLevel = $filters['care_level'] ?? null;
        $type = $filters['report_type'] ?? 'administration';
        $actor = $request->user();
        abort_unless($actor !== null, 403);
        $canViewControlled = $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY);
        $readerSiteIds = $this->governanceScope->reportSiteIds(
            $actor,
            $siteId !== null ? (int) $siteId : null,
            $clientId !== null ? (int) $clientId : null,
            $type === 'controlled',
        );

        $filename = "emar_{$type}_report_".now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($type, $dateFrom, $dateTo, $clientId, $careLevel, $readerSiteIds, $canViewControlled) {
            $out = fopen('php://output', 'w');
            $reporting = app(MedicationReportingService::class);

            match ($type) {
                'administration' => $this->exportAdministrations($out, $dateFrom, $dateTo, $clientId, $careLevel, $readerSiteIds, $canViewControlled),
                'prn' => $this->exportPrn($out, $dateFrom, $dateTo, $clientId, $careLevel, $readerSiteIds, $canViewControlled),
                'controlled' => $this->exportControlled($out, $dateFrom, $dateTo, $clientId, $careLevel, $readerSiteIds),
                'rounds' => $this->exportRounds($out, $dateFrom, $dateTo, $readerSiteIds, $canViewControlled),
                'errors' => $this->exportErrors($out, $dateFrom, $dateTo, $clientId, $careLevel, $readerSiteIds, $canViewControlled),
                'regular' => $this->exportServiceRecords($out, $reporting->reportRegularUsage($clientId, $dateFrom, $dateTo, $careLevel, $readerSiteIds, $canViewControlled)),
                'short_course' => $this->exportServiceRecords($out, $reporting->reportShortCourseUsage($clientId, $dateFrom, $dateTo, $careLevel, $readerSiteIds, $canViewControlled)),
                'observations' => $this->exportServiceRecords($out, $reporting->reportObservationUsage($clientId, $dateFrom, $dateTo, $careLevel, $readerSiteIds, $canViewControlled)),
                'chart_reviews' => $this->exportServiceRecords($out, $reporting->reportChartReviews($clientId, $dateFrom, $dateTo, $careLevel, $readerSiteIds)),
                'syringe_drivers' => $this->exportServiceRecords($out, $reporting->reportSyringeDriverUsage($clientId, $dateFrom, $dateTo, $careLevel, $readerSiteIds, $canViewControlled)),
                default => $this->exportAdministrations($out, $dateFrom, $dateTo, $clientId, $careLevel, $readerSiteIds, $canViewControlled),
            };

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function exportAdministrations($out, Carbon $dateFrom, Carbon $dateTo, ?int $clientId, ?string $careLevel, array $siteIds, bool $includeControlled): void
    {
        $this->putCsv($out, ['Date', 'Client', 'Care Level', 'Medication', 'Therapeutic Group', 'Status', 'Reason Code', 'Administered By', 'Witness', 'BSL', 'Pulse', 'Blood Pressure', 'Notes']);

        $query = $this->governanceScope->scopeCanonicalClientMedicationRows(
            ClientMedicationAdministration::query()->effectiveClinicalEvidence(),
            $siteIds,
            false,
        );
        if (! $includeControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($query);
        }

        $query->with(['client:id,first_name,last_name,care_level', 'medication:id,name,pharmac_therapeutic_group,deleted_at', 'administeredBy:id,name', 'witnessedBy:id,name'])
            ->whereBetween('administered_at', [$dateFrom, $dateTo])
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->when($careLevel, fn ($q) => $q->whereHas('client', fn ($clientQuery) => $clientQuery->where('care_level', $careLevel)))
            ->orderBy('administered_at')
            ->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $a) {
                    $clientName = trim(($a->client?->first_name ?? '').' '.($a->client?->last_name ?? ''));
                    $this->putCsv($out, [
                        optional($a->administered_at)->toDateTimeString(),
                        $clientName,
                        $a->client?->care_level,
                        $a->medication?->historicalDisplayName() ?? '',
                        $a->medication?->pharmac_therapeutic_group ?? '',
                        $a->status,
                        $a->reason_code,
                        $a->administeredBy?->name ?? '',
                        $a->witnessedBy?->name ?? '',
                        $a->blood_glucose_level,
                        $a->pulse_bpm,
                        $a->blood_pressure_systolic && $a->blood_pressure_diastolic ? "{$a->blood_pressure_systolic}/{$a->blood_pressure_diastolic}" : '',
                        $a->notes,
                    ]);
                }
            });
    }

    private function exportPrn($out, Carbon $dateFrom, Carbon $dateTo, ?int $clientId, ?string $careLevel, array $siteIds, bool $includeControlled): void
    {
        $this->putCsv($out, ['Date', 'Client', 'Care Level', 'Medication', 'Therapeutic Group', 'Dose', 'Reason', 'Administered By']);

        $query = $this->governanceScope->scopeCanonicalClientMedicationRows(
            ClientMedicationAdministration::query()->effectiveClinicalEvidence(),
            $siteIds,
            false,
        );
        if (! $includeControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($query);
        }

        $query->with(['client:id,first_name,last_name,care_level', 'medication:id,name,is_prn,pharmac_therapeutic_group,deleted_at', 'administeredBy:id,name'])
            ->whereHas('medication', fn ($q) => $q->where('is_prn', true))
            ->whereBetween('administered_at', [$dateFrom, $dateTo])
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->when($careLevel, fn ($q) => $q->whereHas('client', fn ($clientQuery) => $clientQuery->where('care_level', $careLevel)))
            ->orderBy('administered_at')
            ->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $a) {
                    $clientName = trim(($a->client?->first_name ?? '').' '.($a->client?->last_name ?? ''));
                    $this->putCsv($out, [
                        optional($a->administered_at)->toDateTimeString(),
                        $clientName,
                        $a->client?->care_level,
                        $a->medication?->historicalDisplayName() ?? '',
                        $a->medication?->pharmac_therapeutic_group ?? '',
                        $a->dose_given,
                        $a->reason,
                        $a->administeredBy?->name ?? '',
                    ]);
                }
            });
    }

    private function exportControlled($out, Carbon $dateFrom, Carbon $dateTo, ?int $clientId, ?string $careLevel, array $siteIds): void
    {
        $this->putCsv($out, ['Date', 'Client', 'Care Level', 'Medication', 'Therapeutic Group', 'Status', 'Dose', 'Administered By', 'Witness']);

        $this->governanceScope->scopeCanonicalClientMedicationRows(
            ClientMedicationAdministration::query()->effectiveClinicalEvidence(),
            $siteIds,
            false,
        )
            ->with(['client:id,first_name,last_name,care_level', 'medication:id,name,controlled_drug,pharmac_therapeutic_group,deleted_at', 'administeredBy:id,name', 'witnessedBy:id,name'])
            ->whereHas('medication', fn ($q) => $q->where('controlled_drug', true))
            ->whereBetween('administered_at', [$dateFrom, $dateTo])
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->when($careLevel, fn ($q) => $q->whereHas('client', fn ($clientQuery) => $clientQuery->where('care_level', $careLevel)))
            ->orderBy('administered_at')
            ->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $a) {
                    $clientName = trim(($a->client?->first_name ?? '').' '.($a->client?->last_name ?? ''));
                    $this->putCsv($out, [
                        optional($a->administered_at)->toDateTimeString(),
                        $clientName,
                        $a->client?->care_level,
                        $a->medication?->historicalDisplayName() ?? '',
                        $a->medication?->pharmac_therapeutic_group ?? '',
                        $a->status,
                        $a->dose_given,
                        $a->administeredBy?->name ?? '',
                        $a->witnessedBy?->name ?? '',
                    ]);
                }
            });
    }

    private function exportRounds($out, Carbon $dateFrom, Carbon $dateTo, array $siteIds, bool $includeControlled): void
    {
        $this->putCsv($out, ['Date', 'Name', 'Status', 'Scheduled Time', 'Started At', 'Completed At', 'Total Meds', 'Administered', 'Refused', 'Missed']);

        MedicationRound::query()
            ->whereIn('site_id', $siteIds)
            ->whereBetween('round_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->orderBy('round_date')
            ->chunk(500, function ($rows) use ($out, $includeControlled) {
                $guidedRounds = app(GuidedRoundService::class);
                foreach ($rows as $r) {
                    $statuses = collect($guidedRounds->cells($r, $includeControlled))->pluck('status');
                    $this->putCsv($out, [
                        $r->round_date?->toDateString(),
                        $r->name,
                        $r->status,
                        $r->scheduled_time,
                        optional($r->started_at)->toDateTimeString(),
                        optional($r->completed_at)->toDateTimeString(),
                        $statuses->count(),
                        $statuses->filter(fn ($status) => $status === 'given')->count(),
                        $statuses->filter(fn ($status) => $status === 'refused')->count(),
                        $statuses->filter(fn ($status) => $status === 'missed')->count(),
                    ]);
                }
            });
    }

    private function exportErrors($out, Carbon $dateFrom, Carbon $dateTo, ?int $clientId, ?string $careLevel, array $siteIds, bool $canViewControlled): void
    {
        $this->putCsv($out, ['Date', 'Client', 'Care Level', 'Type', 'Severity', 'Status', 'Description']);

        $query = $this->governanceScope->scopeCanonicalClientMedicationRows(
            MedicationError::query(),
            $siteIds,
            true,
        );
        if (! $canViewControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($query);
        }

        $query
            ->with(['client:id,first_name,last_name,care_level'])
            ->whereBetween('reported_at', [$dateFrom, $dateTo])
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->when($careLevel, fn ($q) => $q->whereHas('client', fn ($clientQuery) => $clientQuery->where('care_level', $careLevel)))
            ->orderBy('reported_at')
            ->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $e) {
                    $clientName = trim(($e->client?->first_name ?? '').' '.($e->client?->last_name ?? ''));
                    $this->putCsv($out, [
                        optional($e->reported_at)->toDateTimeString(),
                        $clientName,
                        $e->client?->care_level,
                        $e->error_type,
                        $e->severity,
                        $e->status,
                        $e->description,
                    ]);
                }
            });
    }

    private function exportServiceRecords($out, array $report): void
    {
        $records = $report['records'] ?? [];
        if (empty($records)) {
            $this->putCsv($out, ['No data available']);

            return;
        }

        $headers = array_keys($records[0]);
        $this->putCsv($out, $headers);

        foreach ($records as $record) {
            $this->putCsv($out, array_map(function ($value) {
                return is_array($value) ? json_encode($value) : $value;
            }, $record));
        }
    }
}
