<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationCovertAuthorisation;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationDestruction;
use App\Models\MedicationHandover;
use App\Models\MedicationPharmacyOrder;
use App\Models\MedicationPrescriberOrder;
use App\Models\MedicationPrnEffectiveness;
use App\Models\MedicationReview;
use App\Models\MedicationRound;
use App\Models\MedicationRoundTemplate;
use App\Models\MedicationSelfAdminAssessment;
use App\Models\Site;
use App\Models\User;
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
            'scheduled' => $scheduled->map(fn ($med) => [
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
                'dose_times' => $med->dose_times ?? [],
                'administrations' => $med->administrations->map(fn ($a) => [
                    'id' => $a->id,
                    'scheduled_for' => $a->scheduled_for?->toIso8601String(),
                    'administered_at' => $a->administered_at?->toIso8601String(),
                    'status' => $a->status,
                    'administered_by' => $a->administeredBy?->name,
                    'witnessed_by' => $a->witnessedBy?->name,
                    'notes' => $a->notes,
                    'reason' => $a->reason,
                ]),
            ]),
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

        return Inertia::render('emar/ControlledDrugs', [
            'medications' => $controlledMedications,
            'recentEntries' => $recentEntries,
            'discrepancies' => $discrepancies,
            'destructions' => $destructions,
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

        return Inertia::render('emar/Medications', [
            'medications' => $medications,
            'clients' => $clients,
            'filters' => $request->only(['search', 'status', 'type', 'client_id']),
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
                'is_low' => $s->reorder_level && $s->on_hand <= $s->reorder_level,
                'controlled' => $s->medication?->controlled_drug,
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

        $templates = MedicationRoundTemplate::active()->orderBy('scheduled_time')->get();

        return Inertia::render('emar/Rounds', [
            'rounds' => $rounds,
            'templates' => $templates,
            'staff' => $this->getStaffList(),
            'date' => $date,
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
        $dayOfWeek = $date->dayOfWeek;

        $templates = MedicationRoundTemplate::active()->get();
        $totalMedications = ClientMedication::active()->count();
        $created = 0;

        foreach ($templates as $template) {
            if (!$template->appliesToDay($dayOfWeek)) {
                continue;
            }

            // Skip if round already exists for this template on this date
            $exists = MedicationRound::where('round_date', $date->toDateString())
                ->where('name', $template->name)
                ->where('scheduled_time', $template->scheduled_time)
                ->exists();

            if ($exists) {
                continue;
            }

            MedicationRound::create([
                'name' => $template->name,
                'scheduled_time' => $template->scheduled_time,
                'window_minutes' => $template->window_minutes,
                'round_date' => $date->toDateString(),
                'status' => 'pending',
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
}
