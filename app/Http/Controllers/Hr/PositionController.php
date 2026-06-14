<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrPosition;
use App\Domain\Hr\Services\PositionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PositionController extends Controller
{
    use ResolvesHrTenant;

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

        $tenantId = $this->resolveHrTenantIdForUser($user);
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
        abort_unless($this->canManage($user), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('hr_positions')->where('tenant_id', $tenantId)],
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
            'tenant_id' => $tenantId,
            'created_by' => $user->id,
        ]);

        // back() so the modal closes onto the hub Positions tab it was opened from
        // (falls back to the standalone create page when used directly).
        return redirect()->back()->with('success', 'Position created.');
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

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $parentPositions = HrPosition::forTenant($tenantId)
            ->active()
            ->where('id', '!=', $position->id)
            ->orderBy('title')
            ->get(['id', 'title', 'code']);

        $departments = HrDepartment::query()
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
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

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('hr_positions')->where('tenant_id', $tenantId)->ignore($position->id)],
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
