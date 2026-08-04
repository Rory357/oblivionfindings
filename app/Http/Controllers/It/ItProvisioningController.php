<?php

namespace App\Http\Controllers\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Notifications\ItProvisioningCancelledNotification;
use App\Domain\It\ItStaffDirectory;
use App\Domain\It\Presenters\ItTicketRoutingPresenter;
use App\Domain\It\Services\ItCatalogFieldOptionService;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Domain\It\Services\ItLinkedContextOptions;
use App\Domain\It\Services\ItProvisioningAccessService;
use App\Domain\It\Services\ItProvisioningRequestLifecycleService;
use App\Domain\It\Services\ItTicketIntakeService;
use App\Domain\It\Services\ItTicketInteractionService;
use App\Domain\It\Services\ItTicketTriageService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Domain\It\Services\ItWorkTransitionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\It\ApproveProvisioningRequestRequest;
use App\Http\Requests\It\AssignProvisioningRequestRequest;
use App\Http\Requests\It\BulkProvisioningActionRequest;
use App\Http\Requests\It\CancelProvisioningRequestRequest;
use App\Http\Requests\It\FailProvisioningRequestRequest;
use App\Http\Requests\It\FulfilProvisioningRequestRequest;
use App\Http\Requests\It\ResolveTicketRequest;
use App\Http\Requests\It\StoreItTicketRequest;
use App\Http\Requests\It\StoreProvisioningRequestRequest;
use App\Http\Requests\It\UpdateSlaPoliciesRequest;
use App\Http\Requests\It\UpdateTicketRequest;
use App\Models\ItCatalogItem;
use App\Models\ItKbArticle;
use App\Models\ItProvisioningRequest;
use App\Models\ItProvisioningWorkflow;
use App\Models\ItService;
use App\Models\ItSlaPolicy;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\Site;
use App\Models\User;
use App\Notifications\It\TicketCreatedNotification;
use App\Notifications\It\TicketResolvedNotification;
use App\Support\It\BusinessHours;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

/**
 * IT & Provisioning hub (/it): the onboarding-driven provisioning queue
 * (accounts / access / equipment for new hires) plus the general helpdesk
 * ticket queue. Replaces the design-preview wireframe — see
 * docs/IT_PROVISIONING_WIREFRAME.md.
 */
class ItProvisioningController extends Controller
{
    use Concerns\BuildsItOptions;

    public function __construct(
        private readonly ItWorkTransitionService $transitionService,
        private readonly ItTicketIntakeService $ticketIntake,
        private readonly ItTicketInteractionService $ticketInteractions,
        private readonly ItTicketTriageService $triageService,
        private readonly ItProvisioningRequestLifecycleService $provisioningLifecycle,
        private readonly ItEmailDeliveryService $emailDeliveries,
        private readonly ItWorkAccessService $workAccess,
        private readonly ItProvisioningAccessService $provisioningAccess,
        private readonly ItCatalogFieldOptionService $catalogFieldOptions,
        private readonly ItLinkedContextOptions $linkedContextOptions,
        private readonly ItTicketRoutingPresenter $routingPresenter,
    ) {}

    /* ================================================================== */
    /*  Hub */
    /* ================================================================== */

    public function index(Request $request)
    {
        $user = $request->user();
        $isAgent = $user && ($user->canDo('it.view') || $user->canDo('it.manage'));
        $canRequest = $user && $user->canDo('it.request');
        abort_unless($isAgent || $canRequest, 403);

        $filters = [
            'status' => $this->cleanFilter($request->query('status'), ItProvisioningRequest::STATUSES),
            'type' => $this->cleanFilter($request->query('type'), ItProvisioningRequest::TYPES),
            'assignee' => is_numeric($request->query('assignee')) ? (int) $request->query('assignee') : null,
            'ticket_status' => $this->cleanFilter($request->query('ticket_status'), ItTicket::STATUSES),
            'ticket_priority' => $this->cleanFilter($request->query('ticket_priority'), ItTicket::PRIORITIES),
            'ticket_category' => $this->cleanFilter($request->query('ticket_category'), ItTicket::CATEGORIES),
            'source' => $this->cleanFilter($request->query('source'), ItTicket::SOURCES),
            'work_type' => $this->cleanFilter($request->query('work_type'), ItTicket::WORK_TYPES),
            'service' => is_numeric($request->query('service')) ? (int) $request->query('service') : null,
            'age' => $this->cleanFilter($request->query('age'), ['under_2', '2_7', '8_30', 'over_30']),
            'missing' => $this->cleanFilter($request->query('missing'), ['service', 'queue', 'team', 'assignee']),
            'reopened' => $request->boolean('reopened'),
            'first_contact' => $request->boolean('first_contact'),
            'open_only' => $request->boolean('open_only'),
            'device_linked' => $request->boolean('device_linked'),
            'resolved_from' => $this->cleanDate($request->query('resolved_from')),
            'resolved_to' => $this->cleanDate($request->query('resolved_to')),
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
            'requests' => $this->requestPage($filters, $user),
            'provisioningWorkflows' => $this->provisioningWorkflows($user),
            'tickets' => $this->ticketPage($filters, $user),
            'assignees' => $this->staffUserOptions($user),
            'employeeOptions' => $this->employeeOptions($user),
            'assetOptions' => $this->assetOptions($user),
            'siteOptions' => $this->linkedContextOptions->sites($user),
            'deviceOptions' => $this->linkedContextOptions->devices($user),
            'serviceOptions' => $this->linkedContextOptions->services(),
            'filters' => $filters,
            // The effective SLA targets go to every agent — the Log & triage
            // wizard reads them for its live "resolution due …" preview. Only
            // editing them is admin-gated (can.edit_sla drives the editor button).
            'slaPolicies' => $this->slaPolicyGrid(),
            'slaCalendar' => $this->slaCalendar(),
            'overview' => $this->overview($user),
            'kbArticles' => $this->kbArticles(),
            'kbOptions' => [
                'owners' => ItStaffDirectory::agentsForSharedSites($user)
                    ->sortBy('name')
                    ->map(fn (User $agent) => ['id' => $agent->id, 'name' => $agent->name])
                    ->values(),
                'sites' => Site::query()
                    ->whereIn('id', $this->workAccess->approvedSiteIds($user))
                    ->where('is_active', true)
                    ->where('archived', false)
                    ->orderBy('name')
                    ->get(['id', 'name']),
                'services' => ItService::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name']),
            ],
        ] : [];

        $catalogItems = $canRequest ? ItCatalogItem::query()
            ->published()
            ->when(! $canManage, fn ($query) => $query->where('internal_only', false))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ItCatalogItem $item) => $item->discoveryPayload($canManage))
            ->values() : collect();
        $catalogEntityTypes = $catalogItems
            ->flatMap(fn (array $item) => collect($item['form_schema']['fields'] ?? [])->pluck('type'))
            ->filter(fn (mixed $type): bool => in_array($type, ItCatalogFieldOptionService::TYPES, true))
            ->unique()
            ->values()
            ->all();

        return Inertia::render('it/index', [
            ...$agentProps,
            'myTickets' => $canRequest ? $this->myTicketRows($user) : [],
            'catalogItems' => $catalogItems->all(),
            'catalogFieldOptions' => $canRequest
                ? $this->catalogFieldOptions->forTypes($user, $catalogEntityTypes)
                : ['employee' => [], 'user' => [], 'asset' => []],
            // Requester KB browse (§I) — pure requesters only; agents browse the
            // full catalogue in their Knowledge tab.
            'kbPublished' => ($canRequest && ! $isAgent) ? $this->kbPublished($user) : [],
            'summary' => $this->summary($user, $isAgent),
            'can' => [
                'view' => $isAgent,
                'manage' => $canManage,
                'request' => $canRequest,
                'edit_sla' => $canEditSla,
            ],
        ]);
    }

    /**
     * §N7: rewrite the application's SLA grid — one row per priority, values
     * become the stamping source for every ticket created (or re-triaged)
     * from here on. Existing tickets keep the targets they were promised.
     */
    public function updateSlaPolicies(UpdateSlaPoliciesRequest $request)
    {
        // Build the application-wide calendar once (null = 24/7) and stamp it onto
        // every priority row — "apply to all policies".
        [$businessHours, $holidayDates] = $this->calendarFromRequest($request);

        foreach (ItTicket::PRIORITIES as $priority) {
            ItSlaPolicy::query()->updateOrCreate(
                ['priority' => $priority],
                [
                    'first_response_minutes' => (int) $request->validated("{$priority}.first_response_minutes"),
                    'resolution_minutes' => (int) $request->validated("{$priority}.resolution_minutes"),
                    'business_hours' => $businessHours,
                    'holiday_dates' => $holidayDates,
                ],
            );
        }

        return redirect()->back()->with('success', 'SLA targets updated — new tickets pick them up immediately.');
    }

    /**
     * Turn the editor's calendar fields into a [business_hours, holiday_dates]
     * pair — a per-weekday window map + holiday list — or [null, null] for the
     * 24/7 clock.
     *
     * @return array{0: array<string, array<int, array{0: string, 1: string}>>|null, 1: array<int, string>|null}
     */
    private function calendarFromRequest(UpdateSlaPoliciesRequest $request): array
    {
        if (! $request->boolean('business_hours_enabled')) {
            return [null, null];
        }

        $window = [[(string) $request->validated('open_time'), (string) $request->validated('close_time')]];
        $days = (array) $request->validated('working_days');
        $businessHours = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
            $businessHours[$day] = in_array($day, $days, true) ? $window : [];
        }

        $holidayDates = array_values(array_unique(array_filter(
            (array) ($request->validated('holiday_dates') ?? []),
        )));

        return [$businessHours, $holidayDates];
    }

    /**
     * The effective SLA grid for the policy editor: application row when set,
     * §G default otherwise, flagged so the UI can say which is which.
     *
     * @return array<string, array{first_response_minutes: int, resolution_minutes: int, is_custom: bool}>
     */
    private function slaPolicyGrid(): array
    {
        $rows = ItSlaPolicy::query()
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

    /**
     * The current application calendar for the editor, flattened to the single-
     * window/working-days view the UI edits. Falls back to disabled (Mon–Fri
     * 08:00–17:00 presets) when the application runs 24/7.
     *
     * @return array{enabled: bool, open_time: string, close_time: string, working_days: array<int, string>, holiday_dates: array<int, string>}
     */
    private function slaCalendar(): array
    {
        $row = ItSlaPolicy::query()
            ->whereNotNull('business_hours')
            ->first();

        $fallback = [
            'enabled' => false,
            'open_time' => '08:00',
            'close_time' => '17:00',
            'working_days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
            'holiday_dates' => [],
        ];

        if (! $row || ! BusinessHours::hasWindows(['business_hours' => $row->business_hours])) {
            return $fallback;
        }

        $hours = $row->business_hours;
        $workingDays = [];
        $open = $fallback['open_time'];
        $close = $fallback['close_time'];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
            $windows = $hours[$day] ?? [];
            if (! empty($windows) && isset($windows[0][0], $windows[0][1])) {
                $workingDays[] = $day;
                $open = (string) $windows[0][0];
                $close = (string) $windows[0][1];
            }
        }

        return [
            'enabled' => true,
            'open_time' => $open,
            'close_time' => $close,
            'working_days' => $workingDays,
            'holiday_dates' => array_values((array) ($row->holiday_dates ?? [])),
        ];
    }

    /* ================================================================== */
    /*  Provisioning requests */
    /* ================================================================== */

    public function assign(AssignProvisioningRequestRequest $request, ItProvisioningRequest $provisioning)
    {
        $user = $request->user();
        abort_unless($this->provisioningAccess->canManage($user, $provisioning), 404);

        $validated = $request->validated();
        $assignee = User::query()->whereNotNull('approved_at')->find((int) $validated['assigned_to_user_id']);
        abort_unless($assignee, 403);

        try {
            $changed = $this->provisioningLifecycle->assign($provisioning, $user, $assignee);
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', $changed ? 'Request assigned.' : 'Request already assigned.');
    }

    public function fulfil(FulfilProvisioningRequestRequest $request, ItProvisioningRequest $provisioning)
    {
        $user = $request->user();
        abort_unless($this->provisioningAccess->canManage($user, $provisioning), 404);

        $validated = $request->validated();

        try {
            $this->provisioningLifecycle->fulfil($provisioning, $user, $validated);
        } catch (DomainException|\LogicException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', "Fulfilled “{$provisioning->item}”.");
    }

    public function approve(ApproveProvisioningRequestRequest $request, ItProvisioningRequest $provisioning)
    {
        $user = $request->user();
        abort_unless($this->provisioningAccess->canManage($user, $provisioning), 404);
        $validated = $request->validated();

        try {
            $this->provisioningLifecycle->approve(
                $provisioning,
                $user,
                $validated['decision_note'] ?? null,
            );
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Provisioning request approved.');
    }

    public function fail(FailProvisioningRequestRequest $request, ItProvisioningRequest $provisioning)
    {
        $user = $request->user();
        abort_unless($this->provisioningAccess->canManage($user, $provisioning), 404);
        $validated = $request->validated();

        try {
            $this->provisioningLifecycle->fail($provisioning, $user, $validated['failure_reason']);
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Failure recorded; the workflow now shows partial completion.');
    }

    public function cancel(CancelProvisioningRequestRequest $request, ItProvisioningRequest $provisioning)
    {
        $user = $request->user();
        abort_unless($this->provisioningAccess->canManage($user, $provisioning), 404);

        $validated = $request->validated();
        $reason = trim((string) $validated['reason']);

        try {
            $provisioning = $this->provisioningLifecycle->cancel($provisioning, $user, $reason);
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        // The lifecycle commits the cancellation and canonical HR task note
        // together. Notification delivery is tracked separately and remains
        // retryable from the operations workspace if the provider fails.
        if ($provisioning->onboarding_task_id) {
            try {
                $task = $provisioning->onboardingTask()->with('checklist.employeeProfile.user:id,name')->first();
                if ($task && $task->status !== 'completed') {
                    $creator = $task->checklist?->created_by
                        ? User::find($task->checklist->created_by)
                        : null;
                    if ($creator) {
                        $this->emailDeliveries->send(
                            $creator,
                            new ItProvisioningCancelledNotification($provisioning, $task, $reason),
                        );
                    }
                }
            } catch (\Throwable $exception) {
                Log::warning('Failed to notify onboarding owner after IT request cancellation', [
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
     * outside onboarding (a swapped device, a one-off access grant). Canonical
     * Site access, state, event and audit ownership live in the shared
     * provisioning lifecycle rather than this transport controller.
     */
    public function storeProvisioning(StoreProvisioningRequestRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();

        $profile = HrEmployeeProfile::query()->findOrFail((int) $data['employee_profile_id']);

        $assigneeId = ! empty($data['assigned_to_user_id']) ? (int) $data['assigned_to_user_id'] : null;
        $assignee = $assigneeId
            ? User::query()->findOrFail($assigneeId)
            : null;

        $provisioning = $this->provisioningLifecycle->createManual(
            $user,
            $profile,
            $assignee,
            $data,
        );

        return redirect()->back()->with('success', "Provisioning request raised — {$provisioning->item}.");
    }

    /**
     * Active employee profiles within the viewer's approved Site scope.
     * Guarded so a pre-migration read serves an empty list.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function employeeOptions(User $user): array
    {
        if (! Schema::hasTable('hr_employee_profiles')) {
            return [];
        }

        return $this->provisioningAccess->selectableProfiles($user)
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
     * fulfil. Inaccessible ids silently drop out of the canonical scoped fetch;
     * settled requests (done/cancelled) are skipped rather than mutated — the
     * flash reports both as "unchanged". One event row per actual change with
     * `via=bulk`; fulfil completes each linked onboarding task through the same
     * bridge as the single route, in its own transaction so one blocked task
     * can't sink the batch.
     */
    public function bulkProvisioning(BulkProvisioningActionRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();
        $action = (string) $validated['action'];

        $assignee = null;
        if ($action === 'assign') {
            $assignee = User::query()->whereNotNull('approved_at')->find((int) $validated['assigned_to_user_id']);
            abort_unless($assignee && ItStaffDirectory::agents()->contains('id', $assignee->id), 403);
        }

        $requests = $this->provisioningAccess->applyRequestScope(ItProvisioningRequest::query(), $user)
            ->whereIn('id', $validated['ids'])
            ->get();

        $updated = 0;
        $skipped = count($validated['ids']) - $requests->count();

        foreach ($requests as $provisioning) {
            try {
                $changed = match ($action) {
                    'assign' => $assignee
                        ? $this->provisioningLifecycle->assign($provisioning, $user, $assignee, 'bulk')
                        : false,
                    'fulfil' => (bool) $this->provisioningLifecycle->fulfil($provisioning, $user),
                    default => false,
                };
            } catch (DomainException|\LogicException) {
                $changed = false;
            }
            $changed ? $updated++ : $skipped++;
        }

        $label = $action === 'assign' ? 'assigned' : 'fulfilled';

        return redirect()->back()->with(
            'success',
            "{$updated} request(s) {$label}".($skipped > 0 ? " · {$skipped} unchanged" : '').'.',
        );
    }

    /**
     * §H provisioning-queue CSV export — the current filtered view (status /
     * type / assignee), all matching rows (not just the page), Site-scoped.
     * Any agent (it.view) can export what the queue shows them; every cell
     * goes through the base Controller's `putCsv()` so a formula-injection
     * payload in a user-controlled field (item, employee name, external ref)
     * can never execute on open.
     */
    public function exportProvisioning(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('it.view'), 403);

        $status = $this->cleanFilter($request->query('status'), ItProvisioningRequest::STATUSES);
        $type = $this->cleanFilter($request->query('type'), ItProvisioningRequest::TYPES);
        $assignee = is_numeric($request->query('assignee')) ? (int) $request->query('assignee') : null;

        $rows = $this->provisioningAccess->applyRequestScope(ItProvisioningRequest::query(), $user)
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

    public function storeTicket(StoreItTicketRequest $request)
    {
        $user = $request->user();
        try {
            $ticket = $this->ticketIntake->create(
                $user,
                $request->validated(),
                $request->file('attachments', []),
            );
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        // Receipt to the REQUESTER — the actor when self-raised, the
        // on-behalf-of colleague when an agent logs it. Plus an urgent alert
        // to the agents working the queue — never to the actor themselves.
        $requester = $ticket->requester;
        if ($requester) {
            $this->emailDeliveries->send($requester, new TicketCreatedNotification($ticket, 'receipt'));
        }
        if ($ticket->priority === 'urgent') {
            $agents = ItStaffDirectory::agentsForTicket($ticket)
                ->reject(fn (User $agent) => $agent->id === $user->id);
            $this->emailDeliveries->send($agents, new TicketCreatedNotification($ticket, 'urgent_alert'));
        }

        return redirect()->back()
            ->with('success', "Ticket logged — {$ticket->reference}.")
            ->with('it_ticket', ['id' => $ticket->id, 'reference' => $ticket->reference]);
    }

    public function updateTicket(UpdateTicketRequest $request, ItTicket $ticket)
    {
        $user = $request->user();
        try {
            $this->triageService->update($ticket, $user, $request->validated());
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Ticket updated.');
    }

    public function resolveTicket(ResolveTicketRequest $request, ItTicket $ticket)
    {
        $user = $request->user();
        try {
            $ticket = $this->ticketInteractions->resolveWithPublicNote(
                $ticket,
                $user,
                (string) $request->validated('note'),
            );
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        // Requester hears (unless they resolved it themselves, or the agent
        // untoggled it); watchers always hear — minus the actor.
        if ($request->boolean('notify_requester', true)
            && $ticket->requester
            && $ticket->requester_user_id !== $user->id) {
            $this->emailDeliveries->send($ticket->requester, new TicketResolvedNotification($ticket, 'requester'));
        }
        $watchers = $ticket->watchers()->get()->reject(fn (User $w) => $w->id === $user->id);
        $this->emailDeliveries->send($watchers, new TicketResolvedNotification($ticket, 'watcher'));

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
        'owned_by_me' => 'Owned by me',
        'my_team' => "My team's work",
        'breaching' => 'Breaching soon',
        'breached' => 'Breached',
        'awaiting_reply' => 'Awaiting reply',
        'waiting' => 'All waiting work',
        'recently_resolved' => 'Recently resolved',
    ];

    /** Apply one saved view's constraints to a tickets query. */
    private function applyTicketView($query, string $view, int $userId)
    {
        return match ($view) {
            'all_open' => $query->whereIn('status', ItTicket::OPEN_STATUSES),
            'unassigned' => $query->whereIn('status', ItTicket::OPEN_STATUSES)->whereNull('assigned_to_user_id'),
            'mine' => $query->whereIn('status', ItTicket::OPEN_STATUSES)->where('assigned_to_user_id', $userId),
            'owned_by_me' => $query->whereIn('status', ItTicket::OPEN_STATUSES)->where('owner_user_id', $userId),
            'my_team' => $query->whereIn('status', ItTicket::OPEN_STATUSES)
                ->whereHas('team', fn ($team) => $team
                    ->where('is_active', true)
                    ->where(fn ($responsibility) => $responsibility
                        ->where('manager_user_id', $userId)
                        ->orWhereHas('members', fn ($members) => $members->whereKey($userId)))),
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
    private function requestPage(array $filters, User $user)
    {
        // House rule: guard new-table reads so a request racing the deploy's
        // migration step renders an empty queue instead of a 500.
        if (! Schema::hasTable('it_provisioning_requests')) {
            return null;
        }

        return $this->provisioningAccess->applyRequestScope(ItProvisioningRequest::query(), $user)
            ->with([
                'employeeProfile:id,user_id,position_title,position_role',
                'employeeProfile.user:id,name',
                'assignee:id,name',
                'responsibleTeam:id,name',
                'approver:id,name',
                'workflow:id,lifecycle_type,status,source_type,effective_at',
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
                'task_key' => $r->task_key,
                'action' => $r->action,
                'category' => $r->category,
                'status' => $r->status,
                'priority' => $r->priority,
                'due_date' => $r->due_date?->toDateString(),
                'assignee' => $r->assignee ? ['id' => $r->assignee->id, 'name' => $r->assignee->name] : null,
                'responsible_team' => $r->responsibleTeam
                    ? ['id' => $r->responsibleTeam->id, 'name' => $r->responsibleTeam->name]
                    : null,
                'stage' => $r->stage,
                'dependency_request_ids' => $r->dependency_request_ids ?? [],
                'approval_required' => $r->approval_required,
                'approval_status' => $r->approval_status,
                'approver' => $r->approver ? ['id' => $r->approver->id, 'name' => $r->approver->name] : null,
                'evidence_required' => $r->evidence_required,
                'evidence_summary' => $r->evidence_summary,
                'failure_reason' => $r->failure_reason,
                'fulfiller_context' => $r->fulfiller_context ?? [],
                'workflow' => $r->workflow ? [
                    'id' => $r->workflow->id,
                    'lifecycle_type' => $r->workflow->lifecycle_type,
                    'status' => $r->workflow->status,
                    'source_type' => $r->workflow->source_type,
                    'effective_at' => $r->workflow->effective_at?->toIso8601String(),
                ] : null,
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

    /** @return array<int, array<string, mixed>> */
    private function provisioningWorkflows(User $user): array
    {
        if (! Schema::hasTable('it_provisioning_workflows')) {
            return [];
        }

        return $this->provisioningAccess->applyWorkflowScope(ItProvisioningWorkflow::query(), $user)
            ->with(['employeeProfile:id,user_id,position_title', 'employeeProfile.user:id,name', 'template:id,name'])
            ->withCount([
                'requests',
                'requests as completed_requests_count' => fn ($query) => $query->where('status', 'done'),
                'requests as failed_requests_count' => fn ($query) => $query->whereIn('status', ['failed', 'cancelled']),
            ])
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (ItProvisioningWorkflow $workflow) => [
                'id' => $workflow->id,
                'lifecycle_type' => $workflow->lifecycle_type,
                'status' => $workflow->status,
                'effective_at' => $workflow->effective_at?->toIso8601String(),
                'source_type' => $workflow->source_type,
                'template' => $workflow->template?->name,
                'employee' => [
                    'id' => $workflow->employee_profile_id,
                    'name' => $workflow->employeeProfile?->user?->name ?? 'Unknown',
                    'role' => $workflow->employeeProfile?->position_title,
                ],
                'progress' => [
                    'total' => $workflow->requests_count,
                    'completed' => $workflow->completed_requests_count,
                    'failed' => $workflow->failed_requests_count,
                ],
            ])->values()->all();
    }

    /** @param array<string, mixed> $filters */
    private function ticketPage(array $filters, User $user)
    {
        if (! Schema::hasTable('it_tickets')) {
            return null;
        }

        $query = $this->workAccess->applyViewScope(ItTicket::query(), $user)
            ->with([
                'requester:id,name',
                'assignee:id,name',
                'service:id,name',
                'queue:id,name',
                'team:id,name',
                'owner:id,name',
            ])
            ->when($filters['view'], fn ($q, $view) => $this->applyTicketView($q, $view, (int) $user->id))
            ->when($filters['q'], fn ($q, $term) => $this->applyTicketSearch($q, $term))
            ->when($filters['ticket_status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['ticket_priority'], fn ($q, $priority) => $q->where('priority', $priority))
            ->when($filters['ticket_category'], fn ($q, $category) => $q->where('category', $category))
            ->when($filters['source'], fn ($q, $source) => $q->where('source', $source))
            ->when($filters['work_type'], fn ($q, $workType) => $q->where('work_type', $workType))
            ->when($filters['service'], fn ($q, $service) => $q->where('it_service_id', $service))
            ->when($filters['age'], fn ($q, $age) => $this->applyTicketAge($q, $age))
            ->when($filters['missing'], fn ($q, $missing) => $q->whereNull(match ($missing) {
                'service' => 'it_service_id',
                'queue' => 'queue_id',
                'team' => 'team_id',
                default => 'assigned_to_user_id',
            }))
            ->when($filters['reopened'], fn ($q) => $q->where('reopened_count', '>', 0))
            ->when($filters['first_contact'], fn ($q) => $q->firstContactResolved())
            ->when($filters['open_only'], fn ($q) => $q->whereIn('status', ItTicket::OPEN_STATUSES))
            ->when($filters['device_linked'], fn ($q) => $q->whereHas('links', fn ($links) => $links
                ->where('linkable_type', 'security_device')
                ->where('relationship', 'affected_device')))
            ->when($filters['resolved_from'], fn ($q, $from) => $q->whereDate('resolved_at', '>=', $from))
            ->when($filters['resolved_to'], fn ($q, $to) => $q->whereDate('resolved_at', '<=', $to))
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
                'work_type' => $t->work_type,
                'service' => $t->service ? ['id' => $t->service->id, 'name' => $t->service->name] : null,
                'category' => $t->category,
                'priority' => $t->priority,
                'status' => $t->status,
                'waiting_party' => $t->waiting_party,
                'waiting_reason' => $t->waiting_reason,
                'next_action' => $t->next_action,
                'waiting_since' => $t->waiting_since?->toIso8601String(),
                'sla_state' => $t->sla_state,
                'first_response_due_at' => $t->first_response_due_at?->toIso8601String(),
                'resolution_due_at' => $t->resolution_due_at?->toIso8601String(),
                'first_responded_at' => $t->first_responded_at?->toIso8601String(),
                'requester' => $t->requester?->name ?? 'Unknown',
                'assignee' => $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name] : null,
                'routing' => $this->routingPresenter->present($t),
                'age' => $t->created_at?->diffForHumans(short: true),
                'updated' => $t->updated_at?->diffForHumans(short: true),
                'resolved' => $t->resolved_at?->diffForHumans(short: true),
            ]);
    }

    private function applyTicketAge($query, string $age): void
    {
        $now = now();
        $twoDays = $now->copy()->subDays(2);
        $sevenDays = $now->copy()->subDays(7);
        $thirtyDays = $now->copy()->subDays(30);

        match ($age) {
            'under_2' => $query->where('created_at', '>=', $twoDays),
            '2_7' => $query->where('created_at', '>=', $sevenDays)->where('created_at', '<', $twoDays),
            '8_30' => $query->where('created_at', '>=', $thirtyDays)->where('created_at', '<', $sevenDays),
            'over_30' => $query->where('created_at', '<', $thirtyDays),
        };
    }

    /**
     * The requester's own tickets — the ONLY ticket rows a self-service
     * payload may carry. No requester column (it is always "you") and no
     * assignee identity beyond a name, mirroring what a helpdesk shows the
     * person who raised the ticket.
     */
    private function myTicketRows(User $user): array
    {
        if (! Schema::hasTable('it_tickets')) {
            return [];
        }

        return $this->workAccess->applyViewScope(ItTicket::query(), $user)
            ->where(fn ($participant) => $participant
                ->where('requester_user_id', $user->id)
                ->orWhere('requested_for_user_id', $user->id))
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
                'waiting_party' => $t->status === 'waiting'
                    ? ($t->waiting_party === 'requester' ? 'requester' : 'other')
                    : null,
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
    private function summary(User $user, bool $isAgent): array
    {
        $ticketsReady = Schema::hasTable('it_tickets');
        $requestsReady = Schema::hasTable('it_provisioning_requests');

        $my = $ticketsReady
            ? $this->workAccess->applyViewScope(ItTicket::query(), $user)
                ->where(fn ($participant) => $participant
                    ->where('requester_user_id', $user->id)
                    ->orWhere('requested_for_user_id', $user->id))
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
            ? $this->workAccess->applyViewScope(ItTicket::query(), $user)
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
                    [(int) $user->id, now()->subDays(30), now()->subDays(7), now()->subDays(30)],
                )
                ->first()
            : null;

        $ownedByMe = $ticketsReady
            ? $this->applyTicketView(
                $this->workAccess->applyViewScope(ItTicket::query(), $user),
                'owned_by_me',
                (int) $user->id,
            )->count()
            : 0;
        $myTeam = $ticketsReady
            ? $this->applyTicketView(
                $this->workAccess->applyViewScope(ItTicket::query(), $user),
                'my_team',
                (int) $user->id,
            )->count()
            : 0;

        $requests = $requestsReady
            ? $this->provisioningAccess->applyRequestScope(ItProvisioningRequest::query(), $user)
                ->selectRaw(
                    "SUM(status = 'pending') AS pending,
                     SUM(status = 'in_progress') AS in_progress,
                     SUM(status = 'failed') AS failed,
                     SUM(status = 'done' AND fulfilled_at >= ?) AS done_30d,
                     SUM(status IN ('pending', 'in_progress', 'failed') AND due_date IS NOT NULL AND due_date < ?) AS overdue,
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
                'owned_by_me' => $ownedByMe,
                'my_team' => $myTeam,
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
            'failed' => (int) ($requests->failed ?? 0),
            'done_30d' => (int) ($requests->done_30d ?? 0),
            'overdue' => (int) ($requests->overdue ?? 0),
            'pending_over_7d' => (int) ($requests->pending_over_7d ?? 0),
        ];

        return $summary;
    }

    /**
     * §F1 Overview payload (agents): the avg-first-response KPI plus the four
     * "needs attention" lanes — short, capped, Site-scoped lists that each
     * deep-link into the filtered queue. The KPI *counts* come from the
     * summary; this adds the one metric the summary can't cheaply carry and
     * the lane contents. Guarded so a pre-migration read renders an empty board.
     *
     * @return array<string, mixed>
     */
    private function overview(User $user): array
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

        $base = fn () => $this->workAccess->applyViewScope(ItTicket::query(), $user)
            ->with(['requester:id,name', 'assignee:id,name']);

        // Avg minutes from raise to first agent reply, over replies in the last 30d.
        $avg = $this->workAccess->applyViewScope(ItTicket::query(), $user)
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
        $byPriority = $this->workAccess->applyViewScope(ItTicket::query(), $user)
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
            'recent_activity' => $this->recentActivity($user),
        ];
    }

    /**
     * §F1 recent-activity feed — the latest visible ticket events,
     * humanised on the client. Ticket subjects only (each row deep-links to a
     * ticket); provisioning events stay on their own request rows. `subject`
     * is loaded via the morph so a deleted ticket drops out rather than 500s.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentActivity(User $user): array
    {
        if (! Schema::hasTable('it_ticket_events')) {
            return [];
        }

        return ItTicketEvent::query()
            ->where('subject_type', 'it_ticket')
            ->whereHasMorph('subject', [ItTicket::class], fn ($ticket) => $this->workAccess->applyViewScope($ticket, $user))
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
    private function kbArticles(): array
    {
        if (! Schema::hasTable('it_kb_articles')) {
            return [];
        }

        return ItKbArticle::query()
            ->with(['author:id,name', 'owner:id,name', 'service:id,name'])
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get()
            ->map(fn (ItKbArticle $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'slug' => $a->slug,
                'category' => $a->category,
                'status' => $a->status,
                'audience' => $a->audience,
                'site_scope' => $a->site_scope ?? [],
                'body' => $a->body,
                'views' => (int) $a->view_count,
                'helpful_yes' => (int) $a->helpful_yes,
                'helpful_no' => (int) $a->helpful_no,
                'helpful_percent' => $a->helpfulPercent(),
                'deflections' => (int) $a->deflection_count,
                'author' => $a->author?->name,
                'owner_user_id' => $a->owner_user_id,
                'owner' => $a->owner?->name,
                'related_service_id' => $a->related_service_id,
                'related_service' => $a->service?->name,
                'review_due_at' => $a->review_due_at?->toDateString(),
                'review_started_at' => $a->review_started_at?->toIso8601String(),
                'published_at' => $a->published_at?->toIso8601String(),
                'retired_at' => $a->retired_at?->toIso8601String(),
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
    private function kbPublished(User $user): array
    {
        if (! Schema::hasTable('it_kb_articles')) {
            return [];
        }

        $userSiteIds = $this->workAccess->approvedSiteIds($user);

        return ItKbArticle::query()
            ->published()
            ->whereIn('audience', ['all_staff', 'specific_sites'])
            ->with([
                'service:id,name',
                'interactions' => fn ($query) => $query
                    ->where('user_id', $user->id)
                    ->whereIn('event_type', ['helpful', 'not_helpful'])
                    ->oldest('id'),
            ])
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get()
            ->filter(fn (ItKbArticle $article) => $article->audience === 'all_staff'
                || array_intersect(
                    array_map('intval', $article->site_scope ?? []),
                    array_map('intval', $userSiteIds),
                ) !== [])
            ->values()
            ->map(function (ItKbArticle $a): array {
                $vote = $a->interactions->first()?->event_type;

                return [
                    'id' => $a->id,
                    'title' => $a->title,
                    'category' => $a->category,
                    'body' => $a->body,
                    'views' => (int) $a->view_count,
                    'helpful_yes' => (int) $a->helpful_yes,
                    'helpful_no' => (int) $a->helpful_no,
                    'helpful_percent' => $a->helpfulPercent(),
                    'user_vote' => match ($vote) {
                        'helpful' => true,
                        'not_helpful' => false,
                        default => null,
                    },
                    'related_service' => $a->service?->name,
                    'review_due_at' => $a->review_due_at?->toDateString(),
                ];
            })
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
