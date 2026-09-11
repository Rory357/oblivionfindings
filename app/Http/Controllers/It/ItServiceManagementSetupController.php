<?php

namespace App\Http\Controllers\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItApiOperationsPresenter;
use App\Domain\It\Services\ItAttachmentCleanupReadService;
use App\Domain\It\Services\ItAutomationOperationsPresenter;
use App\Domain\It\Services\ItAutomationRunDiagnostics;
use App\Domain\It\Services\ItAutomationScheduleCatalog;
use App\Domain\It\Services\ItCatalogManagementService;
use App\Domain\It\Services\ItEmailDeliveryFailure;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Domain\It\Services\ItMailboxConnectionPresenter;
use App\Domain\It\Services\ItProvisioningTemplateService;
use App\Domain\It\Services\ItServiceIdentityCredentialService;
use App\Domain\It\Services\ItServiceManagementSetupService;
use App\Domain\It\Services\ItSetupCommandService;
use App\Domain\It\Services\ItSlaReadService;
use App\Domain\It\Services\ItTechnicalDeliveryOperationsPresenter;
use App\Domain\It\Services\ItTicketRoutingEligibility;
use App\Domain\It\Services\ItTicketRoutingService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Http\Controllers\Controller;
use App\Http\Requests\It\RecoverItSetupCommandRequest;
use App\Http\Requests\It\SaveItCatalogItemRequest;
use App\Http\Requests\It\SaveItQueueRequest;
use App\Http\Requests\It\SaveItServiceRequest;
use App\Http\Requests\It\SaveItTeamRequest;
use App\Http\Requests\It\StoreItProvisioningTemplateRequest;
use App\Http\Requests\It\UnpublishItCatalogItemRequest;
use App\Http\Requests\It\ValidateItSetupCandidateRequest;
use App\Jobs\DispatchItTicketNotifications;
use App\Models\ItApiRequest;
use App\Models\ItAutomationRun;
use App\Models\ItCatalogItem;
use App\Models\ItEmailDelivery;
use App\Models\ItMailboxConnection;
use App\Models\ItProvisioningTemplate;
use App\Models\ItQueue;
use App\Models\ItService;
use App\Models\ItServiceIdentity;
use App\Models\ItSlaPolicy;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\Site;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class ItServiceManagementSetupController extends Controller
{
    public function __construct(
        private readonly ItServiceManagementSetupService $setupService,
        private readonly ItServiceIdentityCredentialService $identityCredentials,
        private readonly ItProvisioningTemplateService $provisioningTemplates,
        private readonly ItAutomationScheduleCatalog $automationCatalog,
        private readonly ItEmailDeliveryService $emailDeliveries,
        private readonly ItWorkAccessService $workAccess,
        private readonly ItCatalogManagementService $catalogueManagement,
        private readonly ItTicketRoutingService $ticketRouting,
        private readonly ItTicketRoutingEligibility $routingEligibility,
        private readonly ItSlaReadService $slaRead,
        private readonly ItSetupCommandService $setupCommands,
        private readonly ItAttachmentCleanupReadService $attachmentCleanup,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', ItTeam::class);
        $user = $request->user();
        $approvedSiteIds = $this->workAccess->approvedSiteIds($user);
        $automationPeriod = $request->validate([
            'q' => ['sometimes', 'nullable', 'string'],
            'automation_from' => ['nullable', 'date_format:Y-m-d'],
            'automation_to' => ['nullable', 'date_format:Y-m-d'],
            'automation_page' => ['sometimes', 'required', 'integer', 'min:1'],
            'api_request_page' => ['sometimes', 'required', 'integer', 'min:1'],
            'device_delivery_page' => ['sometimes', 'required', 'integer', 'min:1'],
            'fleet_delivery_page' => ['sometimes', 'required', 'integer', 'min:1'],
            'review_resource' => ['sometimes', 'required', 'in:teams,queues,services'],
            'actor_user_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'delivery_comment_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'delivery_page' => ['sometimes', 'required', 'integer', 'min:1'],
        ]);
        $deliveryComment = null;
        if (isset($automationPeriod['delivery_comment_id'])) {
            abort_unless(Schema::hasColumn('it_email_deliveries', 'it_ticket_comment_id'), 503, 'Reply delivery tracking is not available yet.');
            $deliveryComment = ItTicketComment::query()->with('ticket')->publicOnly()
                ->findOrFail((int) $automationPeriod['delivery_comment_id']);
            abort_unless($deliveryComment->ticket && $this->workAccess->canWork($user, $deliveryComment->ticket), 404);
        }
        if ($request->query->has('review_resource')) {
            $user = $this->setupService->reviewActor($user, isset($automationPeriod['actor_user_id']) ? (int) $automationPeriod['actor_user_id'] : null);
        }
        $riskTickets = $this->slaRead->whereState(
            $this->workAccess->applyViewScope(ItTicket::query(), $user)->whereIn('status', ItTicket::OPEN_STATUSES),
            ['at_risk', 'breached'],
        );
        $queueRisk = (clone $riskTickets)->select('queue_id')->selectRaw('COUNT(*) AS risk_count')
            ->groupBy('queue_id')->pluck('risk_count', 'queue_id');
        $serviceRisk = (clone $riskTickets)->select('it_service_id')->selectRaw('COUNT(*) AS risk_count')
            ->groupBy('it_service_id')->pluck('risk_count', 'it_service_id');

        $teams = ItTeam::query()
            ->with(['manager:id,name', 'members:id,name'])
            ->withCount([
                'tickets as open_tickets_count' => fn ($query) => $this->workAccess
                    ->applyViewScope($query, $user)
                    ->whereIn('status', ItTicket::OPEN_STATUSES),
                'tasks as open_tasks_count' => fn ($query) => $query
                    ->whereHas('ticket', fn ($ticket) => $this->workAccess->applyViewScope($ticket, $user))
                    ->whereIn('status', ['pending', 'in_progress', 'blocked']),
                'queues', 'members',
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (ItTeam $team) => [
                'id' => $team->id,
                'configuration_version' => $this->setupService->teamVersion($team),
                'name' => $team->name,
                'description' => $team->description,
                'is_active' => $team->is_active,
                'manager' => $this->userOption($team->manager),
                'members' => $team->members->map(fn (User $member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'role' => $member->pivot->role,
                ])->values()->all(),
                'workload' => [
                    'open_tickets' => $team->open_tickets_count,
                    'open_tasks' => $team->open_tasks_count,
                    'queues' => $team->queues_count,
                    'members' => $team->members_count,
                ],
            ])->values();

        $queues = ItQueue::query()
            ->with('team:id,name')
            ->withCount([
                'tickets as open_tickets_count' => fn ($query) => $this->workAccess->applyViewScope($query, $user)->whereIn('status', ItTicket::OPEN_STATUSES),
                'tickets as unassigned_count' => fn ($query) => $this->workAccess->applyViewScope($query, $user)->whereIn('status', ItTicket::OPEN_STATUSES)->whereNull('assigned_to_user_id'),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (ItQueue $queue) => [
                'id' => $queue->id,
                'key' => $queue->key,
                'name' => $queue->name,
                'description' => $queue->description,
                'is_active' => $queue->is_active,
                'team' => $queue->team ? ['id' => $queue->team->id, 'name' => $queue->team->name] : null,
                'filter_rules' => $queue->filter_rules ?? [],
                'readiness' => $this->ticketRouting->queueReadiness($queue),
                'configuration_version' => $this->setupService->queueVersion($queue),
                'workload' => [
                    'open_tickets' => $queue->open_tickets_count,
                    'unassigned' => $queue->unassigned_count,
                    'sla_risk' => (int) $queueRisk->get($queue->id, 0),
                ],
            ])->values();

        $services = ItService::query()
            ->with('owner:id,name')
            ->withCount([
                'tickets as open_tickets_count' => fn ($query) => $this->workAccess->applyViewScope($query, $user)->whereIn('status', ItTicket::OPEN_STATUSES),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (ItService $service) => [
                'id' => $service->id,
                'configuration_version' => $this->setupService->serviceVersion($service),
                'key' => $service->key,
                'name' => $service->name,
                'description' => $service->description,
                'is_active' => $service->is_active,
                'status' => $service->status,
                'criticality' => $service->criticality,
                'owner' => $this->userOption($service->owner),
                'workload' => [
                    'open_tickets' => $service->open_tickets_count,
                    'sla_risk' => (int) $serviceRisk->get($service->id, 0),
                ],
            ])->values();

        // A frozen editor can inspect the current configuration without an
        // Inertia asset-version redirect, or unrelated provider/operations data.
        if ($request->query->has('review_resource')) {
            abort_unless($request->wantsJson() && ! $request->header('X-Inertia'), 400);
            $resource = $automationPeriod['review_resource'];
            $records = match ($resource) {
                'teams' => $teams,
                'queues' => $queues,
                'services' => $services,
            };

            return response()->json(['resource' => $resource, 'viewer_user_id' => $user->id, 'records' => $records])
                ->header('Cache-Control', 'no-store, private');
        }

        // Old flash credentials must never enter an Inertia/history response.
        $request->session()->forget('it_api_credential');
        try {
            $this->identityCredentials->guardManager($user);
            $canManageApiIdentities = true;
        } catch (DomainException) {
            $canManageApiIdentities = false;
        }
        $manageableApiIdentities = ItServiceIdentity::query()
            ->with(['actor:id,name', 'creator:id,name'])
            ->latest('id')
            ->get()
            ->filter(fn (ItServiceIdentity $identity): bool => $this->identityCredentials
                ->canManage($user, $identity));
        $apiIdentities = $manageableApiIdentities
            ->map(fn (ItServiceIdentity $identity): array => $this->identityCredentials->present($identity))->values();

        $provisioningTemplates = ItProvisioningTemplate::query()
            ->when(! $user->canDo('it.organisationWide'), function ($templates) use ($approvedSiteIds): void {
                $templates->where(function ($visible) use ($approvedSiteIds): void {
                    $visible->whereNull('site_id');
                    if ($approvedSiteIds !== []) {
                        $visible->orWhereIn('site_id', $approvedSiteIds);
                    }
                });
            })
            ->with(['site:id,name', 'tasks.responsibleTeam:id,name'])
            ->orderBy('lifecycle_type')
            ->orderByDesc('selection_priority')
            ->orderBy('name')
            ->get()
            ->map(fn (ItProvisioningTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
                'lifecycle_type' => $template->lifecycle_type,
                'position_role' => $template->position_role,
                'site_id' => $template->site_id,
                'site' => $template->site ? ['id' => $template->site->id, 'name' => $template->site->name] : null,
                'employment_type' => $template->employment_type,
                'selection_priority' => $template->selection_priority,
                'is_active' => $template->is_active,
                'tasks' => $template->tasks->map(fn ($task) => [
                    'id' => $task->id,
                    'task_key' => $task->task_key,
                    'title' => $task->title,
                    'description' => $task->description,
                    'category' => $task->category,
                    'action' => $task->action,
                    'request_type' => $task->request_type,
                    'responsible_team_id' => $task->responsible_team_id,
                    'responsible_team' => $task->responsibleTeam
                        ? ['id' => $task->responsibleTeam->id, 'name' => $task->responsibleTeam->name]
                        : null,
                    'stage' => $task->stage,
                    'sort_order' => $task->sort_order,
                    'dependency_task_keys' => $task->dependency_task_keys ?? [],
                    'trigger_fields' => $task->trigger_fields ?? [],
                    'approval_required' => $task->approval_required,
                    'evidence_required' => $task->evidence_required,
                    'due_offset_days' => $task->due_offset_days,
                    'fulfiller_fields' => $task->fulfiller_fields ?? [],
                ])->values(),
            ])->values();

        $deliveryQuery = Schema::hasTable('it_email_deliveries')
            ? $this->emailDeliveries->visibleQuery($request->user())
                ->with([
                    'ticket:id,reference,title',
                    'provisioningRequest:id,item',
                    'recipient:id,name',
                    'retryAttempt:id,retry_of_delivery_id',
                ])
                ->latest('id') : null;
        $deliveryPage = null;
        if ($deliveryComment && $deliveryQuery) {
            $deliveryPage = $deliveryQuery->where('it_ticket_comment_id', $deliveryComment->id)
                ->paginate(50, ['*'], 'delivery_page');
            $deliveryRows = $deliveryPage->getCollection();
        } else {
            $deliveryRows = $deliveryQuery?->limit(100)->get() ?? collect();
        }
        $failedDeliveryCount = Schema::hasTable('it_email_deliveries')
            ? $this->emailDeliveries->visibleQuery($request->user())
                ->whereIn('status', ['failed', 'bounced'])
                ->count()
            : 0;
        $automationDefinitions = Schema::hasTable('it_automation_runs')
            ? $this->automationCatalog->definitions()
            : [];
        $automationRuns = Schema::hasTable('it_automation_runs')
            ? ItAutomationRun::query()
                ->when($automationPeriod['automation_from'] ?? null, fn ($query, $from) => $query->whereDate('started_at', '>=', $from))
                ->when($automationPeriod['automation_to'] ?? null, fn ($query, $to) => $query->whereDate('started_at', '<=', $to))
                ->latest('id')
                ->limit(100)
                ->get()
            : collect();
        $catalogItems = Schema::hasTable('it_catalog_items')
            ? ItCatalogItem::query()
                ->with('service:id,name')
                ->withCount('submissions')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
            : collect();
        $mailboxHealth = app(ItMailboxConnectionPresenter::class)->operations($user);
        $mailboxes = collect($mailboxHealth['connections']);
        $apiErrors = Schema::hasTable('it_api_requests')
            ? ItApiRequest::query()
                ->whereIn('service_identity_id', $apiIdentities->pluck('id'))
                ->where('response_status', '>=', 400)->count()
            : 0;

        $operationsAudit = [
            'technical_delivery_health' => app(ItTechnicalDeliveryOperationsPresenter::class)->operations($user, $automationPeriod),
            'automation_history' => app(ItAutomationOperationsPresenter::class)->operations($user, $automationPeriod, $request->integer('automation_page', 1)),
            'api_health' => app(ItApiOperationsPresenter::class)->operations(
                $user, $manageableApiIdentities, $request->integer('api_request_page', 1), $automationPeriod,
            ),
            'attachment_cleanup' => $this->attachmentCleanup->health($user),
            'mailbox_health' => $mailboxHealth,
            'delivery_health' => $this->emailDeliveries->operationsHealth($user),
            'teams' => [
                'total' => $teams->count(),
                'active' => $teams->where('is_active', true)->count(),
                'missing_manager' => $teams->whereNull('manager')->count(),
                'without_members' => $teams->filter(fn (array $team) => $team['workload']['members'] === 0)->count(),
            ],
            'queues' => [
                'total' => $queues->count(),
                'active' => $queues->where('is_active', true)->count(),
                'missing_team' => $queues->whereNull('team')->count(),
                'without_default_assignee' => $queues->filter(fn (array $queue) => empty($queue['filter_rules']['default_assignee_user_id']))->count(),
            ],
            'catalogue' => [
                'total' => $catalogItems->count(),
                'published' => $catalogItems->where('is_published', true)->count(),
                'missing_service' => $catalogItems->whereNull('it_service_id')->count(),
            ],
            'forms' => [
                'configured' => $catalogItems->filter(fn (ItCatalogItem $item) => count($item->form_schema['fields'] ?? []) > 0)->count(),
                'empty' => $catalogItems->filter(fn (ItCatalogItem $item) => count($item->form_schema['fields'] ?? []) === 0)->count(),
            ],
            'email' => [
                'connections' => $mailboxHealth['available'] ? $mailboxes->count() : null,
                'connected' => $mailboxHealth['available'] ? $mailboxes->where('status', ItMailboxConnection::STATUS_CONNECTED)->count() : null,
                'connection_errors' => $mailboxHealth['available'] ? $mailboxes->where('status', ItMailboxConnection::STATUS_ERROR)->count() : null,
                'failed_or_bounced' => $failedDeliveryCount,
            ],
            'api' => [
                'identities' => $apiIdentities->count(),
                'active' => $apiIdentities->where('is_active', true)->count(),
                'revoked' => $apiIdentities->whereNotNull('revoked_at')->count(),
                'request_errors' => $apiErrors,
            ],
            'slas' => [
                'custom_policies' => Schema::hasTable('it_sla_policies')
                    ? ItSlaPolicy::query()->count()
                    : 0,
                'effective_priorities' => count(ItSlaPolicy::DEFAULTS),
            ],
            'settings' => [
                'inbound_status_callback' => filled(config('it.inbound_mail.secret')),
                'outbound_status_callback' => filled(config('it.outbound_mail.status_secret')),
            ],
        ];

        return Inertia::render('it/setup/index', [
            'teams' => $teams,
            'queues' => $queues,
            'services' => $services,
            'catalogItems' => $catalogItems->map(fn (ItCatalogItem $item) => [
                'id' => $item->id,
                'it_service_id' => $item->it_service_id,
                'service_name' => $item->service?->name,
                'name' => $item->name,
                'slug' => $item->slug,
                'description' => $item->description,
                'outcome_type' => $item->outcome_type,
                'category' => $item->category,
                'provisioning_type' => $item->provisioning_type,
                'default_priority' => $item->default_priority,
                'requires_approval' => $item->requires_approval,
                'is_published' => $item->is_published,
                'internal_only' => $item->internal_only,
                'form_schema_version' => $item->form_schema_version,
                'form_schema' => $item->form_schema,
                'search_terms' => $item->search_terms,
                'sort_order' => $item->sort_order,
                'submission_count' => $item->submissions_count,
            ])->values(),
            'apiIdentities' => $apiIdentities,
            'apiIdentityViewerUserId' => $user->id,
            'canManageApiIdentities' => $canManageApiIdentities,
            'provisioningTemplates' => $provisioningTemplates,
            'operationsAudit' => $operationsAudit,
            'emailDeliveryFilter' => $deliveryComment ? [
                'comment_id' => $deliveryComment->id,
                'ticket_reference' => $deliveryComment->ticket->reference,
                'total' => $deliveryPage?->total() ?? 0,
                'page' => $deliveryPage?->currentPage() ?? 1,
                'last_page' => $deliveryPage?->lastPage() ?? 1,
                'shown' => $deliveryRows->count(),
            ] : null,
            'emailDeliveries' => $deliveryRows->map(fn (ItEmailDelivery $delivery) => [
                'id' => $delivery->id,
                'notification_uuid' => $delivery->notification_uuid,
                'ticket' => $delivery->ticket ? [
                    'id' => $delivery->ticket->id,
                    'reference' => $delivery->ticket->reference,
                    'title' => $delivery->ticket->title,
                ] : null,
                'provisioning' => $delivery->provisioningRequest ? [
                    'id' => $delivery->provisioningRequest->id,
                    'item' => $delivery->provisioningRequest->item,
                ] : null,
                'recipient' => $delivery->recipient?->name,
                'recipient_email' => $delivery->recipient_email,
                'subject' => $delivery->subject,
                'status' => $delivery->status,
                'attempt_count' => $delivery->attempt_count,
                'retry_count' => $delivery->retry_count,
                'last_error' => ItEmailDeliveryFailure::message($delivery),
                'failure_category' => ItEmailDeliveryFailure::category($delivery),
                'queued_at' => $delivery->queued_at?->toIso8601String(),
                'accepted_at' => $delivery->accepted_at?->toIso8601String(),
                'provider_status_at' => $delivery->provider_status_at?->toIso8601String(),
                'delivered_at' => $delivery->delivered_at?->toIso8601String(),
                'can_retry' => $this->emailDeliveries->canOfferRetry($delivery, $user),
            ])->values(),
            'automationDefinitions' => $automationDefinitions,
            'automationRuns' => $automationRuns->map(fn (ItAutomationRun $run) => [
                'id' => $run->id,
                'automation_key' => $run->automation_key,
                'status' => $run->status,
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'runtime_ms' => $run->runtime_ms,
                'error_summary' => ItAutomationRunDiagnostics::safeError($run),
                'cleanup' => $this->attachmentCleanup->runSummary($user, $run),
            ])->values(),
            'agents' => $this->identityCredentials->delegableExecutionAccounts($user)
                ->filter(fn (User $agent) => $this->routingEligibility->currentlyEmployed($agent->id))
                ->sortBy('name')
                ->map(fn (User $agent) => [
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'site_ids' => $this->workAccess->approvedSiteIds($agent),
                    'organisation_wide' => $agent->canDo('it.organisationWide'),
                ])
                ->values(),
            'sites' => Site::query()
                ->whereKey($approvedSiteIds)
                ->where('is_active', true)
                ->where('archived', false)
                ->whereNull('archived_at')
                ->orderBy('name')
                ->get(['id', 'name']),
            'positionRoles' => HrEmployeeProfile::query()
                ->where(function ($visible) use ($user, $approvedSiteIds): void {
                    if ($approvedSiteIds !== []) {
                        $visible->whereIn('primary_site_id', $approvedSiteIds);
                    } else {
                        $visible->whereRaw('1 = 0');
                    }
                    if ($user->canDo('it.organisationWide')) {
                        $visible->orWhereNull('primary_site_id');
                    }
                })
                ->whereNotNull('position_role')
                ->where('position_role', '!=', '')
                ->distinct()
                ->orderBy('position_role')
                ->pluck('position_role')
                ->values(),
            'generatedAt' => now()->toIso8601String(),
        ]);
    }

    public function validateCandidate(ValidateItSetupCandidateRequest $request)
    {
        return response()->json(['candidate' => $this->setupService->authorizeCandidate($request->user(), $request->validated())])
            ->header('Cache-Control', 'no-store, private');
    }

    public function storeTeam(SaveItTeamRequest $request)
    {
        $this->authorize('create', ItTeam::class);
        if ($request->filled('request_uuid')) {
            return $this->createCommand($request, 'teams');
        }

        return $this->run(fn () => $this->setupService
            ->createTeam($request->user(), $request->validated()), 'Team created.');
    }

    public function updateTeam(SaveItTeamRequest $request, ItTeam $team)
    {
        $this->authorize('update', $team);

        return $this->run(fn () => $this->setupService
            ->updateTeam($team, $request->user(), $request->validated()), 'Team updated.');
    }

    public function storeQueue(SaveItQueueRequest $request)
    {
        $this->authorize('create', ItQueue::class);
        if ($request->filled('request_uuid')) {
            return $this->createCommand($request, 'queues');
        }

        return $this->run(fn () => $this->setupService
            ->createQueue($request->user(), $request->validated()), 'Queue created.');
    }

    public function updateQueue(SaveItQueueRequest $request, ItQueue $queue)
    {
        $this->authorize('update', $queue);

        return $this->run(fn () => $this->setupService
            ->updateQueue($queue, $request->user(), $request->validated()), 'Queue updated.');
    }

    public function storeService(SaveItServiceRequest $request)
    {
        $this->authorize('create', ItService::class);
        if ($request->filled('request_uuid')) {
            return $this->createCommand($request, 'services');
        }

        return $this->run(fn () => $this->setupService
            ->createService($request->user(), $request->validated()), 'Service created.');
    }

    public function updateService(SaveItServiceRequest $request, ItService $service)
    {
        $this->authorize('update', $service);

        return $this->run(fn () => $this->setupService
            ->updateService($service, $request->user(), $request->validated()), 'Service updated.');
    }

    private function createCommand(FormRequest $request, string $resource)
    {
        abort_unless($request->wantsJson() && ! $request->header('X-Inertia'), 400);
        try {
            return response()->json($this->setupCommands->create($request->user(), $resource, $request->validated()))
                ->header('Cache-Control', 'no-store, private');
        } catch (DomainException $error) {
            return response()->json(['message' => $error->getMessage(), 'errors' => ['setup' => [$error->getMessage()]]], 422)
                ->header('Cache-Control', 'no-store, private');
        }
    }

    public function recoverCommand(RecoverItSetupCommandRequest $request, string $requestUuid)
    {
        return response()->json($this->setupCommands->recover(
            $request->user(), $request->validated('resource'), $requestUuid, (int) $request->validated('actor_user_id'),
        ))->header('Cache-Control', 'no-store, private');
    }

    public function cancelCommand(RecoverItSetupCommandRequest $request, string $requestUuid)
    {
        return response()->json($this->setupCommands->cancel(
            $request->user(), $request->validated('resource'), $requestUuid, (int) $request->validated('actor_user_id'),
        ))->header('Cache-Control', 'no-store, private');
    }

    public function storeCatalogItem(SaveItCatalogItemRequest $request)
    {
        return $this->run(
            fn () => $this->catalogueManagement->create($request->user(), $request->validated()),
            'Catalogue request saved as a draft.',
        );
    }

    public function updateCatalogItem(SaveItCatalogItemRequest $request, ItCatalogItem $catalogItem)
    {
        return $this->run(
            fn () => $this->catalogueManagement->update($catalogItem, $request->user(), $request->validated()),
            'Catalogue request updated.',
        );
    }

    public function publishCatalogItem(Request $request, ItCatalogItem $catalogItem)
    {
        return $this->run(
            fn () => $this->catalogueManagement->publish($catalogItem, $request->user()),
            'Catalogue request published.',
        );
    }

    public function unpublishCatalogItem(UnpublishItCatalogItemRequest $request, ItCatalogItem $catalogItem)
    {
        return $this->run(
            fn () => $this->catalogueManagement->unpublish(
                $catalogItem,
                $request->user(),
                $request->validated('reason'),
            ),
            'Catalogue request unpublished.',
        );
    }

    public function storeProvisioningTemplate(StoreItProvisioningTemplateRequest $request)
    {
        try {
            $this->provisioningTemplates->create($request->user(), $request->validated());
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->route('it.setup.index')
            ->with('success', 'Provisioning template created.');
    }

    public function updateProvisioningTemplate(
        StoreItProvisioningTemplateRequest $request,
        ItProvisioningTemplate $template,
    ) {
        try {
            $this->provisioningTemplates->update(
                $template,
                $request->user(),
                $request->validated(),
            );
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->route('it.setup.index')
            ->with('success', 'Provisioning template updated.');
    }

    public function retryEmailDelivery(Request $request, ItEmailDelivery $delivery)
    {
        abort_unless(
            $this->emailDeliveries->canRetryDelivery($delivery, $request->user()),
            404,
        );
        if ($request->expectsJson()) {
            $validated = $request->validate(['expected_actor_id' => ['required', 'integer', 'min:1']]);
            abort_unless(
                (int) $validated['expected_actor_id'] === (int) $request->user()->id,
                409,
                'Your signed-in account changed. Refresh the delivery history before retrying.',
            );
        }
        try {
            $retry = $this->emailDeliveries->retry($delivery, $request->user());
        } catch (DomainException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 409);
            }

            return redirect()->back()->with('error', $exception->getMessage());
        }

        DispatchItTicketNotifications::dispatchAfterResponse(null, (int) $retry->id);

        if ($request->expectsJson()) {
            return response()->json(['data' => [
                'original_delivery_id' => (int) $delivery->id,
                'retry_delivery_id' => (int) $retry->id,
                'status' => 'queued',
                'actor_id' => (int) $request->user()->id,
            ]]);
        }

        return redirect()->back()->with('success', 'Email queued for another delivery attempt.');
    }

    /** @param callable(): mixed $action */
    private function run(callable $action, string $success)
    {
        try {
            $action();
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', $success);
    }

    /** @return array{id: int, name: string}|null */
    private function userOption(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }
}
