<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoom\OperatorNote;
use App\Models\ControlRoom\Shift;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ControlRoomShiftController extends Controller
{
    /**
     * Display the active shift dashboard.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);
        $siteAccess = app(UserSiteAccessService::class);
        $bypassPermissions = $this->alertBypassPermissions();

        // Current active shift
        $activeShift = Shift::where('status', 'active')
            ->latest('starts_at')
            ->first();

        $activeShiftData = null;
        $notes = [];

        if ($activeShift) {
            $shiftLead = $activeShift->shiftLead;
            $teamMembers = $activeShift->getTeamMemberUsers();

            $activeShiftData = [
                'id' => $activeShift->id,
                'name' => $activeShift->name,
                'starts_at' => $activeShift->starts_at->toISOString(),
                'ends_at' => $activeShift->ends_at?->toISOString(),
                'status' => $activeShift->status,
                'shift_lead' => $shiftLead ? [
                    'id' => $shiftLead->id,
                    'name' => $shiftLead->name,
                ] : null,
                'team_members' => $teamMembers->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                ])->values()->all(),
                'open_alerts_at_start' => $activeShift->open_alerts_at_start ?? 0,
                'alerts_created' => $activeShift->alerts_created ?? 0,
                'alerts_resolved' => $activeShift->alerts_resolved ?? 0,
                'alerts_escalated' => $activeShift->alerts_escalated ?? 0,
                'handover_notes' => $activeShift->handover_notes,
                'priority_items' => $activeShift->priority_items ?? [],
            ];

            $notes = OperatorNote::where('shift_id', $activeShift->id)
                ->with('user:id,name')
                ->orderByDesc('is_pinned')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (OperatorNote $note) => [
                    'id' => $note->id,
                    'type' => $note->type,
                    'content' => $note->content,
                    'is_pinned' => $note->is_pinned,
                    'requires_followup' => $note->requires_followup,
                    'followup_at' => $note->followup_at?->toISOString(),
                    'alert_id' => $note->alert_id,
                    'user' => $note->user ? [
                        'id' => $note->user->id,
                        'name' => $note->user->name,
                    ] : null,
                    'created_at' => $note->created_at->toISOString(),
                ])
                ->all();
        }

        // Recent completed shifts
        $recentShifts = Shift::whereIn('status', ['completed', 'handover'])
            ->with('shiftLead:id,name')
            ->orderByDesc('starts_at')
            ->limit(10)
            ->get()
            ->map(fn (Shift $shift) => [
                'id' => $shift->id,
                'name' => $shift->name,
                'starts_at' => $shift->starts_at->toISOString(),
                'ends_at' => $shift->ends_at?->toISOString(),
                'status' => $shift->status,
                'shift_lead' => $shift->shiftLead ? [
                    'id' => $shift->shiftLead->id,
                    'name' => $shift->shiftLead->name,
                ] : null,
                'alerts_created' => $shift->alerts_created ?? 0,
                'alerts_resolved' => $shift->alerts_resolved ?? 0,
                'alerts_escalated' => $shift->alerts_escalated ?? 0,
                'duration_minutes' => $shift->getDuration(),
            ])
            ->all();

        // Current alert counts
        $openAlertsBase = ControlRoomAlert::unresolved();
        $siteAccess->applyAlertScope($openAlertsBase, $user, $bypassPermissions);

        $openAlertsCount = (clone $openAlertsBase)->count();
        $criticalAlertsCount = (clone $openAlertsBase)->where('severity', 'critical')->count();

        // Staff list for selects
        $staff = User::staff()
            ->tap(fn ($staffQuery) => $siteAccess->applyStaffScope($staffQuery, $user, $bypassPermissions))
            ->orderBy('name')
            ->select('id', 'name')
            ->limit(200)
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
            ])
            ->all();

        return Inertia::render('control-room/shifts', [
            'activeShift' => $activeShiftData,
            'notes' => $notes,
            'recentShifts' => $recentShifts,
            'openAlertsCount' => $openAlertsCount,
            'criticalAlertsCount' => $criticalAlertsCount,
            'staff' => $staff,
            'can' => [
                'manage' => $user->canDo('controlRoom.alerts.manage'),
            ],
        ]);
    }

    /**
     * Start a new shift.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'shift_lead_user_id' => ['required', 'integer', 'exists:users,id'],
            'team_members' => ['nullable', 'array'],
            'team_members.*' => ['integer', 'exists:users,id'],
        ]);

        $this->assertCanUseShiftStaff($user, [
            (int) $validated['shift_lead_user_id'],
            ...collect($validated['team_members'] ?? [])->map(fn ($id) => (int) $id)->all(),
        ]);

        $shiftLead = User::findOrFail($validated['shift_lead_user_id']);
        $teamMembers = $validated['team_members'] ?? [];

        $shift = Shift::startNew($shiftLead, $validated['name'], $teamMembers);

        AuditLogger::log('controlRoom.shift.started', $shift, [
            'shift_id' => $shift->id,
            'shift_lead_user_id' => $shiftLead->id,
            'team_members' => $teamMembers,
        ]);

        return redirect()->route('control-room.shifts.index')
            ->with('success', 'Shift started successfully.');
    }

    /**
     * Perform shift handover.
     */
    public function handover(Request $request, Shift $shift)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        // Narrative is optional — the wizard presents it that way and the confirm
        // step renders "No handover notes provided" for an empty one.
        $validated = $request->validate([
            'handover_notes' => ['nullable', 'string', 'max:5000'],
            'priority_items' => ['nullable', 'array'],
            'priority_items.*' => ['string', 'max:500'],
            'incoming_shift_name' => ['nullable', 'string', 'max:255'],
            'incoming_lead_user_id' => ['required', 'integer', 'exists:users,id'],
            'incoming_team_members' => ['nullable', 'array'],
            'incoming_team_members.*' => ['integer', 'exists:users,id'],
        ]);

        $this->assertCanUseShiftStaff($user, [
            (int) $validated['incoming_lead_user_id'],
            ...collect($validated['incoming_team_members'] ?? [])->map(fn ($id) => (int) $id)->all(),
        ]);

        $incomingLead = User::findOrFail($validated['incoming_lead_user_id']);
        $handoverNotes = trim((string) ($validated['handover_notes'] ?? ''));

        // Complete the current shift via handover
        $shift->handover(
            $incomingLead,
            $handoverNotes,
            $validated['priority_items'] ?? [],
        );

        // Create a handover operator note on the outgoing shift (only when
        // there is a narrative to record).
        if ($handoverNotes !== '') {
            OperatorNote::create([
                'shift_id' => $shift->id,
                'user_id' => $user->id,
                'type' => 'handover',
                'content' => $handoverNotes,
                'is_pinned' => false,
                'requires_followup' => false,
            ]);
        }

        // Start the new shift for the incoming team. Keep the name the operator
        // typed on the handover wizard; fall back to a timestamped default.
        $incomingShiftName = trim($validated['incoming_shift_name'] ?? '');
        $newShift = Shift::startNew(
            $incomingLead,
            $incomingShiftName !== '' ? $incomingShiftName : 'Shift '.now()->format('Y-m-d H:i'),
            $validated['incoming_team_members'] ?? [],
        );

        AuditLogger::log('controlRoom.shift.handover', $shift, [
            'outgoing_shift_id' => $shift->id,
            'incoming_shift_id' => $newShift->id,
            'incoming_lead_user_id' => $incomingLead->id,
            'priority_items' => $validated['priority_items'] ?? [],
        ]);

        return redirect()->route('control-room.shifts.index')
            ->with('success', 'Handover completed successfully.');
    }

    /**
     * Acknowledge a handover.
     */
    public function acknowledgeHandover(Request $request, Shift $shift)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $shift->update([
            'status' => 'active',
        ]);

        AuditLogger::log('controlRoom.shift.handoverAcknowledged', $shift, [
            'shift_id' => $shift->id,
            'acknowledged_by' => $user->id,
        ]);

        return redirect()->route('control-room.shifts.index')
            ->with('success', 'Handover acknowledged.');
    }

    /**
     * Add an operator note to a shift.
     */
    public function addNote(Request $request, Shift $shift)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:note,action,escalation,decision'],
            'content' => ['required', 'string', 'max:2000'],
            'alert_id' => ['nullable', 'integer'],
            'is_pinned' => ['nullable', 'boolean'],
            'requires_followup' => ['nullable', 'boolean'],
            'followup_at' => ['nullable', 'date'],
        ]);

        $note = OperatorNote::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'type' => $validated['type'],
            'content' => $validated['content'],
            'alert_id' => $validated['alert_id'] ?? null,
            'is_pinned' => $validated['is_pinned'] ?? false,
            'requires_followup' => $validated['requires_followup'] ?? false,
            'followup_at' => $validated['followup_at'] ?? null,
        ]);

        AuditLogger::log('controlRoom.shift.noteAdded', $shift, [
            'shift_id' => $shift->id,
            'note_id' => $note->id,
            'note_type' => $validated['type'],
        ]);

        return redirect()->route('control-room.shifts.index')
            ->with('success', 'Note added.');
    }

    protected function assertCanUseShiftStaff(User $user, array $userIds): void
    {
        $uniqueUserIds = collect($userIds)
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($uniqueUserIds === []) {
            return;
        }

        $query = User::staff()
            ->whereIn('id', $uniqueUserIds);

        app(UserSiteAccessService::class)->applyStaffScope($query, $user, $this->alertBypassPermissions());

        abort_if(
            $query->count() !== count($uniqueUserIds),
            403,
            'You are not authorized to select one or more staff members for this shift.',
        );
    }

    protected function alertBypassPermissions(): array
    {
        return ['reports.viewAny'];
    }
}
