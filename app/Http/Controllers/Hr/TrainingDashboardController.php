<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\StaffTrainingRecord;
use App\Models\TrainingCourse;
use App\Models\Site;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TrainingDashboardController extends Controller
{
    /* ------------------------------------------------------------------ */
    /*  Index — training dashboard: overdue, due soon, by site             */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.training.view'), 403);

        $filterSiteId = $request->query('site_id');

        // Overdue / expired training records
        // Note: StaffTrainingRecord is not tenant-aware; scoping is by user_id only
        $overdue = StaffTrainingRecord::expired()
            ->with([
                'user:id,name,email',
                'trainingCourse:id,name,code,category',
            ])
            ->orderBy('expires_at')
            ->limit(100)
            ->get();

        // Expiring within next 60 days
        $dueSoon = StaffTrainingRecord::expiringSoon(2)
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
            // Count training records for staff at this site
            // Note: Using assignedClients site relationship as proxy for staff site
            $staffAtSite = \App\Models\Staff::whereHas('user.assignedClients.site', fn ($q) => $q->where('sites.id', $site->id))
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
            ->whereHas('trainingRecords', fn ($q) => $q->expired())
            ->withCount(['trainingRecords' => fn ($q) => $q->expired()])
            ->orderBy('training_records_count', 'desc')
            ->limit(20)
            ->get();

        /* ------------------------------------------------------------------ */
        /*  Return view                                                       */
        /* ------------------------------------------------------------------ */

        return Inertia::render('hr/training/index', [
            'stats' => [
                'totalRecords'       => StaffTrainingRecord::count(),
                'expiredCount'       => StaffTrainingRecord::expired()->count(),
                'dueSoonCount'       => StaffTrainingRecord::expiringSoon(2)->count(),
                'completedThisMonth' => StaffTrainingRecord::whereMonth('completed_at', now()->month)->count(),
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
