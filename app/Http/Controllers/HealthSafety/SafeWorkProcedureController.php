<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\SafeWorkProcedure;
use App\Models\SafeWorkProcedureVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Carbon\Carbon;

class SafeWorkProcedureController extends Controller
{
    /**
     * List safe work procedures.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.view'), 403);

        $filters = $request->only(['category', 'status', 'q']);

        $query = DB::table('safe_work_procedures')
            ->leftJoin('users as approver', 'safe_work_procedures.approved_by', '=', 'approver.id')
            ->whereNull('safe_work_procedures.deleted_at')
            ->when(!empty($filters['category']), fn ($q) => $q->where('safe_work_procedures.category', $filters['category']))
            ->when(!empty($filters['status']), fn ($q) => $q->where('safe_work_procedures.status', $filters['status']))
            ->when(!empty($filters['q']), fn ($q) => $q->where(function ($sub) use ($filters) {
                $sub->where('safe_work_procedures.title', 'like', "%{$filters['q']}%")
                    ->orWhere('safe_work_procedures.reference_number', 'like', "%{$filters['q']}%");
            }));

        $procedures = (clone $query)
            ->select(
                'safe_work_procedures.*',
                'approver.name as approved_by_name'
            )
            ->orderBy('safe_work_procedures.title')
            ->paginate(25)
            ->withQueryString();

        // Stats
        $total = DB::table('safe_work_procedures')
            ->whereNull('deleted_at')
            ->count();

        $approved = DB::table('safe_work_procedures')
            ->whereNull('deleted_at')
            ->where('status', 'approved')
            ->count();

        $dueReview = DB::table('safe_work_procedures')
            ->whereNull('deleted_at')
            ->where('status', 'approved')
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
    public function create()
    {
        $user = request()->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $users = DB::table('users')
            ->whereNull('deleted_at')
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return Inertia::render('health-safety/procedures/create', [
            'users' => $users,
        ]);
    }

    /**
     * Store a new procedure.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

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

        $validated['created_by'] = $user->id;
        $validated['current_version'] = 1;
        $validated['status'] = 'draft';

        $procedure = SafeWorkProcedure::create($validated);

        // Create initial version snapshot
        SafeWorkProcedureVersion::create([
            'safe_work_procedure_id' => $procedure->id,
            'version_number' => 1,
            'content_snapshot' => $procedure->toArray(),
            'change_summary' => 'Initial version',
            'changed_by' => $user->id,
        ]);

        return redirect()->route('health-safety.procedures.show', $procedure)
            ->with('success', 'Safe work procedure created.');
    }

    /**
     * Show a procedure with versions.
     */
    public function show(SafeWorkProcedure $procedure)
    {
        $user = request()->user();
        abort_unless($user && $user->canDo('health-safety.view'), 403);

        $procedure->load(['approvedBy', 'creator', 'updater']);

        $versions = $procedure->versions()
            ->with('changedBy:id,name')
            ->orderByDesc('version_number')
            ->get()
            ->map(function ($v) {
                return [
                    'id' => $v->id,
                    'version' => $v->version_number,
                    'change_summary' => $v->change_summary,
                    'changed_by' => $v->changedBy ? ['id' => $v->changedBy->id, 'name' => $v->changedBy->name] : null,
                    'created_at' => $v->created_at->toISOString(),
                ];
            });

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
            'canApprove' => $user->canDo('health-safety.manage'),
        ]);
    }

    /**
     * Update a procedure and create a version snapshot.
     */
    public function update(Request $request, SafeWorkProcedure $procedure)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

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

        $validated['updated_by'] = $user->id;
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
            'changed_by' => $user->id,
        ]);

        return redirect()->route('health-safety.procedures.show', $procedure)
            ->with('success', 'Procedure updated (v' . $procedure->current_version . ').');
    }

    /**
     * Approve a procedure.
     */
    public function approve(Request $request, SafeWorkProcedure $procedure)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $procedure->update([
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => Carbon::now(),
            'updated_by' => $user->id,
        ]);

        return redirect()->route('health-safety.procedures.show', $procedure)
            ->with('success', 'Procedure approved.');
    }
}
