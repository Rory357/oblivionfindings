<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\User;
use App\Services\Operations\HandoverPresenter;
use App\Services\ShiftHandoverService;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class HandoverController extends Controller
{
    public function __construct(
        protected ShiftHandoverService $handoverService,
        protected HandoverPresenter $presenter,
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
        $startUtc = $weekStart->copy()->utc();
        $endUtc = $weekEnd->copy()->utc();

        $canViewAny = $this->handoverService->canViewAny($auth);

        $handovers = ShiftHandover::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->tap(fn ($query) => $this->siteAccess()->applyHandoverScope($query, $auth, $this->handoverBypassPermissions()))
            ->with($this->presenter->mapEagerLoads())
            // Filter the week by the handover's effective date — its outgoing
            // shift's start (what the UI groups + navigates by), falling back to
            // created_at only when there's no dated shift. Keeps the week filter
            // consistent with the day grouping, the rail strip, and the
            // post-create week jump.
            ->where(function ($dateScope) use ($startUtc, $endUtc) {
                $dateScope
                    ->whereHas('outgoingShift', fn ($s) => $s
                        ->whereNotNull('starts_at')
                        ->whereBetween('starts_at', [$startUtc, $endUtc]))
                    ->orWhere(fn ($noShift) => $noShift
                        ->whereDoesntHave('outgoingShift', fn ($s) => $s->whereNotNull('starts_at'))
                        ->whereBetween('created_at', [$startUtc, $endUtc]));
            })
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
            ->map(fn (ShiftHandover $handover) => $this->presenter->mapHandover($handover, $auth))
            ->values();

        return inertia('operations/handovers/Index', [
            'handovers' => $handovers,
            'weekStart' => $weekStart->toDateString(),
            'weekEnd' => $weekEnd->toDateString(),
            'filters' => ['week' => $weekStart->toDateString()],
            'catalogue' => $this->presenter->catalogue($auth),
            'can' => [
                'create' => $this->canCreateHandovers($auth),
                'manage' => $canViewAny || (bool) $auth->canDo('shifts.manageAny'),
                // Gates the read-only "Medications this shift" lens in the detail
                // dialog — the snapshot endpoint sits behind medications.view.
                'view_medications' => (bool) $auth->canDo('medications.view'),
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
