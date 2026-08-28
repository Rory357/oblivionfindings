<?php

namespace App\Services\Operations;

use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\ShiftHandoverService;
use App\Services\UserSiteAccessService;

/**
 * Shared presentation for shift handovers, used by the Shift Handovers
 * workspace and the Attendance page's Handovers tab (which reuses the same
 * HandoverWizard + row shape). Extracted from Operations\HandoverController so
 * both surfaces serialise one source of truth.
 */
class HandoverPresenter
{
    public function __construct(
        protected ShiftHandoverService $handoverService,
        protected UserSiteAccessService $siteAccess,
        protected MedicationGovernanceScopeService $medicationGovernance,
    ) {}

    /**
     * Shape a single handover for index-style surfaces — full record plus the
     * per-user action/edit-lock flags the UI gates affordances on.
     *
     * @return array<string, mixed>
     */
    public function mapHandover(
        ShiftHandover $handover,
        User $auth,
        bool $includeControlledMedication,
    ): array {
        $currentIncomingStaff = $handover->incomingShift?->staff;
        $incomingUserId = $currentIncomingStaff?->id
            ?? ($handover->incoming_shift_id ? null : $handover->incoming_staff_id);
        $submittedIncomingStaffId = in_array($handover->status, [
            ShiftHandoverService::STATUS_SUBMITTED,
            ShiftHandoverService::STATUS_ACKNOWLEDGED,
        ], true) && (int) $handover->incoming_staff_id > 0
            ? (int) $handover->incoming_staff_id
            : null;

        $client = $handover->client;
        $site = $client?->site;
        $edit = $this->handoverService->editPermission($handover, $auth);
        $lockHolder = $this->handoverService->activeLockHolder($handover, $auth->id);

        return [
            'id' => $handover->id,
            'status' => $handover->status,
            'handover_notes' => $handover->handover_notes,
            'client_mood' => $handover->client_mood,
            'medications_due' => $includeControlledMedication
                ? $this->listToDisplayStrings($handover->medications_due)
                : [],
            'cd_verification' => $includeControlledMedication ? $handover->cd_verification : null,
            'cd_required' => $includeControlledMedication && (bool) $handover->cd_required,
            'version' => (int) $handover->version,
            'edit_lock' => $lockHolder ? [
                'held_by_name' => $lockHolder->name,
                'held_at' => optional($handover->locked_at)->toISOString(),
            ] : null,
            'incidents_to_note' => $this->listToDisplayStrings($handover->incidents_to_note),
            'follow_up_items' => $this->listToDisplayStrings($handover->follow_up_items),
            'tasks_pending' => $this->listToDisplayStrings($handover->tasks_pending),
            'created_at' => optional($handover->created_at)->toISOString(),
            'submitted_at' => optional($handover->submitted_at)->toISOString(),
            'acknowledged_at' => optional($handover->acknowledged_at)->toISOString(),
            'client' => $client ? [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'site_id' => $client->site_id,
            ] : null,
            'site' => $site ? ['id' => $site->id, 'name' => $site->name] : null,
            'outgoing_staff' => $handover->outgoingStaff ? [
                'id' => $handover->outgoingStaff->id,
                'name' => $handover->outgoingStaff->name,
                'role' => $handover->outgoingStaff->role,
            ] : null,
            'incoming_staff' => ($currentIncomingStaff || $incomingUserId) ? [
                'id' => $currentIncomingStaff?->id ?? $handover->incomingStaff?->id ?? $incomingUserId,
                'name' => $currentIncomingStaff?->name ?? $handover->incomingStaff?->name ?? 'Pending assignment',
                'role' => $currentIncomingStaff?->role ?? $handover->incomingStaff?->role,
            ] : null,
            // Immutable recipient snapshot bound when the handover was
            // submitted. Reassignment never rewrites this evidence.
            'submitted_incoming_staff' => $submittedIncomingStaffId ? [
                'id' => $submittedIncomingStaffId,
                'name' => $handover->incomingStaff?->name ?? "Staff record #{$submittedIncomingStaffId}",
                'role' => $handover->incomingStaff?->role,
            ] : null,
            // Live assignee who currently holds acknowledgement authority for
            // the bound incoming Shift.
            'current_incoming_staff' => $currentIncomingStaff ? [
                'id' => $currentIncomingStaff->id,
                'name' => $currentIncomingStaff->name,
                'role' => $currentIncomingStaff->role,
            ] : null,
            'acknowledger' => $handover->acknowledger ? [
                'id' => $handover->acknowledger->id,
                'name' => $handover->acknowledger->name,
            ] : null,
            'outgoing_shift' => $this->shiftPayload($handover->outgoingShift),
            'incoming_shift' => $this->shiftPayload($handover->incomingShift),
            'can_submit' => $this->handoverService->canSubmit($handover, $auth),
            'can_acknowledge' => $this->handoverService->canAcknowledge($handover, $auth),
            'can_edit' => $edit['editable'],
            'lock' => [
                'locked' => $edit['locked'],
                'reason' => $edit['reason'],
                'days_left' => $edit['days_left'],
                'age_days' => $edit['age_days'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mapHandoverDetail(
        ShiftHandover $handover,
        User $auth,
        bool $includeControlledMedication,
    ): array {
        return [
            ...$this->mapHandover($handover, $auth, $includeControlledMedication),
            'observations_summary' => $handover->observations_summary,
            'submitter' => $handover->submitter ? [
                'id' => $handover->submitter->id,
                'name' => $handover->submitter->name,
            ] : null,
        ];
    }

    /**
     * Eager loads required before a handover goes through mapHandover().
     *
     * @return array<int, string>
     */
    public function mapEagerLoads(): array
    {
        return [
            'outgoingShift:id,starts_at,ends_at,client_id,user_id,shift_type,status',
            'incomingShift:id,starts_at,ends_at,client_id,user_id,shift_type,status',
            'incomingShift.staff:id,name,role',
            'client:id,first_name,last_name,site_id',
            'client.site:id,name',
            'outgoingStaff:id,name,role',
            'incomingStaff:id,name,role',
            'acknowledger:id,name',
            'submitter:id,name',
            'lockedBy:id,name',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function shiftPayload(?Shift $shift): ?array
    {
        if (! $shift) {
            return null;
        }

        return [
            'id' => $shift->id,
            'starts_at' => optional($shift->starts_at)->toISOString(),
            'ends_at' => optional($shift->ends_at)->toISOString(),
            'shift_type' => $shift->shift_type,
            'label' => $this->shiftLabel($shift),
        ];
    }

    public function shiftLabel(Shift $shift): string
    {
        if ($shift->shift_type) {
            return ucwords(str_replace('_', ' ', (string) $shift->shift_type));
        }

        return optional($shift->starts_at)->format('H:i') ?? 'Shift';
    }

    /**
     * Catalogue data for the handover wizard selects (clients, staff, sites,
     * service contexts, recent + upcoming shifts).
     *
     * @return array<string, mixed>
     */
    public function catalogue(User $auth): array
    {
        $siteIds = $this->siteAccess->accessibleSiteIds(
            $auth,
            MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
        );

        $clients = Client::query()
            ->whereIn('site_id', $siteIds)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'service_context_id', 'site_id'])
            ->map(fn (Client $c) => [
                'id' => $c->id,
                'first_name' => $c->first_name,
                'last_name' => $c->last_name,
                'service_context_id' => $c->service_context_id,
                'site_id' => $c->site_id,
            ])->values();

        $staffBySite = collect($siteIds)->mapWithKeys(
            fn (int $siteId): array => [
                (string) $siteId => $this->medicationGovernance
                    ->staffPicker([$siteId])
                    ->values()
                    ->all(),
            ],
        )->all();
        $staffIds = collect($staffBySite)->flatten(1)->pluck('id')->unique()->values();
        $staff = User::query()
            ->whereIn('id', $staffIds)
            ->with('hrEmployeeProfile:id,user_id,primary_site_id,secondary_site_ids')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role'])
            ->map(function (User $user) use ($siteIds): array {
                $profile = $user->hrEmployeeProfile;

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'site_ids' => collect([
                        $profile?->primary_site_id,
                        ...($profile?->secondary_site_ids ?? []),
                    ])->map(fn ($siteId) => (int) $siteId)
                        ->intersect($siteIds)
                        ->unique()
                        ->values()
                        ->all(),
                ];
            })->values();

        $sites = Site::query()->whereIn('id', $siteIds)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Site $s) => ['id' => $s->id, 'name' => $s->name])->values();

        $serviceContexts = ServiceContext::query()
            ->availableToSites($siteIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'site_id'])
            ->map(fn (ServiceContext $s) => ['id' => $s->id, 'name' => $s->name, 'type' => $s->type])->values();

        // Shifts feed the wizard's outgoing/incoming selects + auto-next chain.
        // The wizard is client-centric, so scope to accessible Site clients over a
        // recent + upcoming window.
        $clientIds = $clients->pluck('id');
        $shifts = Shift::query()
            ->tap(fn ($query) => $this->siteAccess->applyShiftScope(
                $query,
                $auth,
                MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
            ))
            ->whereIn('client_id', $clientIds)
            ->whereNotNull('site_id')
            ->whereNotNull('starts_at')
            ->whereNotIn('status', ['cancelled'])
            ->whereBetween('starts_at', [now()->subDays(30), now()->addDays(21)])
            ->with('staff:id,name,role')
            ->orderBy('starts_at')
            ->limit(800)
            ->get(['id', 'client_id', 'site_id', 'user_id', 'service_context_id', 'shift_type', 'starts_at', 'ends_at', 'actual_ends_at', 'status'])
            ->map(fn (Shift $s) => [
                'id' => $s->id,
                'client_id' => $s->client_id,
                'site_id' => $s->site_id,
                'user_id' => $s->user_id,
                'service_context_id' => $s->service_context_id,
                'shift_type' => $s->shift_type,
                'status' => $s->status,
                'label' => $this->shiftLabel($s),
                'starts_at' => optional($s->starts_at)->toISOString(),
                'ends_at' => optional($s->ends_at)->toISOString(),
                'actual_ends_at' => optional($s->actual_ends_at)->toISOString(),
                'staff' => $s->staff ? ['id' => $s->staff->id, 'name' => $s->staff->name] : null,
            ])->values();

        $canViewControlled = $auth->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY);
        $canRecordControlled = $auth->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY);
        $controlledWitnessesBySite = $canViewControlled && $canRecordControlled
            ? collect($siteIds)->mapWithKeys(
                fn (int $siteId): array => [
                    (string) $siteId => $this->medicationGovernance
                        ->controlledWitnessPicker([$siteId], (int) $auth->id)
                        ->values()
                        ->all(),
                ],
            )->all()
            : [];

        return [
            'clients' => $clients,
            'staff' => $staff,
            'staffBySite' => $staffBySite,
            'sites' => $sites,
            'serviceContexts' => $serviceContexts,
            'shifts' => $shifts,
            'controlledWitnessesBySite' => $controlledWitnessesBySite,
            'capabilities' => [
                'view_controlled' => $canViewControlled,
                'record_controlled' => $canRecordControlled,
                'manage_any_shifts' => $auth->canDo('shifts.manageAny'),
            ],
        ];
    }

    /**
     * Normalise a stored structured list (strings or {label,…} objects) to plain
     * display strings. Mirrors the resilient reader in the Show page.
     *
     * @return array<int, string>
     */
    public function listToDisplayStrings(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->map(fn ($item) => $this->displayListItem($item))
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '')
            ->values()
            ->all();
    }

    protected function displayListItem(mixed $item): string
    {
        if (is_string($item)) {
            return $item;
        }

        if (is_array($item)) {
            foreach (['label', 'description', 'name', 'title', 'note', 'value'] as $key) {
                if (isset($item[$key]) && is_string($item[$key]) && trim($item[$key]) !== '') {
                    return $item[$key];
                }
            }

            return collect($item)
                ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                ->implode(' ');
        }

        return (string) ($item ?? '');
    }
}
