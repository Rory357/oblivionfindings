<?php

namespace App\Http\Controllers\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Notifications\ItProvisioningCancelledNotification;
use App\Domain\Hr\Services\OnboardingService;
use App\Domain\It\ItStaffDirectory;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Http\Requests\It\BulkProvisioningActionRequest;
use App\Http\Requests\It\StoreProvisioningRequestRequest;
use App\Http\Requests\It\UpdateSlaPoliciesRequest;
use App\Models\ItKbArticle;
use App\Models\ItProvisioningRequest;
use App\Models\ItSlaPolicy;
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
            'ticket_category' => $this->cleanFilter($request->query('ticket_category'), ItTicket::CATEGORIES),
            'sla' => $this->cleanFilter($request->query('sla'), ItTicket::SLA_STATES),
            'view' => $this->cleanFilter($request->query('view'), array_keys(self::TICKET_VIEWS)),
            'q' => trim((string) $request->query('q', '')) !== '' ? trim((string) $request->query('q')) : null,
            'from' => $this->cleanDate($request->query('from')),
            'to' => $this->cleanDate($request->query('to')),
            'sort' => $this->cleanFilter($request->query('sort'), ['reference', 'created', 'updated', 'priority', 'status']),
            'dir' => $this->cleanFilter($request->query('dir'), ['asc', 'desc']),
        ];

        $canManage = (bool) ($user && $user->canDo('it.manage'));
        $canEditSla = $canManage && $user->hasRole('admin');

        // Requesters get ONLY their own tickets — the agent queues, summary
        // and staff directory never reach a self-service payload.
        $agentProps = $isAgent ? [
            'requests' => $this->requestPage($tenantId, $filters),
            'tickets' => $this->ticketPage($tenantId, $filters, $user->id),
            'assignees' => $this->tenantUserOptions($tenantId),
            'employeeOptions' => $this->employeeOptions($tenantId),
            'assetOptions' => $this->assetOptions(),
            'filters' => $filters,
            // The effective SLA targets go to every agent — the Log & triage
            // wizard reads them for its live "resolution due …" preview. Only
            // editing them is admin-gated (can.edit_sla drives the editor button).
            'slaPolicies' => $this->slaPolicyGrid($tenantId),
            'overview' => $this->overview($tenantId),
            'kbArticles' => $this->kbArticles($tenantId),
        ] : [];

        return Inertia::render('it/index', [
            ...$agentProps,
            'myTickets' => $canRequest ? $this->myTicketRows($tenantId, $user->id) : [],
            // Requester KB browse (§I) — pure requesters only; agents browse the
            // full catalogue in their Knowledge tab.
            'kbPublished' => ($canRequest && ! $isAgent) ? $this->kbPublished($tenantId) : [],
            'summary' => $this->summary($tenantId, $user->id, $isAgent),
            'can' => [
                'view' => $isAgent,
                'manage' => $canManage,
                'request' => $canRequest,
                'edit_sla' => $canEditSla,
            ],
        ]);
    }

    /**
     * §N7: rewrite the tenant's SLA grid — one row per priority, values
     * become the stamping source for every ticket created (or re-triaged)
     * from here on. Existing tickets keep the targets they were promised.
     */
    public function updateSlaPolicies(UpdateSlaPoliciesRequest $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

        foreach (ItTicket::PRIORITIES as $priority) {
            ItSlaPolicy::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'priority' => $priority],
                [
                    'first_response_minutes' => (int) $request->validated("{$priority}.first_response_minutes"),
                    'resolution_minutes' => (int) $request->validated("{$priority}.resolution_minutes"),
                ],
            );
        }

        return redirect()->back()->with('success', 'SLA targets updated — new tickets pick them up immediately.');
    }

    /**
     * The effective SLA grid for the policy editor: tenant row when set,
     * §G default otherwise, flagged so the UI can say which is which.
     *
     * @return array<string, array{first_response_minutes: int, resolution_minutes: int, is_custom: bool}>
     */
    private function slaPolicyGrid(int $tenantId): array
    {
        $rows = ItSlaPolicy::query()
            ->where('tenant_id', $tenantId)
            ->get()
            ->keyBy('priority');

        $grid = [];
        foreach (ItTicket::PRIORITIES as $priority) {
            $row = $rows->get($priority);
            [$response, $resolution] = ItSlaPolicy::DEFAULTS[$priority];
            $grid[$priority] = [
                'first_response_minutes' => $row ? (int) $row->first_response_minutes : $response,
                'resolution_minutes' => $row ? (int) $row->resolution_minutes : $resolution,
                'is_custom' => (bool) $row,
            ];
        }

        return $grid;
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

        ItTicketEvent::record($provisioning, 'assigned', $user->id, [
            'to' => (int) $validated['assigned_to_user_id'],
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

                ItTicketEvent::record($provisioning, 'fulfilled', $user->id, array_filter([
                    'external_ref' => $validated['external_ref'] ?? null,
                ]));
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

        ItTicketEvent::record($provisioning, 'cancelled', $user->id, array_filter([
            'reason' => $reason,
        ], fn ($v) => $v !== null));

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

    /**
     * §H manual "New provisioning request" — the ad-hoc path agents raise
     * outside onboarding (a swapped device, a one-off access grant). Tenant
     * ownership of the employee profile and any assignee is asserted here,
     * mirroring assign/fulfil; a `created` event opens the activity trail.
     */
    public function storeProvisioning(StoreProvisioningRequestRequest $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $data = $request->validated();

        $profile = HrEmployeeProfile::query()->find((int) $data['employee_profile_id']);
        $this->assertHrTenantAccess($tenantId, $profile?->tenant_id);

        $assigneeId = ! empty($data['assigned_to_user_id']) ? (int) $data['assigned_to_user_id'] : null;
        if ($assigneeId) {
            $inOtherTenant = HrEmployeeProfile::query()
                ->where('user_id', $assigneeId)
                ->whereNotNull('tenant_id')
                ->where('tenant_id', '!=', $tenantId)
                ->exists();
            if ($inOtherTenant) {
                return redirect()->back()->with('error', 'That colleague is in a different organisation.');
            }
        }

        $provisioning = ItProvisioningRequest::query()->create([
            'tenant_id' => $tenantId,
            'employee_profile_id' => (int) $data['employee_profile_id'],
            'type' => $data['type'],
            'item' => $data['item'],
            'assigned_to_user_id' => $assigneeId,
            'status' => $assigneeId ? 'in_progress' : 'pending',
            'priority' => $data['priority'],
            'due_date' => $data['due_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $user->id,
        ]);

        ItTicketEvent::record($provisioning, 'created', $user->id, array_filter([
            'type' => $provisioning->type,
            'assigned_to_user_id' => $assigneeId,
        ]));

        return redirect()->back()->with('success', "Provisioning request raised — {$provisioning->item}.");
    }

    /**
     * Active tenant employee profiles for the manual-request employee picker.
     * Guarded so a pre-migration read serves an empty list.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function employeeOptions(int $tenantId): array
    {
        if (! Schema::hasTable('hr_employee_profiles')) {
            return [];
        }

        return HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->with('user:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (HrEmployeeProfile $p) => [
                'id' => $p->id,
                'name' => $p->user?->name ?? $p->position_title ?? "Employee #{$p->id}",
            ])
            ->all();
    }

    /**
     * §H one action over many provisioning requests: assign to an agent, or
     * fulfil. Foreign-tenant ids silently drop out of the tenant-scoped fetch;
     * settled requests (done/cancelled) are skipped rather than mutated — the
     * flash reports both as "unchanged". One event row per actual change with
     * `via=bulk`; fulfil completes each linked onboarding task through the same
     * bridge as the single route, in its own transaction so one blocked task
     * can't sink the batch.
     */
    public function bulkProvisioning(BulkProvisioningActionRequest $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $validated = $request->validated();
        $action = (string) $validated['action'];

        $assignee = null;
        if ($action === 'assign') {
            $assignee = User::query()->find((int) $validated['assigned_to_user_id']);
            // Same foreign-tenant guard as assign/storeProvisioning: reject a
            // recipient whose HR profile sits in a different organisation.
            $inOtherTenant = $assignee && HrEmployeeProfile::query()
                ->where('user_id', $assignee->id)
                ->whereNotNull('tenant_id')
                ->where('tenant_id', '!=', $tenantId)
                ->exists();
            if ($inOtherTenant) {
                return redirect()->back()->with('error', 'That colleague is in a different organisation.');
            }
        }

        $requests = ItProvisioningRequest::query()
            ->forTenant($tenantId)
            ->with('onboardingTask')
            ->whereIn('id', $validated['ids'])
            ->get();

        $updated = 0;
        $skipped = count($validated['ids']) - $requests->count();

        foreach ($requests as $provisioning) {
            $changed = match ($action) {
                'assign' => $this->bulkAssignProvisioning($provisioning, $assignee, $user),
                'fulfil' => $this->bulkFulfilProvisioning($provisioning, $user),
                default => false,
            };
            $changed ? $updated++ : $skipped++;
        }

        $label = $action === 'assign' ? 'assigned' : 'fulfilled';

        return redirect()->back()->with(
            'success',
            "{$updated} request(s) {$label}".($skipped > 0 ? " · {$skipped} unchanged" : '').'.',
        );
    }

    private function bulkAssignProvisioning(ItProvisioningRequest $provisioning, ?User $assignee, User $actor): bool
    {
        if (in_array($provisioning->status, ['done', 'cancelled'], true)) {
            return false; // settled requests keep their history
        }
        $newId = $assignee?->id;
        if ((int) $provisioning->assigned_to_user_id === (int) $newId) {
            return false;
        }

        $provisioning->update([
            'assigned_to_user_id' => $newId,
            'status' => $provisioning->status === 'pending' ? 'in_progress' : $provisioning->status,
        ]);

        ItTicketEvent::record($provisioning, 'assigned', $actor->id, [
            'to' => $newId,
            'via' => 'bulk',
        ]);

        return true;
    }

    private function bulkFulfilProvisioning(ItProvisioningRequest $provisioning, User $actor): bool
    {
        if (in_array($provisioning->status, ['done', 'cancelled'], true)) {
            return false;
        }

        try {
            DB::transaction(function () use ($provisioning, $actor) {
                $provisioning->update([
                    'status' => 'done',
                    'fulfilled_at' => now(),
                    'fulfilled_by' => $actor->id,
                ]);

                // Cross-loop: fulfilment completes the source onboarding task,
                // exactly as the single fulfil route does (dependency/rollup all fire).
                $task = $provisioning->onboardingTask;
                if ($task && $task->status !== 'completed') {
                    $this->onboardingService->completeTask($task, $actor->id, array_filter([
                        'signed_off_by' => $task->sign_off_required ? $actor->id : null,
                    ], fn ($v) => $v !== null));
                }

                ItTicketEvent::record($provisioning, 'fulfilled', $actor->id, ['via' => 'bulk']);
            });
        } catch (\LogicException) {
            return false; // a blocked task can't complete — leave the request untouched
        }

        return true;
    }

    /**
     * §H provisioning-queue CSV export — the current filtered view (status /
     * type / assignee), all matching rows (not just the page), tenant-scoped.
     * Any agent (it.view) can export what the queue shows them; every cell
     * goes through the base Controller's `putCsv()` so a formula-injection
     * payload in a user-controlled field (item, employee name, external ref)
     * can never execute on open.
     */
    public function exportProvisioning(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('it.view'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $status = $this->cleanFilter($request->query('status'), ItProvisioningRequest::STATUSES);
        $type = $this->cleanFilter($request->query('type'), ItProvisioningRequest::TYPES);
        $assignee = is_numeric($request->query('assignee')) ? (int) $request->query('assignee') : null;

        $rows = ItProvisioningRequest::query()
            ->forTenant($tenantId)
            ->with([
                'employeeProfile:id,user_id,position_title,position_role',
                'employeeProfile.user:id,name',
                'assignee:id,name',
            ])
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->when($type, fn ($q, $t) => $q->where('type', $t))
            ->when($assignee, fn ($q, $a) => $q->where('assigned_to_user_id', $a))
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'done' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->get();

        $tz = config('app.worker_timezone', 'Pacific/Auckland');
        $human = fn (?string $v) => $v ? ucfirst(str_replace('_', ' ', $v)) : '';
        $filename = 'provisioning-requests-'.now()->timezone($tz)->format('Y-m-d').'.csv';

        $headers = ['Employee', 'Role', 'Item', 'Type', 'Status', 'Priority', 'Assignee', 'Due date', 'Source', 'External ref', 'Raised', 'Fulfilled'];

        return response()->streamDownload(function () use ($rows, $headers, $tz, $human) {
            $out = fopen('php://output', 'w');
            $this->putCsv($out, $headers);
            foreach ($rows as $r) {
                $this->putCsv($out, [
                    $r->employeeProfile?->user?->name ?? 'Unknown',
                    $r->employeeProfile?->position_title ?? $r->employeeProfile?->position_role ?? '',
                    $r->item,
                    $human($r->type),
                    $human($r->status),
                    $human($r->priority),
                    $r->assignee?->name ?? '',
                    $r->due_date?->format('Y-m-d') ?? '',
                    $r->onboarding_task_id ? 'Onboarding' : 'Manual',
                    $r->external_ref ?? '',
                    $r->created_at?->timezone($tz)->format('Y-m-d H:i') ?? '',
                    $r->fulfilled_at?->timezone($tz)->format('Y-m-d H:i') ?? '',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
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
            // §N2 agent triage fields — dropped for self-service requesters below.
            'subcategory' => ['nullable', 'string', 'max:255'],
            // On-behalf-of: an agent logs a ticket for the person who actually
            // hit the problem; the receipt then goes to them, not the agent.
            'requester_user_id' => [
                'nullable', 'integer', 'exists:users,id',
                $this->rejectForeignTenantRecipient($tenantId),
            ],
            'assigned_to_user_id' => [
                'nullable', 'integer', 'exists:users,id',
                $this->rejectForeignTenantRecipient($tenantId),
            ],
            'asset_id' => ['nullable', 'integer', 'exists:assets,id'],
            'watchers' => ['nullable', 'array'],
            'watchers.*' => ['integer', 'exists:users,id'],
            // §H convert/link: an agent can raise a ticket straight off a
            // provisioning request (the new laptop arrived broken).
            'provisioning_request_id' => [
                'nullable', 'integer',
                Rule::exists('it_provisioning_requests', 'id')->where('tenant_id', $tenantId),
            ],
            ...$this->itAttachmentRules(),
        ]);

        // Triage fields are agent-only: a self-service requester cannot log on
        // behalf of someone else, pick an assignee, set a subcategory, link an
        // asset/provisioning request or add watchers, whatever the body says.
        $requesterId = $isAgent && ! empty($validated['requester_user_id'])
            ? (int) $validated['requester_user_id']
            : $user->id;
        $assigneeId = $isAgent ? ($validated['assigned_to_user_id'] ?? null) : null;
        $provisioningRequestId = $isAgent ? ($validated['provisioning_request_id'] ?? null) : null;
        $subcategory = $isAgent ? ($validated['subcategory'] ?? null) : null;
        $assetId = $isAgent ? ($validated['asset_id'] ?? null) : null;
        $watcherIds = $isAgent && ! empty($validated['watchers'])
            ? array_values(array_unique(array_map('intval', $validated['watchers'])))
            : [];

        $ticket = ItTicket::createWithReference([
            'tenant_id' => $tenantId,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'requester_user_id' => $requesterId,
            'assigned_to_user_id' => $assigneeId,
            'asset_id' => $assetId,
            'provisioning_request_id' => $provisioningRequestId,
            'category' => $validated['category'],
            'subcategory' => $subcategory,
            'priority' => $validated['priority'],
            'source' => $isAgent ? 'agent' : 'portal',
            'status' => $assigneeId ? 'in_progress' : 'open',
        ]);

        // Every ticket gets SLA targets from the tenant policy (or the §G
        // defaults) the moment it exists — the clock starts at creation.
        $ticket->stampSlaDueDates();
        $ticket->save();

        $this->storeItAttachments($ticket, $request->file('attachments'), $user);

        if ($watcherIds) {
            $ticket->watchers()->syncWithoutDetaching($watcherIds);
        }

        ItTicketEvent::record($ticket, 'created', $user->id, array_filter([
            'source' => $ticket->source,
            'assigned_to_user_id' => $assigneeId,
            'provisioning_request_id' => $provisioningRequestId,
            'on_behalf_of' => $requesterId !== $user->id ? $requesterId : null,
        ]));

        // Receipt to the REQUESTER — the actor when self-raised, the
        // on-behalf-of colleague when an agent logs it. Plus an urgent alert
        // to the agents working the queue — never to the actor themselves.
        $requester = $requesterId === $user->id ? $user : User::query()->find($requesterId);
        $requester?->notify(new TicketCreatedNotification($ticket, 'receipt'));
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

    /** Escaped LIKE across reference, title and requester name (§F2 search). */
    private function applyTicketSearch($query, string $term)
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $query->where(function ($w) use ($like) {
            $w->where('reference', 'like', $like)
                ->orWhere('title', 'like', $like)
                ->orWhereHas('requester', fn ($r) => $r->where('name', 'like', $like));
        });
    }

    /**
     * Column sort when a header asks for one; otherwise the triage order
     * the queue has always had (open first, urgent first, newest first).
     * Semantic columns sort by severity/progression, not alphabetically.
     */
    private function applyTicketSort($query, ?string $sort, ?string $dir): void
    {
        $direction = $dir === 'asc' ? 'asc' : 'desc';

        match ($sort) {
            'reference' => $query->orderBy('reference', $direction),
            'created' => $query->orderBy('created_at', $direction),
            'updated' => $query->orderBy('updated_at', $direction),
            'priority' => $query->orderByRaw("CASE priority WHEN 'low' THEN 0 WHEN 'normal' THEN 1 WHEN 'high' THEN 2 ELSE 3 END {$direction}"),
            'status' => $query->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'waiting' THEN 2 WHEN 'resolved' THEN 3 ELSE 4 END {$direction}"),
            default => $query
                ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'waiting' THEN 2 WHEN 'resolved' THEN 3 ELSE 4 END")
                ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
                ->orderByDesc('created_at'),
        };

        $query->orderByDesc('id'); // stable tiebreak for pagination
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
                'linkedTickets:id,provisioning_request_id,reference,status',
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
                // §H reciprocal link — the most recent ticket raised off this
                // request, plus a count so the chip can say "+2".
                'linked_ticket' => $r->linkedTickets->last()
                    ? ['id' => $r->linkedTickets->last()->id, 'reference' => $r->linkedTickets->last()->reference]
                    : null,
                'linked_ticket_count' => $r->linkedTickets->count(),
            ]);
    }

    /** @param array<string, mixed> $filters */
    private function ticketPage(int $tenantId, array $filters, int $userId)
    {
        if (! Schema::hasTable('it_tickets')) {
            return null;
        }

        $query = ItTicket::query()
            ->forTenant($tenantId)
            ->with(['requester:id,name', 'assignee:id,name'])
            ->when($filters['view'], fn ($q, $view) => $this->applyTicketView($q, $view, $userId))
            ->when($filters['q'], fn ($q, $term) => $this->applyTicketSearch($q, $term))
            ->when($filters['ticket_status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['ticket_priority'], fn ($q, $priority) => $q->where('priority', $priority))
            ->when($filters['ticket_category'], fn ($q, $category) => $q->where('category', $category))
            ->when($filters['sla'], fn ($q, $sla) => $q->where('sla_state', $sla))
            ->when($filters['assignee'], fn ($q, $assignee) => $q->where('assigned_to_user_id', $assignee))
            ->when($filters['from'], fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->whereDate('created_at', '<=', $to));

        $this->applyTicketSort($query, $filters['sort'] ?? null, $filters['dir'] ?? null);

        return $query
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
                // CSAT (§K): a resolved ticket invites a rating; once given we
                // show the score back. Every row here is the requester's own.
                'can_rate' => $t->status === 'resolved',
                'csat_score' => $t->csat_submitted_at ? (int) $t->csat_score : null,
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
                     SUM(status = 'closed') AS status_closed,
                     SUM(status IN ('resolved', 'closed') AND resolved_at >= ? AND sla_state = 'met') AS met_30d",
                    [$userId, now()->subDays(30), now()->subDays(7), now()->subDays(30)],
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
            // Of tickets settled in the last 30d, how many met their SLA target
            // (feeds the hero's compliance ring — 10b).
            'met_30d' => (int) ($tickets->met_30d ?? 0),
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

    /**
     * §F1 Overview payload (agents): the avg-first-response KPI plus the four
     * "needs attention" lanes — short, capped, tenant-scoped lists that each
     * deep-link into the filtered queue. The KPI *counts* come from the
     * summary; this adds the one metric the summary can't cheaply carry and
     * the lane contents. Guarded so a pre-migration read renders an empty board.
     *
     * @return array<string, mixed>
     */
    private function overview(int $tenantId): array
    {
        $empty = [
            'avg_first_response_mins' => null,
            'sla_lane' => [],
            'awaiting_lane' => [],
            'aging_lane' => [],
            'unassigned_by_priority' => ['urgent' => 0, 'high' => 0, 'normal' => 0, 'low' => 0],
            'recent_activity' => [],
        ];

        if (! Schema::hasTable('it_tickets')) {
            return $empty;
        }

        $base = fn () => ItTicket::query()
            ->forTenant($tenantId)
            ->with(['requester:id,name', 'assignee:id,name']);

        // Avg minutes from raise to first agent reply, over replies in the last 30d.
        $avg = ItTicket::query()
            ->forTenant($tenantId)
            ->whereNotNull('first_responded_at')
            ->where('first_responded_at', '>=', now()->subDays(30))
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, first_responded_at)) AS mins')
            ->value('mins');

        // SLA lane: open tickets at risk or breached, most urgent clock first.
        $slaLane = $base()
            ->whereIn('status', ItTicket::OPEN_STATUSES)
            ->whereIn('sla_state', ['at_risk', 'breached'])
            ->orderByRaw("CASE sla_state WHEN 'breached' THEN 0 ELSE 1 END")
            ->orderBy('resolution_due_at')
            ->limit(6)
            ->get()
            ->map(fn (ItTicket $t) => [
                'id' => $t->id,
                'reference' => $t->reference,
                'title' => $t->title,
                'priority' => $t->priority,
                'sla_state' => $t->sla_state,
                'resolution_due_at' => $t->resolution_due_at?->toIso8601String(),
                'assignee' => $t->assignee?->name,
            ]);

        // Awaiting agent reply: open, no first response yet, oldest first.
        $awaitingLane = $base()
            ->whereIn('status', ['open', 'in_progress'])
            ->whereNull('first_responded_at')
            ->orderBy('created_at')
            ->limit(6)
            ->get()
            ->map(fn (ItTicket $t) => [
                'id' => $t->id,
                'reference' => $t->reference,
                'title' => $t->title,
                'priority' => $t->priority,
                'requester' => $t->requester?->name ?? 'Unknown',
                'age' => $t->created_at?->diffForHumans(short: true),
            ]);

        // Aging: open longer than 7 days, oldest first.
        $agingLane = $base()
            ->whereIn('status', ItTicket::OPEN_STATUSES)
            ->where('created_at', '<', now()->subDays(7))
            ->orderBy('created_at')
            ->limit(6)
            ->get()
            ->map(fn (ItTicket $t) => [
                'id' => $t->id,
                'reference' => $t->reference,
                'title' => $t->title,
                'priority' => $t->priority,
                'assignee' => $t->assignee?->name,
                'age' => $t->created_at?->diffForHumans(short: true),
            ]);

        // Unassigned open tickets, split by priority (feeds the chip row).
        $byPriority = ItTicket::query()
            ->forTenant($tenantId)
            ->whereIn('status', ItTicket::OPEN_STATUSES)
            ->whereNull('assigned_to_user_id')
            ->selectRaw("SUM(priority = 'urgent') AS urgent, SUM(priority = 'high') AS high, SUM(priority = 'normal') AS normal, SUM(priority = 'low') AS low")
            ->first();

        return [
            'avg_first_response_mins' => $avg !== null ? (int) round((float) $avg) : null,
            'sla_lane' => $slaLane,
            'awaiting_lane' => $awaitingLane,
            'aging_lane' => $agingLane,
            'unassigned_by_priority' => [
                'urgent' => (int) ($byPriority->urgent ?? 0),
                'high' => (int) ($byPriority->high ?? 0),
                'normal' => (int) ($byPriority->normal ?? 0),
                'low' => (int) ($byPriority->low ?? 0),
            ],
            'recent_activity' => $this->recentActivity($tenantId),
        ];
    }

    /**
     * §F1 recent-activity feed — the latest ticket events across the tenant,
     * humanised on the client. Ticket subjects only (each row deep-links to a
     * ticket); provisioning events stay on their own request rows. `subject`
     * is loaded via the morph so a deleted ticket drops out rather than 500s.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentActivity(int $tenantId): array
    {
        if (! Schema::hasTable('it_ticket_events')) {
            return [];
        }

        return ItTicketEvent::query()
            ->where('tenant_id', $tenantId)
            ->where('subject_type', 'it_ticket')
            ->with(['actor:id,name', 'subject'])
            ->latest('created_at')
            ->limit(8)
            ->get()
            ->filter(fn (ItTicketEvent $e) => $e->subject !== null)
            ->map(fn (ItTicketEvent $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'payload' => $e->payload,
                'actor' => $e->actor?->name,
                'ticket_id' => $e->subject_id,
                'reference' => $e->subject?->reference,
                'at' => $e->created_at?->diffForHumans(short: true),
            ])
            ->values()
            ->all();
    }

    /**
     * §I knowledge-base articles for the agent Knowledge tab — the whole
     * catalogue (drafts included), newest-edited first. Guarded so a
     * pre-migration read renders an empty tab. Carries the body so the edit
     * modal prefills without a second fetch (KB volume is low).
     *
     * @return array<int, array<string, mixed>>
     */
    private function kbArticles(int $tenantId): array
    {
        if (! Schema::hasTable('it_kb_articles')) {
            return [];
        }

        return ItKbArticle::query()
            ->forTenant($tenantId)
            ->with('author:id,name')
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get()
            ->map(fn (ItKbArticle $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'slug' => $a->slug,
                'category' => $a->category,
                'status' => $a->status,
                'body' => $a->body,
                'views' => (int) $a->view_count,
                'helpful_yes' => (int) $a->helpful_yes,
                'helpful_no' => (int) $a->helpful_no,
                'helpful_percent' => $a->helpfulPercent(),
                'author' => $a->author?->name,
                'updated' => $a->updated_at?->diffForHumans(short: true),
            ])
            ->all();
    }

    /**
     * §I published knowledge-base articles for the requester browse tab —
     * published only, with the body so the reader renders without a fetch.
     * Guarded so a pre-migration read renders an empty browse.
     *
     * @return array<int, array<string, mixed>>
     */
    private function kbPublished(int $tenantId): array
    {
        if (! Schema::hasTable('it_kb_articles')) {
            return [];
        }

        return ItKbArticle::query()
            ->forTenant($tenantId)
            ->published()
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get()
            ->map(fn (ItKbArticle $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'category' => $a->category,
                'body' => $a->body,
                'views' => (int) $a->view_count,
                'helpful_yes' => (int) $a->helpful_yes,
                'helpful_no' => (int) $a->helpful_no,
                'helpful_percent' => $a->helpfulPercent(),
            ])
            ->all();
    }

    /** @param array<int, string> $allowed */
    private function cleanFilter(mixed $value, array $allowed): ?string
    {
        return in_array($value, $allowed, true) ? $value : null;
    }

    /** A strict, real Y-m-d date or null — the queue's date-range filters. */
    private function cleanDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)) {
            return null;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]) ? $value : null;
    }
}
