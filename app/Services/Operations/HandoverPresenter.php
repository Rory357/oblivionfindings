<?php

namespace App\Services\Operations;

use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\User;
use App\Services\ShiftHandoverService;

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
    ) {
    }

    /**
     * Shape a single handover for index-style surfaces — full record plus the
     * per-user action/edit-lock flags the UI gates affordances on.
     *
     * @return array<string, mixed>
     */
    public function mapHandover(ShiftHandover $handover, User $auth): array
    {
        $currentIncomingStaff = $handover->incomingShift?->staff;
        $incomingUserId = $currentIncomingStaff?->id
            ?? ($handover->incoming_shift_id ? null : $handover->incoming_staff_id);

        $client = $handover->client;
        $site = $client?->site;
        $edit = $this->handoverService->editPermission($handover, $auth);

        return [
            'id' => $handover->id,
            'status' => $handover->status,
            'handover_notes' => $handover->handover_notes,
            'client_mood' => $handover->client_mood,
            'medications_due' => $this->listToDisplayStrings($handover->medications_due),
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
        $organizationId = $auth->organization_id;

        $clients = Client::query()
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'service_context_id', 'site_id'])
            ->map(fn (Client $c) => [
                'id' => $c->id,
                'first_name' => $c->first_name,
                'last_name' => $c->last_name,
                'service_context_id' => $c->service_context_id,
                'site_id' => $c->site_id,
            ])->values();

        $staff = User::staff()
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
            ])->values();

        // Sites carry tenant_id (not organization_id), so they are left unscoped
        // here — matching the rostering filter dropdowns.
        $sites = Site::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Site $s) => ['id' => $s->id, 'name' => $s->name])->values();

        $serviceContexts = ServiceContext::query()->orderBy('name')->get(['id', 'name', 'type'])
            ->map(fn (ServiceContext $s) => ['id' => $s->id, 'name' => $s->name, 'type' => $s->type])->values();

        // Shifts feed the wizard's outgoing/incoming selects + auto-next chain.
        // The wizard is client-centric, so scope to this org's clients over a
        // recent + upcoming window.
        $clientIds = $clients->pluck('id');
        $shifts = Shift::query()
            ->whereIn('client_id', $clientIds)
            ->whereNotNull('starts_at')
            ->whereNotIn('status', ['cancelled'])
            ->whereBetween('starts_at', [now()->subDays(30), now()->addDays(21)])
            ->with('staff:id,name,role')
            ->orderBy('starts_at')
            ->limit(800)
            ->get(['id', 'client_id', 'site_id', 'user_id', 'service_context_id', 'shift_type', 'starts_at', 'ends_at', 'status'])
            ->map(fn (Shift $s) => [
                'id' => $s->id,
                'client_id' => $s->client_id,
                'site_id' => $s->site_id,
                'user_id' => $s->user_id,
                'service_context_id' => $s->service_context_id,
                'shift_type' => $s->shift_type,
                'label' => $this->shiftLabel($s),
                'starts_at' => optional($s->starts_at)->toISOString(),
                'ends_at' => optional($s->ends_at)->toISOString(),
                'staff' => $s->staff ? ['id' => $s->staff->id, 'name' => $s->staff->name] : null,
            ])->values();

        return [
            'clients' => $clients,
            'staff' => $staff,
            'sites' => $sites,
            'serviceContexts' => $serviceContexts,
            'shifts' => $shifts,
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
