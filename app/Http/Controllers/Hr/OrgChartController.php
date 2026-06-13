<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\OrgChartService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OrgChartController extends Controller
{
    public function __construct(
        private readonly OrgChartService $orgChartService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        // Route gates on hr.employees.viewAny; accept the finer hr.orgchart.view
        // too so neither key alone 403s a legitimately-permitted user.
        abort_unless($user && ($user->canDo('hr.orgchart.view') || $user->canDo('hr.employees.viewAny')), 403);

        $hierarchy = $this->orgChartService->getHierarchy($user->tenant_id);
        $canManage = $user->canDo('hr.orgchart.manage') || $user->canDo('hr.employees.manage');

        // Flat people list powers the "Change manager" picker (manager-only).
        $people = $canManage
            ? HrEmployeeProfile::forTenant($user->tenant_id)
                ->active()
                ->with('user:id,name')
                ->orderBy('position_title')
                ->get()
                ->map(fn ($p) => [
                    'user_id' => $p->user_id,
                    'name' => $p->user?->name ?? 'Unknown',
                    'position_title' => $p->position_title,
                ])
                ->values()
            : collect();

        return Inertia::render('hr/orgchart/index', [
            'hierarchy' => $hierarchy,
            'people' => $people,
            'can' => [
                'manage' => $canManage,
            ],
        ]);
    }

    public function update(Request $request, HrEmployeeProfile $profile)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('hr.orgchart.manage') || $user->canDo('hr.employees.manage')), 403);

        $validated = $request->validate([
            'manager_user_id' => ['nullable', 'exists:users,id'],
        ]);

        $managerUserId = $validated['manager_user_id'] ?? null;
        if ($managerUserId !== null && (int) $managerUserId === (int) $profile->user_id) {
            return redirect()->back()->with('error', 'An employee cannot report to themselves.');
        }

        $this->orgChartService->updateManager($profile, $managerUserId);

        return redirect()->back()->with('success', 'Reporting structure updated.');
    }
}
