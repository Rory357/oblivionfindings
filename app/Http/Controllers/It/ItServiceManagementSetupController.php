<?php

namespace App\Http\Controllers\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\ItStaffDirectory;
use App\Domain\It\Services\ItAutomationScheduleCatalog;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Domain\It\Services\ItProvisioningTemplateService;
use App\Domain\It\Services\ItServiceIdentityCredentialService;
use App\Domain\It\Services\ItServiceManagementSetupService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Http\Requests\It\SaveItQueueRequest;
use App\Http\Requests\It\SaveItServiceRequest;
use App\Http\Requests\It\SaveItTeamRequest;
use App\Http\Requests\It\StoreItProvisioningTemplateRequest;
use App\Http\Requests\It\StoreItServiceIdentityRequest;
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
use App\Models\Site;
use App\Models\User;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class ItServiceManagementSetupController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        private readonly ItServiceManagementSetupService $setupService,
        private readonly ItServiceIdentityCredentialService $identityCredentials,
        private readonly ItProvisioningTemplateService $provisioningTemplates,
        private readonly ItAutomationScheduleCatalog $automationCatalog,
        private readonly ItEmailDeliveryService $emailDeliveries,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', ItTeam::class);
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        $automationPeriod = $request->validate([
            'automation_from' => ['nullable', 'date_format:Y-m-d'],
            'automation_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $teams = ItTeam::query()
            ->forTenant($tenantId)
            ->with(['manager:id,name', 'members:id,name'])
            ->withCount([
                'tickets as open_tickets_count' => fn ($query) => $query->whereIn('status', ItTicket::OPEN_STATUSES),
                'tasks as open_tasks_count' => fn ($query) => $query->whereIn('status', ['pending', 'in_progress', 'blocked']),
                'queues', 'members',
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (ItTeam $team) => [
                'id' => $team->id,
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
            ->forTenant($tenantId)
            ->with('team:id,name')
            ->withCount([
                'tickets as open_tickets_count' => fn ($query) => $query->whereIn('status', ItTicket::OPEN_STATUSES),
                'tickets as unassigned_count' => fn ($query) => $query->whereIn('status', ItTicket::OPEN_STATUSES)->whereNull('assigned_to_user_id'),
                'tickets as sla_risk_count' => fn ($query) => $query->whereIn('status', ItTicket::OPEN_STATUSES)->whereIn('sla_state', ['at_risk', 'breached']),
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
                'workload' => [
                    'open_tickets' => $queue->open_tickets_count,
                    'unassigned' => $queue->unassigned_count,
                    'sla_risk' => $queue->sla_risk_count,
                ],
            ])->values();

        $services = ItService::query()
            ->forTenant($tenantId)
            ->with('owner:id,name')
            ->withCount([
                'tickets as open_tickets_count' => fn ($query) => $query->whereIn('status', ItTicket::OPEN_STATUSES),
                'tickets as sla_risk_count' => fn ($query) => $query->whereIn('status', ItTicket::OPEN_STATUSES)->whereIn('sla_state', ['at_risk', 'breached']),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (ItService $service) => [
                'id' => $service->id,
                'key' => $service->key,
                'name' => $service->name,
                'description' => $service->description,
                'is_active' => $service->is_active,
                'status' => $service->status,
                'criticality' => $service->criticality,
                'owner' => $this->userOption($service->owner),
                'workload' => [
                    'open_tickets' => $service->open_tickets_count,
                    'sla_risk' => $service->sla_risk_count,
                ],
            ])->values();

        $apiIdentities = ItServiceIdentity::query()
            ->forTenant($tenantId)
            ->with(['actor:id,name', 'creator:id,name'])
            ->latest('id')
            ->get()
            ->map(fn (ItServiceIdentity $identity) => [
                'id' => $identity->id,
                'public_id' => $identity->public_id,
                'name' => $identity->name,
                'description' => $identity->description,
                'actor' => $this->userOption($identity->actor),
                'creator' => $this->userOption($identity->creator),
                'abilities' => $identity->abilities ?? [],
                'allowed_work_types' => $identity->allowed_work_types ?? [],
                'allowed_site_ids' => $identity->allowed_site_ids ?? [],
                'allowed_fields' => $identity->allowed_fields ?? ['create' => [], 'read' => []],
                'require_signature' => $identity->require_signature,
                'rate_limit_per_minute' => $identity->rate_limit_per_minute,
                'expires_at' => $identity->expires_at?->toIso8601String(),
                'revoked_at' => $identity->revoked_at?->toIso8601String(),
                'last_used_at' => $identity->last_used_at?->toIso8601String(),
                'created_at' => $identity->created_at?->toIso8601String(),
                'is_active' => $identity->isActive(),
            ])->values();

        $provisioningTemplates = ItProvisioningTemplate::query()
            ->forTenant($tenantId)
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

        $deliveryRows = Schema::hasTable('it_email_deliveries')
            ? ItEmailDelivery::query()
                ->forTenant($tenantId)
                ->with([
                    'ticket:id,reference,title',
                    'provisioningRequest:id,item',
                    'recipient:id,name',
                    'retryAttempt:id,retry_of_delivery_id',
                ])
                ->latest('id')
                ->limit(100)
                ->get()
            : collect();
        $failedDeliveryCount = Schema::hasTable('it_email_deliveries')
            ? ItEmailDelivery::query()
                ->forTenant($tenantId)
                ->whereIn('status', ['failed', 'bounced'])
                ->count()
            : 0;
        $automationDefinitions = Schema::hasTable('it_automation_runs')
            ? $this->automationCatalog->definitions($tenantId)
            : [];
        $automationRuns = Schema::hasTable('it_automation_runs')
            ? ItAutomationRun::query()
                ->forTenantOrSystem($tenantId)
                ->when($automationPeriod['automation_from'] ?? null, fn ($query, $from) => $query->whereDate('started_at', '>=', $from))
                ->when($automationPeriod['automation_to'] ?? null, fn ($query, $to) => $query->whereDate('started_at', '<=', $to))
                ->latest('id')
                ->limit(100)
                ->get()
            : collect();
        $catalogItems = Schema::hasTable('it_catalog_items')
            ? ItCatalogItem::query()->forTenant($tenantId)->get()
            : collect();
        $mailboxes = Schema::hasTable('it_mailbox_connections')
            ? ItMailboxConnection::query()->where('tenant_id', $tenantId)->get()
            : collect();
        $apiErrors = Schema::hasTable('it_api_requests')
            ? ItApiRequest::query()->forTenant($tenantId)->where('response_status', '>=', 400)->count()
            : 0;

        $operationsAudit = [
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
                'connections' => $mailboxes->count(),
                'connected' => $mailboxes->where('status', ItMailboxConnection::STATUS_CONNECTED)->count(),
                'connection_errors' => $mailboxes->where('status', ItMailboxConnection::STATUS_ERROR)->count(),
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
                    ? ItSlaPolicy::query()->where('tenant_id', $tenantId)->count()
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
            'apiIdentities' => $apiIdentities,
            'oneTimeApiCredential' => $request->session()->get('it_api_credential'),
            'provisioningTemplates' => $provisioningTemplates,
            'operationsAudit' => $operationsAudit,
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
                'last_error' => $delivery->last_error,
                'queued_at' => $delivery->queued_at?->toIso8601String(),
                'accepted_at' => $delivery->accepted_at?->toIso8601String(),
                'provider_status_at' => $delivery->provider_status_at?->toIso8601String(),
                'delivered_at' => $delivery->delivered_at?->toIso8601String(),
                'can_retry' => in_array($delivery->status, ['failed', 'bounced'], true)
                    && $delivery->retryAttempt === null,
            ])->values(),
            'automationDefinitions' => $automationDefinitions,
            'automationRuns' => $automationRuns->map(fn (ItAutomationRun $run) => [
                'id' => $run->id,
                'automation_key' => $run->automation_key,
                'status' => $run->status,
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'runtime_ms' => $run->runtime_ms,
                'error_summary' => $run->error_summary,
            ])->values(),
            'agents' => ItStaffDirectory::agents($tenantId)
                ->sortBy('name')
                ->map(fn (User $user) => $this->userOption($user))
                ->values(),
            'sites' => Site::query()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'positionRoles' => HrEmployeeProfile::query()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('position_role')
                ->where('position_role', '!=', '')
                ->distinct()
                ->orderBy('position_role')
                ->pluck('position_role')
                ->values(),
            'generatedAt' => now()->toIso8601String(),
        ]);
    }

    public function storeTeam(SaveItTeamRequest $request)
    {
        $this->authorize('create', ItTeam::class);

        return $this->run($request, fn (int $tenantId) => $this->setupService
            ->createTeam($request->user(), $tenantId, $request->validated()), 'Team created.');
    }

    public function updateTeam(SaveItTeamRequest $request, ItTeam $team)
    {
        $this->authorize('update', $team);

        return $this->run($request, fn (int $tenantId) => $this->setupService
            ->updateTeam($team, $request->user(), $tenantId, $request->validated()), 'Team updated.');
    }

    public function storeQueue(SaveItQueueRequest $request)
    {
        $this->authorize('create', ItQueue::class);

        return $this->run($request, fn (int $tenantId) => $this->setupService
            ->createQueue($request->user(), $tenantId, $request->validated()), 'Queue created.');
    }

    public function updateQueue(SaveItQueueRequest $request, ItQueue $queue)
    {
        $this->authorize('update', $queue);

        return $this->run($request, fn (int $tenantId) => $this->setupService
            ->updateQueue($queue, $request->user(), $tenantId, $request->validated()), 'Queue updated.');
    }

    public function storeService(SaveItServiceRequest $request)
    {
        $this->authorize('create', ItService::class);

        return $this->run($request, fn (int $tenantId) => $this->setupService
            ->createService($request->user(), $tenantId, $request->validated()), 'Service created.');
    }

    public function updateService(SaveItServiceRequest $request, ItService $service)
    {
        $this->authorize('update', $service);

        return $this->run($request, fn (int $tenantId) => $this->setupService
            ->updateService($service, $request->user(), $tenantId, $request->validated()), 'Service updated.');
    }

    public function storeIdentity(StoreItServiceIdentityRequest $request)
    {
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        try {
            $data = $request->validated();
            $credential = $this->identityCredentials->create($request->user(), $tenantId, [
                ...$data,
                'allowed_fields' => [
                    'create' => array_values($data['create_fields']),
                    'read' => array_values($data['read_fields']),
                ],
            ]);
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->route('it.setup.index')
            ->with('success', 'API identity created. Copy its credential now; it will not be shown again.')
            ->with('it_api_credential', [
                'identity_id' => $credential['identity']->id,
                'name' => $credential['identity']->name,
                'token' => $credential['token'],
            ]);
    }

    public function revokeIdentity(Request $request, ItServiceIdentity $identity)
    {
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        $this->identityCredentials->revoke($identity, $request->user(), $tenantId);

        return redirect()->route('it.setup.index')->with('success', 'API identity revoked.');
    }

    public function storeProvisioningTemplate(StoreItProvisioningTemplateRequest $request)
    {
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        try {
            $this->provisioningTemplates->create($request->user(), $tenantId, $request->validated());
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
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        $this->assertHrTenantAccess($tenantId, $template->tenant_id);
        try {
            $this->provisioningTemplates->update(
                $template,
                $request->user(),
                $tenantId,
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
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        try {
            $this->emailDeliveries->retry($delivery, $request->user(), $tenantId);
        } catch (DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Email queued for another delivery attempt.');
    }

    /** @param callable(int): mixed $action */
    private function run(Request $request, callable $action, string $success)
    {
        $tenantId = $this->resolveHrTenantIdForUser($request->user());
        try {
            $action($tenantId);
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
