<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrCheckIn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class WellbeingController extends Controller
{
    /* ------------------------------------------------------------------ */
    /*  Index — wellbeing dashboard                                        */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.analytics.view'), 403);

        $tenantId = $user->tenant_id;

        // Mood trends over the past 8 weeks
        $moodTrends = HrCheckIn::forTenant($tenantId)
            ->where('created_at', '>=', now()->subWeeks(8))
            ->select(
                DB::raw("DATE_FORMAT(check_in_date, '%Y-%u') as week"),
                'mood',
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('week', 'mood')
            ->orderBy('week')
            ->get()
            ->groupBy('week')
            ->map(function ($group, $week) {
                $total = $group->sum('count');
                $data = ['week' => $week, 'total' => $total];
                foreach (['great', 'good', 'okay', 'struggling', 'bad'] as $mood) {
                    $count = $group->where('mood', $mood)->sum('count');
                    $data[$mood] = $total > 0 ? round(($count / $total) * 100, 1) : 0;
                }
                return $data;
            })
            ->values();

        // Average energy and workload by week
        $averages = HrCheckIn::forTenant($tenantId)
            ->where('created_at', '>=', now()->subWeeks(8))
            ->select(
                DB::raw("DATE_FORMAT(check_in_date, '%Y-%u') as week"),
                DB::raw('AVG(energy_level) as avg_energy'),
                DB::raw('AVG(workload_rating) as avg_workload')
            )
            ->whereNotNull('energy_level')
            ->groupBy('week')
            ->orderBy('week')
            ->get()
            ->map(fn ($row) => [
                'week' => $row->week,
                'avg_energy' => round($row->avg_energy, 1),
                'avg_workload' => round($row->avg_workload, 1),
            ]);

        // Recent anonymous notes
        $recentNotes = HrCheckIn::forTenant($tenantId)
            ->where('is_anonymous', false)
            ->whereNotNull('notes')
            ->where('notes', '!=', '')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'mood' => $c->mood,
                'notes' => $c->notes,
                'check_in_date' => $c->check_in_date->toDateString(),
            ]);

        // Summary stats
        $last30Days = HrCheckIn::forTenant($tenantId)
            ->where('check_in_date', '>=', now()->subDays(30))
            ->get();

        $summary = [
            'total_checkins' => $last30Days->count(),
            'avg_energy' => $last30Days->whereNotNull('energy_level')->avg('energy_level')
                ? round($last30Days->whereNotNull('energy_level')->avg('energy_level'), 1)
                : null,
            'avg_workload' => $last30Days->whereNotNull('workload_rating')->avg('workload_rating')
                ? round($last30Days->whereNotNull('workload_rating')->avg('workload_rating'), 1)
                : null,
            'mood_breakdown' => [
                'great' => $last30Days->where('mood', 'great')->count(),
                'good' => $last30Days->where('mood', 'good')->count(),
                'okay' => $last30Days->where('mood', 'okay')->count(),
                'struggling' => $last30Days->where('mood', 'struggling')->count(),
                'bad' => $last30Days->where('mood', 'bad')->count(),
            ],
        ];

        return Inertia::render('hr/wellbeing/index', [
            'moodTrends' => $moodTrends,
            'averages' => $averages,
            'recentNotes' => $recentNotes,
            'summary' => $summary,
        ]);
    }
}
