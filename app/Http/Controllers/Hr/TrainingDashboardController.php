<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\StaffTrainingRecord;
use App\Models\TrainingCourse;
use App\Models\Site;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TrainingDashboardController extends Controller
{
    use ResolvesHrTenant;

    /* ------------------------------------------------------------------ */
    /*  Index — training dashboard: overdue, due soon, by site             */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        $canView = $user && ($user->canDo('hr.training.view') || $user->canDo('training.viewAny'));
        abort_unless($canView, 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $staffUserIds = $this->hrStaffUserIdsForTenant($tenantId);

        $filterSiteId = $request->query('site_id');

        if ($filterSiteId) {
            $staffUserIds = HrEmployeeProfile::query()
                ->where('tenant_id', $tenantId)
                ->where(function ($query) use ($filterSiteId) {
                    $query->where('primary_site_id', (int) $filterSiteId)
                        ->orWhereJsonContains('secondary_site_ids', (int) $filterSiteId);
                })
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        // Overdue / expired training records
        // StaffTrainingRecord is not tenant-aware; scope by tenant staff user IDs.
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
        $sites = Site::orderBy('name')
            ->get(['id', 'name']);

        $bySite = [];
        foreach ($sites as $site) {
            $staffAtSite = HrEmployeeProfile::query()
                ->where('tenant_id', $tenantId)
                ->where(function ($query) use ($site) {
                    $query->where('primary_site_id', $site->id)
                        ->orWhereJsonContains('secondary_site_ids', $site->id);
                })
                ->pluck('user_id');

            $bySite[] = [
                'site_id'   => $site->id,
                'site_name' => $site->name,
                'total'     => StaffTrainingRecord::whereIn('user_id', $staffAtSite)->count(),
                'expired'   => StaffTrainingRecord::expired()->whereIn('user_id', $staffAtSite)->count(),
            ];
        }

        /* ------------------------------------------------------------------ */
        /*  Due-soon matrix by role / competency                              */
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
                    'course_id'   => $course->id,
                    'course_name' => $course->name,
                    'category'    => $course->category,
                    'count'       => $expiringCount,
                ];
            }
        }

        /* ------------------------------------------------------------------ */
        /*  Courses needing renewal (global)                                  */
        /* ------------------------------------------------------------------ */

        $renewalNeeded = TrainingCourse::where('requires_renewal', true)
            ->whereHas('trainingRecords', fn ($q) => $q->expired()->whereIn('user_id', $staffUserIds))
            ->withCount(['trainingRecords' => fn ($q) => $q->expired()->whereIn('user_id', $staffUserIds)])
            ->orderBy('training_records_count', 'desc')
            ->limit(20)
            ->get();

        /* ------------------------------------------------------------------ */
        /*  Return view                                                       */
        /* ------------------------------------------------------------------ */

        return Inertia::render('hr/training/index', [
            'stats' => [
                'totalRecords'       => StaffTrainingRecord::whereIn('user_id', $staffUserIds)->count(),
                'expiredCount'       => StaffTrainingRecord::expired()->whereIn('user_id', $staffUserIds)->count(),
                'dueSoonCount'       => StaffTrainingRecord::expiringSoon(2)->whereIn('user_id', $staffUserIds)->count(),
                'completedThisMonth' => StaffTrainingRecord::whereIn('user_id', $staffUserIds)->whereMonth('completed_at', now()->month)->count(),
            ],
            'overdue'       => $overdue,
            'dueSoon'       => $dueSoon,
            'bySite'        => $bySite,
            'matrix'        => $matrix,
            'renewalNeeded' => $renewalNeeded,
            'filters'       => ['site_id' => $filterSiteId],
        ]);
    }
}
