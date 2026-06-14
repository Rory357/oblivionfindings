<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\OrgChartService;
use Illuminate\Http\Request;

class OrgChartController extends Controller
{
    public function __construct(
        private readonly OrgChartService $orgChartService,
    ) {}

    /**
     * The org chart is folded into the People hub "Org chart" tab. Preserve the
     * route by redirecting; the hub controller builds the hierarchy + people.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        // Route gates on hr.employees.viewAny; accept the finer hr.orgchart.view
        // too so neither key alone 403s a legitimately-permitted user.
        abort_unless($user && ($user->canDo('hr.orgchart.view') || $user->canDo('hr.employees.viewAny')), 403);

        return redirect()->route('hr.people.index', ['tab' => 'orgchart']);
    }

    public function update(Request $request, HrEmployeeProfile $profile)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('hr.orgchart.manage') || $user->canDo('hr.employees.manage')), 403);

        $validated = $request->validate([
            'manager_user_id' => ['nullable', 'exists:users,id'],
        ]);

        $managerUserId = $validated['manager_user_id'] ?? null;
        if ($managerUserId !== null && $this->orgChartService->wouldCreateCycle($profile, (int) $managerUserId)) {
            return redirect()->back()->with('error', 'That change would create a reporting loop.');
        }

        $this->orgChartService->updateManager($profile, $managerUserId);

        return redirect()->back()->with('success', 'Reporting structure updated.');
    }
}
