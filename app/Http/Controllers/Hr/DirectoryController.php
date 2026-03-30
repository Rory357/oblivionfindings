<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Site;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DirectoryController extends Controller
{
    use ResolvesHrTenant;

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $search = trim((string) $request->query('q', ''));
        $department = $request->query('department');
        $site = $request->query('site');

        $employees = HrEmployeeProfile::forTenant($tenantId)
            ->active()
            ->when($search !== '', fn ($q) => $q->whereHas('user', fn ($u) =>
                $u->where('name', 'like', "%{$search}%")
            ))
            ->when($department, fn ($q) => $q->where('department', $department))
            ->when($site, fn ($q) => $q->where('primary_site_id', $site))
            ->with('user:id,name,email', 'primarySite:id,name')
            ->orderBy('position_title')
            ->paginate(24)
            ->withQueryString();

        $employees->through(fn ($emp) => [
            'id' => $emp->id,
            'name' => $emp->preferred_name ?? $emp->user?->name ?? 'Unknown',
            'full_name' => $emp->user?->name ?? 'Unknown',
            'email' => $emp->work_email,
            'phone' => $emp->work_phone,
            'position_title' => $emp->position_title,
            'department' => $emp->department,
            'site' => $emp->primarySite?->name,
            'profile_photo_path' => $emp->profile_photo_path,
            'bio' => $emp->bio,
        ]);

        $departments = HrEmployeeProfile::forTenant($tenantId)
            ->active()
            ->whereNotNull('department')
            ->distinct()
            ->pluck('department')
            ->sort()
            ->values();

        $sites = Site::where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('hr/directory/index', [
            'employees' => $employees,
            'departments' => $departments,
            'sites' => $sites,
            'filters' => [
                'q' => $search,
                'department' => $department,
                'site' => $site,
            ],
        ]);
    }

    public function show(Request $request, HrEmployeeProfile $profile)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $profile->load('user:id,name,email,cellphone,work_phone', 'primarySite:id,name', 'position:id,title,code');

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

        // Kudos categories
        $kudosCategories = [
            'teamwork' => 'Teamwork',
            'innovation' => 'Innovation',
            'leadership' => 'Leadership',
            'customer_focus' => 'Customer Focus',
            'going_above' => 'Going Above & Beyond',
            'other' => 'Other',
        ];

        return Inertia::render('hr/directory/show', [
            'employee' => [
                'id' => $profile->id,
                'user_id' => $profile->user_id,
                'name' => $profile->preferred_name ?? $profile->user?->name ?? 'Unknown',
                'full_name' => $profile->user?->name ?? 'Unknown',
                'email' => $profile->work_email ?? $profile->user?->email,
                'phone' => $profile->work_phone ?? $profile->user?->cellphone ?? $profile->personal_phone,
                'work_phone' => $profile->work_phone,
                'cellphone' => $profile->user?->cellphone,
                'personal_email' => $profile->personal_email,
                'position_title' => $profile->position_title,
                'department' => $profile->department,
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
            'kudosCategories' => $kudosCategories,
            'canManage' => $canManage,
            'authUserId' => $user->id,
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
