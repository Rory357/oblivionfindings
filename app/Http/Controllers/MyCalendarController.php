<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\MedicationRound;
use App\Models\ControlRoom\AlertTask;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class MyCalendarController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user(), 403);

        return Inertia::render('my-calendar', []);
    }

    public function events(Request $request)
    {
        abort_unless($request->user(), 403);

        $userId = $request->user()->id;

        try {
            $start = Carbon::parse($request->input('start', now()->startOfWeek()));
            $end = Carbon::parse($request->input('end', now()->endOfWeek()));
        } catch (\Exception $e) {
            $start = now()->startOfWeek();
            $end = now()->endOfWeek();
        }

        $events = [];

        // 1. Shifts
        $shifts = Shift::where('user_id', $userId)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->with('client:id,first_name,last_name')
            ->get();

        foreach ($shifts as $shift) {
            $events[] = [
                'id' => 'shift-' . $shift->id,
                'title' => trim($shift->client->first_name . ' ' . $shift->client->last_name),
                'start' => $shift->starts_at->toIso8601String(),
                'end' => $shift->ends_at->toIso8601String(),
                'backgroundColor' => $shift->status === 'in_progress' ? '#dcfce7' : ($shift->status === 'completed' ? '#f1f5f9' : '#dbeafe'),
                'textColor' => $shift->status === 'in_progress' ? '#166534' : ($shift->status === 'completed' ? '#64748b' : '#1e40af'),
                'borderColor' => 'transparent',
                'extendedProps' => [
                    'type' => 'shift',
                    'client_id' => $shift->client_id,
                    'status' => $shift->status,
                    'location' => $shift->location,
                ],
            ];
        }

        // 2. Medication Rounds
        try {
            $rounds = MedicationRound::where('assigned_to', $userId)
                ->whereDate('round_date', '>=', $start)
                ->whereDate('round_date', '<=', $end)
                ->get();

            foreach ($rounds as $round) {
                $roundStart = Carbon::parse($round->round_date->format('Y-m-d') . ' ' . $round->scheduled_time);
                $roundEnd = $roundStart->copy()->addHour();

                $events[] = [
                    'id' => 'med-round-' . $round->id,
                    'title' => 'Medication Round',
                    'start' => $roundStart->toIso8601String(),
                    'end' => $roundEnd->toIso8601String(),
                    'backgroundColor' => '#fed7aa',
                    'textColor' => '#9a3412',
                    'borderColor' => 'transparent',
                    'extendedProps' => [
                        'type' => 'medication_round',
                        'status' => $round->status ?? null,
                    ],
                ];
            }
        } catch (\Throwable $e) {
            // Table may not exist yet
        }

        // 3. Leave Requests
        try {
            $leaveRequests = \App\Domain\Hr\Models\HrLeaveRequest::where('user_id', $userId)
                ->where('status', 'approved')
                ->where('starts_at', '<', $end)
                ->where('ends_at', '>', $start)
                ->get();

            foreach ($leaveRequests as $leave) {
                $events[] = [
                    'id' => 'leave-' . $leave->id,
                    'title' => ucfirst($leave->leave_type ?? 'Leave'),
                    'start' => Carbon::parse($leave->starts_at)->toIso8601String(),
                    'end' => Carbon::parse($leave->ends_at)->toIso8601String(),
                    'allDay' => true,
                    'backgroundColor' => '#d1fae5',
                    'textColor' => '#065f46',
                    'borderColor' => 'transparent',
                    'extendedProps' => [
                        'type' => 'leave',
                        'leave_type' => $leave->leave_type ?? null,
                    ],
                ];
            }
        } catch (\Throwable $e) {
            // Table may not exist yet
        }

        // 4. Alert Tasks
        try {
            $alertTasks = AlertTask::where('assigned_to_user_id', $userId)
                ->whereNotNull('due_at')
                ->where('due_at', '>=', $start)
                ->where('due_at', '<=', $end)
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->get();

            foreach ($alertTasks as $task) {
                $events[] = [
                    'id' => 'alert-task-' . $task->id,
                    'title' => $task->title ?? 'Alert Task',
                    'start' => Carbon::parse($task->due_at)->toIso8601String(),
                    'allDay' => true,
                    'backgroundColor' => '#e9d5ff',
                    'textColor' => '#6b21a8',
                    'borderColor' => 'transparent',
                    'extendedProps' => [
                        'type' => 'alert_task',
                        'status' => $task->status,
                        'priority' => $task->priority ?? null,
                    ],
                ];
            }
        } catch (\Throwable $e) {
            // Table may not exist yet
        }

        return response()->json($events);
    }
}
