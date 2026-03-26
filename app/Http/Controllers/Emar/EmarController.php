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
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmarController extends Controller
{
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
            ->where('active', true)
            ->get(['id', 'name', 'email']);

        return Inertia::render('emar/Competency', [
            'assessments' => $assessments,
            'expiringSoon' => $expiringSoon,
            'expired' => $expired,
            'staffWithoutAssessment' => $staffWithoutAssessment,
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
        ]);
    }
}
