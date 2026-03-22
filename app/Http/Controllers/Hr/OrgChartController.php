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
        abort_unless($user && $user->canDo('hr.orgchart.view'), 403);

        $hierarchy = $this->orgChartService->getHierarchy($user->tenant_id);

        return Inertia::render('hr/orgchart/index', [
            'hierarchy' => $hierarchy,
            'can' => [
                'manage' => $user->canDo('hr.orgchart.manage'),
            ],
        ]);
    }

    public function update(Request $request, HrEmployeeProfile $profile)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.orgchart.manage'), 403);

        $validated = $request->validate([
            'manager_user_id' => ['nullable', 'exists:users,id'],
        ]);

        $this->orgChartService->updateManager($profile, $validated['manager_user_id']);

        return redirect()->back()->with('success', 'Reporting structure updated.');
    }
}
