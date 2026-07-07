<?php

namespace App\Http\Controllers\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Notifications\ItProvisioningCancelledNotification;
use App\Domain\Hr\Services\OnboardingService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Models\ItProvisioningRequest;
use App\Models\ItTicket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * IT & Provisioning hub (/it): the onboarding-driven provisioning queue
 * (accounts / access / equipment for new hires) plus the general helpdesk
 * ticket queue. Replaces the design-preview wireframe — see
 * docs/IT_PROVISIONING_WIREFRAME.md.
 */
class ItProvisioningController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        private readonly OnboardingService $onboardingService,
    ) {}

    /* ================================================================== */
    /*  Hub */
    /* ================================================================== */

    public function index(Request $request)
    {
        $user = $request->user();
        $isAgent = $user && $user->canDo('it.view');
        $canRequest = $user && $user->canDo('it.request');
        abort_unless($isAgent || $canRequest, 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $filters = [
            'status' => $this->cleanFilter($request->query('status'), ItProvisioningRequest::STATUSES),
            'type' => $this->cleanFilter($request->query('type'), ItProvisioningRequest::TYPES),
            'assignee' => is_numeric($request->query('assignee')) ? (int) $request->query('assignee') : null,
            'ticket_status' => $this->cleanFilter($request->query('ticket_status'), ItTicket::STATUSES),
            'ticket_priority' => $this->cleanFilter($request->query('ticket_priority'), ItTicket::PRIORITIES),
        ];

        // Requesters get ONLY their own tickets — the agent queues, stats and
        // staff directory never reach a self-service payload.
        $agentProps = $isAgent ? [
            'requests' => $this->requestRows($tenantId, $filters),
            'tickets' => $this->ticketRows($tenantId, $filters),
            'stats' => $this->stats($tenantId),
            'assignees' => $this->tenantUserOptions($tenantId),
            'filters' => $filters,
        ] : [];

        return Inertia::render('it/index', [
            ...$agentProps,
            'myTickets' => $canRequest ? $this->myTicketRows($tenantId, $user->id) : [],
            'can' => [
                'view' => $isAgent,
                'manage' => (bool) ($user && $user->canDo('it.manage')),
                'request' => $canRequest,
            ],
        ]);
    }

    /* ================================================================== */
    /*  Provisioning requests */
    /* ================================================================== */

    public function assign(Request $request, ItProvisioningRequest $provisioning)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('it.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $provisioning->tenant_id);

        $validated = $request->validate([
            'assigned_to_user_id' => [
                'required', 'integer', 'exists:users,id',
                $this->rejectForeignTenantRecipient($tenantId),
            ],
        ]);

        if (in_array($provisioning->status, ['done', 'cancelled'], true)) {
            return redirect()->back()->with('error', 'This request is closed — reopen it before reassigning.');
        }

        $provisioning->update([
            'assigned_to_user_id' => (int) $validated['assigned_to_user_id'],
            'status' => $provisioning->status === 'pending' ? 'in_progress' : $provisioning->status,
        ]);

        return redirect()->back()->with('success', 'Request assigned.');
    }

    public function fulfil(Request $request, ItProvisioningRequest $provisioning)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('it.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $provisioning->tenant_id);

        $validated = $request->validate([
            'external_ref' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($provisioning->status === 'done') {
            return redirect()->back()->with('error', 'This request is already fulfilled.');
        }
        if ($provisioning->status === 'cancelled') {
            return redirect()->back()->with('error', 'This request was cancelled — it can no longer be fulfilled.');
        }

        try {
            DB::transaction(function () use ($provisioning, $validated, $user) {
                $provisioning->update([
                    'status' => 'done',
                    'external_ref' => $validated['external_ref'] ?? $provisioning->external_ref,
                    'notes' => $validated['notes'] ?? $provisioning->notes,
                    'fulfilled_at' => now(),
                    'fulfilled_by' => $user->id,
                ]);

                // Cross-loop: fulfilment completes the source onboarding task
                // (mirrors provisionAssetForTask so dependency/rollup/webhook
                // all fire). Sign-off tasks record the fulfiller as sign-off.
                $task = $provisioning->onboardingTask;
                if ($task && $task->status !== 'completed') {
                    $this->onboardingService->completeTask($task, $user->id, array_filter([
                        'notes' => $validated['notes'] ?? null,
                        'signed_off_by' => $task->sign_off_required ? $user->id : null,
                    ], fn ($v) => $v !== null));
                }
            });
        } catch (\LogicException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', "Fulfilled “{$provisioning->item}”.");
    }

    public function cancel(Request $request, ItProvisioningRequest $provisioning)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('it.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $provisioning->tenant_id);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $reason = trim((string) ($validated['reason'] ?? '')) ?: null;

        if ($provisioning->status === 'done') {
            return redirect()->back()->with('error', 'A fulfilled request cannot be cancelled.');
        }

        $provisioning->update(['status' => 'cancelled']);

        // Cross-loop: a cancelled request must not orphan its source onboarding
        // task — annotate the still-open task and tell the checklist creator so
        // it gets resolved manually. Best-effort: never blocks the cancel.
        if ($provisioning->onboarding_task_id) {
            try {
                $task = $provisioning->onboardingTask()->with('checklist.employeeProfile.user:id,name')->first();
                if ($task && $task->status !== 'completed') {
                    $note = 'IT request cancelled'.($reason ? ": {$reason}" : '').' — resolve this task manually.';
                    $existing = trim((string) $task->notes);
                    $task->update(['notes' => $existing === '' ? $note : $existing."\n".$note]);

                    $creator = $task->checklist?->created_by
                        ? User::find($task->checklist->created_by)
                        : null;
                    $creator?->notify(new ItProvisioningCancelledNotification($provisioning, $task, $reason));
                }
            } catch (\Throwable $exception) {
                Log::warning('Failed to annotate/notify onboarding task after IT request cancellation', [
                    'provisioning_request_id' => $provisioning->id,
                    'onboarding_task_id' => $provisioning->onboarding_task_id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return redirect()->back()->with('success', 'Request cancelled.');
    }

    /* ================================================================== */
    /*  Helpdesk tickets */
    /* ================================================================== */

    public function storeTicket(Request $request)
    {
        $user = $request->user();
        abort_unless((bool) $user, 403);
        $this->authorize('create', ItTicket::class);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $isAgent = $user->canDo('it.manage');

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['required', Rule::in(ItTicket::CATEGORIES)],
            'priority' => ['required', Rule::in(ItTicket::PRIORITIES)],
            'assigned_to_user_id' => [
                'nullable', 'integer', 'exists:users,id',
                $this->rejectForeignTenantRecipient($tenantId),
            ],
        ]);

        // Triage fields are agent-only: a self-service requester cannot pick
        // an assignee, whatever the request body says.
        $assigneeId = $isAgent ? ($validated['assigned_to_user_id'] ?? null) : null;

        ItTicket::create([
            'tenant_id' => $tenantId,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'requester_user_id' => $user->id,
            'assigned_to_user_id' => $assigneeId,
            'category' => $validated['category'],
            'priority' => $validated['priority'],
            'status' => $assigneeId ? 'in_progress' : 'open',
        ]);

        return redirect()->back()->with('success', 'Ticket logged.');
    }

    public function updateTicket(Request $request, ItTicket $ticket)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('it.manage'), 403);
        $this->authorize('update', $ticket);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $ticket->tenant_id);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(ItTicket::STATUSES)],
            'priority' => ['sometimes', Rule::in(ItTicket::PRIORITIES)],
            'assigned_to_user_id' => [
                'sometimes', 'nullable', 'integer', 'exists:users,id',
                $this->rejectForeignTenantRecipient($tenantId),
            ],
        ]);

        $update = $validated;

        if (array_key_exists('status', $validated)) {
            $update['resolved_at'] = $validated['status'] === 'resolved'
                ? ($ticket->resolved_at ?? now())
                : null;
        }

        $ticket->update($update);

        return redirect()->back()->with('success', 'Ticket updated.');
    }

    public function resolveTicket(Request $request, ItTicket $ticket)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('it.manage'), 403);
        $this->authorize('resolve', $ticket);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $ticket->tenant_id);

        if (in_array($ticket->status, ['resolved', 'closed'], true)) {
            return redirect()->back()->with('error', 'This ticket is already resolved.');
        }

        $ticket->update([
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);

        return redirect()->back()->with('success', "Resolved “{$ticket->title}”.");
    }

    /* ================================================================== */
    /*  Payload builders */
    /* ================================================================== */

    /** @param array<string, mixed> $filters */
    private function requestRows(int $tenantId, array $filters): array
    {
        // House rule: guard new-table reads so a request racing the deploy's
        // migration step renders an empty queue instead of a 500.
        if (! Schema::hasTable('it_provisioning_requests')) {
            return [];
        }

        return ItProvisioningRequest::query()
            ->forTenant($tenantId)
            ->with([
                'employeeProfile:id,user_id,position_title,position_role',
                'employeeProfile.user:id,name',
                'assignee:id,name',
                'onboardingTask:id,checklist_id,title,status,sign_off_required',
            ])
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['type'], fn ($q, $type) => $q->where('type', $type))
            ->when($filters['assignee'], fn ($q, $assignee) => $q->where('assigned_to_user_id', $assignee))
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'done' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ItProvisioningRequest $r) => [
                'id' => $r->id,
                'employee' => [
                    'name' => $r->employeeProfile?->user?->name ?? 'Unknown',
                    'role' => $r->employeeProfile?->position_title ?? $r->employeeProfile?->position_role,
                ],
                'item' => $r->item,
                'type' => $r->type,
                'status' => $r->status,
                'assignee' => $r->assignee ? ['id' => $r->assignee->id, 'name' => $r->assignee->name] : null,
                'external_ref' => $r->external_ref,
                'notes' => $r->notes,
                'from_onboarding' => $r->onboarding_task_id !== null,
                'sign_off_required' => (bool) ($r->onboardingTask?->sign_off_required ?? false),
                'created' => $r->created_at?->diffForHumans(short: true),
                'fulfilled' => $r->fulfilled_at?->diffForHumans(short: true),
            ])
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $filters */
    private function ticketRows(int $tenantId, array $filters): array
    {
        if (! Schema::hasTable('it_tickets')) {
            return [];
        }

        return ItTicket::query()
            ->forTenant($tenantId)
            ->with(['requester:id,name', 'assignee:id,name'])
            ->when($filters['ticket_status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['ticket_priority'], fn ($q, $priority) => $q->where('priority', $priority))
            ->when($filters['assignee'], fn ($q, $assignee) => $q->where('assigned_to_user_id', $assignee))
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'resolved' THEN 2 ELSE 3 END")
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ItTicket $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'description' => $t->description,
                'category' => $t->category,
                'priority' => $t->priority,
                'status' => $t->status,
                'requester' => $t->requester?->name ?? 'Unknown',
                'assignee' => $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name] : null,
                'age' => $t->created_at?->diffForHumans(short: true),
                'resolved' => $t->resolved_at?->diffForHumans(short: true),
            ])
            ->values()
            ->all();
    }

    /**
     * The requester's own tickets — the ONLY ticket rows a self-service
     * payload may carry. No requester column (it is always "you") and no
     * assignee identity beyond a name, mirroring what a helpdesk shows the
     * person who raised the ticket.
     */
    private function myTicketRows(int $tenantId, int $userId): array
    {
        if (! Schema::hasTable('it_tickets')) {
            return [];
        }

        return ItTicket::query()
            ->forTenant($tenantId)
            ->where('requester_user_id', $userId)
            ->with('assignee:id,name')
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'resolved' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ItTicket $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'description' => $t->description,
                'category' => $t->category,
                'priority' => $t->priority,
                'status' => $t->status,
                'assignee' => $t->assignee?->name,
                'age' => $t->created_at?->diffForHumans(short: true),
                'resolved' => $t->resolved_at?->diffForHumans(short: true),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, int> */
    private function stats(int $tenantId): array
    {
        $requests = Schema::hasTable('it_provisioning_requests')
            ? ItProvisioningRequest::query()
                ->forTenant($tenantId)
                ->selectRaw("SUM(status = 'pending') AS pending, SUM(status = 'in_progress') AS in_progress, SUM(status = 'done' AND fulfilled_at >= ?) AS done_30d", [now()->subDays(30)])
                ->first()
            : null;

        $tickets = Schema::hasTable('it_tickets')
            ? ItTicket::query()
                ->forTenant($tenantId)
                ->selectRaw("SUM(status IN ('open', 'in_progress')) AS open_count, SUM(status IN ('open', 'in_progress') AND priority = 'urgent') AS urgent")
                ->first()
            : null;

        return [
            'requests_pending' => (int) ($requests->pending ?? 0),
            'requests_in_progress' => (int) ($requests->in_progress ?? 0),
            'requests_done_30d' => (int) ($requests->done_30d ?? 0),
            'tickets_open' => (int) ($tickets->open_count ?? 0),
            'tickets_urgent' => (int) ($tickets->urgent ?? 0),
        ];
    }

    /** Active tenant staff usable as request/ticket assignees (IT owners). */
    private function tenantUserOptions(int $tenantId): array
    {
        return HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->with('user:id,name')
            ->get()
            ->map(fn (HrEmployeeProfile $p) => [
                'id' => $p->user_id,
                'name' => $p->user?->name,
            ])
            ->filter(fn ($u) => $u['id'] && $u['name'])
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->all();
    }

    /** @param array<int, string> $allowed */
    private function cleanFilter(mixed $value, array $allowed): ?string
    {
        return in_array($value, $allowed, true) ? $value : null;
    }
}
