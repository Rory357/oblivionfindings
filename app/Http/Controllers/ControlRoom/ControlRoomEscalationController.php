<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ControlRoom\Concerns\AuthorizesControlRoomAlertAccess;
use App\Models\ControlRoom\AlertQueue;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Services\AuditLogger;
use App\Services\ControlRoom\AlertPriorityService;
use App\Services\ControlRoom\AlertWorklistPresenter;
use App\Services\ControlRoom\AlertWorkspaceService;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use InvalidArgumentException;

class ControlRoomEscalationController extends Controller
{
    use AuthorizesControlRoomAlertAccess;

    private const TRANSACTION_ATTEMPTS = 3;

    private const QUEUE_CAPACITY = 20;

    /** Display the bounded escalation priority worklist and queue pressure summary. */
    public function index(
        Request $request,
        AlertPriorityService $priority,
        AlertWorklistPresenter $presenter,
    ) {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);
        $siteAccess = app(UserSiteAccessService::class);
        $bypassPermissions = $this->alertBypassPermissions();
        $canManage = $user->canDo('controlRoom.alerts.manage');

        $activeQueues = TriageQueue::active()
            ->orderBy('tier')
            ->orderBy('name')
            ->get();

        $queues = $activeQueues
            ->map(function (TriageQueue $queue) use ($siteAccess, $user, $bypassPermissions) {
                $queueAlerts = ControlRoomAlert::query()->unresolved()->where('queue_id', $queue->id);
                $siteAccess->applyAlertScope($queueAlerts, $user, $bypassPermissions);
                $totalCount = (clone $queueAlerts)->count();
                $breachedCount = (clone $queueAlerts)
                    ->whereHas('sla', fn ($sla) => $sla->breached())
                    ->count();
                $utilization = (int) round(($totalCount / self::QUEUE_CAPACITY) * 100);

                return [
                    'id' => $queue->id,
                    'name' => $queue->name,
                    'code' => $queue->code,
                    'tier' => $queue->tier,
                    'description' => $queue->description ?? null,
                    'auto_escalate_after_minutes' => $queue->auto_escalate_after_minutes,
                    'escalate_to_queue_id' => $queue->escalate_to_queue_id,
                    'alert_count' => $totalCount,
                    'breached_count' => $breachedCount,
                    'capacity' => self::QUEUE_CAPACITY,
                    'utilization_percent' => $utilization,
                    'pressure_label' => $this->pressureLabel($utilization),
                    'capacity_explanation' => '20-alert operational display threshold; alerts remain paginated and no work is hidden.',
                ];
            })
            ->all();

        $allQueues = $activeQueues
            ->map(fn (TriageQueue $q) => [
                'id' => $q->id,
                'name' => $q->name,
                'tier' => $q->tier,
            ])
            ->all();

        $filters = [
            'queue_id' => $request->filled('queue_id') ? (string) $request->input('queue_id') : null,
            'tier' => $request->filled('tier') ? (string) $request->input('tier') : null,
            'severity' => $request->filled('severity') ? (string) $request->input('severity') : null,
            'search' => $request->filled('search') ? trim((string) $request->input('search')) : null,
        ];
        $activeQueueIds = $activeQueues->pluck('id');

        $summaryQuery = ControlRoomAlert::query()
            ->unresolved()
            ->whereIn('queue_id', $activeQueueIds);
        $siteAccess->applyAlertScope($summaryQuery, $user, $bypassPermissions);
        $summary = [
            'active_queues' => $activeQueues->count(),
            'total_alerts' => (clone $summaryQuery)->count(),
            'breaches' => (clone $summaryQuery)->whereHas('sla', fn ($sla) => $sla->breached())->count(),
            'urgent' => (clone $summaryQuery)->whereIn('severity', ['critical', 'high'])->count(),
            'unassigned' => (clone $summaryQuery)->whereNull('assigned_to_user_id')->count(),
        ];

        $currentQueueEntries = AlertQueue::query()
            ->select('alert_id', DB::raw('MAX(entered_at) as entered_queue_at'))
            ->whereNull('exited_at')
            ->groupBy('alert_id');
        $worklistQuery = ControlRoomAlert::query()
            ->unresolved()
            ->whereIn('control_room_alerts.queue_id', $activeQueueIds)
            ->join('control_room_triage_queues as worklist_queue', 'worklist_queue.id', '=', 'control_room_alerts.queue_id')
            ->leftJoinSub($currentQueueEntries, 'current_queue_entry', fn ($join) => $join->on('current_queue_entry.alert_id', '=', 'control_room_alerts.id'))
            ->with([
                'assignedTo:id,name',
                'sla',
                'site:id,name',
                'client:id,first_name,last_name,site_id,organization_id',
                'client.site:id,name,tenant_id',
                'queue:id,name,tier',
                'playbookRun.playbook:id,name',
                'clientIncident:id,reference_number,control_room_alert_id,status',
                'hsEvent:id,reference_number,control_room_alert_id,handover_status,status',
            ]);
        $siteAccess->applyAlertScope($worklistQuery, $user, $bypassPermissions);

        $worklistQuery
            ->when($filters['queue_id'], fn ($query, $queueId) => $query->where('control_room_alerts.queue_id', (int) $queueId))
            ->when($filters['tier'], fn ($query, $tier) => $query->where('worklist_queue.tier', (int) $tier))
            ->when($filters['severity'], fn ($query, $severity) => $query->where('control_room_alerts.severity', $severity))
            ->when($filters['search'], function ($query, $search) {
                $query->where(function ($searchQuery) use ($search) {
                    $searchQuery
                        ->where('control_room_alerts.reference_number', 'like', "%{$search}%")
                        ->orWhere('control_room_alerts.alert_type', 'like', "%{$search}%")
                        ->orWhere('control_room_alerts.notes', 'like', "%{$search}%");
                });
            });

        $worklist = $priority
            ->applyEscalation($worklistQuery)
            ->select('control_room_alerts.*')
            ->addSelect('current_queue_entry.entered_queue_at')
            ->paginate(30)
            ->withQueryString();
        $worklist->through(function (ControlRoomAlert $alert) use ($presenter, $user, $canManage) {
            $row = $presenter->present($alert, $user, $canManage);
            $enteredAt = $alert->getAttribute('entered_queue_at') ?? $alert->triggered_at;

            return array_merge($row, [
                'escalation_level' => (int) $alert->escalation_level,
                'entered_queue_at' => $enteredAt ? Carbon::parse($enteredAt)->toIso8601String() : null,
            ]);
        });

        return Inertia::render('control-room/escalations', [
            'queues' => $queues,
            'allQueues' => $allQueues,
            'worklist' => $worklist,
            'summary' => $summary,
            'filters' => $filters,
            'serverTime' => now()->toISOString(),
            'can' => [
                'manage' => $canManage,
                'assign' => $user->canDo('controlRoom.alerts.assign'),
            ],
            'detail' => fn () => $request->filled('alert')
                ? app(AlertWorkspaceService::class)->build($user, (int) $request->input('alert'))
                : null,
        ]);
    }

    private function pressureLabel(int $utilization): string
    {
        return match (true) {
            $utilization === 0 => 'Empty',
            $utilization < 80 => 'Available',
            $utilization <= 100 => 'Near capacity',
            $utilization <= 200 => 'Over capacity',
            default => 'Severe overload',
        };
    }

    /**
     * Acknowledge an alert from the escalation queue page.
     */
    public function acknowledgeFromQueue(
        Request $request,
        ControlRoomAlert $alert,
        ControlRoomAlertLifecycleService $lifecycle,
    ) {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

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
                $lockedAlert = $this->nestedAlertResources()->alert($user, $alert, true);
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
                $lockedAlert = $this->nestedAlertResources()->alert($user, $alert, true);
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
            $alerts = $this->nestedAlertResources()->alerts($user, $alertIds, true);

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
