<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoom\AlertQueue;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Services\AuditLogger;
use App\Services\ControlRoom\AlertWorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ControlRoomEscalationController extends Controller
{
    /**
     * Display the escalation queue Kanban board.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);

        $queues = TriageQueue::active()
            ->orderBy('tier')
            ->orderBy('name')
            ->get()
            ->map(function (TriageQueue $queue) {
                // Board columns show the top of the queue only — rendering every
                // alert (dev data has 1,000+) makes the page unusable. The full
                // count still drives the column badge and capacity bar.
                $totalCount = ControlRoomAlert::unresolved()
                    ->where('queue_id', $queue->id)
                    ->count();

                $alerts = ControlRoomAlert::unresolved()
                    ->where('queue_id', $queue->id)
                    ->with(['assignedTo:id,name', 'sla', 'client:id,first_name,last_name'])
                    ->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low')")
                    ->orderBy('triggered_at')
                    ->limit(25)
                    ->get();

                $alertData = $alerts->map(function (ControlRoomAlert $alert) use ($queue) {
                    // Get the current queue entry for time-in-queue
                    $currentQueueEntry = AlertQueue::where('alert_id', $alert->id)
                        ->where('queue_id', $queue->id)
                        ->whereNull('exited_at')
                        ->latest('entered_at')
                        ->first();

                    $enteredAt = $currentQueueEntry?->entered_at ?? $alert->triggered_at;

                    $sla = $alert->sla;

                    // Format alert_type nicely: replace dots/underscores with spaces, title case
                    $formattedAlertType = Str::title(str_replace(['.', '_'], ' ', $alert->alert_type));

                    // Build client name from relation
                    $clientName = null;
                    if ($alert->client) {
                        $clientName = trim($alert->client->first_name . ' ' . $alert->client->last_name);
                    }

                    return [
                        'id' => $alert->id,
                        'severity' => $alert->severity,
                        'alert_type' => $formattedAlertType,
                        'alert_type_raw' => $alert->alert_type,
                        'source' => $alert->source,
                        'status' => $alert->status,
                        'escalation_level' => $alert->escalation_level,
                        'triggered_at' => $alert->triggered_at?->toISOString(),
                        'acknowledged_at' => $alert->acknowledged_at?->toISOString(),
                        'assigned_to' => $alert->assignedTo ? [
                            'id' => $alert->assignedTo->id,
                            'name' => $alert->assignedTo->name,
                        ] : null,
                        'client_name' => $clientName,
                        'context' => $alert->context,
                        'entered_queue_at' => $enteredAt?->toISOString(),
                        'sla' => $sla ? [
                            'acknowledge_deadline' => $sla->acknowledge_deadline?->toISOString(),
                            'response_deadline' => $sla->response_deadline?->toISOString(),
                            'resolution_deadline' => $sla->resolution_deadline?->toISOString(),
                            'acknowledged_at' => $sla->acknowledged_at?->toISOString(),
                            'responded_at' => $sla->responded_at?->toISOString(),
                            'resolved_at' => $sla->resolved_at?->toISOString(),
                            'acknowledge_breached' => $sla->acknowledge_breached ?? false,
                            'response_breached' => $sla->response_breached ?? false,
                            'resolution_breached' => $sla->resolution_breached ?? false,
                            'status' => $sla->getStatus(),
                        ] : null,
                    ];
                })->all();

                return [
                    'id' => $queue->id,
                    'name' => $queue->name,
                    'code' => $queue->code,
                    'tier' => $queue->tier,
                    'description' => $queue->description ?? null,
                    'auto_escalate_after_minutes' => $queue->auto_escalate_after_minutes,
                    'escalate_to_queue_id' => $queue->escalate_to_queue_id,
                    'alert_count' => $totalCount,
                    'alerts' => $alertData,
                ];
            })
            ->all();

        // All queues for the "move to" dropdown
        $allQueues = TriageQueue::active()
            ->orderBy('tier')
            ->orderBy('name')
            ->get()
            ->map(fn (TriageQueue $q) => [
                'id' => $q->id,
                'name' => $q->name,
                'tier' => $q->tier,
            ])
            ->all();

        return Inertia::render('control-room/escalations', [
            'queues' => $queues,
            'allQueues' => $allQueues,
            'serverTime' => now()->toISOString(),
            'can' => [
                'manage' => $user->canDo('controlRoom.alerts.manage'),
                'assign' => $user->canDo('controlRoom.alerts.assign'),
            ],
            // Workspace-over-list: when ?alert= is present the workspace dialog
            // opens over the queue board (Inertia partial-reloads only this prop).
            'detail' => fn () => $request->filled('alert')
                ? app(AlertWorkspaceService::class)->build($user, (int) $request->input('alert'))
                : null,
        ]);
    }

    /**
     * Acknowledge an alert from the escalation queue page.
     */
    public function acknowledgeFromQueue(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        if ($alert->status === 'closed' || $alert->status === 'resolved') {
            return back()->withErrors(['alert' => 'Cannot acknowledge a closed or resolved alert.']);
        }

        $alert->update([
            'status' => 'ack',
            'acknowledged_at' => now(),
            'acknowledged_by_user_id' => $user->id,
        ]);

        $alert->sla?->recordAcknowledge();

        AuditLogger::log('controlRoom.alert.acknowledge', $alert, [
            'alert_id' => $alert->id,
            'acknowledged_by' => $user->id,
            'source' => 'escalation_queue',
        ]);

        return back()->with('success', 'Alert acknowledged.');
    }

    /**
     * Assign an alert to the current user.
     */
    public function assignToMe(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.assign'), 403);

        $alert->update([
            'assigned_to_user_id' => $user->id,
            'assigned_at' => now(),
            'assigned_by_user_id' => $user->id,
        ]);

        AuditLogger::log('controlRoom.alert.assignToMe', $alert, [
            'alert_id' => $alert->id,
            'assigned_to' => $user->id,
            'assigned_by' => $user->id,
            'source' => 'escalation_queue',
        ]);

        return back()->with('success', 'Alert assigned to you.');
    }

    /**
     * Move an alert to a different queue.
     */
    public function moveToQueue(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $validated = $request->validate([
            'target_queue_id' => ['required', 'integer', 'exists:control_room_triage_queues,id'],
        ]);

        $targetQueue = TriageQueue::findOrFail($validated['target_queue_id']);

        // Close the current queue entry
        AlertQueue::where('alert_id', $alert->id)
            ->whereNull('exited_at')
            ->update([
                'exited_at' => now(),
                'exit_reason' => 'moved',
            ]);

        // Create a new queue entry
        AlertQueue::create([
            'alert_id' => $alert->id,
            'queue_id' => $targetQueue->id,
            'entered_at' => now(),
        ]);

        // Update the alert's queue
        $alert->update([
            'queue_id' => $targetQueue->id,
        ]);

        return redirect()->back()->with('success', "Alert #{$alert->id} moved to {$targetQueue->name}.");
    }

    /**
     * Bulk escalate alerts to their next tier queue.
     */
    public function bulkEscalate(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $validated = $request->validate([
            'alert_ids' => ['required', 'array', 'min:1'],
            'alert_ids.*' => ['integer', 'exists:control_room_alerts,id'],
            // Parity with single escalate — bulk moves carry a reason on each
            // alert's escalation history so the audit trail stays complete.
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $escalatedCount = 0;
        $skippedCount = 0;

        foreach ($validated['alert_ids'] as $alertId) {
            $alert = ControlRoomAlert::find($alertId);
            if (!$alert || !$alert->queue_id) {
                $skippedCount++;
                continue;
            }

            $currentQueue = TriageQueue::find($alert->queue_id);
            if (!$currentQueue || !$currentQueue->escalate_to_queue_id) {
                $skippedCount++;
                continue;
            }

            $nextQueue = TriageQueue::find($currentQueue->escalate_to_queue_id);
            if (!$nextQueue) {
                $skippedCount++;
                continue;
            }

            // Close current queue entry
            AlertQueue::where('alert_id', $alert->id)
                ->whereNull('exited_at')
                ->update([
                    'exited_at' => now(),
                    'exit_reason' => 'escalated',
                ]);

            // Create new queue entry
            AlertQueue::create([
                'alert_id' => $alert->id,
                'queue_id' => $nextQueue->id,
                'entered_at' => now(),
            ]);

            // Update alert
            $newLevel = ($alert->escalation_level ?? 0) + 1;
            $alert->update([
                'queue_id' => $nextQueue->id,
                'escalation_level' => $newLevel,
                'escalated_at' => now(),
                'escalated_by_user_id' => $user->id,
                'context' => array_merge($alert->context ?? [], [
                    'escalation_history' => array_merge($alert->context['escalation_history'] ?? [], [
                        [
                            'level' => $newLevel,
                            'reason' => $validated['reason'],
                            'escalated_by' => $user->id,
                            'escalated_at' => now()->toISOString(),
                            'bulk' => true,
                        ],
                    ]),
                ]),
            ]);

            $escalatedCount++;
        }

        $message = "{$escalatedCount} alert(s) escalated.";
        if ($skippedCount > 0) {
            $message .= " {$skippedCount} skipped (no next queue).";
        }

        return redirect()->back()->with('success', $message);
    }
}
