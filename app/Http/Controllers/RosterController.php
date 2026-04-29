<?php

namespace App\Http\Controllers;

use App\Http\Resources\MyShiftResource;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Inertia\Inertia;
use Inertia\Response;

class RosterController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user(), 403);

        return Inertia::render('my-roster/index', $this->payloadFor($request->user()));
    }

    public function data(Request $request): JsonResponse
    {
        abort_unless($request->user(), 403);

        return response()->json($this->payloadFor($request->user()));
    }

    private function payloadFor(User $user): array
    {
        $workerNow = Carbon::now($this->workerTimezone());
        $todayStart = $workerNow->copy()->startOfDay();
        $todayEnd = $workerNow->copy()->endOfDay();
        $upcomingEnd = $workerNow->copy()->addDays(14)->endOfDay();
        $recentStart = $workerNow->copy()->subDays(7)->startOfDay();

        $today = $this->shiftsBetween($user, $todayStart, $todayEnd, $workerNow);
        $upcoming = $this->shiftsBetween(
            $user,
            $todayEnd->copy()->addSecond(),
            $upcomingEnd,
            $workerNow,
        );
        $recent = $this->recentCompletedShifts($user, $recentStart, $workerNow);

        $groupedByDay = collect($today)
            ->merge($upcoming)
            ->groupBy('day_key')
            ->map(fn ($items) => $items->values()->all())
            ->all();

        return [
            'today' => $workerNow->format('l, j F Y'),
            'today_shifts' => $today,
            'upcoming_shifts' => $upcoming,
            'recent_shifts' => $recent,
            'grouped_by_day' => $groupedByDay,
            'window' => [
                'timezone' => $this->workerTimezone(),
                'today' => $workerNow->toDateString(),
                'upcoming_days' => 14,
                'recent_days' => 7,
            ],
            'my_day_labels' => Lang::get('my-day'),
        ];
    }

    private function shiftsBetween(
        User $user,
        Carbon $start,
        Carbon $end,
        Carbon $workerNow,
    ): array {
        return Shift::query()
            ->where('user_id', $user->id)
            ->visibleToFrontline($user->organization_id)
            ->whereBetween('starts_at', [$start->copy()->utc(), $end->copy()->utc()])
            ->with([
                'client:id,first_name,last_name,profile_photo_path',
                'serviceContext:id,name',
                'tasks',
                'timesheets' => fn ($query) => $query->latest('updated_at'),
            ])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Shift $shift) => MyShiftResource::fromShift($shift, $workerNow))
            ->values()
            ->all();
    }

    private function recentCompletedShifts(User $user, Carbon $recentStart, Carbon $workerNow): array
    {
        return Shift::query()
            ->where('user_id', $user->id)
            ->visibleToFrontline($user->organization_id)
            ->where(function ($query) use ($recentStart) {
                $query->where('actual_ends_at', '>=', $recentStart->copy()->utc())
                    ->orWhere(function ($fallback) use ($recentStart) {
                        $fallback->whereNull('actual_ends_at')
                            ->where('ends_at', '>=', $recentStart->copy()->utc());
                    });
            })
            ->where(function ($query) use ($workerNow) {
                $query->whereIn('status', ['completed', 'clocked_out', 'finished'])
                    ->orWhere('actual_ends_at', '<=', $workerNow->copy()->utc());
            })
            ->with([
                'client:id,first_name,last_name,profile_photo_path',
                'serviceContext:id,name',
                'tasks',
                'timesheets' => fn ($query) => $query->latest('updated_at'),
            ])
            ->orderByDesc('actual_ends_at')
            ->orderByDesc('ends_at')
            ->get()
            ->map(fn (Shift $shift) => MyShiftResource::fromShift($shift, $workerNow))
            ->values()
            ->all();
    }

    private function workerTimezone(): string
    {
        return (string) config('app.worker_timezone', 'Pacific/Auckland');
    }
}
