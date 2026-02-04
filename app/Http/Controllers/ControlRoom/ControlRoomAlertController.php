<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ControlRoomAlertController extends Controller
{
    /**
     * Display the specified alert.
     */
    public function show(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);

        $alert->load([
            'asset:id,name,asset_tag',
            'fleetSignal',
            'assignedTo:id,name,email',
            'acknowledgedBy:id,name',
            'resolvedBy:id,name',
            'closedBy:id,name',
            'escalatedBy:id,name',
            'assignedBy:id,name',
            'createdBy:id,name',
        ]);

        // Fetch audit trail for this alert
        $auditLogs = AuditLog::query()
            ->where('auditable_type', ControlRoomAlert::class)
            ->where('auditable_id', $alert->id)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'user' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
                'meta' => $log->meta,
                'created_at' => $log->created_at->toISOString(),
            ]);

        AuditLogger::log('controlRoom.alert.view', $alert, [
            'alert_id' => $alert->id,
        ]);

        return Inertia::render('control-room/show', [
            'alert' => [
                'id' => $alert->id,
                'source' => $alert->source,
                'alert_type' => $alert->alert_type,
                'severity' => $alert->severity,
                'status' => $alert->status,
                'asset_id' => $alert->asset_id,
                'asset' => $alert->asset ? [
                    'id' => $alert->asset->id,
                    'name' => $alert->asset->name,
                    'asset_tag' => $alert->asset->asset_tag,
                ] : null,
                'fleet_signal_id' => $alert->fleet_signal_id,
                'fleet_signal' => $alert->fleetSignal ? [
                    'id' => $alert->fleetSignal->id,
                    'signal_type' => $alert->fleetSignal->signal_type,
                    'severity_hint' => $alert->fleetSignal->severity_hint,
                    'occurred_at' => optional($alert->fleetSignal->occurred_at)->toISOString(),
                    'payload' => $alert->fleetSignal->payload,
                ] : null,
                'assigned_to_user_id' => $alert->assigned_to_user_id,
                'assigned_to' => $alert->assignedTo ? [
                    'id' => $alert->assignedTo->id,
                    'name' => $alert->assignedTo->name,
                    'email' => $alert->assignedTo->email,
                ] : null,
                'acknowledged_by' => $alert->acknowledgedBy ? [
                    'id' => $alert->acknowledgedBy->id,
                    'name' => $alert->acknowledgedBy->name,
                ] : null,
                'resolved_by' => $alert->resolvedBy ? [
                    'id' => $alert->resolvedBy->id,
                    'name' => $alert->resolvedBy->name,
                ] : null,
                'closed_by' => $alert->closedBy ? [
                    'id' => $alert->closedBy->id,
                    'name' => $alert->closedBy->name,
                ] : null,
                'escalated_by' => $alert->escalatedBy ? [
                    'id' => $alert->escalatedBy->id,
                    'name' => $alert->escalatedBy->name,
                ] : null,
                'assigned_by' => $alert->assignedBy ? [
                    'id' => $alert->assignedBy->id,
                    'name' => $alert->assignedBy->name,
                ] : null,
                'created_by' => $alert->createdBy ? [
                    'id' => $alert->createdBy->id,
                    'name' => $alert->createdBy->name,
                ] : null,
                'triggered_at' => optional($alert->triggered_at)->toISOString(),
                'acknowledged_at' => optional($alert->acknowledged_at)->toISOString(),
                'resolved_at' => optional($alert->resolved_at)->toISOString(),
                'closed_at' => optional($alert->closed_at)->toISOString(),
                'escalated_at' => optional($alert->escalated_at)->toISOString(),
                'assigned_at' => optional($alert->assigned_at)->toISOString(),
                'escalation_level' => $alert->escalation_level,
                'context' => $alert->context,
                'notes' => $alert->notes,
                'created_at' => optional($alert->created_at)->toISOString(),
                'updated_at' => optional($alert->updated_at)->toISOString(),
            ],
            'audit_logs' => $auditLogs,
            'can' => [
                'manage' => $user->canDo('controlRoom.alerts.manage'),
                'assign' => $user->canDo('controlRoom.alerts.assign'),
                'escalate' => $user->canDo('controlRoom.alerts.escalate'),
            ],
            'staff' => User::query()
                ->whereHas('roles', fn($q) => $q->whereIn('name', ['admin', 'provider_manager', 'coordinator']))
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
        ]);
    }

    /**
     * Acknowledge an alert.
     */
    public function acknowledge(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        if ($alert->status === 'closed' || $alert->status === 'resolved') {
            return back()->withErrors(['alert' => 'Cannot acknowledge a closed or resolved alert.']);
        }

        $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $alert->update([
            'status' => 'ack',
            'acknowledged_at' => now(),
            'acknowledged_by_user_id' => $user->id,
            'notes' => $request->input('notes') ?: $alert->notes,
        ]);

        AuditLogger::log('controlRoom.alert.acknowledge', $alert, [
            'alert_id' => $alert->id,
            'acknowledged_by' => $user->id,
        ]);

        return back()->with('success', 'Alert acknowledged.');
    }

    /**
     * Start triaging an alert.
     */
    public function triage(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        if (!in_array($alert->status, ['open', 'ack'])) {
            return back()->withErrors(['alert' => 'Alert must be open or acknowledged to start triage.']);
        }

        $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $alert->update([
            'status' => 'triaging',
            'notes' => $request->input('notes') ?: $alert->notes,
        ]);

        AuditLogger::log('controlRoom.alert.triage', $alert, [
            'alert_id' => $alert->id,
            'triaged_by' => $user->id,
        ]);

        return back()->with('success', 'Alert is now being triaged.');
    }

    /**
     * Resolve an alert.
     */
    public function resolve(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        if ($alert->status === 'closed' || $alert->status === 'resolved') {
            return back()->withErrors(['alert' => 'Alert is already resolved or closed.']);
        }

        $request->validate([
            'resolution_notes' => ['required', 'string', 'max:2000'],
        ]);

        $alert->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolved_by_user_id' => $user->id,
            'notes' => $request->input('resolution_notes'),
        ]);

        AuditLogger::log('controlRoom.alert.resolve', $alert, [
            'alert_id' => $alert->id,
            'resolved_by' => $user->id,
        ]);

        return back()->with('success', 'Alert resolved.');
    }

    /**
     * Close an alert (final state).
     */
    public function close(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        if ($alert->status === 'closed') {
            return back()->withErrors(['alert' => 'Alert is already closed.']);
        }

        $request->validate([
            'closure_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $alert->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by_user_id' => $user->id,
            'notes' => $request->input('closure_notes') ?: $alert->notes,
        ]);

        AuditLogger::log('controlRoom.alert.close', $alert, [
            'alert_id' => $alert->id,
            'closed_by' => $user->id,
        ]);

        return back()->with('success', 'Alert closed.');
    }

    /**
     * Assign an alert to a staff member.
     */
    public function assign(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.assign'), 403);

        $data = $request->validate([
            'assigned_to_user_id' => ['required', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $alert->update([
            'assigned_to_user_id' => $data['assigned_to_user_id'],
            'assigned_at' => now(),
            'assigned_by_user_id' => $user->id,
            'notes' => $data['notes'] ?? $alert->notes,
        ]);

        AuditLogger::log('controlRoom.alert.assign', $alert, [
            'alert_id' => $alert->id,
            'assigned_to' => $data['assigned_to_user_id'],
            'assigned_by' => $user->id,
        ]);

        return back()->with('success', 'Alert assigned.');
    }

    /**
     * Unassign an alert.
     */
    public function unassign(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.assign'), 403);

        $previousAssignee = $alert->assigned_to_user_id;

        $alert->update([
            'assigned_to_user_id' => null,
            'assigned_at' => null,
            'assigned_by_user_id' => null,
        ]);

        AuditLogger::log('controlRoom.alert.unassign', $alert, [
            'alert_id' => $alert->id,
            'previous_assignee' => $previousAssignee,
            'unassigned_by' => $user->id,
        ]);

        return back()->with('success', 'Alert unassigned.');
    }

    /**
     * Escalate an alert.
     */
    public function escalate(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.escalate'), 403);

        if ($alert->status === 'closed' || $alert->status === 'resolved') {
            return back()->withErrors(['alert' => 'Cannot escalate a closed or resolved alert.']);
        }

        $data = $request->validate([
            'escalation_reason' => ['required', 'string', 'max:1000'],
            'escalation_level' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        $newLevel = $data['escalation_level'] ?? (($alert->escalation_level ?? 0) + 1);

        $alert->update([
            'escalation_level' => min($newLevel, 5),
            'escalated_at' => now(),
            'escalated_by_user_id' => $user->id,
            'context' => array_merge($alert->context ?? [], [
                'escalation_history' => array_merge($alert->context['escalation_history'] ?? [], [
                    [
                        'level' => $newLevel,
                        'reason' => $data['escalation_reason'],
                        'escalated_by' => $user->id,
                        'escalated_at' => now()->toISOString(),
                    ],
                ]),
            ]),
        ]);

        AuditLogger::log('controlRoom.alert.escalate', $alert, [
            'alert_id' => $alert->id,
            'escalation_level' => $newLevel,
            'escalated_by' => $user->id,
        ]);

        return back()->with('success', 'Alert escalated to level ' . $newLevel . '.');
    }

    /**
     * Add a note to an alert.
     */
    public function addNote(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $existingNotes = $alert->context['activity_log'] ?? [];
        $existingNotes[] = [
            'type' => 'note',
            'content' => $data['note'],
            'user_id' => $user->id,
            'user_name' => $user->name,
            'created_at' => now()->toISOString(),
        ];

        $alert->update([
            'context' => array_merge($alert->context ?? [], [
                'activity_log' => $existingNotes,
            ]),
        ]);

        AuditLogger::log('controlRoom.alert.addNote', $alert, [
            'alert_id' => $alert->id,
        ]);

        return back()->with('success', 'Note added.');
    }

    /**
     * Store a new alert (API endpoint for external integrations).
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.create'), 403);

        $data = $request->validate([
            'source' => ['required', 'string', 'in:fleet,personal_tracker,manual,external,compliance,other'],
            'alert_type' => ['required', 'string', 'max:100'],
            'severity' => ['required', 'string', 'in:low,medium,high,critical'],
            'asset_id' => ['nullable', 'integer', 'exists:assets,id'],
            'fleet_signal_id' => ['nullable', 'integer', 'exists:fleet_signals,id'],
            'context' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $data['status'] = 'open';
        $data['triggered_at'] = now();
        $data['created_by_user_id'] = $user->id;

        $alert = ControlRoomAlert::create($data);

        AuditLogger::log('controlRoom.alert.create', $alert, [
            'alert_id' => $alert->id,
            'source' => $data['source'],
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Alert created.',
                'alert' => [
                    'id' => $alert->id,
                    'status' => $alert->status,
                ],
            ], 201);
        }

        return redirect()->route('control-room.alerts.show', $alert)
            ->with('success', 'Alert created.');
    }
}
