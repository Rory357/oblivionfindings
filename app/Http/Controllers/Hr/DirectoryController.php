<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Models\Site;
use Illuminate\Http\Request;

class DirectoryController extends Controller
{
    use ResolvesHrTenant;

    /**
     * The standalone employee directory has been folded into the People hub as a
     * "Directory" tab (one list, one source). Preserve the route by redirecting,
     * carrying the search/department filters across (site → site_id).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $params = ['tab' => 'people'];
        if ($q = trim((string) $request->query('q', ''))) {
            $params['q'] = $q;
        }
        if ($department = $request->query('department')) {
            $params['department'] = $department;
        }
        if ($site = $request->query('site')) {
            $params['site_id'] = $site;
        }

        return redirect()->route('hr.people.index', $params);
    }

    public function show(Request $request, HrEmployeeProfile $profile)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        // The route is open to all authenticated users; restrict to staff (the
        // viewer must have an HR employee profile) so portal/family users can't
        // pull a colleague's directory card.
        $viewerIsStaff = HrEmployeeProfile::where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->exists();
        abort_unless($viewerIsStaff, 403);

        $profile->load('user:id,name,email,cellphone,work_phone', 'primarySite:id,name', 'position:id,title,code', 'departmentRelation:id,name');

        // Tenure calculation
        $tenure = null;
        if ($profile->start_date) {
            $totalMonths = (int) $profile->start_date->diffInMonths(now());
            $tenure = [
                'years' => (int) floor($totalMonths / 12),
                'months' => $totalMonths % 12,
            ];
        }

        // Manager
        $manager = null;
        if ($profile->manager_user_id) {
            $managerProfile = HrEmployeeProfile::where('user_id', $profile->manager_user_id)
                ->where('tenant_id', $tenantId)
                ->active()
                ->with('user:id,name')
                ->first();
            if ($managerProfile) {
                $manager = [
                    'id' => $managerProfile->id,
                    'name' => $managerProfile->user?->name ?? 'Unknown',
                    'position_title' => $managerProfile->position_title,
                    'profile_photo_path' => $managerProfile->profile_photo_path,
                ];
            }
        }

        // Direct reports
        $directReports = HrEmployeeProfile::where('manager_user_id', $profile->user_id)
            ->where('tenant_id', $tenantId)
            ->active()
            ->with('user:id,name')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->user?->name ?? 'Unknown',
                'position_title' => $r->position_title,
                'profile_photo_path' => $r->profile_photo_path,
            ]);

        // Kudos received (public)
        $kudosReceived = HrKudos::where('tenant_id', $tenantId)
            ->where('to_user_id', $profile->user_id)
            ->where('is_public', true)
            ->with('fromUser:id,name')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($k) => [
                'id' => $k->id,
                'from_name' => $k->fromUser?->name ?? 'Someone',
                'category' => $k->category,
                'message' => $k->message,
                'created_at' => $k->created_at?->toDateString(),
            ]);

        $kudosCount = HrKudos::where('tenant_id', $tenantId)
            ->where('to_user_id', $profile->user_id)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        // Compliance (manager-only)
        $canManage = $user->canDo('hr.employees.manage') || $user->canDo('hr.compliance.view');
        $complianceSummary = null;
        $goals = null;

        if ($canManage) {
            $statuses = HrStaffComplianceStatus::where('tenant_id', $tenantId)
                ->where('user_id', $profile->user_id)
                ->with('requirement:id,name,category')
                ->get();

            $complianceSummary = [
                'compliant' => $statuses->where('status', 'compliant')->count(),
                'expiring_soon' => $statuses->where('status', 'expiring_soon')->count(),
                'expired' => $statuses->whereIn('status', ['expired', 'non_compliant'])->count(),
                'not_started' => $statuses->whereNotIn('status', ['compliant', 'expiring_soon', 'expired', 'non_compliant'])->count(),
                'total' => $statuses->count(),
            ];

            $goals = HrDevelopmentGoal::where('tenant_id', $tenantId)
                ->where('employee_user_id', $profile->user_id)
                ->whereIn('status', ['not_started', 'in_progress', 'blocked'])
                ->limit(5)
                ->get()
                ->map(fn ($g) => [
                    'id' => $g->id,
                    'title' => $g->title,
                    'status' => $g->status,
                    'progress_percent' => $g->progress_percent ?? 0,
                ]);
        }

        // JSON for the People-hub Directory staff-details modal (the standalone
        // full-page directory profile was dropped in favour of the modal).
        // Personal contact is manager-only; everyone else sees work contact.
        return response()->json([
            'employee' => [
                'id' => $profile->id,
                'user_id' => $profile->user_id,
                'name' => $profile->preferred_name ?? $profile->user?->name ?? 'Unknown',
                'full_name' => $profile->user?->name ?? 'Unknown',
                'email' => $profile->work_email ?? $profile->user?->email,
                'work_phone' => $profile->work_phone,
                'personal_email' => $canManage ? $profile->personal_email : null,
                'personal_phone' => $canManage ? ($profile->user?->cellphone ?? $profile->personal_phone) : null,
                'position_title' => $profile->position_title,
                'department' => $profile->departmentRelation?->name ?? $profile->department,
                'team' => $profile->team,
                'site' => $profile->primarySite?->name,
                'profile_photo_path' => $profile->profile_photo_path,
                'bio' => $profile->bio,
                'start_date' => $profile->start_date?->toDateString(),
                'employment_type' => $profile->employment_type,
                'is_first_aider' => $profile->is_first_aider,
                'is_fire_warden' => $profile->is_fire_warden,
            ],
            'tenure' => $tenure,
            'manager' => $manager,
            'directReports' => $directReports,
            'kudosReceived' => $kudosReceived,
            'kudosCount' => $kudosCount,
            'complianceSummary' => $complianceSummary,
            'goals' => $goals,
            'canManage' => $canManage,
        ]);
    }

    public function uploadPhoto(Request $request, HrEmployeeProfile $profile)
    {
        $user = $request->user();
        abort_unless($user && ($user->id === $profile->user_id || $user->canDo('hr.employees.manage')), 403);

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

        $path = $request->file('photo')->store("hr/photos/{$profile->id}", 'public');
        $profile->update(['profile_photo_path' => $path]);

        return redirect()->back()->with('success', 'Photo updated.');
    }
}
