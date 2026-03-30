<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrEmployeeProfile;
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

        $profile->load('user:id,name,email', 'primarySite:id,name', 'position:id,title,code');

        return Inertia::render('hr/directory/show', [
            'employee' => [
                'id' => $profile->id,
                'name' => $profile->preferred_name ?? $profile->user?->name ?? 'Unknown',
                'full_name' => $profile->user?->name ?? 'Unknown',
                'email' => $profile->work_email,
                'phone' => $profile->work_phone,
                'position_title' => $profile->position_title,
                'department' => $profile->department,
                'team' => $profile->team,
                'site' => $profile->primarySite?->name,
                'profile_photo_path' => $profile->profile_photo_path,
                'bio' => $profile->bio,
                'start_date' => $profile->start_date?->toDateString(),
                'is_first_aider' => $profile->is_first_aider,
                'is_fire_warden' => $profile->is_fire_warden,
            ],
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
