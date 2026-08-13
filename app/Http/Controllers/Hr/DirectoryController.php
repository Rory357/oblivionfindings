<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Domain\Hr\Services\HrProfilePhotoStorageService;
use App\Domain\Hr\Services\PeopleMutationLockService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class DirectoryController extends Controller
{
    /**
     * Retired management alias only. The operational staff directory remains
     * /hr/my/directory and belongs to the separate My HR boundary.
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

    public function show(
        Request $request,
        string $profile,
        HrCurrentStaffService $currentStaff,
        UserSiteAccessService $siteAccess,
        HrProfilePhotoStorageService $profilePhotos,
    ) {
        $user = $request->user();
        $profile = $this->currentVisibleProfile(
            $profile,
            $user,
            $currentStaff,
            $siteAccess,
        );
        $visibleStaff = $this->visibleCurrentStaffQuery($user, $siteAccess);

        $profile->load('user:id,name', 'primarySite:id,name', 'departmentRelation:id,name');
        abort_unless($profile->user, 404);

        $accessibleSiteIds = $siteAccess->accessibleSiteIds(
            $user,
            UserSiteAccessService::HR_EMPLOYEE_SITE_BYPASS_PERMISSIONS,
        );
        $visiblePrimarySite = $profile->primarySite
            && in_array((int) $profile->primary_site_id, $accessibleSiteIds, true)
                ? $profile->primarySite
                : null;

        // Tenure calculation
        $tenure = null;
        if ($profile->start_date) {
            $totalMonths = (int) $profile->start_date->diffInMonths(now());
            $tenure = [
                'years' => (int) floor($totalMonths / 12),
                'months' => $totalMonths % 12,
            ];
        }

        $canViewOrgChart = $user->canDo('hr.orgchart.view')
            || $user->canDo('hr.employees.viewAny');

        // Manager
        $manager = null;
        if ($canViewOrgChart && $profile->manager_user_id) {
            $managerProfile = HrEmployeeProfile::where('user_id', $profile->manager_user_id)
                ->whereIn('user_id', (clone $visibleStaff)->select('users.id'))
                ->with('user:id,name')
                ->first();
            if ($managerProfile) {
                $manager = [
                    'id' => $managerProfile->id,
                    'name' => $managerProfile->user?->name ?? 'Unknown',
                    'position_title' => $managerProfile->position_title,
                ];
            }
        }

        // Direct reports
        $directReports = $canViewOrgChart
            ? HrEmployeeProfile::where('manager_user_id', $profile->user_id)
                ->whereIn('user_id', (clone $visibleStaff)->select('users.id'))
                ->with('user:id,name')
                ->orderBy('id')
                ->limit(10)
                ->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->user?->name ?? 'Unknown',
                    'position_title' => $r->position_title,
                ])
            : collect();

        // Kudos received (public)
        $canViewRecognition = $user->canDo('hr.recognition.view');
        $kudosReceived = collect();
        $kudosCount = 0;
        if ($canViewRecognition) {
            $visibleKudos = HrKudos::query()
                ->where('to_user_id', $profile->user_id)
                ->where('is_public', true)
                ->whereIn('from_user_id', (clone $visibleStaff)->select('users.id'));
            $kudosReceived = (clone $visibleKudos)
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

            $kudosCount = (clone $visibleKudos)
                ->where('created_at', '>=', now()->subDays(30))
                ->count();
        }

        // Each non-directory domain keeps its own narrow permission boundary.
        // In particular, compliance access must never imply personal-contact or
        // performance access.
        $canViewSensitive = $user->canDo('hr.employees.viewRestricted');
        $canViewCompliance = $user->canDo('hr.compliance.view');
        $canViewPerformance = $user->canDo('hr.goals.view')
            || $user->canDo('hr.goals.manage')
            || $user->canDo('hr.performance.view')
            || $user->canDo('hr.performance.manage');
        $complianceSummary = null;
        $goals = null;

        if ($canViewCompliance) {
            $statuses = HrStaffComplianceStatus::query()
                ->where('user_id', $profile->user_id)
                ->get(['status']);

            $complianceSummary = [
                'compliant' => $statuses->where('status', 'compliant')->count(),
                'expiring_soon' => $statuses->where('status', 'expiring_soon')->count(),
                'expired' => $statuses->whereIn('status', ['expired', 'non_compliant'])->count(),
                'not_started' => $statuses->whereNotIn('status', ['compliant', 'expiring_soon', 'expired', 'non_compliant'])->count(),
                'total' => $statuses->count(),
            ];
        }

        if ($canViewPerformance) {
            $goals = HrDevelopmentGoal::query()
                ->where('employee_user_id', $profile->user_id)
                ->whereIn('status', ['not_started', 'in_progress', 'blocked'])
                ->orderBy('id')
                ->limit(5)
                ->get(['id', 'title', 'status', 'progress_percent'])
                ->map(fn ($g) => [
                    'id' => $g->id,
                    'title' => $g->title,
                    'status' => $g->status,
                    'progress_percent' => $g->progress_percent ?? 0,
                ]);
        }

        // JSON for the People-hub Directory staff-details modal (the standalone
        // full-page directory profile was dropped in favour of the modal).
        // Personal contact requires the dedicated restricted-profile permission;
        // work contact remains the ordinary directory contract.
        return response()->json([
            'employee' => [
                'id' => $profile->id,
                'user_id' => $profile->user_id,
                'name' => $profile->preferred_name ?? $profile->user?->name ?? 'Unknown',
                'full_name' => $profile->user?->name ?? 'Unknown',
                'email' => $profile->work_email,
                'work_phone' => $profile->work_phone,
                'personal_email' => $canViewSensitive ? $profile->personal_email : null,
                'personal_phone' => $canViewSensitive ? $profile->personal_phone : null,
                'position_title' => $profile->position_title,
                'department' => $profile->departmentRelation?->name ?? $profile->department,
                'team' => $profile->team,
                'site' => $visiblePrimarySite?->name,
                'profile_photo_url' => $this->profilePhotoUrl($profile, $profilePhotos),
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
            // Retained for the existing modal contract; it gates only the
            // bounded compliance roll-up in that component.
            'canManage' => $canViewCompliance,
        ]);
    }

    public function uploadPhoto(
        Request $request,
        string $profile,
        HrCurrentStaffService $currentStaff,
        UserSiteAccessService $siteAccess,
        PeopleMutationLockService $mutationLocks,
        HrProfilePhotoStorageService $profilePhotos,
    ) {
        $user = $request->user();
        $profile = $this->currentVisibleProfile(
            $profile,
            $user,
            $currentStaff,
            $siteAccess,
        );
        abort_unless(
            $user->id === $profile->user_id || $user->canDo('hr.employees.manage'),
            403,
        );

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

        $photo = $request->file('photo');
        abort_unless($photo instanceof UploadedFile, 422);
        $newPath = null;
        $committed = false;

        try {
            $newPath = DB::transaction(function () use (
                $user,
                $profile,
                $photo,
                $currentStaff,
                $mutationLocks,
                $profilePhotos,
                &$committed,
                &$newPath,
            ): string {
                $locked = $mutationLocks->lock(
                    [$user->id, $profile->user_id],
                    [$profile->id],
                );
                $lockedActor = $locked['users']->get($user->id);
                $lockedProfile = $locked['profiles']->get($profile->id);
                abort_unless(
                    $lockedActor instanceof User
                        && $lockedProfile instanceof HrEmployeeProfile
                        && ! $lockedProfile->trashed()
                        && $currentStaff->isCurrent($lockedActor),
                    404,
                );

                // A fresh access service is intentional: the pre-validation
                // visibility decision must not be reused after the lock wait.
                $lockedSiteAccess = new UserSiteAccessService;
                abort_unless(
                    $this->isCurrentVisibleProfile(
                        (int) $lockedProfile->id,
                        $lockedActor,
                        $lockedSiteAccess,
                    ),
                    404,
                );
                abort_unless(
                    $lockedActor->id === $lockedProfile->user_id
                        || $lockedActor->canDo('hr.employees.manage'),
                    403,
                );

                $oldPath = $lockedProfile->profile_photo_path;
                $storedPath = $this->storeProfilePhoto(
                    $photo,
                    (int) $lockedProfile->id,
                    $profilePhotos,
                );
                $newPath = is_string($storedPath) ? $storedPath : null;
                if (! $profilePhotos->isOwnedPath($newPath, (int) $lockedProfile->id)
                    || ! $profilePhotos->privateExists($newPath, (int) $lockedProfile->id)) {
                    throw ValidationException::withMessages([
                        'photo' => 'The photo could not be stored. Please try again.',
                    ]);
                }

                $this->persistProfilePhoto($lockedProfile, $newPath);
                DB::afterCommit(function () use (
                    $lockedProfile,
                    $oldPath,
                    $newPath,
                    $profilePhotos,
                    &$committed,
                ): void {
                    // Mark the database write committed before any fallible
                    // cleanup. The outer catch must never remove the new object
                    // after the row has begun referencing it.
                    $committed = true;

                    try {
                        $persistedPath = $this->persistedProfilePhotoPath((int) $lockedProfile->id);
                        if ($oldPath !== $newPath && $persistedPath !== $oldPath) {
                            $profilePhotos->deleteEverywhere($oldPath, (int) $lockedProfile->id);
                        }
                    } catch (Throwable $exception) {
                        $this->reportWithoutThrowing($exception);
                    }
                });

                return $newPath;
            }, 1);
            $committed = true;
        } catch (Throwable $exception) {
            if (! $committed) {
                $this->deletePrivateWithoutThrowing(
                    $profilePhotos,
                    $newPath,
                    (int) $profile->id,
                );
            }

            throw $exception;
        }

        return redirect()->back()->with('success', 'Photo updated.');
    }

    public function photo(
        Request $request,
        string $profile,
        HrCurrentStaffService $currentStaff,
        UserSiteAccessService $siteAccess,
        HrProfilePhotoStorageService $profilePhotos,
    ) {
        $profile = $this->currentVisibleProfile(
            $profile,
            $request->user(),
            $currentStaff,
            $siteAccess,
        );
        $path = $profile->profile_photo_path;
        $response = $profilePhotos->response($path, (int) $profile->id, [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        abort_unless($response, 404);

        return $response;
    }

    public function concealInvalidProfile(): never
    {
        abort(404);
    }

    protected function storeProfilePhoto(
        UploadedFile $photo,
        int $profileId,
        HrProfilePhotoStorageService $profilePhotos,
    ): string|false {
        return $profilePhotos->store($photo, $profileId);
    }

    protected function persistProfilePhoto(HrEmployeeProfile $profile, string $path): void
    {
        $profile->update(['profile_photo_path' => $path]);
    }

    protected function persistedProfilePhotoPath(int $profileId): ?string
    {
        return HrEmployeeProfile::query()
            ->whereKey($profileId)
            ->value('profile_photo_path');
    }

    private function currentVisibleProfile(
        string $routeProfileId,
        ?User $viewer,
        HrCurrentStaffService $currentStaff,
        UserSiteAccessService $siteAccess,
    ): HrEmployeeProfile {
        abort_unless($viewer && $currentStaff->isCurrent($viewer), 404);
        $profileId = $this->boundedRouteId($routeProfileId);
        $profile = HrEmployeeProfile::query()
            ->whereKey($profileId)
            ->whereIn(
                'user_id',
                $this->visibleCurrentStaffQuery($viewer, $siteAccess)->select('users.id'),
            )
            ->first();
        abort_unless($profile, 404);

        return $profile;
    }

    private function isCurrentVisibleProfile(
        int $profileId,
        User $viewer,
        UserSiteAccessService $siteAccess,
    ): bool {
        return HrEmployeeProfile::query()
            ->whereKey($profileId)
            ->whereIn(
                'user_id',
                $this->visibleCurrentStaffQuery($viewer, $siteAccess)->select('users.id'),
            )
            ->exists();
    }

    private function boundedRouteId(string $value): int
    {
        $normalized = ltrim($value, '0');
        $maximum = (string) PHP_INT_MAX;
        abort_unless(
            ctype_digit($value)
                && $normalized !== ''
                && (strlen($normalized) < strlen($maximum)
                    || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) <= 0)),
            404,
        );

        return (int) $normalized;
    }

    private function profilePhotoUrl(
        HrEmployeeProfile $profile,
        HrProfilePhotoStorageService $profilePhotos,
    ): ?string {
        $path = $profile->profile_photo_path;
        if ($profilePhotos->readableDisk($path, (int) $profile->id) === null) {
            return null;
        }

        return route('hr.directory.photo', ['profile' => $profile->id]);
    }

    private function deletePrivateWithoutThrowing(
        HrProfilePhotoStorageService $profilePhotos,
        mixed $path,
        int $profileId,
    ): void {
        try {
            $profilePhotos->deletePrivate($path, $profileId);
        } catch (Throwable $exception) {
            $this->reportWithoutThrowing($exception);
        }
    }

    private function reportWithoutThrowing(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            // Cleanup/reporting failures must never corrupt the committed
            // database-to-object reference or mask the primary exception.
        }
    }

    /** @return Builder<User> */
    private function visibleCurrentStaffQuery(
        User $viewer,
        UserSiteAccessService $siteAccess,
    ): Builder {
        $query = User::query();
        $siteAccess->applyHrEmployeeStaffScope($query, $viewer);

        return $query;
    }
}
