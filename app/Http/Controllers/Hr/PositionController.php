<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrPosition;
use App\Domain\Hr\Services\PositionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PositionController extends Controller
{
    public function __construct(
        private readonly PositionService $positionService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.positions.view'), 403);

        $tenantId = $user->tenant_id;
        $search = trim((string) $request->query('q', ''));
        $department = $request->query('department');
        $status = $request->query('status');

        $positions = HrPosition::forTenant($tenantId)
            ->when($status === 'active', fn ($q) => $q->active())
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($department, fn ($q) => $q->inDepartment($department))
            ->when($search !== '', fn ($q) => $q->where(function ($sub) use ($search) {
                $sub->where('title', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%");
            }))
            ->withCount(['employees' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('department')
            ->orderBy('title')
            ->paginate(20)
            ->withQueryString();

        $positions->through(fn ($pos) => [
            'id' => $pos->id,
            'title' => $pos->title,
            'code' => $pos->code,
            'department' => $pos->department,
            'team' => $pos->team,
            'employment_type' => $pos->employment_type,
            'fte' => (float) $pos->fte,
            'headcount_budget' => $pos->headcount_budget,
            'current_headcount' => $pos->employees_count,
            'vacancies' => max(0, $pos->headcount_budget - $pos->employees_count),
            'is_active' => $pos->is_active,
        ]);

        $departments = HrDepartment::query()
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('hr/positions/index', [
            'positions' => $positions,
            'departments' => $departments,
            'filters' => [
                'q' => $search,
                'department' => $department,
                'status' => $status,
            ],
            'can' => [
                'manage' => $user->canDo('hr.positions.manage'),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.positions.manage'), 403);

        $tenantId = $user->tenant_id;
        $parentPositions = HrPosition::forTenant($tenantId)->active()->orderBy('title')->get(['id', 'title', 'code']);
        $departments = HrDepartment::query()
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
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
        abort_unless($user && $user->canDo('hr.positions.manage'), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('hr_positions')->where('tenant_id', $user->tenant_id)],
            'department' => ['nullable', 'string', 'max:255'],
            'team' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'requirements' => ['nullable', 'string', 'max:5000'],
            'employment_type' => ['required', Rule::in(['full_time', 'part_time', 'casual', 'fixed_term'])],
            'fte' => ['required', 'numeric', 'min:0.01', 'max:1.00'],
            'headcount_budget' => ['required', 'integer', 'min:1', 'max:999'],
            'reports_to_position_id' => ['nullable', 'exists:hr_positions,id'],
        ]);

        $this->positionService->createPosition([
            ...$validated,
            'tenant_id' => $user->tenant_id,
            'created_by' => $user->id,
        ]);

        return redirect()->route('hr.positions.index')->with('success', 'Position created.');
    }

    public function show(Request $request, HrPosition $position)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.positions.view'), 403);

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
                'manage' => $user->canDo('hr.positions.manage'),
            ],
        ]);
    }

    public function edit(Request $request, HrPosition $position)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.positions.manage'), 403);

        $parentPositions = HrPosition::forTenant($user->tenant_id)
            ->active()
            ->where('id', '!=', $position->id)
            ->orderBy('title')
            ->get(['id', 'title', 'code']);

        $departments = HrDepartment::query()
            ->when($user->tenant_id, fn ($q) => $q->where('tenant_id', $user->tenant_id), fn ($q) => $q->whereNull('tenant_id'))
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
        abort_unless($user && $user->canDo('hr.positions.manage'), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('hr_positions')->where('tenant_id', $user->tenant_id)->ignore($position->id)],
            'department' => ['nullable', 'string', 'max:255'],
            'team' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'requirements' => ['nullable', 'string', 'max:5000'],
            'employment_type' => ['required', Rule::in(['full_time', 'part_time', 'casual', 'fixed_term'])],
            'fte' => ['required', 'numeric', 'min:0.01', 'max:1.00'],
            'headcount_budget' => ['required', 'integer', 'min:1', 'max:999'],
            'reports_to_position_id' => ['nullable', 'exists:hr_positions,id'],
            'is_active' => ['boolean'],
        ]);

        $this->positionService->updatePosition($position, $validated);

        return redirect()->route('hr.positions.show', $position)->with('success', 'Position updated.');
    }
}
