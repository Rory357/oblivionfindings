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

        $tenantId = null;
        $filterSiteId = $request->query('site_id');

        // Overdue / expired training records
        // Note: StaffTrainingRecord is not tenant-aware; scoping is by user_id only
        $overdue = StaffTrainingRecord::expired()
            ->when($filterSiteId, fn ($q) => $q->whereHas('user.staffProfile', fn ($p) =>
                $p->where('primary_site_id', $filterSiteId)
                  ->orWhereJsonContains('secondary_site_ids', (int) $filterSiteId)
            ))
            ->with([
                'user:id,name,email',
                'trainingCourse:id,name,code,category',
            ])
            ->orderBy('expires_at')
            ->limit(100)
            ->get();

        // Expiring within next 60 days
        $dueSoon = StaffTrainingRecord::expiringSoon(2)
            ->when($filterSiteId, fn ($q) => $q->whereHas('user.staffProfile', fn ($p) =>
                $p->where('primary_site_id', $filterSiteId)
                  ->orWhereJsonContains('secondary_site_ids', (int) $filterSiteId)
            ))
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
            $siteUserIds = \App\Domain\Hr\Models\HrEmployeeProfile::where('is_active', true)
                ->where(function ($q) use ($site) {
                    $q->where('primary_site_id', $site->id)
                      ->orWhereJsonContains('secondary_site_ids', $site->id);
                })
                ->pluck('user_id');

            if ($siteUserIds->isEmpty()) {
                $bySite[] = [
                    'site_id'   => $site->id,
                    'site_name' => $site->name,
                    'overdue'   => 0,
                    'due_soon'  => 0,
                    'valid'     => 0,
                    'total'     => 0,
                ];
                continue;
            }

            $overdueCount = StaffTrainingRecord::expired()
                ->whereIn('user_id', $siteUserIds)
                ->count();

            $dueSoonCount = StaffTrainingRecord::expiringSoon(2)
                ->whereIn('user_id', $siteUserIds)
                ->count();

            $validCount = StaffTrainingRecord::valid()
                ->whereIn('user_id', $siteUserIds)
                ->count();

            $totalCount = StaffTrainingRecord::whereIn('user_id', $siteUserIds)->count();

            $bySite[] = [
                'site_id'   => $site->id,
                'site_name' => $site->name,
                'overdue'   => $overdueCount,
                'due_soon'  => $dueSoonCount,
                'valid'     => $validCount,
                'total'     => $totalCount,
            ];
        }

        // Course catalog summary
        $courses = TrainingCourse::active()
            ->withCount([
                'trainingRecords as total_records',
                'trainingRecords as completed_count' => fn ($q) => $q->completed(),
                'trainingRecords as expired_count'   => fn ($q) => $q->expired(),
            ])
            ->orderBy('category')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'category', 'requires_renewal', 'validity_period_months']);

        return Inertia::render('hr/training/index', [
            'overdue' => $overdue,
            'dueSoon' => $dueSoon,
            'bySite' => $bySite,
            'courses' => $courses,
            'sites' => $sites,
            'filters' => [
                'site_id' => $filterSiteId,
            ],
            'can' => [
                'manage' => $user->canDo('hr.training.manage'),
            ],
        ]);
    }
}
