<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Domain\Hr\Models\HrPosition;
use App\Domain\Hr\Services\PositionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PositionController extends Controller
{
    public function __construct(
        private readonly PositionService $positionService,
    ) {}

    /**
     * Positions are folded into the People hub "Positions" tab. Preserve the
     * route by redirecting, carrying filters across as the namespaced hub keys.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $params = ['tab' => 'positions'];
        if ($q = trim((string) $request->query('q', ''))) {
            $params['pq'] = $q;
        }
        if ($department = $request->query('department')) {
            $params['pdepartment'] = $department;
        }
        if ($status = $request->query('status')) {
            $params['pstatus'] = $status;
        }

        return redirect()->route('hr.people.index', $params);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $parentPositions = HrPosition::query()->active()->orderBy('title')->get(['id', 'title', 'code']);
        $departments = HrDepartment::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('hr/positions/create', [
            'parentPositions' => $parentPositions,
            'departments' => $departments,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('hr_positions', 'code')],
            'department' => ['nullable', 'string', 'max:255'],
            'team' => ['nullable', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'responsibilities' => ['nullable', 'string', 'max:20000'],
            'requirements' => ['nullable', 'string', 'max:20000'],
            'description' => ['nullable', 'string', 'max:20000'],
            'employment_type' => ['required', Rule::in(['full_time', 'part_time', 'casual', 'fixed_term'])],
            'fte' => ['required', 'numeric', 'min:0.01', 'max:1.00'],
            'headcount_budget' => ['required', 'integer', 'min:1', 'max:999'],
            'reports_to_position_id' => ['nullable', 'exists:hr_positions,id'],
            'open_requisition' => ['nullable', 'boolean'],
        ]);

        $positionData = $validated;
        unset($positionData['open_requisition']);

        $position = $this->positionService->createPosition([
            ...$positionData,
            'created_by' => $user->id,
        ]);

        $message = 'Position created.';

        // Optional one-step recruitment: a new position is empty (current 0), so
        // its whole budget is the gap. Mirrors the onboarding/invite toggle pattern.
        if ($request->boolean('open_requisition') && $user->canDo('hr.recruitment.manage')) {
            HrJobRequisition::create([
                'title' => $position->title,
                'slug' => Str::slug($position->title).'-'.strtolower(Str::random(5)),
                'position_id' => $position->id,
                'employment_type' => $position->employment_type,
                'openings' => max(1, (int) $position->headcount_budget),
                'status' => 'draft',
                'summary' => $position->summary,
                'description' => $position->description ?: ($position->summary ?: $position->title),
                'requirements' => $position->requirements,
                'responsibilities' => $position->responsibilities,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
            $message = "Position created with a draft requisition for {$position->headcount_budget} opening(s).";
        }

        // back() so the modal closes onto the hub Positions tab it was opened from
        // (falls back to the standalone create page when used directly).
        return redirect()->back()->with('success', $message);
    }

    public function show(Request $request, HrPosition $position)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $position->load([
            'reportsTo:id,title,code',
            'directReportPositions:id,title,code,reports_to_position_id',
            'employees' => fn ($q) => $q->where('is_active', true)->with('user:id,name,email'),
        ]);

        return Inertia::render('hr/positions/show', [
            'position' => [
                'id' => $position->id,
                'title' => $position->title,
                'code' => $position->code,
                'department' => $position->department,
                'team' => $position->team,
                'description' => $position->description,
                'requirements' => $position->requirements,
                'employment_type' => $position->employment_type,
                'fte' => (float) $position->fte,
                'headcount_budget' => $position->headcount_budget,
                'current_headcount' => $position->employees->count(),
                'is_active' => $position->is_active,
                'reports_to' => $position->reportsTo ? [
                    'id' => $position->reportsTo->id,
                    'title' => $position->reportsTo->title,
                    'code' => $position->reportsTo->code,
                ] : null,
                'direct_reports' => $position->directReportPositions->map(fn ($p) => [
                    'id' => $p->id,
                    'title' => $p->title,
                    'code' => $p->code,
                ])->all(),
                'employees' => $position->employees->map(fn ($e) => [
                    'id' => $e->id,
                    'name' => $e->user?->name ?? 'Unknown',
                    'email' => $e->user?->email,
                    'position_title' => $e->position_title,
                ])->all(),
            ],
            'can' => [
                'manage' => $this->canManage($user),
            ],
        ]);
    }

    public function edit(Request $request, HrPosition $position)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $parentPositions = HrPosition::query()
            ->active()
            ->where('id', '!=', $position->id)
            ->orderBy('title')
            ->get(['id', 'title', 'code']);

        $departments = HrDepartment::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('hr/positions/edit', [
            'position' => $position->only([
                'id', 'title', 'code', 'department', 'team', 'description',
                'requirements', 'employment_type', 'fte', 'headcount_budget',
                'reports_to_position_id', 'is_active',
            ]),
            'parentPositions' => $parentPositions,
            'departments' => $departments,
        ]);
    }

    public function update(Request $request, HrPosition $position)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('hr_positions', 'code')->ignore($position->id)],
            'department' => ['nullable', 'string', 'max:255'],
            'team' => ['nullable', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'responsibilities' => ['nullable', 'string', 'max:20000'],
            'requirements' => ['nullable', 'string', 'max:20000'],
            'description' => ['nullable', 'string', 'max:20000'],
            'employment_type' => ['required', Rule::in(['full_time', 'part_time', 'casual', 'fixed_term'])],
            'fte' => ['required', 'numeric', 'min:0.01', 'max:1.00'],
            'headcount_budget' => ['required', 'integer', 'min:1', 'max:999'],
            'reports_to_position_id' => ['nullable', 'exists:hr_positions,id'],
            'is_active' => ['boolean'],
        ]);

        $this->positionService->updatePosition($position, $validated);

        return redirect()->back()->with('success', 'Position updated.');
    }

    private function canView($user): bool
    {
        return (bool) $user && (
            $user->canDo('hr.positions.view')
            || $user->canDo('hr.positions.manage')
            || $user->canDo('hr.employees.viewAny')
            || $user->canDo('hr.employees.manage')
        );
    }

    private function canManage($user): bool
    {
        return (bool) $user && (
            $user->canDo('hr.positions.manage')
            || $user->canDo('hr.employees.manage')
        );
    }
}
