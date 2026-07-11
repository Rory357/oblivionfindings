<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPosition;
use App\Domain\Hr\Models\HrSuccessionCandidate;
use App\Domain\Hr\Models\HrSuccessionPlan;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SuccessionController extends Controller
{
    /**
     * List succession plans with risk level badges, current holder, candidate count.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $plans = HrSuccessionPlan::query()
            ->with(['currentHolder:id,name', 'position:id,title,department'])
            ->withCount('candidates')
            ->when($request->input('risk_level'), fn ($q, $v) => $q->where('risk_level', $v))
            ->when($request->input('department'), fn ($q, $v) => $q->where('department', $v))
            ->when($request->boolean('active_only', true), fn ($q) => $q->active())
            ->orderByRaw("FIELD(risk_level, 'critical', 'high', 'medium', 'low')")
            ->orderBy('role_title')
            ->paginate(20)
            ->withQueryString();

        $plans->through(fn ($plan) => [
            'id' => $plan->id,
            'role_title' => $plan->role_title,
            'department' => $plan->department,
            'risk_level' => $plan->risk_level,
            'current_holder_name' => $plan->currentHolder?->name,
            'current_holder' => $plan->currentHolder?->only('id', 'name'),
            'position' => $plan->position?->only('id', 'title'),
            'notes' => $plan->notes,
            'candidates_count' => $plan->candidates_count,
            'is_active' => $plan->is_active,
            'created_at' => $plan->created_at?->toDateTimeString(),
        ]);

        // Get readiness summary for each plan
        $planIds = $plans->pluck('id');
        $readinessSummary = HrSuccessionCandidate::whereIn('succession_plan_id', $planIds)
            ->select('succession_plan_id', 'readiness', DB::raw('COUNT(*) as count'))
            ->groupBy('succession_plan_id', 'readiness')
            ->get()
            ->groupBy('succession_plan_id')
            ->map(fn ($items) => $items->pluck('count', 'readiness')->toArray());

        $departments = HrSuccessionPlan::distinct()->whereNotNull('department')->pluck('department');

        $canManage = $this->canManage($user);

        return Inertia::render('hr/succession/index', [
            'plans' => $plans,
            'readinessSummary' => $readinessSummary,
            'departments' => $departments,
            'filters' => $request->only(['risk_level', 'department', 'active_only']),
            'stats' => [
                'total' => HrSuccessionPlan::active()->count(),
                'high_risk' => HrSuccessionPlan::active()->whereIn('risk_level', ['high', 'critical'])->count(),
                'vacant' => HrSuccessionPlan::active()->whereNull('current_holder_user_id')->count(),
                'ready_now' => HrSuccessionCandidate::where('readiness', 'ready_now')
                    ->whereIn('succession_plan_id', HrSuccessionPlan::active()->select('id'))
                    ->count(),
            ],
            // Wizard option lists — only fetched for users who can open the wizard.
            'positions' => $canManage
                ? HrPosition::active()->orderBy('title')->get(['id', 'title', 'department'])
                : [],
            'holders' => $canManage
                ? User::orderBy('name')->get(['id', 'name', 'email'])
                : [],
            'can' => [
                'manage' => $canManage,
            ],
        ]);
    }

    /**
     * The full-page create form was folded into a WizardShell modal on the
     * index — keep the GET route alive for bookmarks and route() helpers.
     */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        return redirect()->route('hr.succession.index', ['new' => 1]);
    }

    /**
     * Save plan + candidates.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        $data = $request->validate([
            'position_id' => ['nullable', 'integer', 'exists:hr_positions,id'],
            'role_title' => ['required', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'risk_level' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'current_holder_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'candidates' => ['nullable', 'array'],
            'candidates.*.employee_profile_id' => ['required', 'integer', 'exists:hr_employee_profiles,id'],
            'candidates.*.readiness' => ['required', Rule::in(['ready_now', 'ready_1_year', 'ready_2_years', 'developing'])],
            'candidates.*.strengths' => ['nullable', 'string', 'max:2000'],
            'candidates.*.development_needs' => ['nullable', 'string', 'max:2000'],
            'candidates.*.overall_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'source_review_id' => ['nullable', 'integer', 'exists:hr_performance_reviews,id'],
            'stay' => ['nullable', 'boolean'],
        ]);

        $stay = (bool) ($data['stay'] ?? false);

        // Provenance note when the nomination was deliberately started from a
        // signed-off performance review (no schema change — notes text).
        $notes = $data['notes'] ?? null;
        if (! empty($data['source_review_id'])) {
            $provenance = "Created from performance review #{$data['source_review_id']}.";
            $notes = $notes ? "{$notes}\n\n{$provenance}" : $provenance;
        }

        DB::transaction(function () use ($user, $data, $notes) {
            $plan = HrSuccessionPlan::create([
                'tenant_id' => null,
                'position_id' => $data['position_id'] ?? null,
                'role_title' => $data['role_title'],
                'department' => $data['department'] ?? null,
                'risk_level' => $data['risk_level'],
                'current_holder_user_id' => $data['current_holder_user_id'] ?? null,
                'notes' => $notes,
                'is_active' => true,
                'created_by' => $user->id,
            ]);

            foreach ($data['candidates'] ?? [] as $candidate) {
                $plan->candidates()->create([
                    'employee_profile_id' => $candidate['employee_profile_id'],
                    'readiness' => $candidate['readiness'],
                    'strengths' => $candidate['strengths'] ?? null,
                    'development_needs' => $candidate['development_needs'] ?? null,
                    'overall_rating' => $candidate['overall_rating'] ?? null,
                    'assessed_by' => $user->id,
                    'assessed_at' => now()->toDateString(),
                ]);
            }
        });

        if ($stay) {
            return redirect()->back()->with('success', 'Succession plan created.');
        }

        return redirect()->route('hr.succession.index')->with('success', 'Succession plan created.');
    }

    /**
     * Plan detail with candidate readiness matrix.
     */
    public function show(Request $request, HrSuccessionPlan $plan)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $plan->load([
            'currentHolder:id,name,email',
            'position:id,title,department',
            'creator:id,name',
            'candidates.employeeProfile.user:id,name,email',
            'candidates.assessor:id,name',
        ]);

        $employees = HrEmployeeProfile::where('is_active', true)
            ->with('user:id,name,email')
            ->orderBy('user_id')
            ->get(['id', 'user_id', 'position_title', 'department']);

        $canManage = $this->canManage($user);

        return Inertia::render('hr/succession/show', [
            'plan' => [
                'id' => $plan->id,
                'role_title' => $plan->role_title,
                'department' => $plan->department,
                'risk_level' => $plan->risk_level,
                'notes' => $plan->notes,
                'is_active' => $plan->is_active,
                'current_holder_name' => $plan->currentHolder?->name,
                'current_holder' => $plan->currentHolder?->only('id', 'name', 'email'),
                'position' => $plan->position?->only('id', 'title', 'department'),
                'creator' => $plan->creator?->only('id', 'name'),
                'created_at' => $plan->created_at?->toDateTimeString(),
                'candidates' => $plan->candidates->map(fn ($c) => [
                    'id' => $c->id,
                    'readiness' => $c->readiness,
                    'development_needs' => $c->development_needs,
                    'strengths' => $c->strengths,
                    'overall_rating' => $c->overall_rating,
                    'assessed_at' => $c->assessed_at?->toDateString(),
                    'assessor' => $c->assessor?->only('id', 'name'),
                    'employee' => $c->employeeProfile ? [
                        'id' => $c->employeeProfile->id,
                        'name' => $c->employeeProfile->user?->name,
                        'email' => $c->employeeProfile->user?->email,
                        'position_title' => $c->employeeProfile->position_title,
                        'department' => $c->employeeProfile->department,
                    ] : null,
                ]),
            ],
            'employees' => $employees,
            // Edit-plan wizard option lists.
            'positions' => $canManage
                ? HrPosition::active()->orderBy('title')->get(['id', 'title', 'department'])
                : [],
            'holders' => $canManage
                ? User::orderBy('name')->get(['id', 'name', 'email'])
                : [],
            'can' => [
                'manage' => $canManage,
            ],
        ]);
    }

    /**
     * Update plan.
     */
    public function update(Request $request, HrSuccessionPlan $plan)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $data = $request->validate([
            'role_title' => ['sometimes', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'risk_level' => ['sometimes', Rule::in(['low', 'medium', 'high', 'critical'])],
            'current_holder_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $plan->update($data);

        return redirect()->back()->with('success', 'Succession plan updated.');
    }

    /**
     * Add candidate to plan.
     */
    public function addCandidate(Request $request, HrSuccessionPlan $plan)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $data = $request->validate([
            'employee_profile_id' => ['required', 'integer', 'exists:hr_employee_profiles,id'],
            'readiness' => ['required', Rule::in(['ready_now', 'ready_1_year', 'ready_2_years', 'developing'])],
            'strengths' => ['nullable', 'string', 'max:2000'],
            'development_needs' => ['nullable', 'string', 'max:2000'],
            'overall_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        $plan->candidates()->create([
            'employee_profile_id' => $data['employee_profile_id'],
            'readiness' => $data['readiness'],
            'strengths' => $data['strengths'] ?? null,
            'development_needs' => $data['development_needs'] ?? null,
            'overall_rating' => $data['overall_rating'] ?? null,
            'assessed_by' => $user->id,
            'assessed_at' => now()->toDateString(),
        ]);

        return redirect()->back()->with('success', 'Candidate added to succession plan.');
    }

    /**
     * Update readiness/rating of a candidate.
     */
    public function updateCandidate(Request $request, HrSuccessionCandidate $candidate)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $data = $request->validate([
            'readiness' => ['sometimes', Rule::in(['ready_now', 'ready_1_year', 'ready_2_years', 'developing'])],
            'strengths' => ['nullable', 'string', 'max:2000'],
            'development_needs' => ['nullable', 'string', 'max:2000'],
            'overall_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        $candidate->update(array_merge($data, [
            'assessed_by' => $user->id,
            'assessed_at' => now()->toDateString(),
        ]));

        return redirect()->back()->with('success', 'Candidate updated.');
    }

    /**
     * Archive a succession plan while retaining its candidate history.
     */
    public function destroy(Request $request, HrSuccessionPlan $plan)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $plan->update(['is_active' => false]);

        return redirect()->route('hr.succession.index')->with('success', 'Succession plan archived.');
    }

    /**
     * Remove a candidate from a plan.
     */
    public function removeCandidate(Request $request, HrSuccessionCandidate $candidate)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $candidate->delete();

        return redirect()->back()->with('success', 'Candidate removed from plan.');
    }

    /**
     * Nominate a candidate to the ready-now talent bench. Promotes their
     * readiness so they surface in the "Ready now" pipeline across plans.
     */
    public function nominateToTalentPool(Request $request, HrSuccessionCandidate $candidate)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $candidate->update([
            'readiness' => 'ready_now',
            'assessed_by' => $user->id,
            'assessed_at' => now()->toDateString(),
        ]);

        return redirect()->back()->with('success', 'Candidate nominated to the ready-now talent pool.');
    }

    private function canView($user): bool
    {
        return (bool) $user && (
            $user->canDo('hr.performance.view')
            || $user->canDo('hr.performance.manage')
        );
    }

    private function canManage($user): bool
    {
        return (bool) $user && $user->canDo('hr.performance.manage');
    }
}
