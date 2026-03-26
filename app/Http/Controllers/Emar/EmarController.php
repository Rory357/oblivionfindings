<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ControlledDrugLossReport;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationCovertAuthorisation;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationDestruction;
use App\Models\MedicationHandover;
use App\Models\MedicationInteraction;
use App\Models\MedicationPharmacyOrder;
use App\Models\MedicationPrescriberOrder;
use App\Models\MedicationPrnEffectiveness;
use App\Models\MedicationReview;
use App\Models\MedicationRound;
use App\Models\MedicationRoundTemplate;
use App\Models\MedicationSelfAdminAssessment;
use App\Models\Site;
use App\Models\User;
use App\Services\DoseSchedulingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class EmarController extends Controller
{
    // ─── Helpers ──────────────────────────────────────────

    private function getStaffList()
    {
        return User::orderBy('name')->get(['id', 'name']);
    }

    private function getClientsList()
    {
        return Client::orderBy('last_name')->get(['id', 'first_name', 'last_name']);
    }

    // ─── Dashboard ─────────────────────────────────────────
    public function dashboard()
    {
        $today = today();

        // Today's administration stats
        $todayAdmins = ClientMedicationAdministration::whereDate('scheduled_for', $today)
            ->orWhereDate('administered_at', $today)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'given' THEN 1 ELSE 0 END) as given,
                SUM(CASE WHEN status = 'refused' THEN 1 ELSE 0 END) as refused,
                SUM(CASE WHEN status = 'withheld' THEN 1 ELSE 0 END) as withheld,
                SUM(CASE WHEN status = 'missed' THEN 1 ELSE 0 END) as missed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
            ")->first();

        $totalToday = (int) ($todayAdmins->total ?? 0);
        $givenToday = (int) ($todayAdmins->given ?? 0);
        $adminRate = $totalToday > 0 ? round(($givenToday / $totalToday) * 100, 1) : 0;

        // 7-day administration trend
        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = $today->copy()->subDays($i);
            $dayStats = ClientMedicationAdministration::whereDate('scheduled_for', $date)
                ->orWhereDate('administered_at', $date)
                ->selectRaw("
                    SUM(CASE WHEN status = 'given' THEN 1 ELSE 0 END) as given,
                    SUM(CASE WHEN status = 'refused' THEN 1 ELSE 0 END) as refused,
                    SUM(CASE WHEN status = 'missed' THEN 1 ELSE 0 END) as missed,
                    COUNT(*) as total
                ")->first();
            $trend[] = [
                'date' => $date->format('D'),
                'given' => (int) ($dayStats->given ?? 0),
                'refused' => (int) ($dayStats->refused ?? 0),
                'missed' => (int) ($dayStats->missed ?? 0),
                'total' => (int) ($dayStats->total ?? 0),
            ];
        }

        // PRN stats
        $prnToday = ClientMedicationAdministration::whereDate('administered_at', $today)
            ->where('status', 'given')
            ->whereHas('medication', fn ($q) => $q->where('is_prn', true))
            ->count();
        $prnNearLimit = ClientMedication::active()->prn()->get()->filter(fn ($m) => $m->isPrnNearLimit())->count();

        // Controlled drugs
        $controlledCount = ClientMedication::active()->controlled()->count();
        $activeDiscrepancies = ClientControlledDrugDiscrepancy::whereIn('status', ['reported', 'investigating'])->count();

        // Overdue reviews
        $overdueReviews = MedicationReview::where('status', 'scheduled')
            ->where('scheduled_date', '<', $today->toDateString())
            ->count();

        // Expiring competencies
        $expiringCompetencies = MedicationCompetencyAssessment::where('status', 'passed')
            ->whereBetween('expiry_date', [$today->toDateString(), $today->copy()->addDays(30)->toDateString()])
            ->count();

        // Active alerts
        $activeAlerts = MedicationDashboardAlert::where('status', 'active')->count();

        // Low stock
        $lowStock = ClientMedicationStock::whereHas('medication', fn ($q) => $q->active())
            ->whereNotNull('reorder_level')
            ->whereColumn('on_hand', '<=', 'reorder_level')
            ->count();

        // Active medications & clients
        $activeMedications = ClientMedication::active()->count();
        $activeClients = Client::whereHas('medications', fn ($q) => $q->active())->count();

        // Rounds today
        $roundsToday = MedicationRound::forDate($today)->count();
        $roundsCompleted = MedicationRound::forDate($today)->where('status', 'completed')->count();

        // Sparkline data (last 7 days given counts)
        $givenTrend = array_map(fn ($d) => $d['given'], $trend);

        // Overdue medications (scheduled but not administered, past their window)
        $overdueMedications = ClientMedicationAdministration::where('status', 'pending')
            ->where('scheduled_for', '<', now()->subMinutes(60))
            ->whereDate('scheduled_for', $today)
            ->with(['client:id,first_name,last_name', 'medication:id,client_id,name,dosage'])
            ->limit(10)
            ->get();

        // Upcoming round (next pending round today)
        $nextRound = MedicationRound::where('status', 'pending')
            ->whereDate('round_date', $today)
            ->orderBy('scheduled_time')
            ->with('assignedTo:id,name')
            ->first();

        // Client status grid (per-client medication summary for today)
        $clientStatuses = Client::query()
            ->select(['id', 'first_name', 'last_name'])
            ->withCount([
                'medications as active_medications_count' => fn ($q) => $q->active(),
                'medicationAdministrations as given_today' => fn ($q) => $q->whereDate('administered_at', $today)->where('status', 'given'),
                'medicationAdministrations as pending_today' => fn ($q) => $q->whereDate('scheduled_for', $today)->where('status', 'pending'),
                'medicationAdministrations as missed_today' => fn ($q) => $q->whereDate('scheduled_for', $today)->where('status', 'missed'),
            ])
            ->having('active_medications_count', '>', 0)
            ->orderBy('last_name')
            ->get();

        // Recent activity feed (last 20 administrations)
        $recentActivity = ClientMedicationAdministration::with([
                'client:id,first_name,last_name',
                'medication:id,client_id,name',
                'administeredBy:id,name',
            ])
            ->latest('administered_at')
            ->limit(20)
            ->get();

        // Active alerts from MedicationDashboardAlert
        $activeAlertsList = MedicationDashboardAlert::active()
            ->with(['client:id,first_name,last_name', 'medication:id,client_id,name'])
            ->latest()
            ->limit(10)
            ->get();

        // Compliance snapshot
        $compliance = [
            'competencyExpiring' => MedicationCompetencyAssessment::where('expiry_date', '<=', now()->addDays(30))->where('expiry_date', '>', now())->count(),
            'competencyExpired' => MedicationCompetencyAssessment::where('expiry_date', '<', now())->count(),
            'pendingReviews' => MedicationReview::where('status', 'scheduled')->where('scheduled_date', '<=', now())->count(),
            'overdueReviews' => MedicationReview::where('status', 'overdue')->count(),
        ];

        return Inertia::render('emar/Index', [
            'stats' => [
                'totalToday' => $totalToday,
                'givenToday' => $givenToday,
                'refusedToday' => (int) ($todayAdmins->refused ?? 0),
                'withheldToday' => (int) ($todayAdmins->withheld ?? 0),
                'missedToday' => (int) ($todayAdmins->missed ?? 0),
                'pendingToday' => (int) ($todayAdmins->pending ?? 0),
                'adminRate' => $adminRate,
                'prnToday' => $prnToday,
                'prnNearLimit' => $prnNearLimit,
                'controlledCount' => $controlledCount,
                'activeDiscrepancies' => $activeDiscrepancies,
                'overdueReviews' => $overdueReviews,
                'expiringCompetencies' => $expiringCompetencies,
                'activeAlerts' => $activeAlerts,
                'lowStock' => $lowStock,
                'activeMedications' => $activeMedications,
                'activeClients' => $activeClients,
                'roundsToday' => $roundsToday,
                'roundsCompleted' => $roundsCompleted,
                'givenTrend' => $givenTrend,
            ],
            'trend' => $trend,
            'overdueMedications' => $overdueMedications,
            'nextRound' => $nextRound,
            'clientStatuses' => $clientStatuses,
            'recentActivity' => $recentActivity,
            'activeAlertsList' => $activeAlertsList,
            'compliance' => $compliance,
        ]);
    }

    // ─── MAR Charts ────────────────────────────────────────
    public function mar(Request $request)
    {
        $clients = Client::query()
            ->withCount(['medications as active_medications_count' => fn ($q) => $q->active()])
            ->having('active_medications_count', '>', 0)
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'date_of_birth', 'nhi_number']);

        $selectedClient = null;
        $marData = [];

        if ($request->filled('client_id')) {
            $selectedClient = Client::with([
                'medications' => fn ($q) => $q->active()->orderBy('name'),
                'medications.stock',
                'medications.administrations' => fn ($q) => $q->whereDate('scheduled_for', $request->input('date', today()->toDateString()))
                    ->orWhereDate('administered_at', $request->input('date', today()->toDateString())),
            ])->findOrFail($request->client_id);

            $marData = $this->buildMarData($selectedClient, $request->input('date', today()->toDateString()));
        }

        return Inertia::render('emar/MarCharts', [
            'clients' => $clients,
            'selectedClient' => $selectedClient,
            'marData' => $marData,
            'date' => $request->input('date', today()->toDateString()),
            'staff' => $this->getStaffList(),
            'allergies' => $selectedClient ? $selectedClient->medicationAllergies()->get(['allergen', 'reaction', 'severity']) : [],
            'interactions' => $selectedClient ? $this->getActiveInteractions($selectedClient) : [],
        ]);
    }

    private function buildMarData(Client $client, string $date): array
    {
        $medications = $client->medications()->active()->with(['stock', 'administrations' => function ($q) use ($date) {
            $q->whereDate('scheduled_for', $date)->orWhereDate('administered_at', $date);
        }])->get();

        $scheduled = $medications->where('is_prn', false)->values();
        $prn = $medications->where('is_prn', true)->values();

        return [
            'scheduled' => $scheduled->map(function ($med) use ($date) {
                $doseTimes = $med->dose_times ?? [];

                // If no dose_times stored yet, auto-calculate from frequency
                if (empty($doseTimes) && $med->frequency) {
                    $doseTimes = DoseSchedulingService::calculateDoseTimes($med->frequency);
                }

                // Build administration slots: for each dose_time, find matching admin record
                $administrations = collect($doseTimes)->map(function ($time) use ($med, $date) {
                    $scheduledDatetime = $date . ' ' . $time . ':00';

                    // Find an administration record matching this time slot
                    $admin = $med->administrations->first(function ($a) use ($time) {
                        if (!$a->scheduled_for) return false;
                        return $a->scheduled_for->format('H:i') === $time;
                    });

                    if ($admin) {
                        return [
                            'id' => $admin->id,
                            'scheduled_for' => $admin->scheduled_for?->toIso8601String(),
                            'administered_at' => $admin->administered_at?->toIso8601String(),
                            'status' => $admin->status,
                            'administered_by' => $admin->administeredBy?->name,
                            'witnessed_by' => $admin->witnessedBy?->name,
                            'notes' => $admin->notes,
                            'reason' => $admin->reason,
                        ];
                    }

                    // No record yet: determine if pending or missed
                    $now = now();
                    $scheduledAt = \Carbon\Carbon::parse($scheduledDatetime);
                    $status = $now->greaterThan($scheduledAt->copy()->addHour()) ? 'missed' : 'pending';

                    return [
                        'id' => null,
                        'scheduled_for' => $scheduledAt->toIso8601String(),
                        'administered_at' => null,
                        'status' => $status,
                        'administered_by' => null,
                        'witnessed_by' => null,
                        'notes' => null,
                        'reason' => null,
                    ];
                })->values();

                // Also include any administration records that don't match a dose_time slot
                $unmatchedAdmins = $med->administrations->filter(function ($a) use ($doseTimes) {
                    if (!$a->scheduled_for) return true;
                    return !in_array($a->scheduled_for->format('H:i'), $doseTimes);
                })->map(fn ($a) => [
                    'id' => $a->id,
                    'scheduled_for' => $a->scheduled_for?->toIso8601String(),
                    'administered_at' => $a->administered_at?->toIso8601String(),
                    'status' => $a->status,
                    'administered_by' => $a->administeredBy?->name,
                    'witnessed_by' => $a->witnessedBy?->name,
                    'notes' => $a->notes,
                    'reason' => $a->reason,
                ]);

                return [
                    'id' => $med->id,
                    'name' => $med->name,
                    'dosage' => $med->formatted_dose,
                    'frequency' => $med->frequency,
                    'route' => $med->route,
                    'form' => $med->form,
                    'instructions' => $med->instructions,
                    'controlled_drug' => $med->controlled_drug,
                    'high_risk' => $med->high_risk,
                    'witness_required' => $med->requiresWitness(),
                    'dose_times' => $doseTimes,
                    'administrations' => $administrations->merge($unmatchedAdmins)->values(),
                ];
            }),
            'prn' => $prn->map(fn ($med) => [
                'id' => $med->id,
                'name' => $med->name,
                'dosage' => $med->formatted_dose,
                'indication' => $med->indication,
                'max_per_day' => $med->max_per_day,
                'prn_count_24h' => $med->prn_count_last24_hours,
                'prn_remaining' => $med->prn_remaining,
                'controlled_drug' => $med->controlled_drug,
                'administrations' => $med->administrations->map(fn ($a) => [
                    'id' => $a->id,
                    'administered_at' => $a->administered_at?->toIso8601String(),
                    'status' => $a->status,
                    'reason' => $a->reason,
                    'notes' => $a->notes,
                    'administered_by' => $a->administeredBy?->name,
                ]),
            ]),
            'stats' => [
                'total_scheduled' => $scheduled->count(),
                'total_prn' => $prn->count(),
                'given' => $medications->flatMap->administrations->where('status', 'given')->count(),
                'refused' => $medications->flatMap->administrations->where('status', 'refused')->count(),
                'withheld' => $medications->flatMap->administrations->where('status', 'withheld')->count(),
                'missed' => $medications->flatMap->administrations->where('status', 'missed')->count(),
                'pending' => $medications->flatMap->administrations->where('status', 'pending')->count(),
            ],
        ];
    }

    private function getActiveInteractions(Client $client): array
    {
        $medicationNames = $client->medications()
            ->active()
            ->pluck('name')
            ->map(fn ($name) => strtolower($name))
            ->toArray();

        if (count($medicationNames) < 2) {
            return [];
        }

        $interactions = MedicationInteraction::active()
            ->where(function ($query) use ($medicationNames) {
                foreach ($medicationNames as $name) {
                    $query->orWhere(function ($q) use ($name, $medicationNames) {
                        $q->where(function ($inner) use ($name) {
                            $inner->whereRaw('LOWER(medication_a) LIKE ?', ["%{$name}%"]);
                        })->where(function ($inner) use ($medicationNames, $name) {
                            foreach ($medicationNames as $otherName) {
                                if ($otherName !== $name) {
                                    $inner->orWhereRaw('LOWER(medication_b) LIKE ?', ["%{$otherName}%"]);
                                }
                            }
                        });
                    });
                }
            })
            ->get();

        return $interactions->map(fn ($i) => [
            'drug_a' => $i->medication_a,
            'drug_b' => $i->medication_b,
            'severity' => $i->severity,
            'description' => $i->description,
        ])->toArray();
    }

    // ─── PRN Records ───────────────────────────────────────
    public function prn(Request $request)
    {
        $dateFrom = $request->input('from', now()->subDays(7)->toDateString());
        $dateTo = $request->input('to', today()->toDateString());

        $prnAdministrations = ClientMedicationAdministration::query()
            ->whereHas('medication', fn ($q) => $q->where('is_prn', true))
            ->whereBetween('administered_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->with(['client:id,first_name,last_name', 'medication:id,name,dosage,max_per_day,indication', 'administeredBy:id,name'])
            ->latest('administered_at')
            ->paginate(50);

        $pendingEffectivenessReviews = ClientMedicationAdministration::query()
            ->whereHas('medication', fn ($q) => $q->where('is_prn', true))
            ->where('status', 'given')
            ->where('administered_at', '>=', now()->subHours(4))
            ->whereDoesntHave('prnEffectiveness')
            ->with(['client:id,first_name,last_name', 'medication:id,name'])
            ->latest('administered_at')
            ->limit(20)
            ->get();

        $prnStats = [
            'total_given_period' => ClientMedicationAdministration::query()
                ->whereHas('medication', fn ($q) => $q->where('is_prn', true))
                ->whereBetween('administered_at', [$dateFrom, $dateTo . ' 23:59:59'])
                ->where('status', 'given')
                ->count(),
            'effectiveness_reviews_pending' => $pendingEffectivenessReviews->count(),
            'near_limit_medications' => ClientMedication::active()->prn()
                ->get()
                ->filter(fn ($m) => $m->isPrnNearLimit())
                ->count(),
        ];

        return Inertia::render('emar/PrnRecords', [
            'administrations' => $prnAdministrations,
            'pendingReviews' => $pendingEffectivenessReviews,
            'stats' => $prnStats,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ]);
    }

    // ─── Controlled Drugs ──────────────────────────────────
    public function controlled(Request $request)
    {
        $controlledMedications = ClientMedication::query()
            ->active()
            ->controlled()
            ->with([
                'client:id,first_name,last_name',
                'stock',
            ])
            ->orderBy('name')
            ->get();

        $recentEntries = ClientControlledDrugEntry::query()
            ->with(['client:id,first_name,last_name', 'medication:id,name', 'recordedBy:id,name', 'witnessedBy:id,name'])
            ->latest('recorded_at')
            ->limit(50)
            ->get();

        $discrepancies = ClientControlledDrugDiscrepancy::query()
            ->whereIn('status', ['reported', 'investigating'])
            ->with(['client:id,first_name,last_name', 'medication:id,name'])
            ->latest()
            ->get();

        $destructions = MedicationDestruction::query()
            ->controlled()
            ->with(['client:id,first_name,last_name', 'destroyedByUser:id,name', 'witness1:id,name'])
            ->latest('destroyed_at')
            ->limit(20)
            ->get();

        $lossReports = ControlledDrugLossReport::with(['client:id,first_name,last_name', 'discoveredBy:id,name'])
            ->latest()
            ->get();

        return Inertia::render('emar/ControlledDrugs', [
            'medications' => $controlledMedications,
            'recentEntries' => $recentEntries,
            'discrepancies' => $discrepancies,
            'destructions' => $destructions,
            'lossReports' => $lossReports,
            'staff' => $this->getStaffList(),
            'clients' => $this->getClientsList(),
        ]);
    }

    // ─── Medications Database ──────────────────────────────
    public function medications(Request $request)
    {
        $medications = ClientMedication::query()
            ->with(['client:id,first_name,last_name', 'stock'])
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->when($request->status === 'active', fn ($q) => $q->active())
            ->when($request->status === 'ceased', fn ($q) => $q->where('state', 'ceased'))
            ->when($request->status === 'paused', fn ($q) => $q->where('state', 'paused'))
            ->when($request->type === 'prn', fn ($q) => $q->prn())
            ->when($request->type === 'controlled', fn ($q) => $q->controlled())
            ->when($request->type === 'high_risk', fn ($q) => $q->highRisk())
            ->when($request->client_id, fn ($q, $id) => $q->where('client_id', $id))
            ->latest()
            ->paginate(50);

        $clients = Client::orderBy('last_name')->get(['id', 'first_name', 'last_name']);

        // Build a map of medication IDs that have known interactions with other active meds for the same client
        $interactionMap = [];
        $medsByClient = $medications->getCollection()->groupBy('client_id');
        foreach ($medsByClient as $clientId => $clientMeds) {
            $names = $clientMeds->pluck('name')->map(fn ($n) => strtolower($n))->toArray();
            if (count($names) < 2) continue;

            $clientInteractions = MedicationInteraction::active()
                ->where(function ($query) use ($names) {
                    foreach ($names as $name) {
                        $query->orWhere(function ($q) use ($name, $names) {
                            $q->whereRaw('LOWER(medication_a) LIKE ?', ["%{$name}%"])
                              ->where(function ($inner) use ($names, $name) {
                                  foreach ($names as $other) {
                                      if ($other !== $name) {
                                          $inner->orWhereRaw('LOWER(medication_b) LIKE ?', ["%{$other}%"]);
                                      }
                                  }
                              });
                        });
                    }
                })
                ->get();

            foreach ($clientInteractions as $interaction) {
                foreach ($clientMeds as $med) {
                    $medLower = strtolower($med->name);
                    if (
                        str_contains(strtolower($interaction->medication_a), $medLower) ||
                        str_contains(strtolower($interaction->medication_b), $medLower)
                    ) {
                        $interactionMap[$med->id] = $interaction->severity;
                    }
                }
            }
        }

        return Inertia::render('emar/Medications', [
            'medications' => $medications,
            'clients' => $clients,
            'staff' => $this->getStaffList(),
            'filters' => $request->only(['search', 'status', 'type', 'client_id']),
            'interactionMap' => $interactionMap,
        ]);
    }

    // ─── Stock Management ──────────────────────────────────
    public function stock(Request $request)
    {
        $stockItems = ClientMedicationStock::query()
            ->with(['medication' => fn ($q) => $q->with('client:id,first_name,last_name')])
            ->whereHas('medication', fn ($q) => $q->active())
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'medication_id' => $s->client_medication_id,
                'medication_name' => $s->medication?->name,
                'client_name' => $s->medication?->client?->first_name . ' ' . $s->medication?->client?->last_name,
                'client_id' => $s->medication?->client_id,
                'on_hand' => $s->on_hand,
                'unit' => $s->unit,
                'reorder_level' => $s->reorder_level,
                'last_counted_at' => $s->last_counted_at,
                'is_low' => $s->isLowStock(),
                'controlled' => $s->medication?->controlled_drug,
                'expiry_date' => $s->expiry_date?->toDateString(),
                'batch_number' => $s->batch_number,
                'supplier_name' => $s->supplier_name,
                'reorder_quantity' => $s->reorder_quantity,
                'is_expired' => $s->isExpired(),
                'is_expiring_soon' => $s->isExpiringSoon(30),
                'is_expiring_90' => $s->isExpiringSoon(90),
            ]);

        $lowStockCount = $stockItems->where('is_low', true)->count();

        $pharmacyOrders = MedicationPharmacyOrder::query()
            ->pending()
            ->with(['client:id,first_name,last_name', 'medication:id,name'])
            ->latest()
            ->limit(20)
            ->get();

        return Inertia::render('emar/StockManagement', [
            'stockItems' => $stockItems,
            'lowStockCount' => $lowStockCount,
            'expiringCount' => ClientMedicationStock::expiringSoon()->count(),
            'expiredCount' => ClientMedicationStock::expired()->count(),
            'pharmacyOrders' => $pharmacyOrders,
            'clients' => $this->getClientsList(),
            'activeMedications' => ClientMedication::active()->with('client:id,first_name,last_name')->orderBy('name')->get(['id', 'name', 'client_id']),
        ]);
    }

    // ─── Prescriptions / Prescriber Orders ─────────────────
    public function prescriptions(Request $request)
    {
        $orders = MedicationPrescriberOrder::query()
            ->with(['client:id,first_name,last_name', 'medication:id,name', 'receivedByUser:id,name'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->client_id, fn ($q, $id) => $q->where('client_id', $id))
            ->latest('order_date')
            ->paginate(50);

        $pendingCountersigns = MedicationPrescriberOrder::awaitingCountersign()->count();

        $covertAuthorisations = MedicationCovertAuthorisation::query()
            ->active()
            ->with(['client:id,first_name,last_name', 'medication:id,name'])
            ->get();

        $clients = Client::orderBy('last_name')->get(['id', 'first_name', 'last_name']);

        return Inertia::render('emar/Prescriptions', [
            'orders' => $orders,
            'pendingCountersigns' => $pendingCountersigns,
            'covertAuthorisations' => $covertAuthorisations,
            'clients' => $clients,
            'staff' => $this->getStaffList(),
            'filters' => $request->only(['status', 'client_id']),
        ]);
    }

    // ─── Competency Assessments ────────────────────────────
    public function competency(Request $request)
    {
        $assessments = MedicationCompetencyAssessment::query()
            ->with(['user:id,name,email', 'assessor:id,name'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest('assessment_date')
            ->paginate(50);

        $expiringSoon = MedicationCompetencyAssessment::expiringSoon(30)->with('user:id,name')->get();
        $expired = MedicationCompetencyAssessment::expired()->with('user:id,name')->get();

        $staffWithoutAssessment = User::query()
            ->whereDoesntHave('medicationCompetencyAssessments', fn ($q) => $q->active())
            ->get(['id', 'name', 'email']);

        return Inertia::render('emar/Competency', [
            'assessments' => $assessments,
            'expiringSoon' => $expiringSoon,
            'expired' => $expired,
            'staffWithoutAssessment' => $staffWithoutAssessment,
            'staff' => $this->getStaffList(),
            'filters' => $request->only(['status']),
        ]);
    }

    // ─── Medication Reviews ────────────────────────────────
    public function reviews(Request $request)
    {
        $reviews = MedicationReview::query()
            ->with(['client:id,first_name,last_name', 'reviewer:id,name', 'requestedBy:id,name'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->client_id, fn ($q, $id) => $q->where('client_id', $id))
            ->latest('scheduled_date')
            ->paginate(50);

        $overdueReviews = MedicationReview::overdue()
            ->with('client:id,first_name,last_name')
            ->get();

        $upcomingReviews = MedicationReview::upcoming(30)
            ->with('client:id,first_name,last_name')
            ->get();

        $clients = Client::orderBy('last_name')->get(['id', 'first_name', 'last_name']);

        return Inertia::render('emar/Reviews', [
            'reviews' => $reviews,
            'overdueReviews' => $overdueReviews,
            'upcomingReviews' => $upcomingReviews,
            'clients' => $clients,
            'staff' => $this->getStaffList(),
            'filters' => $request->only(['status', 'client_id']),
        ]);
    }

    // ─── Medication Rounds ─────────────────────────────────
    public function rounds(Request $request)
    {
        $date = $request->input('date', today()->toDateString());

        $rounds = MedicationRound::query()
            ->forDate($date)
            ->with(['assignedTo:id,name', 'startedBy:id,name', 'completedBy:id,name'])
            ->orderBy('scheduled_time')
            ->get();

        $templates = MedicationRoundTemplate::query()
            ->with('defaultAssignedTo:id,name')
            ->orderBy('scheduled_time')
            ->get();

        // Last auto-generated round timestamp
        $lastGenerated = MedicationRound::whereNotNull('round_template_id')
            ->latest('created_at')
            ->value('created_at');

        return Inertia::render('emar/Rounds', [
            'rounds' => $rounds,
            'templates' => $templates,
            'staff' => $this->getStaffList(),
            'date' => $date,
            'lastGenerated' => $lastGenerated?->toIso8601String(),
        ]);
    }

    // ─── Self-Administration Assessments ───────────────────
    public function selfAdmin(Request $request)
    {
        $assessments = MedicationSelfAdminAssessment::query()
            ->with(['client:id,first_name,last_name', 'assessor:id,name'])
            ->when($request->client_id, fn ($q, $id) => $q->where('client_id', $id))
            ->latest('assessment_date')
            ->paginate(50);

        $dueReassessments = MedicationSelfAdminAssessment::query()
            ->where('status', 'completed')
            ->where('reassessment_date', '<=', today()->toDateString())
            ->with('client:id,first_name,last_name')
            ->get();

        $clients = Client::orderBy('last_name')->get(['id', 'first_name', 'last_name']);

        return Inertia::render('emar/SelfAdmin', [
            'assessments' => $assessments,
            'dueReassessments' => $dueReassessments,
            'clients' => $clients,
            'staff' => $this->getStaffList(),
            'filters' => $request->only(['client_id']),
        ]);
    }

    // ─── Destruction Records ───────────────────────────────
    public function destructions(Request $request)
    {
        $destructions = MedicationDestruction::query()
            ->with(['client:id,first_name,last_name', 'medication:id,name', 'destroyedByUser:id,name', 'witness1:id,name', 'witness2:id,name'])
            ->when($request->controlled_only, fn ($q) => $q->controlled())
            ->latest('destroyed_at')
            ->paginate(50);

        return Inertia::render('emar/Destructions', [
            'destructions' => $destructions,
            'staff' => $this->getStaffList(),
            'clients' => $this->getClientsList(),
            'medications' => ClientMedication::active()->orderBy('name')->get(['id', 'name', 'client_id']),
            'filters' => $request->only(['controlled_only']),
        ]);
    }

    // ─── Handovers ─────────────────────────────────────────
    public function handovers(Request $request)
    {
        $handovers = MedicationHandover::query()
            ->with(['outgoingUser:id,name', 'incomingUser:id,name', 'site:id,name'])
            ->latest('handover_at')
            ->paginate(50);

        return Inertia::render('emar/Handovers', [
            'handovers' => $handovers,
            'staff' => $this->getStaffList(),
        ]);
    }

    // ═════════════════════════════════════════════════════════
    // CRUD / Workflow Methods
    // ═════════════════════════════════════════════════════════

    // ─── Prescriber Orders CRUD ─────────────────────────────

    public function storePrescription(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'client_medication_id' => 'nullable|exists:client_medications,id',
            'order_type' => 'required|in:new,change,cease,verbal,telephone',
            'prescriber_name' => 'required|string|max:255',
            'prescriber_registration' => 'nullable|string|max:255',
            'prescriber_type' => 'nullable|string|max:255',
            'medication_name' => 'required|string|max:255',
            'dose' => 'required|string|max:255',
            'route' => 'required|string|max:255',
            'frequency' => 'required|string|max:255',
            'instructions' => 'nullable|string',
            'indication' => 'nullable|string',
            'clinical_notes' => 'nullable|string',
            'order_date' => 'required|date',
            'effective_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
        ]);

        $validated['received_by'] = auth()->id();
        $validated['status'] = 'pending';
        $validated['requires_countersign'] = in_array($validated['order_type'], ['verbal', 'telephone']);

        MedicationPrescriberOrder::create($validated);

        return redirect()->back();
    }

    public function updatePrescription(Request $request, MedicationPrescriberOrder $order)
    {
        $validated = $request->validate([
            'status' => 'nullable|string|max:255',
            'pharmacy_notes' => 'nullable|string',
            'pharmacy_name' => 'nullable|string|max:255',
            'batch_number' => 'nullable|string|max:255',
            'batch_expiry' => 'nullable|date',
            'dispensed_by' => 'nullable|exists:users,id',
            'dispensed_at' => 'nullable|date',
            'clinical_notes' => 'nullable|string',
            'instructions' => 'nullable|string',
        ]);

        $order->update($validated);

        return redirect()->back();
    }

    public function countersignPrescription(MedicationPrescriberOrder $order)
    {
        $order->update([
            'countersigned_at' => now(),
            'countersigned_by' => auth()->id(),
        ]);

        return redirect()->back();
    }

    public function destroyPrescription(MedicationPrescriberOrder $order)
    {
        $order->update(['status' => 'cancelled']);

        return redirect()->back();
    }

    // ─── Covert Authorisations CRUD ─────────────────────────

    public function storeCovert(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'client_medication_id' => 'required|exists:client_medications,id',
            'authorised_by_name' => 'required|string|max:255',
            'authorised_by_registration' => 'nullable|string|max:255',
            'clinical_justification' => 'required|string',
            'legal_basis' => 'nullable|string',
            'administration_method' => 'nullable|string|max:255',
            'pharmacist_advice' => 'nullable|string',
            'authorised_date' => 'required|date',
            'review_date' => 'required|date|after:authorised_date',
        ]);

        $validated['status'] = 'active';
        $validated['recorded_by'] = auth()->id();

        MedicationCovertAuthorisation::create($validated);

        return redirect()->back();
    }

    public function revokeCovert(MedicationCovertAuthorisation $authorisation)
    {
        $authorisation->update(['status' => 'revoked']);

        return redirect()->back();
    }

    // ─── Reviews CRUD ───────────────────────────────────────

    public function storeReview(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'review_type' => 'required|string|max:255',
            'scheduled_date' => 'required|date',
            'reviewer_name' => 'nullable|string|max:255',
            'reviewer_role' => 'nullable|string|max:255',
            'reviewer_user_id' => 'nullable|exists:users,id',
            'trigger_reason' => 'nullable|string',
        ]);

        $validated['status'] = 'scheduled';
        $validated['requested_by'] = auth()->id();

        MedicationReview::create($validated);

        return redirect()->back();
    }

    public function updateReview(Request $request, MedicationReview $review)
    {
        $validated = $request->validate([
            'review_type' => 'nullable|string|max:255',
            'scheduled_date' => 'nullable|date',
            'reviewer_name' => 'nullable|string|max:255',
            'reviewer_role' => 'nullable|string|max:255',
            'reviewer_user_id' => 'nullable|exists:users,id',
            'trigger_reason' => 'nullable|string',
        ]);

        $review->update($validated);

        return redirect()->back();
    }

    public function completeReview(Request $request, MedicationReview $review)
    {
        $validated = $request->validate([
            'clinical_summary' => 'required|string',
            'medications_reviewed' => 'nullable|array',
            'recommendations' => 'nullable|string',
            'actions' => 'nullable|array',
            'whanau_involved' => 'nullable|boolean',
            'whanau_notes' => 'nullable|string',
            'next_review_date' => 'nullable|date',
        ]);

        $validated['status'] = 'completed';
        $validated['completed_date'] = today();

        $review->update($validated);

        return redirect()->back();
    }

    public function destroyReview(MedicationReview $review)
    {
        $review->update(['status' => 'cancelled']);

        return redirect()->back();
    }

    // ─── Competency CRUD ────────────────────────────────────

    public function storeCompetency(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'assessment_type' => 'required|string|max:255',
            'assessment_date' => 'required|date',
            'medication_knowledge' => 'required|boolean',
            'five_rights' => 'required|boolean',
            'safety_checks' => 'required|boolean',
            'documentation' => 'required|boolean',
            'controlled_drugs' => 'required|boolean',
            'prn_assessment' => 'required|boolean',
            'insulin_competent' => 'required|boolean',
            'inhaler_competent' => 'required|boolean',
            'topical_competent' => 'required|boolean',
            'covert_admin_knowledge' => 'required|boolean',
            'error_reporting' => 'required|boolean',
            'allergy_awareness' => 'required|boolean',
            'strengths' => 'nullable|string',
            'areas_for_improvement' => 'nullable|string',
            'action_plan' => 'nullable|string',
            'assessor_comments' => 'nullable|string',
        ]);

        $booleanFields = [
            'medication_knowledge', 'five_rights', 'safety_checks', 'documentation',
            'controlled_drugs', 'prn_assessment', 'insulin_competent', 'inhaler_competent',
            'topical_competent', 'covert_admin_knowledge', 'error_reporting', 'allergy_awareness',
        ];

        $totalScore = collect($booleanFields)->filter(fn ($f) => !empty($validated[$f]))->count();

        $validated['total_score'] = $totalScore;
        $validated['pass_threshold'] = 10;
        $validated['status'] = $totalScore >= 10 ? 'passed' : 'failed';
        $validated['assessor_id'] = auth()->id();
        $validated['expiry_date'] = \Carbon\Carbon::parse($validated['assessment_date'])->addYear()->toDateString();

        MedicationCompetencyAssessment::create($validated);

        return redirect()->back();
    }

    public function updateCompetency(Request $request, MedicationCompetencyAssessment $assessment)
    {
        $validated = $request->validate([
            'assessment_type' => 'nullable|string|max:255',
            'assessment_date' => 'nullable|date',
            'medication_knowledge' => 'nullable|boolean',
            'five_rights' => 'nullable|boolean',
            'safety_checks' => 'nullable|boolean',
            'documentation' => 'nullable|boolean',
            'controlled_drugs' => 'nullable|boolean',
            'prn_assessment' => 'nullable|boolean',
            'insulin_competent' => 'nullable|boolean',
            'inhaler_competent' => 'nullable|boolean',
            'topical_competent' => 'nullable|boolean',
            'covert_admin_knowledge' => 'nullable|boolean',
            'error_reporting' => 'nullable|boolean',
            'allergy_awareness' => 'nullable|boolean',
            'strengths' => 'nullable|string',
            'areas_for_improvement' => 'nullable|string',
            'action_plan' => 'nullable|string',
            'assessor_comments' => 'nullable|string',
            'expiry_date' => 'nullable|date',
        ]);

        $assessment->update($validated);

        return redirect()->back();
    }

    public function destroyCompetency(MedicationCompetencyAssessment $assessment)
    {
        $assessment->delete();

        return redirect()->back();
    }

    // ─── Rounds CRUD / Workflow ─────────────────────────────

    public function storeRoundTemplate(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'scheduled_time' => 'required|date_format:H:i',
            'window_minutes' => 'required|integer|min:5|max:120',
            'days_of_week' => 'nullable|array',
            'days_of_week.*' => 'integer|min:0|max:6',
            'site_id' => 'nullable|exists:sites,id',
            'default_assigned_to' => 'nullable|exists:users,id',
        ]);

        $validated['active'] = true;

        MedicationRoundTemplate::create($validated);

        return redirect()->back();
    }

    public function updateRoundTemplate(Request $request, MedicationRoundTemplate $template)
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'scheduled_time' => 'nullable|date_format:H:i',
            'window_minutes' => 'nullable|integer|min:5|max:120',
            'days_of_week' => 'nullable|array',
            'days_of_week.*' => 'integer|min:0|max:6',
            'active' => 'nullable|boolean',
            'default_assigned_to' => 'nullable|exists:users,id',
        ]);

        $template->update($validated);

        return redirect()->back();
    }

    public function destroyRoundTemplate(MedicationRoundTemplate $template)
    {
        $template->delete();

        return redirect()->back();
    }

    public function generateRounds(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
        ]);

        $date = \Carbon\Carbon::parse($validated['date']);
        $dayOfWeek = $date->dayOfWeekIso; // 1=Mon, 7=Sun

        $templates = MedicationRoundTemplate::active()->get();
        $totalMedications = ClientMedication::active()->count();
        $created = 0;

        foreach ($templates as $template) {
            if (!$template->appliesToDay($dayOfWeek)) {
                continue;
            }

            // Skip if round already exists for this template on this date
            $exists = MedicationRound::where('round_template_id', $template->id)
                ->whereDate('round_date', $date)
                ->exists();

            if ($exists) {
                continue;
            }

            MedicationRound::create([
                'name' => $template->name,
                'round_template_id' => $template->id,
                'round_type' => 'scheduled',
                'scheduled_time' => $template->scheduled_time,
                'window_minutes' => $template->window_minutes ?? 60,
                'round_date' => $date->toDateString(),
                'status' => 'pending',
                'assigned_to' => $template->default_assigned_to,
                'total_medications' => $totalMedications,
                'site_id' => $template->site_id,
                'service_context_id' => $template->service_context_id,
            ]);

            $created++;
        }

        return redirect()->back();
    }

    public function startRound(MedicationRound $round)
    {
        $round->update([
            'status' => 'in_progress',
            'started_by' => auth()->id(),
            'started_at' => now(),
        ]);

        return redirect()->back();
    }

    public function completeRound(MedicationRound $round)
    {
        $round->update([
            'status' => 'completed',
            'completed_by' => auth()->id(),
            'completed_at' => now(),
        ]);

        $round->updateCounts();

        return redirect()->back();
    }

    public function assignRound(Request $request, MedicationRound $round)
    {
        $validated = $request->validate([
            'assigned_to' => 'required|exists:users,id',
        ]);

        $round->update($validated);

        return redirect()->back();
    }

    // ─── Self-Admin CRUD ────────────────────────────────────

    public function storeSelfAdmin(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'cognitive_capacity' => 'required|integer|min:1|max:5',
            'physical_dexterity' => 'required|integer|min:1|max:5',
            'vision_ability' => 'required|integer|min:1|max:5',
            'swallowing_ability' => 'required|integer|min:1|max:5',
            'understanding_score' => 'required|integer|min:1|max:5',
            'can_identify_medications' => 'required|boolean',
            'can_read_labels' => 'required|boolean',
            'can_open_packaging' => 'required|boolean',
            'can_manage_timing' => 'required|boolean',
            'can_store_safely' => 'required|boolean',
            'willing_to_self_admin' => 'required|boolean',
            'risk_factors' => 'nullable|string',
            'support_needed' => 'nullable|string',
            'safe_storage_notes' => 'nullable|string',
            'assessor_notes' => 'nullable|string',
            'reassessment_date' => 'nullable|date',
            'reassessment_trigger' => 'nullable|string',
        ]);

        $totalScore = $validated['cognitive_capacity']
            + $validated['physical_dexterity']
            + $validated['vision_ability']
            + $validated['swallowing_ability']
            + $validated['understanding_score'];

        $validated['outcome'] = match (true) {
            $totalScore >= 21 => 'independent',
            $totalScore >= 16 => 'prompted',
            $totalScore >= 11 => 'supervised',
            default => 'administered',
        };

        $validated['assessed_by'] = auth()->id();
        $validated['assessment_date'] = today();
        $validated['status'] = 'completed';

        MedicationSelfAdminAssessment::create($validated);

        return redirect()->back();
    }

    public function updateSelfAdmin(Request $request, MedicationSelfAdminAssessment $assessment)
    {
        $validated = $request->validate([
            'cognitive_capacity' => 'nullable|integer|min:1|max:5',
            'physical_dexterity' => 'nullable|integer|min:1|max:5',
            'vision_ability' => 'nullable|integer|min:1|max:5',
            'swallowing_ability' => 'nullable|integer|min:1|max:5',
            'understanding_score' => 'nullable|integer|min:1|max:5',
            'can_identify_medications' => 'nullable|boolean',
            'can_read_labels' => 'nullable|boolean',
            'can_open_packaging' => 'nullable|boolean',
            'can_manage_timing' => 'nullable|boolean',
            'can_store_safely' => 'nullable|boolean',
            'willing_to_self_admin' => 'nullable|boolean',
            'risk_factors' => 'nullable|string',
            'support_needed' => 'nullable|string',
            'safe_storage_notes' => 'nullable|string',
            'assessor_notes' => 'nullable|string',
            'reassessment_date' => 'nullable|date',
            'reassessment_trigger' => 'nullable|string',
            'outcome' => 'nullable|string|in:independent,prompted,supervised,administered',
        ]);

        $assessment->update($validated);

        return redirect()->back();
    }

    public function destroySelfAdmin(MedicationSelfAdminAssessment $assessment)
    {
        $assessment->delete();

        return redirect()->back();
    }

    // ─── Destructions CRUD ──────────────────────────────────

    public function storeDestruction(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'client_medication_id' => 'nullable|exists:client_medications,id',
            'medication_name' => 'required|string|max:255',
            'form' => 'nullable|string|max:255',
            'strength' => 'nullable|string|max:255',
            'quantity' => 'required|numeric|min:0.01',
            'unit' => 'required|string|max:50',
            'batch_number' => 'nullable|string|max:255',
            'expiry_date' => 'nullable|date',
            'reason' => 'required|string|max:255',
            'disposal_method' => 'required|string|max:255',
            'is_controlled_drug' => 'nullable|boolean',
            'controlled_drug_class' => 'nullable|string|max:50',
            'witness_1_id' => 'required|exists:users,id',
            'witness_2_id' => 'nullable|exists:users,id',
            'authorised_by_name' => 'nullable|string|max:255',
            'authorised_by_registration' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        // For controlled drugs, require second witness and authorisation
        if (!empty($validated['is_controlled_drug'])) {
            $request->validate([
                'witness_2_id' => 'required|exists:users,id',
                'authorised_by_name' => 'required|string|max:255',
            ]);
        }

        // Ensure witness 1 is not the current user
        if ($validated['witness_1_id'] == auth()->id()) {
            return redirect()->back()->withErrors(['witness_1_id' => 'Witness must be a different person from the person destroying the medication.']);
        }

        $validated['destroyed_by'] = auth()->id();
        $validated['destroyed_at'] = now();

        DB::transaction(function () use ($validated) {
            MedicationDestruction::create($validated);

            if (!empty($validated['client_medication_id'])) {
                $stock = ClientMedicationStock::where('client_medication_id', $validated['client_medication_id'])->first();
                if ($stock) {
                    $stock->decrement('on_hand', $validated['quantity']);
                }
            }
        });

        return redirect()->back();
    }

    // ─── Handovers CRUD ─────────────────────────────────────

    public function storeHandover(Request $request)
    {
        $validated = $request->validate([
            'incoming_user_id' => 'required|exists:users,id',
            'site_id' => 'nullable|exists:sites,id',
            'shift_id' => 'nullable|integer',
            'controlled_drug_counts' => 'nullable|array',
            'controlled_drugs_verified' => 'nullable|boolean',
            'outstanding_medications' => 'nullable|array',
            'new_prescriptions' => 'nullable|array',
            'ceased_medications' => 'nullable|array',
            'incidents' => 'nullable|array',
            'prn_given' => 'nullable|array',
            'flagged_clients' => 'nullable|array',
            'general_notes' => 'nullable|string',
            'checklist_items' => 'nullable|array',
            'checklist_items.*.label' => 'required|string|max:255',
            'checklist_items.*.checked' => 'required|boolean',
            'checklist_items.*.notes' => 'nullable|string|max:500',
            'safety_concerns' => 'nullable|string|max:5000',
            'medication_errors_count' => 'nullable|integer|min:0',
            'pending_gp_followups' => 'nullable|integer|min:0',
            'clients_requiring_attention' => 'nullable|array',
            'clients_requiring_attention.*.client_id' => 'nullable|string',
            'clients_requiring_attention.*.client_name' => 'required|string|max:255',
            'clients_requiring_attention.*.reason' => 'required|string|max:500',
            'previous_shift_notes_read' => 'nullable|boolean',
            'stock_issues_identified' => 'nullable|string|max:5000',
            'prescriber_changes_summary' => 'nullable|string|max:5000',
        ]);

        $validated['outgoing_user_id'] = auth()->id();
        $validated['handover_at'] = now();
        $validated['acknowledged'] = false;

        MedicationHandover::create($validated);

        return redirect()->back();
    }

    public function acknowledgeHandover(MedicationHandover $handover)
    {
        $handover->update([
            'acknowledged' => true,
            'acknowledged_at' => now(),
        ]);

        return redirect()->back();
    }

    // ─── Pharmacy Orders + Stock CRUD ───────────────────────

    public function storePharmacyOrder(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'client_medication_id' => 'required|exists:client_medications,id',
            'pharmacy_name' => 'required|string|max:255',
            'pharmacy_phone' => 'nullable|string|max:255',
            'pharmacy_email' => 'nullable|string|email|max:255',
            'quantity_ordered' => 'required|integer|min:1',
            'order_notes' => 'nullable|string',
            'order_type' => 'nullable|string|max:255',
        ]);

        $validated['status'] = 'draft';
        $validated['ordered_by'] = auth()->id();

        MedicationPharmacyOrder::create($validated);

        return redirect()->back();
    }

    public function updatePharmacyOrder(Request $request, MedicationPharmacyOrder $order)
    {
        $validated = $request->validate([
            'order_notes' => 'nullable|string',
            'pharmacy_name' => 'nullable|string|max:255',
            'pharmacy_phone' => 'nullable|string|max:255',
            'pharmacy_email' => 'nullable|string|email|max:255',
            'quantity_ordered' => 'nullable|integer|min:1',
            'delivery_notes' => 'nullable|string',
        ]);

        $order->update($validated);

        return redirect()->back();
    }

    public function advancePharmacyOrder(Request $request, MedicationPharmacyOrder $order)
    {
        $transitions = [
            'draft' => 'submitted',
            'submitted' => 'confirmed',
            'confirmed' => 'dispensed',
            'dispensed' => 'delivered',
        ];

        $nextStatus = $transitions[$order->status] ?? null;

        if (!$nextStatus) {
            return redirect()->back()->withErrors(['status' => 'Order cannot be advanced from its current status.']);
        }

        $updateData = ['status' => $nextStatus];

        switch ($nextStatus) {
            case 'submitted':
                $updateData['submitted_at'] = now();
                break;
            case 'confirmed':
                $updateData['confirmed_at'] = now();
                break;
            case 'dispensed':
                $request->validate([
                    'batch_number' => 'nullable|string|max:255',
                    'batch_expiry' => 'nullable|date',
                ]);
                $updateData['dispensed_at'] = now();
                $updateData['batch_number'] = $request->input('batch_number');
                $updateData['batch_expiry'] = $request->input('batch_expiry');
                break;
            case 'delivered':
                $request->validate([
                    'quantity_received' => 'nullable|integer|min:0',
                    'delivery_notes' => 'nullable|string',
                ]);
                $updateData['delivered_at'] = now();
                $updateData['received_by'] = auth()->id();
                $updateData['quantity_received'] = $request->input('quantity_received', $order->quantity_ordered);
                $updateData['delivery_notes'] = $request->input('delivery_notes');

                break;
        }

        DB::transaction(function () use ($order, $updateData, $nextStatus) {
            $order->update($updateData);

            if ($nextStatus === 'delivered') {
                $quantityReceived = $updateData['quantity_received'] ?? 0;
                if ($order->client_medication_id && $quantityReceived > 0) {
                    $stock = ClientMedicationStock::firstOrCreate(
                        ['client_medication_id' => $order->client_medication_id],
                        ['on_hand' => 0, 'unit' => 'units']
                    );
                    $stock->increment('on_hand', $quantityReceived);
                }
            }
        });

        return redirect()->back();
    }

    public function receiveStock(Request $request)
    {
        $validated = $request->validate([
            'client_medication_id' => 'required|exists:client_medications,id',
            'quantity' => 'required|integer|min:1',
        ]);

        DB::transaction(function () use ($validated) {
            $stock = ClientMedicationStock::firstOrCreate(
                ['client_medication_id' => $validated['client_medication_id']],
                ['on_hand' => 0, 'unit' => 'units']
            );
            $stock->increment('on_hand', $validated['quantity']);
            $stock->update(['last_counted_at' => now()]);
        });

        return redirect()->back();
    }

    public function adjustStock(Request $request)
    {
        $validated = $request->validate([
            'client_medication_id' => 'required|exists:client_medications,id',
            'new_quantity' => 'required|integer|min:0',
            'reason' => 'required|string|max:500',
        ]);

        DB::transaction(function () use ($validated) {
            $stock = ClientMedicationStock::firstOrCreate(
                ['client_medication_id' => $validated['client_medication_id']],
                ['on_hand' => 0, 'unit' => 'units']
            );
            $stock->update([
                'on_hand' => $validated['new_quantity'],
                'last_counted_at' => now(),
                'notes' => 'Stock adjustment: ' . $validated['reason'],
            ]);
        });

        return redirect()->back();
    }

    // ─── PRN Effectiveness CRUD ─────────────────────────────

    public function storePrnEffectiveness(Request $request)
    {
        $validated = $request->validate([
            'client_medication_administration_id' => 'required|exists:client_medication_administrations,id',
            'effectiveness' => 'required|in:effective,partially_effective,not_effective',
            'review_minutes_after' => 'nullable|integer|min:0',
            'observations' => 'nullable|string',
            'escalation_needed' => 'nullable|boolean',
            'escalation_action' => 'nullable|string',
        ]);

        // Get administration to populate client and medication IDs
        $administration = ClientMedicationAdministration::findOrFail($validated['client_medication_administration_id']);

        $validated['client_id'] = $administration->client_id;
        $validated['client_medication_id'] = $administration->client_medication_id;
        $validated['reviewed_by'] = auth()->id();
        $validated['reviewed_at'] = now();

        MedicationPrnEffectiveness::create($validated);

        return redirect()->back();
    }

    // ─── Medications CRUD ─────────────────────────────────

    public function storeMedication(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'medication_name' => 'required|string|max:255',
            'brand_name' => 'nullable|string|max:255',
            'dose' => 'required|string|max:100',
            'dose_unit' => 'nullable|string|max:50',
            'frequency' => 'required|string|max:100',
            'route' => 'nullable|string|max:50',
            'form' => 'nullable|string|max:50',
            'instructions' => 'nullable|string|max:2000',
            'indication' => 'nullable|string|max:500',
            'is_prn' => 'nullable|boolean',
            'prn_reason' => 'nullable|string|max:500',
            'max_doses_per_day' => 'nullable|integer|min:1',
            'min_hours_between_doses' => 'nullable|numeric|min:0',
            'is_controlled_drug' => 'nullable|boolean',
            'is_high_risk' => 'nullable|boolean',
            'witness_required' => 'nullable|boolean',
            'start_date' => 'nullable|date',
            'prescriber_name' => 'nullable|string|max:255',
        ]);

        $medication = ClientMedication::create([
            'client_id' => $validated['client_id'],
            'name' => $validated['medication_name'],
            'brand_name' => $validated['brand_name'] ?? null,
            'dosage' => $validated['dose'],
            'dose_unit' => $validated['dose_unit'] ?? null,
            'frequency' => $validated['frequency'],
            'route' => $validated['route'] ?? null,
            'form' => $validated['form'] ?? null,
            'instructions' => $validated['instructions'] ?? null,
            'indication' => $validated['indication'] ?? null,
            'is_prn' => $validated['is_prn'] ?? false,
            'prn_reason' => $validated['prn_reason'] ?? null,
            'max_doses_per_day' => $validated['max_doses_per_day'] ?? null,
            'min_hours_between_doses' => $validated['min_hours_between_doses'] ?? null,
            'is_controlled_drug' => $validated['is_controlled_drug'] ?? false,
            'is_high_risk' => $validated['is_high_risk'] ?? false,
            'witness_required' => $validated['witness_required'] ?? false,
            'start_date' => $validated['start_date'] ?? now()->toDateString(),
            'prescriber_name' => $validated['prescriber_name'] ?? null,
            'state' => 'active',
        ]);

        // Auto-calculate dose times from the frequency
        $medication->update([
            'dose_times' => DoseSchedulingService::calculateDoseTimes($validated['frequency']),
        ]);

        return redirect()->back();
    }

    public function updateMedication(Request $request, ClientMedication $medication)
    {
        $validated = $request->validate([
            'medication_name' => 'sometimes|string|max:255',
            'brand_name' => 'nullable|string|max:255',
            'dose' => 'sometimes|string|max:100',
            'dose_unit' => 'nullable|string|max:50',
            'frequency' => 'sometimes|string|max:100',
            'route' => 'nullable|string|max:50',
            'form' => 'nullable|string|max:50',
            'instructions' => 'nullable|string|max:2000',
            'indication' => 'nullable|string|max:500',
            'is_prn' => 'nullable|boolean',
            'prn_reason' => 'nullable|string|max:500',
            'max_doses_per_day' => 'nullable|integer|min:1',
            'min_hours_between_doses' => 'nullable|numeric|min:0',
            'is_controlled_drug' => 'nullable|boolean',
            'is_high_risk' => 'nullable|boolean',
            'witness_required' => 'nullable|boolean',
            'prescriber_name' => 'nullable|string|max:255',
        ]);

        $updateData = [];
        if (isset($validated['medication_name'])) $updateData['name'] = $validated['medication_name'];
        if (isset($validated['dose'])) $updateData['dosage'] = $validated['dose'];
        unset($validated['medication_name'], $validated['dose']);
        $updateData = array_merge($updateData, $validated);

        // Recalculate dose_times if frequency changed
        if (isset($validated['frequency']) && $validated['frequency'] !== $medication->frequency) {
            $updateData['dose_times'] = DoseSchedulingService::calculateDoseTimes($validated['frequency']);
        }

        $medication->update($updateData);

        return redirect()->back();
    }

    public function discontinueMedication(Request $request, ClientMedication $medication)
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $medication->update([
            'state' => 'ceased',
            'end_date' => now()->toDateString(),
            'discontinued_reason' => $request->reason,
            'discontinued_by' => auth()->id(),
            'discontinued_at' => now(),
        ]);

        return redirect()->back();
    }

    // ─── Controlled Drug Entry CRUD ──────────────────────

    public function storeCDEntry(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'medication_name' => 'required|string|max:255',
            'entry_type' => 'required|in:receipt,administration,disposal,transfer_in,transfer_out,balance_check,adjustment',
            'quantity' => 'required|numeric|min:0',
            'unit' => 'nullable|string|max:50',
            'balance_before' => 'nullable|numeric|min:0',
            'balance_after' => 'nullable|numeric|min:0',
            'witnessed_by' => 'required|exists:users,id|different:' . auth()->id(),
            'batch_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:2000',
        ]);

        // Try to find matching controlled medication
        $medication = ClientMedication::where('client_id', $validated['client_id'])
            ->where('name', 'like', '%' . $validated['medication_name'] . '%')
            ->controlled()
            ->first();

        ClientControlledDrugEntry::create([
            'client_id' => $validated['client_id'],
            'client_medication_id' => $medication?->id,
            'medication_name' => $validated['medication_name'],
            'entry_type' => $validated['entry_type'],
            'quantity' => $validated['quantity'],
            'unit' => $validated['unit'] ?? 'tablets',
            'balance_before' => $validated['balance_before'],
            'balance_after' => $validated['balance_after'],
            'recorded_by' => auth()->id(),
            'witnessed_by' => $validated['witnessed_by'],
            'batch_number' => $validated['batch_number'],
            'notes' => $validated['notes'],
            'recorded_at' => now(),
        ]);

        // Update stock if medication found and balance_after is provided
        if ($medication && isset($validated['balance_after'])) {
            $stock = $medication->stock ?? $medication->stock()->create([
                'client_id' => $validated['client_id'],
            ]);
            $stock->update(['on_hand' => $validated['balance_after']]);
        }

        return redirect()->back();
    }

    public function storeBalanceCheck(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'medication_name' => 'required|string|max:255',
            'expected_balance' => 'required|numeric|min:0',
            'actual_balance' => 'required|numeric|min:0',
            'witnessed_by' => 'required|exists:users,id',
            'discrepancy_notes' => 'nullable|string|max:2000',
        ]);

        $medication = ClientMedication::where('client_id', $validated['client_id'])
            ->where('name', 'like', '%' . $validated['medication_name'] . '%')
            ->controlled()
            ->first();

        DB::transaction(function () use ($validated, $medication) {
            // Record the balance check entry
            ClientControlledDrugEntry::create([
                'client_id' => $validated['client_id'],
                'client_medication_id' => $medication?->id,
                'medication_name' => $validated['medication_name'],
                'entry_type' => 'balance_check',
                'quantity' => $validated['actual_balance'],
                'balance_before' => $validated['expected_balance'],
                'balance_after' => $validated['actual_balance'],
                'recorded_by' => auth()->id(),
                'witnessed_by' => $validated['witnessed_by'],
                'notes' => $validated['discrepancy_notes'],
                'recorded_at' => now(),
            ]);

            // Create discrepancy if amounts don't match
            if ($validated['expected_balance'] != $validated['actual_balance']) {
                ClientControlledDrugDiscrepancy::create([
                    'client_id' => $validated['client_id'],
                    'client_medication_id' => $medication?->id,
                    'medication_name' => $validated['medication_name'],
                    'expected_quantity' => $validated['expected_balance'],
                    'actual_quantity' => $validated['actual_balance'],
                    'discrepancy' => $validated['actual_balance'] - $validated['expected_balance'],
                    'reported_by' => auth()->id(),
                    'witnessed_by' => $validated['witnessed_by'],
                    'notes' => $validated['discrepancy_notes'],
                    'status' => 'reported',
                    'reported_at' => now(),
                ]);
            }
        });

        return redirect()->back();
    }

    public function resolveDiscrepancy(Request $request, ClientControlledDrugDiscrepancy $discrepancy)
    {
        $validated = $request->validate([
            'resolution_notes' => 'required|string|max:2000',
            'resolution_action' => 'required|string|max:255',
        ]);

        $discrepancy->update([
            'status' => 'resolved',
            'resolution_notes' => $validated['resolution_notes'],
            'resolution_action' => $validated['resolution_action'],
            'resolved_by' => auth()->id(),
            'resolved_at' => now(),
        ]);

        return redirect()->back();
    }

    // ─── Handover Update/Delete ──────────────────────────

    public function updateHandover(Request $request, MedicationHandover $handover)
    {
        $validated = $request->validate([
            'incoming_user_id' => 'sometimes|exists:users,id',
            'controlled_drugs_verified' => 'nullable|boolean',
            'general_notes' => 'nullable|string|max:5000',
            'checklist_items' => 'nullable|array',
            'checklist_items.*.label' => 'required|string|max:255',
            'checklist_items.*.checked' => 'required|boolean',
            'checklist_items.*.notes' => 'nullable|string|max:500',
            'safety_concerns' => 'nullable|string|max:5000',
            'medication_errors_count' => 'nullable|integer|min:0',
            'pending_gp_followups' => 'nullable|integer|min:0',
            'clients_requiring_attention' => 'nullable|array',
            'clients_requiring_attention.*.client_id' => 'nullable|string',
            'clients_requiring_attention.*.client_name' => 'required|string|max:255',
            'clients_requiring_attention.*.reason' => 'required|string|max:500',
            'previous_shift_notes_read' => 'nullable|boolean',
            'stock_issues_identified' => 'nullable|string|max:5000',
            'prescriber_changes_summary' => 'nullable|string|max:5000',
        ]);

        $handover->update($validated);

        return redirect()->back();
    }

    public function destroyHandover(MedicationHandover $handover)
    {
        $handover->delete();

        return redirect()->back();
    }

    // ─── Destruction Delete ──────────────────────────────

    public function destroyDestruction(MedicationDestruction $destruction)
    {
        $destruction->delete();

        return redirect()->back();
    }

    // ─── Medications CSV Import ──────────────────────────

    public function importMedications(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        $file = $request->file('csv_file');
        $handle = fopen($file->getRealPath(), 'r');

        if (! $handle) {
            return redirect()->back()->withErrors(['csv_file' => 'Unable to read the CSV file.']);
        }

        $imported = 0;
        $skipped = 0;
        $rowNumber = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }

            // Skip header row
            if ($rowNumber === 1 && stripos($row[0] ?? '', 'client') !== false) {
                continue;
            }

            // Expect: client_name, medication_name, dose, frequency, route
            if (count($row) < 4) {
                $skipped++;
                continue;
            }

            $clientName = trim($row[0] ?? '');
            $medicationName = trim($row[1] ?? '');
            $dose = trim($row[2] ?? '');
            $frequency = trim($row[3] ?? '');
            $route = trim($row[4] ?? 'oral');

            if (! $clientName || ! $medicationName || ! $dose || ! $frequency) {
                $skipped++;
                continue;
            }

            // Try to match client by name ("Last, First" or "First Last")
            $client = null;
            if (str_contains($clientName, ',')) {
                [$lastName, $firstName] = array_map('trim', explode(',', $clientName, 2));
                $client = Client::where('last_name', $lastName)
                    ->where('first_name', $firstName)
                    ->first();
            } else {
                $parts = explode(' ', $clientName, 2);
                if (count($parts) === 2) {
                    $client = Client::where('first_name', $parts[0])
                        ->where('last_name', $parts[1])
                        ->first();
                }
            }

            if (! $client) {
                $skipped++;
                continue;
            }

            // Calculate dose times from frequency
            $doseTimes = DoseSchedulingService::calculateDoseTimes($frequency);

            ClientMedication::create([
                'client_id' => $client->id,
                'name' => $medicationName,
                'dosage' => $dose,
                'frequency' => $frequency,
                'dose_times' => $doseTimes,
                'route' => $route,
                'state' => 'active',
                'active' => true,
                'start_date' => now()->toDateString(),
            ]);

            $imported++;
        }

        fclose($handle);

        return redirect()->back();
    }
}
