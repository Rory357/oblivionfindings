<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\User;
use App\Services\ShiftHandoverService;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class HandoverController extends Controller
{
    public function __construct(
        protected ShiftHandoverService $handoverService,
    ) {
    }

    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->handoverService->canAccessWorkflow($auth), 403);

        $filters = $request->validate([
            'week' => ['nullable', 'date'],
        ]);

        // Week is the unit of navigation (Mon–Sun). Compute the window in the
        // worker timezone, then query the UTC-stored created_at column with the
        // UTC-converted bounds (see reference_eloquent_timezone_storage).
        $tz = config('app.worker_timezone') ?: config('app.timezone', 'UTC');
        $weekStart = ! empty($filters['week'])
            ? Carbon::parse($filters['week'], $tz)->startOfWeek(Carbon::MONDAY)
            : Carbon::now($tz)->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);

        $canViewAny = $this->handoverService->canViewAny($auth);

        $handovers = ShiftHandover::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->tap(fn ($query) => $this->siteAccess()->applyHandoverScope($query, $auth, $this->handoverBypassPermissions()))
            ->with([
                'outgoingShift:id,starts_at,ends_at,client_id,user_id,shift_type,status',
                'incomingShift:id,starts_at,ends_at,client_id,user_id,shift_type,status',
                'incomingShift.staff:id,name,role',
                'client:id,first_name,last_name,site_id',
                'client.site:id,name',
                'outgoingStaff:id,name,role',
                'incomingStaff:id,name,role',
                'acknowledger:id,name',
                'submitter:id,name',
            ])
            ->whereBetween('created_at', [$weekStart->copy()->utc(), $weekEnd->copy()->utc()])
            ->when(! $canViewAny, function ($query) use ($auth) {
                $query->where(function ($nested) use ($auth) {
                    $nested->where('outgoing_staff_id', $auth->id)
                        ->orWhere(function ($incomingStaffQuery) use ($auth) {
                            $incomingStaffQuery->whereNull('incoming_shift_id')
                                ->where('incoming_staff_id', $auth->id);
                        })
                        ->orWhereHas('outgoingShift', fn ($shiftQuery) => $shiftQuery->where('user_id', $auth->id))
                        ->orWhereHas('incomingShift', fn ($shiftQuery) => $shiftQuery->where('user_id', $auth->id));
                });
            })
            ->orderByDesc('created_at')
            ->limit(300)
            ->get()
            ->map(fn (ShiftHandover $handover) => $this->mapHandover($handover, $auth))
            ->values();

        return inertia('operations/handovers/Index', [
            'handovers' => $handovers,
            'weekStart' => $weekStart->toDateString(),
            'weekEnd' => $weekEnd->toDateString(),
            'filters' => ['week' => $weekStart->toDateString()],
            'catalogue' => $this->catalogue($auth),
            'can' => [
                'create' => $this->canCreateHandovers($auth),
                'manage' => $canViewAny || (bool) $auth->canDo('shifts.manageAny'),
            ],
            'currentUser' => ['id' => $auth->id, 'name' => $auth->name],
        ]);
    }

    public function show(Request $request, $handover)
    {
        $auth = $request->user();
        abort_unless($this->handoverService->canAccessWorkflow($auth), 403);

        $handover = ShiftHandover::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with([
                'outgoingShift.client:id,first_name,last_name',
                'outgoingShift.staff:id,name',
                'incomingShift.client:id,first_name,last_name',
                'incomingShift.staff:id,name',
                'client:id,first_name,last_name',
                'outgoingStaff:id,name',
                'incomingStaff:id,name',
                'acknowledger:id,name',
                'submitter:id,name',
            ])
            ->findOrFail($handover);

        $this->assertCanAccessHandover($auth, $handover);

        abort_unless(
            $this->handoverService->canViewAny($auth) || $this->handoverService->relatedToUser($handover, $auth),
            403
        );

        return inertia('operations/handovers/Show', [
            'handover' => $handover,
        ]);
    }

    public function store(Request $request, $shift = null)
    {
        $auth = $request->user();
        abort_unless($this->canCreateHandovers($auth), 403);

        // Shared by two routes: the wizard (POST /operations/handovers with
        // shift_id in the body) and the legacy per-shift form (POST
        // /operations/shifts/{shift}/handover with the shift in the URL).
        $validated = $request->validate([
            'shift_id' => [$shift === null ? 'required' : 'nullable', 'integer', 'exists:shifts,id'],
            'incoming_shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'incoming_staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'handover_notes' => ['required', 'string', 'max:5000'],
            'client_mood' => ['nullable', 'string', 'max:255'],
            'tasks_pending' => ['nullable'],
            'medications_due' => ['nullable'],
            'incidents_to_note' => ['nullable'],
            'follow_up_items' => ['nullable'],
            'medications_due_text' => ['nullable', 'string'],
            'follow_up_items_text' => ['nullable', 'string'],
            'incidents_to_note_text' => ['nullable', 'string'],
            'tasks_pending_text' => ['nullable', 'string'],
            'submit' => ['nullable', 'boolean'],
        ]);

        $outgoingShift = Shift::query()
            ->with(['tasks:id,shift_id,label,is_completed', 'incidents:id,shift_id,type,severity,status,occurred_at'])
            ->findOrFail($shift ?? $validated['shift_id']);

        $this->siteAccess()->assertCanAccessShift(
            $auth,
            $outgoingShift,
            $this->handoverBypassPermissions(),
            'You are not authorized to create handovers for this site.',
        );

        if (! $auth->canDo('shifts.manageAny') && (int) $outgoingShift->user_id !== (int) $auth->id) {
            abort(403);
        }

        $result = $this->handoverService->save($outgoingShift, $auth, [
            'handover_notes' => $validated['handover_notes'],
            'client_id' => $validated['client_id'] ?? null,
            'incoming_shift_id' => $validated['incoming_shift_id'] ?? null,
            'incoming_staff_id' => $validated['incoming_staff_id'] ?? null,
            'client_mood' => $validated['client_mood'] ?? null,
            'medications_due' => $this->resolveListInput($request, 'medications_due'),
            'incidents_to_note' => $this->resolveListInput($request, 'incidents_to_note'),
            'follow_up_items' => $this->resolveListInput($request, 'follow_up_items'),
            'tasks_pending' => $this->resolveListInput($request, 'tasks_pending'),
            'submit' => (bool) ($validated['submit'] ?? true),
        ]);

        return redirect()->back()->with(
            'success',
            $result['action'] === 'draft_saved' ? 'Handover draft saved.' : 'Handover submitted.'
        );
    }

    public function update(Request $request, $handover)
    {
        $auth = $request->user();
        abort_unless($this->handoverService->canAccessWorkflow($auth), 403);

        $handover = ShiftHandover::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with([
                'outgoingShift:id,user_id,client_id,site_id,service_context_id,starts_at,ends_at,status',
                'incomingShift:id,user_id,starts_at,ends_at,status',
            ])
            ->findOrFail($handover);

        $this->assertCanAccessHandover($auth, $handover);

        $edit = $this->handoverService->editPermission($handover, $auth);
        abort_unless($edit['editable'], 403, $edit['reason'] === 'window_closed'
            ? 'The edit window for this handover has closed — only managers can edit it now.'
            : 'You are not authorized to edit this handover.');

        $validated = $request->validate([
            'incoming_shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'incoming_staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'handover_notes' => ['required', 'string', 'max:5000'],
            'client_mood' => ['nullable', 'string', 'max:255'],
            'medications_due_text' => ['nullable', 'string'],
            'follow_up_items_text' => ['nullable', 'string'],
            'incidents_to_note_text' => ['nullable', 'string'],
            'tasks_pending_text' => ['nullable', 'string'],
            'submit' => ['nullable', 'boolean'],
        ]);

        $this->handoverService->applyEdit($handover, $auth, [
            'incoming_shift_id' => $validated['incoming_shift_id'] ?? null,
            'incoming_staff_id' => $validated['incoming_staff_id'] ?? null,
            'handover_notes' => $validated['handover_notes'],
            'client_mood' => $validated['client_mood'] ?? null,
            'medications_due' => $this->parseHandoverText($validated['medications_due_text'] ?? null),
            'incidents_to_note' => $this->parseHandoverText($validated['incidents_to_note_text'] ?? null),
            'follow_up_items' => $this->parseHandoverText($validated['follow_up_items_text'] ?? null),
            'tasks_pending' => $this->parseHandoverText($validated['tasks_pending_text'] ?? null),
            'submit' => (bool) ($validated['submit'] ?? false),
        ]);

        return redirect()->back()->with('success', 'Handover updated.');
    }

    public function submit(Request $request, $handover)
    {
        $auth = $request->user();
        abort_unless($this->handoverService->canAccessWorkflow($auth), 403);

        $handover = ShiftHandover::query()
            ->with([
                'outgoingShift.client:id,first_name,last_name,site_id',
                'outgoingShift.site:id,name,type',
                'outgoingShift.serviceContext:id,name,type',
                'outgoingShift.staff:id,name',
                'incomingShift:id,client_id,site_id,service_context_id,user_id,starts_at,ends_at,status',
                'incomingShift.staff:id,name',
            ])
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($handover);

        $this->assertCanAccessHandover($auth, $handover);

        abort_unless($this->handoverService->canSubmit($handover, $auth), 403);

        $this->handoverService->submit($handover, $auth);

        return redirect()->back()->with('success', 'Handover submitted.');
    }

    public function acknowledge(Request $request, $handover)
    {
        $auth = $request->user();
        abort_unless($this->handoverService->canAccessWorkflow($auth), 403);

        $handover = ShiftHandover::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with([
                'incomingShift.staff:id,name',
                'outgoingShift:id,user_id,client_id,site_id,service_context_id,starts_at,ends_at,status',
            ])
            ->findOrFail($handover);

        $this->assertCanAccessHandover($auth, $handover);

        abort_unless($this->handoverService->canAcknowledge($handover, $auth), 403);

        $this->handoverService->acknowledge($handover, $auth);

        return redirect()->back()->with('success', 'Handover acknowledged.');
    }

    /**
     * Shape a single handover for the redesigned index — full record plus the
     * per-user action/edit-lock flags the UI gates affordances on.
     *
     * @return array<string, mixed>
     */
    protected function mapHandover(ShiftHandover $handover, User $auth): array
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
     * @return array<string, mixed>|null
     */
    protected function shiftPayload(?Shift $shift): ?array
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

    protected function shiftLabel(Shift $shift): string
    {
        if ($shift->shift_type) {
            return ucwords(str_replace('_', ' ', (string) $shift->shift_type));
        }

        return optional($shift->starts_at)->format('H:i') ?? 'Shift';
    }

    /**
     * Catalogue data for the hero filters + the new/edit wizard selects.
     *
     * @return array<string, mixed>
     */
    protected function catalogue(User $auth): array
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
    protected function listToDisplayStrings(mixed $items): array
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

    /**
     * Prefer the newline-delimited `{key}_text` field (wizard / eMAR contract);
     * fall back to a raw array under `{key}` (legacy per-shift form).
     */
    protected function resolveListInput(Request $request, string $key): mixed
    {
        if ($request->filled($key.'_text')) {
            return $this->parseHandoverText($request->input($key.'_text'));
        }

        return $request->input($key);
    }

    /**
     * @return array<int, array{label: string}>
     */
    protected function parseHandoverText(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n/', $value) ?: [])
            ->map(fn ($line) => trim((string) $line))
            ->filter(fn ($line) => $line !== '')
            ->map(fn ($line) => ['label' => $line])
            ->values()
            ->all();
    }

    protected function canViewHandovers(?User $auth): bool
    {
        return $this->handoverService->canAccessWorkflow($auth);
    }

    protected function canCreateHandovers(?User $auth): bool
    {
        return (bool) $auth && (
            $auth->canDo('handovers.create')
            || $auth->canDo('shifts.update')
            || $auth->canDo('shifts.manageAny')
        );
    }

    protected function siteAccess(): UserSiteAccessService
    {
        return app(UserSiteAccessService::class);
    }

    /**
     * @return array<int, string>
     */
    protected function handoverBypassPermissions(): array
    {
        return ['shifts.manageAny', 'handovers.viewAny', 'reports.viewAny'];
    }

    protected function assertCanAccessHandover(User $auth, ShiftHandover $handover): void
    {
        $this->siteAccess()->assertCanAccessHandover(
            $auth,
            $handover,
            $this->handoverBypassPermissions(),
            'You are not authorized to access handovers for this site.',
        );
    }
}
