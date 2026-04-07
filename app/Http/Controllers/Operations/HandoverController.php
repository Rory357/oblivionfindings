<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\User;
use App\Services\ShiftHandoverService;
use App\Services\UserSiteAccessService;
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
            'q' => ['nullable', 'string', 'max:255'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'status' => ['nullable', 'string', 'in:draft,submitted,acknowledged'],
        ]);

        $dateFrom = $filters['date_from'] ?? $filters['date'] ?? null;
        $dateTo = $filters['date_to'] ?? $filters['date'] ?? null;
        $search = trim((string) ($filters['q'] ?? ''));

        $handovers = ShiftHandover::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->tap(fn ($query) => $this->siteAccess()->applyHandoverScope($query, $auth, $this->handoverBypassPermissions()))
            ->with([
                'outgoingShift:id,starts_at,ends_at,client_id,user_id',
                'incomingShift:id,starts_at,ends_at,client_id,user_id',
                'incomingShift.staff:id,name',
                'client:id,first_name,last_name',
                'outgoingStaff:id,name',
                'incomingStaff:id,name',
                'acknowledger:id,name',
                'submitter:id,name',
            ])
            ->when(! empty($filters['client_id']), fn ($q) => $q->where('client_id', $filters['client_id']))
            ->when($dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('created_at', '<=', $dateTo))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($nested) use ($search) {
                    $nested->where('handover_notes', 'like', '%'.$search.'%')
                        ->orWhereHas('client', fn ($clientQuery) => $clientQuery
                            ->where('first_name', 'like', '%'.$search.'%')
                            ->orWhere('last_name', 'like', '%'.$search.'%'))
                        ->orWhereHas('outgoingStaff', fn ($staffQuery) => $staffQuery
                            ->where('name', 'like', '%'.$search.'%'))
                        ->orWhereHas('incomingStaff', fn ($staffQuery) => $staffQuery
                            ->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->when(! $this->handoverService->canViewAny($auth), function ($query) use ($auth) {
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
            ->paginate(25)
            ->through(function (ShiftHandover $handover) use ($auth) {
                $currentIncomingStaff = $handover->incomingShift?->staff;
                $incomingUserId = $currentIncomingStaff?->id
                    ?? ($handover->incoming_shift_id ? null : $handover->incoming_staff_id);

                return [
                    'id' => $handover->id,
                    'status' => $handover->status,
                    'handover_notes' => $handover->handover_notes,
                    'client_mood' => $handover->client_mood,
                    'tasks_pending' => $handover->tasks_pending,
                    'follow_up_items' => $handover->follow_up_items,
                    'submitted_at' => optional($handover->submitted_at)->toISOString(),
                    'created_at' => optional($handover->created_at)->toISOString(),
                    'acknowledged_at' => optional($handover->acknowledged_at)->toISOString(),
                    'client' => $handover->client ? [
                        'id' => $handover->client->id,
                        'first_name' => $handover->client->first_name,
                        'last_name' => $handover->client->last_name,
                    ] : null,
                    'outgoing_staff' => $handover->outgoingStaff ? [
                        'id' => $handover->outgoingStaff->id,
                        'name' => $handover->outgoingStaff->name,
                    ] : null,
                    'incoming_staff' => ($handover->incomingStaff || $incomingUserId) ? [
                        'id' => $currentIncomingStaff?->id ?? $handover->incomingStaff?->id ?? $incomingUserId,
                        'name' => $currentIncomingStaff?->name ?? $handover->incomingStaff?->name ?? 'Pending assignment',
                    ] : null,
                    'acknowledger' => $handover->acknowledger ? [
                        'id' => $handover->acknowledger->id,
                        'name' => $handover->acknowledger->name,
                    ] : null,
                    'outgoing_shift' => $handover->outgoingShift ? [
                        'id' => $handover->outgoingShift->id,
                        'starts_at' => optional($handover->outgoingShift->starts_at)->toISOString(),
                        'ends_at' => optional($handover->outgoingShift->ends_at)->toISOString(),
                    ] : null,
                    'incoming_shift' => $handover->incomingShift ? [
                        'id' => $handover->incomingShift->id,
                        'starts_at' => optional($handover->incomingShift->starts_at)->toISOString(),
                        'ends_at' => optional($handover->incomingShift->ends_at)->toISOString(),
                    ] : null,
                    'can_submit' => $this->handoverService->canSubmit($handover, $auth),
                    'can_acknowledge' => $this->handoverService->canAcknowledge($handover, $auth),
                ];
            })
            ->withQueryString();

        return inertia('operations/handovers/Index', [
            'handovers' => $handovers,
            'filters' => [
                'q' => $filters['q'] ?? null,
                'date' => $filters['date'] ?? null,
                'client_id' => $filters['client_id'] ?? null,
                'status' => $filters['status'] ?? null,
            ],
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

    public function store(Request $request, $shift)
    {
        $auth = $request->user();
        abort_unless($this->canCreateHandovers($auth), 403);

        $outgoingShift = Shift::query()
            ->with(['tasks:id,shift_id,label,is_completed', 'incidents:id,shift_id,type,severity,status,occurred_at'])
            ->findOrFail($shift);

        $this->siteAccess()->assertCanAccessShift(
            $auth,
            $outgoingShift,
            $this->handoverBypassPermissions(),
            'You are not authorized to create handovers for this site.',
        );

        if (! $auth->canDo('shifts.manageAny') && (int) $outgoingShift->user_id !== (int) $auth->id) {
            abort(403);
        }

        $data = $request->validate([
            'handover_notes' => ['required', 'string', 'max:5000'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'incoming_shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'incoming_staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'client_mood' => ['nullable', 'string', 'max:255'],
            'tasks_pending' => ['nullable'],
            'medications_due' => ['nullable'],
            'incidents_to_note' => ['nullable'],
            'follow_up_items' => ['nullable'],
            'submit' => ['nullable', 'boolean'],
        ]);

        $result = $this->handoverService->save($outgoingShift, $auth, $data);

        return redirect()->back()->with(
            'success',
            $result['action'] === 'draft_saved' ? 'Handover draft saved.' : 'Handover submitted.'
        );
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
