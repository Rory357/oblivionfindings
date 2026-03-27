<?php

namespace App\Http\Controllers;

use App\Models\ControlRoom\OperatorNote;
use App\Models\ControlRoomAlert;
use App\Models\IncidentFollowup;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MyTasksController extends Controller
{
    private const PRIORITY_ORDER = [
        'critical' => 0,
        'high' => 1,
        'medium' => 2,
        'low' => 3,
    ];

    public function __invoke(Request $request): Response
    {
        abort_unless($request->user(), 403);

        $userId = $request->user()->id;
        $now = Carbon::now();
        $todayEnd = $now->copy()->endOfDay();

        $tasks = collect()
            ->merge($this->getAlertTasks($userId))
            ->merge($this->getFollowupTasks($userId))
            ->merge($this->getNoteFollowupTasks($userId));

        // Sort by priority (critical first), then due_at ascending (nulls last)
        $tasks = $tasks->sort(function ($a, $b) {
            $aPriority = self::PRIORITY_ORDER[$a['priority']] ?? 3;
            $bPriority = self::PRIORITY_ORDER[$b['priority']] ?? 3;

            if ($aPriority !== $bPriority) {
                return $aPriority - $bPriority;
            }

            // Nulls last for due_at
            if ($a['due_at'] === null && $b['due_at'] === null) {
                return 0;
            }
            if ($a['due_at'] === null) {
                return 1;
            }
            if ($b['due_at'] === null) {
                return -1;
            }

            return Carbon::parse($a['due_at'])->timestamp - Carbon::parse($b['due_at'])->timestamp;
        })->values();

        $stats = [
            'total_tasks' => $tasks->count(),
            'critical_count' => $tasks->where('priority', 'critical')->count(),
            'due_today' => $tasks->filter(function ($task) use ($now, $todayEnd) {
                if (! $task['due_at']) {
                    return false;
                }
                $due = Carbon::parse($task['due_at']);

                return $due->gte($now) && $due->lte($todayEnd);
            })->count(),
            'overdue' => $tasks->filter(function ($task) use ($now) {
                return $task['due_at'] && Carbon::parse($task['due_at'])->lt($now);
            })->count(),
        ];

        return Inertia::render('my-tasks', [
            'tasks' => $tasks->all(),
            'stats' => $stats,
        ]);
    }

    private function getAlertTasks(int $userId): array
    {
        return ControlRoomAlert::where('assigned_to_user_id', $userId)
            ->unresolved()
            ->with(['asset:id,name', 'client:id,first_name,last_name', 'sla'])
            ->get()
            ->map(function (ControlRoomAlert $alert) {
                $clientName = $alert->client
                    ? trim($alert->client->first_name.' '.$alert->client->last_name)
                    : null;

                $slaStatus = null;
                if ($alert->sla) {
                    if ($alert->sla->response_breached) {
                        $slaStatus = 'breached';
                    } elseif ($alert->sla->response_deadline && $alert->sla->response_deadline->lt(now()->addMinutes(15))) {
                        $slaStatus = 'at_risk';
                    } else {
                        $slaStatus = 'on_track';
                    }
                }

                return [
                    'id' => 'alert-'.$alert->id,
                    'type' => 'alert',
                    'title' => $alert->alert_type,
                    'priority' => $alert->severity ?? 'medium',
                    'status' => $alert->status,
                    'source_url' => '/control-room/alerts/'.$alert->id,
                    'due_at' => $alert->sla?->response_deadline?->toIso8601String(),
                    'created_at' => $alert->triggered_at?->toIso8601String() ?? $alert->created_at->toIso8601String(),
                    'meta' => [
                        'source' => $alert->source,
                        'client_name' => $clientName,
                        'sla_status' => $slaStatus,
                        'asset_name' => $alert->asset?->name,
                    ],
                ];
            })
            ->all();
    }

    private function getFollowupTasks(int $userId): array
    {
        return IncidentFollowup::where('assigned_to_user_id', $userId)
            ->whereNull('completed_at')
            ->with(['incident.client:id,first_name,last_name'])
            ->get()
            ->map(function (IncidentFollowup $followup) {
                $incident = $followup->incident;
                $clientName = $incident?->client
                    ? trim($incident->client->first_name.' '.$incident->client->last_name)
                    : null;

                return [
                    'id' => 'followup-'.$followup->id,
                    'type' => 'followup',
                    'title' => 'Incident follow-up: '.($incident?->title ?? 'Unknown incident'),
                    'priority' => $incident?->severity ?? 'medium',
                    'status' => 'pending',
                    'source_url' => '/incidents/'.($followup->client_incident_id),
                    'due_at' => $followup->due_at?->toIso8601String(),
                    'created_at' => $followup->created_at->toIso8601String(),
                    'meta' => [
                        'client_name' => $clientName,
                    ],
                ];
            })
            ->all();
    }

    private function getNoteFollowupTasks(int $userId): array
    {
        return OperatorNote::where('user_id', $userId)
            ->where('requires_followup', true)
            ->get()
            ->map(function (OperatorNote $note) {
                $sourceUrl = $note->alert_id
                    ? '/control-room/alerts/'.$note->alert_id
                    : '/control-room/shifts';

                return [
                    'id' => 'note-'.$note->id,
                    'type' => 'note_followup',
                    'title' => 'Follow-up: '.Str::limit($note->content, 60),
                    'priority' => 'medium',
                    'status' => 'pending',
                    'source_url' => $sourceUrl,
                    'due_at' => $note->followup_at?->toIso8601String(),
                    'created_at' => $note->created_at->toIso8601String(),
                    'meta' => [],
                ];
            })
            ->values()
            ->all();
    }
}
