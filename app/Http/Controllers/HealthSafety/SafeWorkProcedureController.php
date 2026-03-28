<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\SafeWorkProcedure;
use App\Models\SafeWorkProcedureVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SafeWorkProcedureController extends Controller
{
    /**
     * List safe work procedures.
     */
    public function index(Request $request): \Inertia\Response
    {
        $filters = $request->only(['category', 'status', 'q']);

        $procedures = SafeWorkProcedure::with('approvedBy:id,name')
            ->when(!empty($filters['category']), fn ($q) => $q->where('category', $filters['category']))
            ->when(!empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['q']), fn ($q) => $q->where(function ($sub) use ($filters) {
                $sub->where('title', 'like', "%{$filters['q']}%")
                    ->orWhere('reference_number', 'like', "%{$filters['q']}%");
            }))
            ->orderBy('title')
            ->paginate(25)
            ->withQueryString();

        // Stats
        $total = SafeWorkProcedure::count();
        $approved = SafeWorkProcedure::where('status', 'approved')->count();
        $dueReview = SafeWorkProcedure::where('status', 'approved')
            ->whereNotNull('review_date')
            ->where('review_date', '<=', Carbon::now()->addDays(30))
            ->count();

        return Inertia::render('health-safety/procedures/index', [
            'procedures' => $procedures,
            'filters' => $filters,
            'stats' => [
                'total' => $total,
                'approved' => $approved,
                'due_for_review' => $dueReview,
            ],
        ]);
    }

    /**
     * Create form.
     */
    public function create(): \Inertia\Response
    {
        return Inertia::render('health-safety/procedures/create', [
            'users' => User::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Store a new procedure.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'reference_number' => 'required|string|max:100|unique:safe_work_procedures,reference_number',
            'category' => 'required|in:manual_handling,infection_control,medication,chemical_handling,fire_safety,vehicle_operation,personal_care,challenging_behaviour,lone_working,equipment_use,other',
            'purpose' => 'nullable|string',
            'scope' => 'nullable|string',
            'hazards_addressed' => 'nullable|array',
            'ppe_required' => 'nullable|array',
            'steps' => 'nullable|array',
            'emergency_procedures' => 'nullable|array',
            'review_date' => 'nullable|date',
            'applicable_roles' => 'nullable|array',
            'applicable_sites' => 'nullable|array',
            'related_training' => 'nullable|array',
        ]);

        $validated['created_by'] = $request->user()->id;
        $validated['current_version'] = 1;
        $validated['status'] = 'draft';

        $procedure = SafeWorkProcedure::create($validated);

        // Create initial version snapshot
        SafeWorkProcedureVersion::create([
            'safe_work_procedure_id' => $procedure->id,
            'version_number' => 1,
            'content_snapshot' => $procedure->toArray(),
            'change_summary' => 'Initial version',
            'changed_by' => $request->user()->id,
        ]);

        return redirect()->route('health-safety.procedures.show', $procedure)
            ->with('success', 'Safe work procedure created.');
    }

    /**
     * Show a procedure with versions.
     */
    public function show(SafeWorkProcedure $procedure): \Inertia\Response
    {
        $procedure->load(['approvedBy:id,name', 'creator:id,name', 'updater:id,name']);

        $versions = $procedure->versions()
            ->with('changedBy:id,name')
            ->orderByDesc('version_number')
            ->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'version' => $v->version_number,
                'change_summary' => $v->change_summary,
                'changed_by' => $v->changedBy ? ['id' => $v->changedBy->id, 'name' => $v->changedBy->name] : null,
                'created_at' => $v->created_at->toISOString(),
            ]);

        // Map procedure to expected shape
        $procedureData = [
            'id' => $procedure->id,
            'title' => $procedure->title,
            'reference_number' => $procedure->reference_number,
            'category' => $procedure->category,
            'status' => $procedure->status,
            'version' => $procedure->current_version,
            'purpose' => $procedure->purpose,
            'scope' => $procedure->scope,
            'steps' => $procedure->steps ?? [],
            'ppe_required' => $procedure->ppe_required ?? [],
            'emergency_procedures' => is_array($procedure->emergency_procedures)
                ? implode("\n", $procedure->emergency_procedures)
                : $procedure->emergency_procedures,
            'applicable_roles' => $procedure->applicable_roles ?? [],
            'applicable_sites' => $procedure->applicable_sites ?? [],
            'approved_by' => $procedure->approvedBy ? ['id' => $procedure->approvedBy->id, 'name' => $procedure->approvedBy->name] : null,
            'approved_at' => $procedure->approved_at?->toISOString(),
            'review_date' => $procedure->review_date?->toDateString(),
        ];

        return Inertia::render('health-safety/procedures/show', [
            'procedure' => $procedureData,
            'versions' => $versions,
            'canApprove' => $procedure->status !== 'approved',
        ]);
    }

    /**
     * Update a procedure and create a version snapshot.
     */
    public function update(Request $request, SafeWorkProcedure $procedure): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'category' => 'nullable|in:manual_handling,infection_control,medication,chemical_handling,fire_safety,vehicle_operation,personal_care,challenging_behaviour,lone_working,equipment_use,other',
            'purpose' => 'nullable|string',
            'scope' => 'nullable|string',
            'hazards_addressed' => 'nullable|array',
            'ppe_required' => 'nullable|array',
            'steps' => 'nullable|array',
            'emergency_procedures' => 'nullable|array',
            'review_date' => 'nullable|date',
            'applicable_roles' => 'nullable|array',
            'applicable_sites' => 'nullable|array',
            'related_training' => 'nullable|array',
            'change_summary' => 'nullable|string',
        ]);

        $changeSummary = $validated['change_summary'] ?? 'Updated procedure';
        unset($validated['change_summary']);

        $validated['updated_by'] = $request->user()->id;
        $validated['current_version'] = $procedure->current_version + 1;

        // If approved, revert to under_review on content change
        if ($procedure->status === 'approved') {
            $validated['status'] = 'under_review';
        }

        $procedure->update($validated);

        // Create version snapshot
        SafeWorkProcedureVersion::create([
            'safe_work_procedure_id' => $procedure->id,
            'version_number' => $procedure->current_version,
            'content_snapshot' => $procedure->fresh()->toArray(),
            'change_summary' => $changeSummary,
            'changed_by' => $request->user()->id,
        ]);

        return redirect()->route('health-safety.procedures.show', $procedure)
            ->with('success', 'Procedure updated (v' . $procedure->current_version . ').');
    }

    /**
     * Approve a procedure.
     */
    public function approve(Request $request, SafeWorkProcedure $procedure): RedirectResponse
    {
        $procedure->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => Carbon::now(),
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->route('health-safety.procedures.show', $procedure)
            ->with('success', 'Procedure approved.');
    }
}
