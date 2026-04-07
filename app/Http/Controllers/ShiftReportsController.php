<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;

class ShiftReportsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('reports.viewAny'), 403);

        $from = $request->query('from') ? now()->parse($request->query('from'))->startOfDay() : now()->subDays(7)->startOfDay();
        $to = $request->query('to') ? now()->parse($request->query('to'))->endOfDay() : now()->endOfDay();

        $query = Shift::query()
            ->with(['client:id,first_name,last_name,site_id', 'staff:id,name'])
            ->whereBetween('starts_at', [$from, $to])
            ->orderByDesc('starts_at')
            ->limit(300);

        app(UserSiteAccessService::class)->applyShiftScope($query, $user, ['shifts.manageAny']);

        $shifts = $query
            ->get()
            ->map(function (Shift $s) {
                $noteCount = \App\Models\ClientNote::query()
                    ->where('shift_id', $s->id)
                    ->whereIn('type', ['progress_note', 'shift_note'])
                    ->count();

                $taskTotal = $s->tasks()->count();
                $taskDone = $s->tasks()->where('is_completed', true)->count();

                return [
                    'id' => $s->id,
                    'status' => $s->status,
                    'starts_at' => optional($s->starts_at)->toISOString(),
                    'ends_at' => optional($s->ends_at)->toISOString(),
                    'client' => $s->client ? ($s->client->first_name . ' ' . $s->client->last_name) : null,
                    'staff' => $s->staff ? $s->staff->name : null,
                    'notes_count' => $noteCount,
                    'tasks_total' => $taskTotal,
                    'tasks_done' => $taskDone,
                ];
            })
            ->values();

        return inertia('reports/shifts', [
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'shifts' => $shifts,
        ]);
    }
}
