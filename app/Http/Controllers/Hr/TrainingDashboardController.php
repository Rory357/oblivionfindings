<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\StaffTrainingRecord;
use App\Models\TrainingCourse;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TrainingDashboardController extends Controller
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — training dashboard: overdue, due soon, by site */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        $canView = $user && ($user->canDo('hr.training.view') || $user->canDo('training.viewAny'));
        abort_unless($canView, 403);
        $visibleStaff = User::query();
        $this->siteAccess->applyStaffScope($visibleStaff, $user);
        $visibleStaffUserIds = $visibleStaff->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
        $staffUserIds = $visibleStaffUserIds;

        $filterSiteId = $request->query('site_id');

        if ($filterSiteId) {
            $filterSiteId = (int) $filterSiteId;
            abort_unless(in_array($filterSiteId, $this->siteAccess->accessibleSiteIds($user), true), 404);
            $staffUserIds = HrEmployeeProfile::query()
                ->whereIn('user_id', $visibleStaffUserIds ?: [0])
                ->where(function ($query) use ($filterSiteId) {
                    $query->where('primary_site_id', $filterSiteId)
                        ->orWhereJsonContains('secondary_site_ids', $filterSiteId);
                })
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        // Overdue / expired training records
        // StaffTrainingRecord has no Site relation; scope through the authorised staff user IDs.
        $overdue = StaffTrainingRecord::expired()
            ->whereIn('user_id', $staffUserIds)
            ->with([
                'user:id,name,email',
                'trainingCourse:id,name,code,category',
            ])
            ->orderBy('expires_at')
            ->limit(100)
            ->get();

        // Expiring within next 60 days
        $dueSoon = StaffTrainingRecord::expiringSoon(2)
            ->whereIn('user_id', $staffUserIds)
            ->with([
                'user:id,name,email',
                'trainingCourse:id,name,code,category',
            ])
            ->orderBy('expires_at')
            ->limit(100)
            ->get();

        // Summary counts by site
        $sites = Site::query();
        $this->siteAccess->applySiteScope($sites, $user);
        $sites = $sites->orderBy('name')->get(['id', 'name']);

        $bySite = [];
        foreach ($sites as $site) {
            $staffAtSite = HrEmployeeProfile::query()
                ->whereIn('user_id', $visibleStaffUserIds ?: [0])
                ->where(function ($query) use ($site) {
                    $query->where('primary_site_id', $site->id)
                        ->orWhereJsonContains('secondary_site_ids', $site->id);
                })
                ->pluck('user_id');

            $bySite[] = [
                'site_id' => $site->id,
                'site_name' => $site->name,
                'total' => StaffTrainingRecord::whereIn('user_id', $staffAtSite)->count(),
                'expired' => StaffTrainingRecord::expired()->whereIn('user_id', $staffAtSite)->count(),
            ];
        }

        /* ------------------------------------------------------------------ */
        /*  Due-soon matrix by role / competency */
        /* ------------------------------------------------------------------ */

        $courses = TrainingCourse::orderBy('category')->orderBy('name')->get(['id', 'name', 'category']);

        $matrix = [];
        foreach ($courses as $course) {
            $expiringCount = StaffTrainingRecord::where('training_course_id', $course->id)
                ->whereIn('user_id', $staffUserIds)
                ->expiringSoon(2)
                ->count();

            if ($expiringCount > 0) {
                $matrix[] = [
                    'course_id' => $course->id,
                    'course_name' => $course->name,
                    'category' => $course->category,
                    'count' => $expiringCount,
                ];
            }
        }

        /* ------------------------------------------------------------------ */
        /*  Courses needing renewal (global) */
        /* ------------------------------------------------------------------ */

        $renewalNeeded = TrainingCourse::where('requires_renewal', true)
            ->whereHas('trainingRecords', fn ($q) => $q->expired()->whereIn('user_id', $staffUserIds))
            ->withCount(['trainingRecords' => fn ($q) => $q->expired()->whereIn('user_id', $staffUserIds)])
            ->orderBy('training_records_count', 'desc')
            ->limit(20)
            ->get();

        /* ------------------------------------------------------------------ */
        /*  Return view */
        /* ------------------------------------------------------------------ */

        return Inertia::render('hr/training/index', [
            'stats' => [
                'totalRecords' => StaffTrainingRecord::whereIn('user_id', $staffUserIds)->count(),
                'expiredCount' => StaffTrainingRecord::expired()->whereIn('user_id', $staffUserIds)->count(),
                'dueSoonCount' => StaffTrainingRecord::expiringSoon(2)->whereIn('user_id', $staffUserIds)->count(),
                'completedThisMonth' => StaffTrainingRecord::whereIn('user_id', $staffUserIds)
                    ->whereYear('completed_at', now()->year)
                    ->whereMonth('completed_at', now()->month)
                    ->count(),
            ],
            'overdue' => $overdue,
            'dueSoon' => $dueSoon,
            'bySite' => $bySite,
            'matrix' => $matrix,
            'renewalNeeded' => $renewalNeeded,
            'filters' => ['site_id' => $filterSiteId],
        ]);
    }
}
