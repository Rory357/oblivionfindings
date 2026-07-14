<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoom\AlertQueue;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Services\AuditLogger;
use App\Services\ControlRoom\AlertWorkspaceService;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use InvalidArgumentException;

class ControlRoomEscalationController extends Controller
{
    private const TRANSACTION_ATTEMPTS = 3;

    /**
     * Display the escalation queue Kanban board.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);
        $siteAccess = app(UserSiteAccessService::class);
        $bypassPermissions = $this->alertBypassPermissions();

        $queues = TriageQueue::active()
            ->orderBy('tier')
            ->orderBy('name')
            ->get()
            ->map(function (TriageQueue $queue) use ($siteAccess, $user, $bypassPermissions) {
                // Board columns show the top of the queue only — rendering every
                // alert (dev data has 1,000+) makes the page unusable. The full
                // count still drives the column badge and capacity bar.
                $queueAlerts = ControlRoomAlert::query()
                    ->unresolved()
                    ->where('queue_id', $queue->id);
                $siteAccess->applyAlertScope($queueAlerts, $user, $bypassPermissions);

                $totalCount = (clone $queueAlerts)->count();

                $alerts = (clone $queueAlerts)
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
    public function acknowledgeFromQueue(
        Request $request,
        ControlRoomAlert $alert,
        ControlRoomAlertLifecycleService $lifecycle,
    )
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->siteAccess()->assertCanAccessAlert(
            $user,
            $alert,
            $this->alertBypassPermissions(),
            'You are not authorized to acknowledge this alert.',
        );

        try {
            $lifecycle->acknowledge($alert, $user);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['alert' => $e->getMessage()]);
        }

        return back()->with('success', 'Alert acknowledged.');
    }

    /**
     * Assign an alert to the current user.
     */
    public function assignToMe(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.assign'), 403);

        try {
            DB::transaction(function () use ($alert, $user): void {
                $lockedAlert = $this->lockAlert($alert);
                $this->siteAccess()->assertCanAccessAlert(
                    $user,
                    $lockedAlert,
                    $this->alertBypassPermissions(),
                    'You are not authorized to assign this alert.',
                );
                $this->assertActionable($lockedAlert, 'assign');

                $lockedAlert->update([
                    'assigned_to_user_id' => $user->id,
                    'assigned_at' => now(),
                    'assigned_by_user_id' => $user->id,
                ]);

                AuditLogger::log('controlRoom.alert.assignToMe', $lockedAlert, [
                    'alert_id' => $lockedAlert->id,
                    'assigned_to' => $user->id,
                    'assigned_by' => $user->id,
                    'source' => 'escalation_queue',
                ]);
            }, self::TRANSACTION_ATTEMPTS);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['alert' => $e->getMessage()]);
        }

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

        try {
            $targetQueue = DB::transaction(function () use ($alert, $user, $validated): TriageQueue {
                $lockedAlert = $this->lockAlert($alert);
                $this->siteAccess()->assertCanAccessAlert(
                    $user,
                    $lockedAlert,
                    $this->alertBypassPermissions(),
                    'You are not authorized to move this alert.',
                );
                $this->assertActionable($lockedAlert, 'move');
                if ($lockedAlert->queue_id !== null) {
                    $currentQueue = TriageQueue::query()
                        ->whereKey($lockedAlert->queue_id)
                        ->lockForUpdate()
                        ->first();
                    if (! $currentQueue?->is_active) {
                        throw new InvalidArgumentException('The current queue is inactive; reactivate or reconcile it before moving this alert.');
                    }
                }
                $targetQueue = TriageQueue::query()
                    ->whereKey($validated['target_queue_id'])
                    ->lockForUpdate()
                    ->first();
                if (! $targetQueue?->is_active) {
                    throw new InvalidArgumentException('The destination queue is inactive and cannot receive alerts.');
                }
                $movedAt = now();

                AlertQueue::query()
                    ->where('alert_id', $lockedAlert->id)
                    ->whereNull('exited_at')
                    ->update([
                        'exited_at' => $movedAt,
                        'exit_reason' => 'moved',
                    ]);

                AlertQueue::query()->create([
                    'alert_id' => $lockedAlert->id,
                    'queue_id' => $targetQueue->id,
                    'entered_at' => $movedAt,
                ]);

                $lockedAlert->update([
                    'queue_id' => $targetQueue->id,
                ]);

                return $targetQueue;
            }, self::TRANSACTION_ATTEMPTS);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['alert' => $e->getMessage()]);
        }

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

        $alertIds = collect($validated['alert_ids'])
            ->map(fn ($alertId) => (int) $alertId)
            ->unique()
            ->values();
        [$escalatedCount, $skippedCount] = DB::transaction(function () use ($alertIds, $user, $validated): array {
            // Lock alerts first, in a stable order, before touching queue history.
            // This makes the site and actionable checks authoritative at write time.
            $alertsQuery = ControlRoomAlert::query()
                ->whereIn('id', $alertIds)
                ->orderBy('id');
            $this->siteAccess()->applyAlertScope(
                $alertsQuery,
                $user,
                $this->alertBypassPermissions(),
            );
            $alerts = $alertsQuery->lockForUpdate()->get()->keyBy('id');

            abort_if(
                $alerts->count() !== $alertIds->count(),
                403,
                'You are not authorized to escalate one or more selected alerts.',
            );

            $escalatedCount = 0;
            $skippedCount = 0;

            foreach ($alertIds as $alertId) {
                $alert = $alerts->get($alertId);
                if (! $alert->isActionable() || ! $alert->queue_id) {
                    $skippedCount++;

                    continue;
                }

                $currentQueue = TriageQueue::query()
                    ->whereKey($alert->queue_id)
                    ->lockForUpdate()
                    ->first();
                if (! $currentQueue?->is_active || ! $currentQueue->escalate_to_queue_id) {
                    $skippedCount++;

                    continue;
                }

                $nextQueue = TriageQueue::query()
                    ->whereKey($currentQueue->escalate_to_queue_id)
                    ->lockForUpdate()
                    ->first();
                if (! $nextQueue?->is_active) {
                    $skippedCount++;

                    continue;
                }

                $escalatedAt = now();
                AlertQueue::query()
                    ->where('alert_id', $alert->id)
                    ->whereNull('exited_at')
                    ->update([
                        'exited_at' => $escalatedAt,
                        'exit_reason' => 'escalated',
                    ]);

                AlertQueue::query()->create([
                    'alert_id' => $alert->id,
                    'queue_id' => $nextQueue->id,
                    'entered_at' => $escalatedAt,
                ]);

                $newLevel = min(
                    ((int) ($alert->escalation_level ?? 0)) + 1,
                    ControlRoomAlert::MAX_ESCALATION_LEVEL,
                );
                $alert->update([
                    'queue_id' => $nextQueue->id,
                    'escalation_level' => $newLevel,
                    'escalated_at' => $escalatedAt,
                    'escalated_by_user_id' => $user->id,
                    'context' => array_merge($alert->context ?? [], [
                        'escalation_history' => array_merge($alert->context['escalation_history'] ?? [], [
                            [
                                'level' => $newLevel,
                                'reason' => $validated['reason'],
                                'escalated_by' => $user->id,
                                'escalated_at' => $escalatedAt->toISOString(),
                                'bulk' => true,
                            ],
                        ]),
                    ]),
                ]);

                $escalatedCount++;
            }

            return [$escalatedCount, $skippedCount];
        }, self::TRANSACTION_ATTEMPTS);

        $message = "{$escalatedCount} alert(s) escalated.";
        if ($skippedCount > 0) {
            $message .= " {$skippedCount} skipped (terminal, inactive, or no next queue).";
        }

        return redirect()->back()->with('success', $message);
    }

    protected function lockAlert(ControlRoomAlert $alert): ControlRoomAlert
    {
        return ControlRoomAlert::query()
            ->lockForUpdate()
            ->findOrFail($alert->getKey());
    }

    protected function assertActionable(ControlRoomAlert $alert, string $action): void
    {
        if (! $alert->isActionable()) {
            throw new InvalidArgumentException(
                "Cannot {$action} an alert with terminal status '{$alert->status}'.",
            );
        }
    }

    protected function siteAccess(): UserSiteAccessService
    {
        return app(UserSiteAccessService::class);
    }

    /**
     * @return array<int, string>
     */
    protected function alertBypassPermissions(): array
    {
        return ['reports.viewAny'];
    }
}
