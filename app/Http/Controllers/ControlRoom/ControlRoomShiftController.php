<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoom\OperatorNote;
use App\Models\ControlRoom\Shift;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ControlRoom\ControlRoomShiftHandoverService;
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
                'handover_status' => $activeShift->handover_status,
                'handover_version' => $activeShift->handover_version,
                'handover_prepared_at' => $activeShift->handover_prepared_at?->toISOString(),
                'is_stale' => $activeShift->isHandoverStale(),
                'stale_after_hours' => $activeShift->handoverStaleAfterHours(),
                'can_override' => $activeShift->handover_status === Shift::HANDOVER_NONE
                    && $activeShift->isHandoverStale()
                    && (int) $activeShift->shift_lead_user_id !== $user->id
                    && $user->canDo('controlRoom.handovers.override'),
                'incoming_lead' => $activeShift->handedOverTo ? [
                    'id' => $activeShift->handedOverTo->id,
                    'name' => $activeShift->handedOverTo->name,
                ] : null,
            ];

            $notes = OperatorNote::where('shift_id', $activeShift->id)
                ->with(['user:id,name', 'alert:id,reference_number'])
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
                    'alert_reference' => $note->alert?->reference_number,
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
            ->with(['roles.permissions:id,key', 'permissionOverrides:id,key'])
            ->orderBy('name')
            ->select('id', 'name')
            ->limit(200)
            ->get();
        $eligibleLeads = $staff
            ->filter(fn (User $candidate) => $candidate->canDo('controlRoom.alerts.manage'))
            ->map(fn (User $candidate) => [
                'id' => $candidate->id,
                'name' => $candidate->name,
            ])
            ->values()
            ->all();
        $staff = $staff->map(fn (User $u) => [
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
            'eligibleLeads' => $eligibleLeads,
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
        $this->assertCanLeadControlRoomShift($user, (int) $validated['shift_lead_user_id']);

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
     * Autosave a resumable handover draft without changing shift ownership.
     */
    public function saveHandoverDraft(
        Request $request,
        Shift $shift,
        ControlRoomShiftHandoverService $handovers,
    ) {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanPrepareHandover($shift, $user);

        $validated = $request->validate([
            'handover_notes' => ['nullable', 'string', 'max:5000'],
            'incoming_shift_name' => ['nullable', 'string', 'max:255'],
            'incoming_lead_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'incoming_team_members' => ['nullable', 'array'],
            'incoming_team_members.*' => ['integer', 'exists:users,id'],
            'reviewed_alert_ids' => ['nullable', 'array'],
            'reviewed_alert_ids.*' => ['integer', 'exists:control_room_alerts,id'],
            'priority_alert_ids' => ['nullable', 'array'],
            'priority_alert_ids.*' => ['integer', 'exists:control_room_alerts,id'],
            'carry_forward_acknowledged' => ['nullable', 'boolean'],
            'carry_forward_signature' => ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'],
            'override_reason' => ['nullable', 'string', 'min:10', 'max:2000'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $staffIds = collect($validated['incoming_team_members'] ?? [])
            ->map(fn ($id) => (int) $id);
        if (filled($validated['incoming_lead_user_id'] ?? null)) {
            $staffIds->push((int) $validated['incoming_lead_user_id']);
        }
        $this->assertCanUseShiftStaff($user, $staffIds->all());
        if (filled($validated['incoming_lead_user_id'] ?? null)) {
            $this->assertCanLeadControlRoomShift($user, (int) $validated['incoming_lead_user_id']);
        }

        $expectedVersion = (int) $validated['expected_version'];
        $overrideReason = $validated['override_reason'] ?? null;
        unset($validated['expected_version'], $validated['override_reason']);

        $handovers->saveDraft(
            $shift,
            $validated,
            $user,
            $expectedVersion,
            $overrideReason,
        );

        return redirect()->route('control-room.shifts.handover-page', $shift)
            ->with('success', 'Handover draft saved.');
    }

    /**
     * Freeze the outgoing lead's reviewed handover for the incoming lead.
     */
    public function handover(
        Request $request,
        Shift $shift,
        ControlRoomShiftHandoverService $handovers,
    ) {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanPrepareHandover($shift, $user);

        $validated = $request->validate([
            'incoming_lead_user_id' => ['required', 'integer', 'exists:users,id'],
            'reviewed_alert_ids' => ['present', 'array'],
            'reviewed_alert_ids.*' => ['integer', 'exists:control_room_alerts,id'],
            'override_reason' => ['nullable', 'string', 'min:10', 'max:2000'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $this->assertCanUseShiftStaff($user, [(int) $validated['incoming_lead_user_id']]);
        $this->assertCanLeadControlRoomShift($user, (int) $validated['incoming_lead_user_id']);
        $incomingLead = User::findOrFail($validated['incoming_lead_user_id']);

        $handovers->prepare(
            $shift,
            $incomingLead,
            $validated['reviewed_alert_ids'],
            $user,
            (int) $validated['expected_version'],
            $validated['override_reason'] ?? null,
        );

        return redirect()->route('control-room.shifts.handover-page', $shift)
            ->with('success', 'Handover prepared for '.$incomingLead->name.'.');
    }

    /**
     * Accept a prepared handover and atomically switch the active shift.
     */
    public function acceptHandover(
        Request $request,
        Shift $shift,
        ControlRoomShiftHandoverService $handovers,
    ) {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $handovers->accept($shift, $user, (int) $validated['expected_version']);

        return redirect()->route('control-room.shifts.index')
            ->with('success', 'Handover accepted. The incoming shift is now active.');
    }

    /**
     * Acknowledge a handover.
     */
    public function acknowledgeHandover(
        Request $request,
        Shift $shift,
        ControlRoomShiftHandoverService $handovers,
    ) {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $handovers->accept(
            $shift,
            $user,
            (int) ($request->input('expected_version') ?? $shift->fresh()->handover_version),
        );

        return redirect()->route('control-room.shifts.index')
            ->with('success', 'Handover accepted. The incoming shift is now active.');
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
            'purpose' => in_array(
                $validated['type'],
                [OperatorNote::TYPE_ESCALATION, OperatorNote::TYPE_HANDOVER],
                true,
            )
                ? OperatorNote::PURPOSE_ESCALATION_HANDOVER
                : OperatorNote::PURPOSE_GENERAL,
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

    protected function assertCanLeadControlRoomShift(User $user, int $leadUserId): void
    {
        $query = User::staff()->whereKey($leadUserId);
        app(UserSiteAccessService::class)->applyStaffScope($query, $user, $this->alertBypassPermissions());

        $lead = $query->first();
        abort_unless(
            $lead && $lead->canDo('controlRoom.alerts.manage'),
            403,
            'The selected shift lead is not eligible to manage a Control Room handover.',
        );
    }

    protected function assertCanPrepareHandover(Shift $shift, User $user): void
    {
        abort_unless(
            (int) $shift->shift_lead_user_id === $user->id
                || (
                    $shift->isHandoverStale()
                    && $user->canDo('controlRoom.handovers.override')
                ),
            403,
            'Only the outgoing lead or an authorised stale-shift manager can prepare this handover.',
        );
    }

    protected function alertBypassPermissions(): array
    {
        return ['reports.viewAny'];
    }
}
