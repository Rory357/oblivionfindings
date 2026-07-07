<?php

namespace App\Http\Controllers\It;

use App\Domain\Hr\Notifications\ItProvisioningCancelledNotification;
use App\Domain\Hr\Services\OnboardingService;
use App\Domain\It\ItStaffDirectory;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Models\ItProvisioningRequest;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Notifications\It\TicketAssignedNotification;
use App\Notifications\It\TicketCreatedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
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
    use Concerns\BuildsItOptions, Concerns\StoresItAttachments, ResolvesHrTenant;

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
            'view' => $this->cleanFilter($request->query('view'), array_keys(self::TICKET_VIEWS)),
        ];

        // Requesters get ONLY their own tickets — the agent queues, summary
        // and staff directory never reach a self-service payload.
        $agentProps = $isAgent ? [
            'requests' => $this->requestPage($tenantId, $filters),
            'tickets' => $this->ticketPage($tenantId, $filters, $user->id),
            'assignees' => $this->tenantUserOptions($tenantId),
            'filters' => $filters,
        ] : [];

        return Inertia::render('it/index', [
            ...$agentProps,
            'myTickets' => $canRequest ? $this->myTicketRows($tenantId, $user->id) : [],
            'summary' => $this->summary($tenantId, $user->id, $isAgent),
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
            ...$this->itAttachmentRules(),
        ]);

        // Triage fields are agent-only: a self-service requester cannot pick
        // an assignee, whatever the request body says.
        $assigneeId = $isAgent ? ($validated['assigned_to_user_id'] ?? null) : null;

        $ticket = ItTicket::createWithReference([
            'tenant_id' => $tenantId,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'requester_user_id' => $user->id,
            'assigned_to_user_id' => $assigneeId,
            'category' => $validated['category'],
            'priority' => $validated['priority'],
            'source' => $isAgent ? 'agent' : 'portal',
            'status' => $assigneeId ? 'in_progress' : 'open',
        ]);

        // Every ticket gets SLA targets from the tenant policy (or the §G
        // defaults) the moment it exists — the clock starts at creation.
        $ticket->stampSlaDueDates();
        $ticket->save();

        $this->storeItAttachments($ticket, $request->file('attachments'), $user);

        ItTicketEvent::record($ticket, 'created', $user->id, array_filter([
            'source' => $ticket->source,
            'assigned_to_user_id' => $assigneeId,
        ]));

        // Receipt to the requester ("we've got it"), plus an urgent alert to
        // the agents working the queue — never to the actor themselves.
        $user->notify(new TicketCreatedNotification($ticket, 'receipt'));
        if ($ticket->priority === 'urgent') {
            $agents = ItStaffDirectory::agents($tenantId)
                ->reject(fn (User $agent) => $agent->id === $user->id);
            NotificationFacade::send($agents, new TicketCreatedNotification($ticket, 'urgent_alert'));
        }

        return redirect()->back()
            ->with('success', "Ticket logged — {$ticket->reference}.")
            ->with('it_ticket', ['id' => $ticket->id, 'reference' => $ticket->reference]);
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
            'category' => ['sometimes', Rule::in(ItTicket::CATEGORIES)],
            'subcategory' => ['sometimes', 'nullable', 'string', 'max:255'],
            'asset_id' => ['sometimes', 'nullable', 'integer', 'exists:assets,id'],
            'assigned_to_user_id' => [
                'sometimes', 'nullable', 'integer', 'exists:users,id',
                $this->rejectForeignTenantRecipient($tenantId),
            ],
        ]);

        $original = $ticket->only(['status', 'priority', 'assigned_to_user_id']);
        $update = $validated;

        if (array_key_exists('status', $validated)) {
            $update['resolved_at'] = $validated['status'] === 'resolved'
                ? ($ticket->resolved_at ?? now())
                : null;

            // The waiting clock: entering pauses the SLA, leaving banks the
            // paused minutes (ItTicket::startWaiting/stopWaiting mutate the
            // model; drop status from the mass-update so they own it).
            if ($validated['status'] === 'waiting' && $original['status'] !== 'waiting') {
                $ticket->startWaiting();
                unset($update['status']);
            } elseif ($original['status'] === 'waiting' && $validated['status'] !== 'waiting') {
                $ticket->stopWaiting($validated['status']);
                unset($update['status']);
            }
        }

        $ticket->fill($update);
        $ticket->save();
        $ticket->refresh();

        // Activity trail + assignee notification, once per actual change.
        if ($ticket->status !== $original['status']) {
            ItTicketEvent::record($ticket, 'status_changed', $user->id, [
                'from' => $original['status'],
                'to' => $ticket->status,
            ]);
        }
        if ($ticket->priority !== $original['priority']) {
            // Re-target the SLA clock for the new priority (same anchor).
            $ticket->stampSlaDueDates();
            $ticket->save();

            ItTicketEvent::record($ticket, 'priority_changed', $user->id, [
                'from' => $original['priority'],
                'to' => $ticket->priority,
            ]);
        }
        if ((int) $ticket->assigned_to_user_id !== (int) $original['assigned_to_user_id']) {
            ItTicketEvent::record($ticket, 'assigned', $user->id, [
                'from' => $original['assigned_to_user_id'],
                'to' => $ticket->assigned_to_user_id,
            ]);
            if ($ticket->assignee && $ticket->assigned_to_user_id !== $user->id) {
                $ticket->assignee->notify(new TicketAssignedNotification($ticket));
            }
        }

        return redirect()->back()->with('success', 'Ticket updated.');
    }

    public function resolveTicket(\App\Http\Requests\It\ResolveTicketRequest $request, ItTicket $ticket)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $ticket->tenant_id);

        if (in_array($ticket->status, ['resolved', 'closed'], true)) {
            return redirect()->back()->with('error', 'This ticket is already resolved.');
        }

        // The resolution note is the final PUBLIC reply — "what fixed it"
        // always lands on the record, visible to the requester.
        $ticket->comments()->create([
            'tenant_id' => $ticket->tenant_id,
            'author_user_id' => $user->id,
            'body' => $request->validated('note'),
            'is_internal' => false,
        ]);

        if ($ticket->status === 'waiting') {
            $ticket->stopWaiting('resolved');
        }
        $ticket->status = 'resolved';
        $ticket->resolved_at = now();
        if (! $ticket->first_responded_at) {
            $ticket->first_responded_at = now();
        }
        // Inside the resolution target → the SLA is met. (Due dates are
        // stamped by the SLA engine; without one there is nothing to meet.)
        if ($ticket->resolution_due_at && now()->lte($ticket->resolution_due_at->copy()->addMinutes((int) $ticket->sla_paused_minutes))) {
            $ticket->sla_state = 'met';
        }
        $ticket->save();

        ItTicketEvent::record($ticket, 'resolved', $user->id);

        // Requester hears (unless they resolved it themselves, or the agent
        // untoggled it); watchers always hear — minus the actor.
        if ($request->boolean('notify_requester', true)
            && $ticket->requester
            && $ticket->requester_user_id !== $user->id) {
            $ticket->requester->notify(new \App\Notifications\It\TicketResolvedNotification($ticket, 'requester'));
        }
        $watchers = $ticket->watchers()->get()->reject(fn (User $w) => $w->id === $user->id);
        NotificationFacade::send($watchers, new \App\Notifications\It\TicketResolvedNotification($ticket, 'watcher'));

        return redirect()->back()->with('success', "Resolved {$ticket->reference} — the requester can see the fix.");
    }

    /* ================================================================== */
    /*  Payload builders */
    /* ================================================================== */

    /**
     * Saved views for the tickets queue — server-side where clauses keyed by
     * the `view` query param. "awaiting_reply" is a v1 proxy (no agent
     * response yet); it sharpens once the thread lands.
     *
     * @var array<string, string>
     */
    private const TICKET_VIEWS = [
        'all_open' => 'All open',
        'unassigned' => 'Unassigned',
        'mine' => 'Mine',
        'breaching' => 'Breaching soon',
        'breached' => 'Breached',
        'awaiting_reply' => 'Awaiting reply',
        'waiting' => 'Waiting on requester',
        'recently_resolved' => 'Recently resolved',
    ];

    /** Apply one saved view's constraints to a tickets query. */
    private function applyTicketView($query, string $view, int $userId)
    {
        return match ($view) {
            'all_open' => $query->whereIn('status', ItTicket::OPEN_STATUSES),
            'unassigned' => $query->whereIn('status', ItTicket::OPEN_STATUSES)->whereNull('assigned_to_user_id'),
            'mine' => $query->whereIn('status', ItTicket::OPEN_STATUSES)->where('assigned_to_user_id', $userId),
            'breaching' => $query->whereIn('status', ItTicket::OPEN_STATUSES)->where('sla_state', 'at_risk'),
            'breached' => $query->whereIn('status', ItTicket::OPEN_STATUSES)->where('sla_state', 'breached'),
            'awaiting_reply' => $query->whereIn('status', ['open', 'in_progress'])->whereNull('first_responded_at'),
            'waiting' => $query->where('status', 'waiting'),
            'recently_resolved' => $query->whereIn('status', ['resolved', 'closed'])
                ->where('resolved_at', '>=', now()->subDays(7)),
            default => $query,
        };
    }

    /** @param array<string, mixed> $filters */
    private function requestPage(int $tenantId, array $filters)
    {
        // House rule: guard new-table reads so a request racing the deploy's
        // migration step renders an empty queue instead of a 500.
        if (! Schema::hasTable('it_provisioning_requests')) {
            return null;
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
            ->paginate(15, ['*'], 'requests_page')
            ->withQueryString()
            ->through(fn (ItProvisioningRequest $r) => [
                'id' => $r->id,
                'employee' => [
                    'name' => $r->employeeProfile?->user?->name ?? 'Unknown',
                    'role' => $r->employeeProfile?->position_title ?? $r->employeeProfile?->position_role,
                ],
                'item' => $r->item,
                'type' => $r->type,
                'status' => $r->status,
                'priority' => $r->priority,
                'due_date' => $r->due_date?->toDateString(),
                'assignee' => $r->assignee ? ['id' => $r->assignee->id, 'name' => $r->assignee->name] : null,
                'external_ref' => $r->external_ref,
                'notes' => $r->notes,
                'from_onboarding' => $r->onboarding_task_id !== null,
                'sign_off_required' => (bool) ($r->onboardingTask?->sign_off_required ?? false),
                'created' => $r->created_at?->diffForHumans(short: true),
                'fulfilled' => $r->fulfilled_at?->diffForHumans(short: true),
            ]);
    }

    /** @param array<string, mixed> $filters */
    private function ticketPage(int $tenantId, array $filters, int $userId)
    {
        if (! Schema::hasTable('it_tickets')) {
            return null;
        }

        return ItTicket::query()
            ->forTenant($tenantId)
            ->with(['requester:id,name', 'assignee:id,name'])
            ->when($filters['view'], fn ($q, $view) => $this->applyTicketView($q, $view, $userId))
            ->when($filters['ticket_status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['ticket_priority'], fn ($q, $priority) => $q->where('priority', $priority))
            ->when($filters['assignee'], fn ($q, $assignee) => $q->where('assigned_to_user_id', $assignee))
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'waiting' THEN 2 WHEN 'resolved' THEN 3 ELSE 4 END")
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->paginate(15, ['*'], 'tickets_page')
            ->withQueryString()
            ->through(fn (ItTicket $t) => [
                'id' => $t->id,
                'reference' => $t->reference,
                'title' => $t->title,
                'description' => $t->description,
                'category' => $t->category,
                'priority' => $t->priority,
                'status' => $t->status,
                'sla_state' => $t->sla_state,
                'first_response_due_at' => $t->first_response_due_at?->toIso8601String(),
                'resolution_due_at' => $t->resolution_due_at?->toIso8601String(),
                'first_responded_at' => $t->first_responded_at?->toIso8601String(),
                'requester' => $t->requester?->name ?? 'Unknown',
                'assignee' => $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name] : null,
                'age' => $t->created_at?->diffForHumans(short: true),
                'updated' => $t->updated_at?->diffForHumans(short: true),
                'resolved' => $t->resolved_at?->diffForHumans(short: true),
            ]);
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
                'reference' => $t->reference,
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

    /**
     * The server summary that feeds the hero, tab badges and saved-view
     * chips. Computed over ALL rows (never the current page); requesters get
     * only their own `my` section — an agent-shaped summary must never reach
     * a self-service payload.
     *
     * @return array<string, mixed>
     */
    private function summary(int $tenantId, int $userId, bool $isAgent): array
    {
        $ticketsReady = Schema::hasTable('it_tickets');
        $requestsReady = Schema::hasTable('it_provisioning_requests');

        $my = $ticketsReady
            ? ItTicket::query()
                ->forTenant($tenantId)
                ->where('requester_user_id', $userId)
                ->selectRaw(
                    "SUM(status IN ('open', 'in_progress', 'waiting')) AS open_count,
                     SUM(status = 'waiting') AS waiting,
                     SUM(status IN ('resolved', 'closed') AND resolved_at >= ?) AS resolved_30d",
                    [now()->subDays(30)],
                )
                ->first()
            : null;

        $summary = [
            'my' => [
                'open' => (int) ($my->open_count ?? 0),
                'waiting' => (int) ($my->waiting ?? 0),
                'resolved_30d' => (int) ($my->resolved_30d ?? 0),
            ],
        ];

        if (! $isAgent) {
            return $summary;
        }

        $tickets = $ticketsReady
            ? ItTicket::query()
                ->forTenant($tenantId)
                ->selectRaw(
                    "SUM(status IN ('open', 'in_progress', 'waiting')) AS open_count,
                     SUM(status IN ('open', 'in_progress', 'waiting') AND assigned_to_user_id IS NULL) AS unassigned,
                     SUM(status IN ('open', 'in_progress', 'waiting') AND assigned_to_user_id IS NULL AND priority = 'urgent') AS urgent_unassigned,
                     SUM(status IN ('open', 'in_progress', 'waiting') AND priority = 'urgent') AS urgent_open,
                     SUM(status IN ('open', 'in_progress', 'waiting') AND sla_state = 'at_risk') AS at_risk,
                     SUM(status IN ('open', 'in_progress', 'waiting') AND sla_state = 'breached') AS breached,
                     SUM(status IN ('open', 'in_progress') AND first_responded_at IS NULL) AS awaiting_reply,
                     SUM(status = 'waiting') AS waiting,
                     SUM(status IN ('open', 'in_progress', 'waiting') AND assigned_to_user_id = ?) AS mine,
                     SUM(status IN ('resolved', 'closed') AND resolved_at >= ?) AS resolved_30d,
                     SUM(status IN ('resolved', 'closed') AND resolved_at >= ?) AS recently_resolved,
                     SUM(status = 'open') AS status_open,
                     SUM(status = 'in_progress') AS status_in_progress,
                     SUM(status = 'resolved') AS status_resolved,
                     SUM(status = 'closed') AS status_closed",
                    [$userId, now()->subDays(30), now()->subDays(7)],
                )
                ->first()
            : null;

        $requests = $requestsReady
            ? ItProvisioningRequest::query()
                ->forTenant($tenantId)
                ->selectRaw(
                    "SUM(status = 'pending') AS pending,
                     SUM(status = 'in_progress') AS in_progress,
                     SUM(status = 'done' AND fulfilled_at >= ?) AS done_30d,
                     SUM(status IN ('pending', 'in_progress') AND due_date IS NOT NULL AND due_date < ?) AS overdue,
                     SUM(status = 'pending' AND created_at < ?) AS pending_over_7d",
                    [now()->subDays(30), now()->toDateString(), now()->subDays(7)],
                )
                ->first()
            : null;

        $summary['tickets'] = [
            'open' => (int) ($tickets->open_count ?? 0),
            'unassigned' => (int) ($tickets->unassigned ?? 0),
            'urgent_unassigned' => (int) ($tickets->urgent_unassigned ?? 0),
            'urgent_open' => (int) ($tickets->urgent_open ?? 0),
            'at_risk' => (int) ($tickets->at_risk ?? 0),
            'breached' => (int) ($tickets->breached ?? 0),
            'awaiting_reply' => (int) ($tickets->awaiting_reply ?? 0),
            'waiting' => (int) ($tickets->waiting ?? 0),
            'resolved_30d' => (int) ($tickets->resolved_30d ?? 0),
            'by_status' => [
                'open' => (int) ($tickets->status_open ?? 0),
                'in_progress' => (int) ($tickets->status_in_progress ?? 0),
                'waiting' => (int) ($tickets->waiting ?? 0),
                'resolved' => (int) ($tickets->status_resolved ?? 0),
                'closed' => (int) ($tickets->status_closed ?? 0),
            ],
            'views' => [
                'all_open' => (int) ($tickets->open_count ?? 0),
                'unassigned' => (int) ($tickets->unassigned ?? 0),
                'mine' => (int) ($tickets->mine ?? 0),
                'breaching' => (int) ($tickets->at_risk ?? 0),
                'breached' => (int) ($tickets->breached ?? 0),
                'awaiting_reply' => (int) ($tickets->awaiting_reply ?? 0),
                'waiting' => (int) ($tickets->waiting ?? 0),
                'recently_resolved' => (int) ($tickets->recently_resolved ?? 0),
            ],
        ];

        $summary['provisioning'] = [
            'pending' => (int) ($requests->pending ?? 0),
            'in_progress' => (int) ($requests->in_progress ?? 0),
            'done_30d' => (int) ($requests->done_30d ?? 0),
            'overdue' => (int) ($requests->overdue ?? 0),
            'pending_over_7d' => (int) ($requests->pending_over_7d ?? 0),
        ];

        return $summary;
    }

    /** @param array<int, string> $allowed */
    private function cleanFilter(mixed $value, array $allowed): ?string
    {
        return in_array($value, $allowed, true) ? $value : null;
    }

}
