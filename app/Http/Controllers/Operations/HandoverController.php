<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
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
    ) {}

    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->handoverService->canAccessWorkflow($auth), 403);

        $input = $request->validate([
            'week' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
            'staff' => ['nullable', 'integer', 'min:1'],
            'client' => ['nullable', 'integer', 'min:1'],
            'site' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:all,draft,submitted,acknowledged'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $tz = config('app.worker_timezone') ?: config('app.timezone', 'UTC');
        $weekStart = ! empty($input['week'])
            ? Carbon::parse($input['week'], $tz)->startOfWeek(Carbon::MONDAY)
            : Carbon::now($tz)->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);
        $startUtc = $weekStart->copy()->utc();
        $endUtc = $weekStart->copy()->addWeek()->utc();
        $filters = [
            'week' => $weekStart->toDateString(),
            'q' => trim($input['q'] ?? ''),
            'staff' => isset($input['staff']) ? (int) $input['staff'] : null,
            'client' => isset($input['client']) ? (int) $input['client'] : null,
            'site' => isset($input['site']) ? (int) $input['site'] : null,
            'status' => $input['status'] ?? 'all',
            'page' => (int) ($input['page'] ?? 1),
        ];
        $canViewAny = $this->handoverService->canViewAny($auth);
        $includeControlledMedication = $auth->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY);

        $handoverQuery = ShiftHandover::query()
            ->tap(fn ($query) => $this->siteAccess()->applyHandoverScope($query, $auth, $this->handoverBypassPermissions()))
            // Keep the outgoing Shift's effective date, with created_at only
            // for a record which has no dated outgoing Shift.
            ->where(function ($dateScope) use ($startUtc, $endUtc) {
                $dateScope
                    ->whereHas('outgoingShift', fn ($shift) => $shift
                        ->whereNotNull('starts_at')
                        ->where('starts_at', '>=', $startUtc)->where('starts_at', '<', $endUtc))
                    ->orWhere(fn ($noShift) => $noShift
                        ->whereDoesntHave('outgoingShift', fn ($shift) => $shift->whereNotNull('starts_at'))
                        ->where('created_at', '>=', $startUtc)->where('created_at', '<', $endUtc));
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
            ->when($filters['client'] !== null, fn ($query) => $query
                ->whereHas('client', fn ($client) => $client->whereKey($filters['client'])))
            ->when($filters['site'] !== null, fn ($query) => $query
                ->whereHas('client.site', fn ($site) => $site->whereKey($filters['site'])))
            ->when($filters['staff'] !== null, function ($query) use ($filters): void {
                $query->where(function ($staff) use ($filters): void {
                    $staff->whereHas('outgoingStaff', fn ($user) => $user->whereKey($filters['staff']))
                        ->orWhereHas('acknowledger', fn ($user) => $user->whereKey($filters['staff']))
                        ->orWhereHas('incomingShift.staff', fn ($user) => $user->whereKey($filters['staff']))
                        ->orWhere(fn ($unbound) => $unbound->whereNull('incoming_shift_id')
                            ->whereHas('incomingStaff', fn ($user) => $user->whereKey($filters['staff'])));
                });
            });

        if ($filters['q'] !== '') {
            $needle = mb_strtolower($filters['q'], 'UTF-8');
            $matchingIds = [];
            // Search exactly the displayed fields, never raw JSON or private
            // worker notes. Chunk the whole permitted week without a search cap.
            (clone $handoverQuery)->select([
                'shift_handovers.id', 'client_id', 'outgoing_staff_id', 'incoming_shift_id', 'incoming_staff_id',
                'handover_notes', 'client_mood', 'medications_due', 'incidents_to_note', 'follow_up_items', 'tasks_pending',
            ])->with([
                'client:id,first_name,last_name,site_id', 'client.site:id,name',
                'outgoingStaff:id,name', 'incomingShift:id,user_id', 'incomingShift.staff:id,name', 'incomingStaff:id,name',
            ])->chunkById(200, function ($handovers) use ($needle, $includeControlledMedication, &$matchingIds): void {
                foreach ($handovers as $handover) {
                    if (str_contains(mb_strtolower($this->presenter->indexSearchText($handover, $includeControlledMedication), 'UTF-8'), $needle)) {
                        $matchingIds[] = (int) $handover->id;
                    }
                }
            }, 'shift_handovers.id', 'id');
            // Integer literals avoid a parameter ceiling without weakening
            // the original scope or silently capping complete search results.
            $handoverQuery->whereIntegerInRaw('shift_handovers.id', $matchingIds);
        }

        $statusCounts = (clone $handoverQuery)->reorder()
            ->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')
            ->pluck('aggregate', 'status')->map(fn ($count): int => (int) $count)->all();
        $total = array_sum($statusCounts);
        $openIncoming = (clone $handoverQuery)->where(function ($incoming): void {
            $incoming->whereHas('incomingShift', fn ($shift) => $shift->whereNull('user_id'))
                ->orWhere(fn ($unbound) => $unbound->whereNull('incoming_shift_id')->whereNull('incoming_staff_id'));
        })->count();
        $activeTotal = $filters['status'] === 'all' ? $total : ($statusCounts[$filters['status']] ?? 0);
        $filters['page'] = min($filters['page'], max(1, (int) ceil($activeTotal / 300)));
        $handoverPage = (clone $handoverQuery)
            ->when($filters['status'] !== 'all', fn ($query) => $query->where('status', $filters['status']))
            ->with($this->presenter->mapEagerLoads())
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(300, ['*'], 'page', $filters['page'])->appends($filters);
        $handovers = $handoverPage->getCollection()
            ->map(fn (ShiftHandover $handover) => $this->presenter->mapHandover(
                $handover,
                $auth,
                $includeControlledMedication,
            ))->values();

        return inertia('operations/handovers/Index', [
            'handovers' => $handovers,
            'handoverPagination' => [
                'current_page' => $handoverPage->currentPage(), 'last_page' => $handoverPage->lastPage(),
                'per_page' => $handoverPage->perPage(), 'total' => $handoverPage->total(),
                'from' => $handoverPage->firstItem(), 'to' => $handoverPage->lastItem(),
                'links' => $handoverPage->linkCollection()->all(),
            ],
            'summary' => [
                'total' => $total, 'draft' => $statusCounts['draft'] ?? 0,
                'submitted' => $statusCounts['submitted'] ?? 0,
                'acknowledged' => $statusCounts['acknowledged'] ?? 0,
                'openIncoming' => $openIncoming,
            ],
            'evidence' => [
                'state' => $total === 0 ? 'no_records' : 'recorded',
                'basis' => 'handover_records_by_outgoing_shift_start_or_created_at',
                'scope' => $canViewAny ? 'permitted_sites' : 'own_related_records_in_permitted_sites',
                'filter_basis' => 'week_search_staff_client_site_before_status',
                'list_basis' => 'same_cohort_with_selected_status',
                'period_start' => $startUtc->toIso8601String(),
                'period_end_exclusive' => $endUtc->toIso8601String(),
                'timezone' => $tz, 'checked_at' => now()->toIso8601String(), 'complete' => true,
            ],
            'lists' => ['handovers' => ['total' => $handoverPage->total(), 'shown' => $handovers->count(),
                'limit' => 300, 'truncated' => $handoverPage->total() > $handovers->count()]],
            'weekStart' => $weekStart->toDateString(),
            'weekEnd' => $weekEnd->toDateString(),
            'filters' => $filters,
            'catalogue' => $this->presenter->catalogue($auth),
            'can' => [
                'create' => $this->canCreateHandovers($auth),
                'manage' => (bool) $auth->canDo('shifts.manageAny'),
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
            ->tap(fn ($query) => $this->siteAccess()->applyHandoverScope($query, $auth, $this->handoverBypassPermissions()))
            ->with($this->presenter->mapEagerLoads())
            ->findOrFail($handover);

        $this->assertCanAccessHandover($auth, $handover);

        abort_unless(
            $this->handoverService->canViewAny($auth) || $this->handoverService->relatedToUser($handover, $auth),
            404
        );

        return inertia('operations/handovers/Show', [
            'handover' => $this->presenter->mapHandoverDetail(
                $handover,
                $auth,
                $auth->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
            ),
        ]);
    }

    public function store(Request $request, $shift = null)
    {
        $auth = $request->user();
        abort_unless($this->canCreateHandovers($auth), 403);

        // Shared by two routes: the wizard (POST /operations/handovers with
        // shift_id in the body) and the legacy per-shift form (POST
        // /operations/shifts/{shift}/handover with the shift in the URL).
        $identity = $shift === null
            ? $request->validate(['shift_id' => ['required', 'integer', 'min:1']])
            : ['shift_id' => filter_var($shift, FILTER_VALIDATE_INT)];
        abort_unless(is_int($identity['shift_id']) && $identity['shift_id'] > 0, 404);
        if ($shift !== null && $request->filled('shift_id')) {
            $bodyShiftId = filter_var($request->input('shift_id'), FILTER_VALIDATE_INT);
            abort_unless($bodyShiftId !== false && $bodyShiftId === $identity['shift_id'], 404);
        }
        $outgoingShift = $this->handoverService->writableOutgoingShift($auth, $identity['shift_id']);
        $this->assertControlledHandoverInputAuthority($request, $auth);

        $validated = $request->validate([
            'shift_id' => [$shift === null ? 'required' : 'nullable', 'integer', 'min:1'],
            'incoming_shift_id' => ['nullable', 'integer', 'min:1'],
            'incoming_staff_id' => ['nullable', 'integer', 'min:1'],
            'client_id' => ['nullable', 'integer', 'min:1'],
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
            'cd_result' => ['nullable', 'in:verified,discrepancy'],
            'cd_witness_id' => ['nullable', 'integer', 'min:1'],
            'cd_witness_credential' => ['nullable', 'string', 'max:255'],
            'cd_notes' => ['nullable', 'string', 'max:2000'],
            'version' => ['nullable', 'integer', 'min:1'],
            'submit' => ['nullable', 'boolean'],
        ]);

        $result = $this->handoverService->save($outgoingShift, $auth, [
            'handover_notes' => $validated['handover_notes'],
            'client_id' => $validated['client_id'] ?? null,
            ...($request->exists('incoming_shift_id') ? [
                'incoming_shift_id' => $validated['incoming_shift_id'] ?? null,
            ] : []),
            ...($request->exists('incoming_staff_id') ? [
                'incoming_staff_id' => $validated['incoming_staff_id'] ?? null,
            ] : []),
            'client_mood' => $validated['client_mood'] ?? null,
            ...($this->hasMedicationDueInput($request) ? [
                'medications_due' => $this->resolveListInput($request, 'medications_due'),
            ] : []),
            'incidents_to_note' => $this->resolveListInput($request, 'incidents_to_note'),
            'follow_up_items' => $this->resolveListInput($request, 'follow_up_items'),
            'tasks_pending' => $this->resolveListInput($request, 'tasks_pending'),
            'cd_verification_input' => [
                'result' => $validated['cd_result'] ?? null,
                'witness_id' => $validated['cd_witness_id'] ?? null,
                'witness_credential' => $validated['cd_witness_credential'] ?? null,
                'notes' => $validated['cd_notes'] ?? null,
            ],
            'expected_version' => $validated['version'] ?? null,
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
            ->tap(fn ($query) => $this->siteAccess()->applyHandoverScope($query, $auth, $this->handoverBypassPermissions()))
            ->with([
                'outgoingShift:id,user_id,client_id,site_id,service_context_id,starts_at,ends_at,status',
                'incomingShift:id,user_id,starts_at,ends_at,status',
            ])
            ->findOrFail($handover);

        $this->assertCanAccessHandover($auth, $handover);
        $this->assertCanMutateHandover($auth, $handover);

        $edit = $this->handoverService->editPermission($handover, $auth);
        abort_unless($edit['editable'], 403, $edit['reason'] === 'window_closed'
            ? 'The edit window for this handover has closed — only managers can edit it now.'
            : 'You are not authorized to edit this handover.');
        $this->assertControlledHandoverInputAuthority($request, $auth);

        $validated = $request->validate([
            'incoming_shift_id' => ['nullable', 'integer', 'min:1'],
            'incoming_staff_id' => ['nullable', 'integer', 'min:1'],
            'handover_notes' => ['required', 'string', 'max:5000'],
            'client_mood' => ['nullable', 'string', 'max:255'],
            'medications_due_text' => ['nullable', 'string'],
            'follow_up_items_text' => ['nullable', 'string'],
            'incidents_to_note_text' => ['nullable', 'string'],
            'tasks_pending_text' => ['nullable', 'string'],
            'cd_result' => ['nullable', 'in:verified,discrepancy'],
            'cd_witness_id' => ['nullable', 'integer', 'min:1'],
            'cd_witness_credential' => ['nullable', 'string', 'max:255'],
            'cd_notes' => ['nullable', 'string', 'max:2000'],
            'version' => ['required', 'integer', 'min:1'],
            'submit' => ['nullable', 'boolean'],
        ]);

        $this->handoverService->applyEdit($handover, $auth, [
            ...($request->exists('incoming_shift_id') ? [
                'incoming_shift_id' => $validated['incoming_shift_id'] ?? null,
            ] : []),
            ...($request->exists('incoming_staff_id') ? [
                'incoming_staff_id' => $validated['incoming_staff_id'] ?? null,
            ] : []),
            'handover_notes' => $validated['handover_notes'],
            'client_mood' => $validated['client_mood'] ?? null,
            ...($this->hasMedicationDueInput($request) ? [
                'medications_due' => $this->parseHandoverText($validated['medications_due_text'] ?? null),
            ] : []),
            'incidents_to_note' => $this->parseHandoverText($validated['incidents_to_note_text'] ?? null),
            'follow_up_items' => $this->parseHandoverText($validated['follow_up_items_text'] ?? null),
            'tasks_pending' => $this->parseHandoverText($validated['tasks_pending_text'] ?? null),
            'cd_verification_input' => [
                'result' => $validated['cd_result'] ?? null,
                'witness_id' => $validated['cd_witness_id'] ?? null,
                'witness_credential' => $validated['cd_witness_credential'] ?? null,
                'notes' => $validated['cd_notes'] ?? null,
            ],
            'expected_version' => $validated['version'],
            'submit' => (bool) ($validated['submit'] ?? false),
        ]);

        return redirect()->back()->with('success', 'Handover updated.');
    }

    public function submit(Request $request, $handover)
    {
        $auth = $request->user();
        abort_unless($this->handoverService->canAccessWorkflow($auth), 403);

        $handover = ShiftHandover::query()
            ->tap(fn ($query) => $this->siteAccess()->applyHandoverScope($query, $auth, $this->handoverBypassPermissions()))
            ->with([
                'outgoingShift.client:id,first_name,last_name,site_id',
                'outgoingShift.site:id,name,type',
                'outgoingShift.serviceContext:id,name,type',
                'outgoingShift.staff:id,name',
                'incomingShift:id,client_id,site_id,service_context_id,user_id,starts_at,ends_at,status',
                'incomingShift.staff:id,name',
            ])
            ->findOrFail($handover);

        $this->assertCanAccessHandover($auth, $handover);
        $this->assertCanMutateHandover($auth, $handover);

        abort_unless($this->handoverService->canSubmit($handover, $auth), 403);

        $this->handoverService->submit($handover, $auth);

        return redirect()->back()->with('success', 'Handover submitted.');
    }

    public function acknowledge(Request $request, $handover)
    {
        $auth = $request->user();
        abort_unless($this->handoverService->canAccessWorkflow($auth), 403);

        $handover = ShiftHandover::query()
            ->tap(fn ($query) => $this->siteAccess()->applyHandoverScope($query, $auth, $this->handoverBypassPermissions()))
            ->whereNotNull('incoming_shift_id')
            ->whereHas('incomingShift', fn ($shift) => $shift->where('user_id', $auth->id))
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

    protected function assertControlledHandoverInputAuthority(Request $request, User $actor): void
    {
        $hasControlledEvidenceInput = collect(['cd_result', 'cd_witness_id', 'cd_witness_credential', 'cd_notes'])
            ->contains(fn (string $key): bool => $request->filled($key));
        if (! $hasControlledEvidenceInput && ! $this->hasMedicationDueInput($request)) {
            return;
        }

        abort_unless(
            $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)
                && $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY),
            404,
        );
    }

    protected function hasMedicationDueInput(Request $request): bool
    {
        return $request->exists('medications_due') || $request->exists('medications_due_text');
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
        return MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS;
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

    /**
     * Conceal same-Site direct objects from unrelated workers before status,
     * edit-window, or validation details can disclose that the row exists.
     */
    protected function assertCanMutateHandover(User $auth, ShiftHandover $handover): void
    {
        abort_unless(
            $auth->canDo('shifts.manageAny')
                || (int) ($handover->outgoingShift?->user_id ?? 0) === (int) $auth->id,
            404,
        );
    }
}
