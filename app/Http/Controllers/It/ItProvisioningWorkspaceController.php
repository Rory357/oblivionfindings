<?php

namespace App\Http\Controllers\It;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Domain\Hr\Services\HrLifecycleAccessService;
use App\Domain\It\ItStaffDirectory;
use App\Domain\It\Services\ItCatalogAttachmentService;
use App\Domain\It\Services\ItProvisioningAccessService;
use App\Domain\It\Services\ItProvisioningCanonicalTargetService;
use App\Domain\It\Services\ItProvisioningCommandService;
use App\Domain\It\Services\ItProvisioningReadinessService;
use App\Domain\It\Services\ItProvisioningResponsibilityService;
use App\Domain\It\Services\ItProvisioningTemplatePublicationService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Http\Controllers\Controller;
use App\Models\ItProvisioningRequest;
use App\Models\ItProvisioningTemplate;
use App\Models\ItProvisioningTemplateVersion;
use App\Models\ItProvisioningWorkflow;
use App\Models\User;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

final class ItProvisioningWorkspaceController extends Controller
{
    public function __construct(
        private readonly ItProvisioningAccessService $access,
        private readonly ItProvisioningReadinessService $readiness,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $request->user();
        abort_unless($actor->approved_at !== null && app(HrCurrentStaffService::class)->isCurrent($actor) && ($actor->canDo('it.view') || $actor->canDo('it.manage')), 403);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:30'], 'lifecycle_type' => ['nullable', 'in:joiner,mover,leaver'],
            'view' => ['nullable', 'in:tasks,workflows,templates'], 'list_view' => ['nullable', 'in:table,cards']]);
        $view = $filters['view'] ?? 'tasks';
        $base = $this->access->applyRequestScope(ItProvisioningRequest::query(), $actor);
        $summary = [
            'open' => (clone $base)->whereNotIn('status', ['done', 'cancelled'])->count(),
            'awaiting_approval' => (clone $base)->whereNotIn('status', ['done', 'cancelled'])->where('approval_required', true)->where('approval_status', '!=', 'approved')->count(),
            'failed' => (clone $base)->where('status', 'failed')->count(),
            'overdue' => (clone $base)->whereNotIn('status', ['done', 'cancelled'])->whereDate('due_date', '<', today())->count(),
        ];
        $query = trim((string) ($filters['q'] ?? ''));
        $search = '%'.addcslashes($query, '%_\\').'%';
        $rows = null;
        $templates = [];
        if ($view === 'tasks') {
            $tasks = $base->with(['employeeProfile.user:id,name', 'workflow', 'assignee:id,name', 'responsibleTeam:id,name']);
            if ($query !== '') {
                $tasks->where(function ($q) use ($search, $query): void {
                    $q->where('item', 'like', $search)->orWhereHas('employeeProfile.user', fn ($users) => $users->where('name', 'like', $search));
                    if (preg_match('/^(?:IT-P)?0*(\d+)$/i', $query, $match)) {
                        $q->orWhereKey((int) $match[1]);
                    }
                });
            }
            if (! empty($filters['lifecycle_type'])) {
                $tasks->whereHas('workflow', fn ($q) => $q->where('lifecycle_type', $filters['lifecycle_type']));
            }
            if (($filters['status'] ?? null) === 'open') {
                $tasks->whereNotIn('status', ['done', 'cancelled']);
            } elseif (($filters['status'] ?? null) === 'awaiting_approval') {
                $tasks->whereNotIn('status', ['done', 'cancelled'])->where('approval_required', true)->where('approval_status', '!=', 'approved');
            } elseif (($filters['status'] ?? null) === 'overdue') {
                $tasks->whereNotIn('status', ['done', 'cancelled'])->whereDate('due_date', '<', today());
            } elseif (in_array($filters['status'] ?? null, ItProvisioningRequest::STATUSES, true)) {
                $tasks->where('status', $filters['status']);
            }
            $rows = $tasks->orderByRaw('due_date IS NULL')->orderBy('due_date')->orderBy('id')->paginate(20)->withQueryString()
                ->through(fn (ItProvisioningRequest $task) => $this->task($task, $actor));
        } elseif ($view === 'workflows') {
            $workflows = $this->access->applyWorkflowScope(ItProvisioningWorkflow::query(), $actor)
                ->with(['employeeProfile.user:id,name', 'templateVersion', 'owner:id,name', 'cover:id,name']);
            if ($query !== '') {
                $workflows->whereHas('employeeProfile.user', fn ($users) => $users->where('name', 'like', $search));
            }
            if (! empty($filters['lifecycle_type'])) {
                $workflows->where('lifecycle_type', $filters['lifecycle_type']);
            }
            if (in_array($filters['status'] ?? null, ItProvisioningWorkflow::STATUSES, true)) {
                $workflows->where('status', $filters['status']);
            }
            $rows = $workflows->latest('id')->paginate(20)->withQueryString()->through(fn (ItProvisioningWorkflow $workflow) => $this->workflow($workflow, $actor));
        } else {
            abort_unless($actor->canDo('it.manage'), 403);
            if ($this->readiness->storageReady()) {
                $templates = ItProvisioningTemplate::query()->with('publishedVersion')->orderBy('name')->get()
                    ->filter(fn ($template) => app(ItProvisioningTemplatePublicationService::class)->canView($actor, $template))
                    ->filter(fn ($template) => $query === '' || str_contains(mb_strtolower($template->name), mb_strtolower($query)))
                    ->filter(fn ($template) => empty($filters['lifecycle_type']) || $template->lifecycle_type === $filters['lifecycle_type'])
                    ->map(fn ($template) => ['id' => $template->id, 'name' => $template->name, 'lifecycle_type' => $template->lifecycle_type,
                        'version' => $template->lock_version, 'published_version' => $template->publishedVersion?->version,
                        'href' => '/it/provisioning/templates/'.$template->id])->values()->all();
            }
        }

        return Inertia::render('it/provisioning/index', [
            'actorId' => (int) $actor->id, 'canManage' => $actor->canDo('it.manage'), 'storageReady' => $this->readiness->storageReady(),
            'filters' => [...$filters, 'view' => $view], 'summary' => $summary, 'records' => $rows, 'templates' => $templates,
        ]);
    }

    public function showTask(Request $request, int $task): Response
    {
        $actor = $request->user();
        $record = $this->access->applyRequestScope(ItProvisioningRequest::query(), $actor)->whereKey($task)
            ->with(['employeeProfile.user:id,name', 'workflow.templateVersion', 'assignee:id,name', 'responsibleTeam:id,name', 'events.actor:id,name'])->firstOrFail();
        $data = $this->task($record, $actor);
        $data += [
            'instructions' => $record->notes, 'evidence_summary' => $record->evidence_summary,
            'external_ref' => $record->external_ref, 'failure_reason' => $record->failure_reason,
            'fulfilment_mode' => $record->fulfilment_mode, 'canonical_target_type' => $record->canonical_target_type,
            'canonical_target_id' => $record->canonical_target_id,
            'canonical_target' => app(ItProvisioningCanonicalTargetService::class)->find($actor, $record, $record->canonical_target_type, $record->canonical_target_id),
            'fulfilled_at' => $record->fulfilled_at?->toIso8601String(),
            'approval_expires_at' => $record->approval_expires_at?->toIso8601String(),
            'approval_requested_at' => $record->approval_requested_at?->toIso8601String(),
            'primary_approver_user_id' => $record->primary_approver_user_id,
            'cover_approver_user_id' => $record->cover_approver_user_id,
            'dependencies' => $this->access->applyRequestScope(ItProvisioningRequest::query(), $actor)
                ->whereKey($record->dependency_request_ids ?? [])->get(['id', 'item', 'status'])->map(fn ($dependency) => [
                    'id' => $dependency->id, 'title' => $dependency->item, 'status' => $dependency->status, 'href' => '/it/provisioning/tasks/'.$dependency->id,
                ])->all(),
            'events' => $record->events->map(fn ($event) => ['id' => $event->id, 'type' => $event->type,
                'actor' => $event->actor?->name, 'at' => $event->created_at?->toIso8601String(), 'details' => $event->payload])->all(),
            'attachments' => app(ItCatalogAttachmentService::class)->forResult($actor, $record),
            'linked_tickets' => app(ItWorkAccessService::class)->applyViewScope($record->linkedTickets()->getQuery(), $actor)
                ->get(['id', 'reference', 'title'])->map(fn ($ticket) => ['title' => $ticket->reference.' · '.$ticket->title, 'href' => '/it/tickets/'.$ticket->id])->all(),
        ];

        return Inertia::render('it/provisioning/task', ['actorId' => (int) $actor->id, 'task' => $data]);
    }

    public function showWorkflow(Request $request, int $workflow): Response
    {
        $actor = $request->user();
        $record = $this->access->applyWorkflowScope(ItProvisioningWorkflow::query(), $actor)->whereKey($workflow)
            ->with(['employeeProfile.user:id,name', 'templateVersion', 'owner:id,name', 'cover:id,name', 'events.actor:id,name'])->firstOrFail();
        $data = $this->workflow($record, $actor);
        $data['tasks'] = $this->access->applyRequestScope($record->requests()->getQuery(), $actor)
            ->with(['employeeProfile.user:id,name', 'workflow', 'assignee:id,name', 'responsibleTeam:id,name'])->get()
            ->map(fn ($task) => $this->task($task, $actor))->all();
        $data['events'] = $data['can_manage'] ? $record->events->map(fn ($event) => ['id' => $event->id, 'type' => $event->type,
            'actor' => $event->actor?->name, 'at' => $event->created_at?->toIso8601String(), 'details' => $event->payload])->all() : [];
        $data['original_contract'] = $data['can_manage'] ? $record->templateVersion?->contract : null;

        return Inertia::render('it/provisioning/workflow', ['actorId' => (int) $actor->id, 'workflow' => $data]);
    }

    public function showTemplate(Request $request, int $template): Response
    {
        abort_unless($this->readiness->storageReady(), 409, 'Complete provisioning history setup before reviewing publication.');
        $actor = $request->user();
        $record = ItProvisioningTemplate::query()->with('publishedVersion')->findOrFail($template);
        abort_unless(app(ItProvisioningTemplatePublicationService::class)->canView($actor, $record), 404);
        $versions = ItProvisioningTemplateVersion::query()->where('provisioning_template_id', $record->id)->orderByDesc('version')->get()
            ->filter(fn ($version) => app(ItProvisioningTemplatePublicationService::class)->canViewVersion($actor, $version));

        return Inertia::render('it/provisioning/template', ['actorId' => (int) $actor->id, 'template' => [
            'id' => (int) $record->id, 'name' => $record->name, 'version' => (int) $record->lock_version,
            'current_version_id' => $record->current_version_id, 'published_version_id' => $record->published_version_id,
            'published_at' => $record->published_at?->toIso8601String(), 'lifecycle_type' => $record->lifecycle_type,
            'versions' => $versions->map(fn ($version) => ['id' => (int) $version->id, 'version' => (int) $version->version,
                'recorded_at' => $version->created_at?->toIso8601String(), 'provenance' => $version->provenance, 'contract' => $version->contract])->all(),
        ]]);
    }

    public function options(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor->approved_at !== null && app(HrCurrentStaffService::class)->isCurrent($actor) && $actor->canDo('it.manage'), 403);
        $input = $request->validate(['actor_user_id' => ['required', 'integer'], 'kind' => ['required', 'in:employees,agents,templates,identity,asset_assignment,device_assignment'],
            'context_kind' => ['nullable', 'in:request,workflow,launch,catalogue'], 'context_id' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:100'], 'selected_id' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'min:1']]);
        abort_unless((int) $input['actor_user_id'] === (int) $actor->id, 403);
        $siteId = null;
        $profile = null;
        if (($input['context_kind'] ?? null) === 'request') {
            $task = ItProvisioningRequest::query()->findOrFail($input['context_id'] ?? 0);
            abort_unless($this->access->canManage($actor, $task), 404);
            $siteId = $this->access->siteIdFor($task);
        } elseif (($input['context_kind'] ?? null) === 'workflow') {
            $workflow = ItProvisioningWorkflow::query()->findOrFail($input['context_id'] ?? 0);
            abort_unless($this->access->canManageWorkflow($actor, $workflow), 404);
            $siteId = $workflow->site_id_snapshot ?? $workflow->employeeProfile?->primary_site_id;
        } elseif (($input['context_kind'] ?? null) === 'launch') {
            $profile = $this->access->selectableProfiles($actor)->whereKey($input['context_id'] ?? 0)->firstOrFail();
            $siteId = $profile->primary_site_id;
        }
        $query = mb_strtolower(trim($input['q'] ?? ''));
        if ($input['kind'] === 'employees') {
            $options = $this->access->selectableProfiles($actor)->with('user:id,name')->orderBy('id')->get()
                ->map(fn ($employee) => ['id' => (int) $employee->id, 'label' => ($employee->user?->name ?? 'Employee').' · '.$employee->employee_number]);
        } elseif ($input['kind'] === 'agents') {
            abort_unless(isset($input['context_kind']), 422);
            $options = ItStaffDirectory::agents()->filter(fn ($agent) => app(ItProvisioningResponsibilityService::class)->eligible((int) $agent->id, $siteId) !== null)
                ->map(fn ($agent) => ['id' => (int) $agent->id, 'label' => $agent->name]);
        } elseif (in_array($input['kind'], ['identity', 'asset_assignment', 'device_assignment'], true)) {
            abort_unless(($input['context_kind'] ?? null) === 'request' && isset($task), 422);
            $options = app(ItProvisioningCanonicalTargetService::class)->options($actor, $task, $input['kind']);
        } else {
            abort_unless(($profile || ($input['context_kind'] ?? null) === 'catalogue') && $this->readiness->storageReady(), 422);
            $options = ItProvisioningTemplate::query()->whereNotNull('published_version_id')->with('publishedVersion')->orderBy('id')->get()
                ->filter(function ($template) use ($profile, $actor): bool {
                    if (! $template->publishedVersion || ! app(ItProvisioningTemplatePublicationService::class)->canViewVersion($actor, $template->publishedVersion)) {
                        return false;
                    }
                    $contract = $template->publishedVersion?->contract ?? [];
                    foreach (['site_id' => 'primary_site_id', 'position_role' => 'position_role', 'employment_type' => 'employment_type'] as $field => $profileField) {
                        if ($profile && ($contract[$field] ?? null) !== null && (string) $contract[$field] !== (string) $profile->{$profileField}) {
                            return false;
                        }
                    }

                    return $contract !== [] && ($contract['is_active'] ?? false);
                })->map(fn ($template) => ['id' => (int) $template->publishedVersion->id,
                    'label' => $template->publishedVersion->contract['name'].' · Version '.$template->publishedVersion->version,
                    'lifecycle_type' => $template->publishedVersion->contract['lifecycle_type'],
                    'tasks' => collect($template->publishedVersion->contract['tasks'] ?? [])->map(fn (array $task) => Arr::only($task, ['task_key', 'title', 'description', 'action', 'stage', 'dependency_task_keys',
                        'approval_required', 'evidence_required', 'due_offset_days']))->values()->all()]);
        }
        $selected = isset($input['selected_id']) ? $options->firstWhere('id', (int) $input['selected_id']) : null;
        $filtered = $options->filter(fn ($option) => $query === '' || str_contains(mb_strtolower($option['label']), $query))->values();
        $page = max(1, (int) ($input['page'] ?? 1));

        return response()->json(['actor_user_id' => (int) $actor->id, 'kind' => $input['kind'],
            'context_kind' => $input['context_kind'] ?? null, 'context_id' => isset($input['context_id']) ? (int) $input['context_id'] : null,
            'options' => $filtered->slice(($page - 1) * 25, 25)->values()->all(), 'selected' => $selected,
            'page' => $page, 'has_more' => $filtered->count() > $page * 25])->header('Cache-Control', 'no-store');
    }

    public function command(Request $request, string $kind, int $target, string $operation)
    {
        try {
            $result = app(ItProvisioningCommandService::class)->execute($request->user(), $kind, $target, $operation, $request->all());

            return response()->json($result)->header('Cache-Control', 'no-store');
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => ['command' => [$exception->getMessage()]]], 422);
        }
    }

    public function recover(Request $request, string $kind, int $target, string $operation)
    {
        return response()->json(app(ItProvisioningCommandService::class)->lookup($request->user(), $kind, $target, $operation, $request->all()))
            ->header('Cache-Control', 'no-store');
    }

    public function cancelCommand(Request $request, string $kind, int $target, string $operation)
    {
        return response()->json(app(ItProvisioningCommandService::class)->lookup($request->user(), $kind, $target, $operation, $request->all(), true))
            ->header('Cache-Control', 'no-store');
    }

    private function task(ItProvisioningRequest $task, User $actor): array
    {
        return ['id' => (int) $task->id, 'version' => (int) $task->lock_version,
            'reference' => 'IT-P'.str_pad((string) $task->id, 6, '0', STR_PAD_LEFT),
            'title' => $task->item, 'href' => '/it/provisioning/tasks/'.$task->id, 'status' => $task->status,
            'employee' => ['id' => (int) $task->employee_profile_id, 'name' => $task->employeeProfile?->user?->name ?? 'Employee'],
            'workflow' => $task->workflow ? ['id' => (int) $task->workflow->id, 'lifecycle_type' => $task->workflow->lifecycle_type,
                'href' => '/it/provisioning/workflows/'.$task->workflow->id] : null,
            'type' => $task->type, 'category' => $task->category, 'action' => $task->action, 'stage' => $task->stage,
            'due_date' => $task->due_date?->toDateString(), 'priority' => $task->priority,
            'approval_required' => $task->approval_required, 'approval_status' => $task->approval_status,
            'evidence_required' => $task->evidence_required, 'reversal_of_request_id' => $task->reversal_of_request_id,
            'assignee' => $task->assignee ? ['id' => (int) $task->assignee->id, 'name' => $task->assignee->name] : null,
            'team' => $task->responsibleTeam?->name,
            'readiness' => $this->readiness->forRequest($task, $actor)];
    }

    private function workflow(ItProvisioningWorkflow $workflow, User $actor): array
    {
        $visible = $this->access->applyRequestScope($workflow->requests()->getQuery(), $actor);

        return ['id' => (int) $workflow->id, 'version' => (int) $workflow->lock_version,
            'href' => '/it/provisioning/workflows/'.$workflow->id, 'status' => $workflow->status,
            'lifecycle_type' => $workflow->lifecycle_type,
            'employee' => ['id' => (int) $workflow->employee_profile_id, 'name' => $workflow->employeeProfile?->user?->name ?? 'Employee'],
            'effective_at' => $workflow->effective_at?->toIso8601String(), 'original_effective_at' => $workflow->original_effective_at?->toIso8601String(),
            'owner' => $workflow->owner ? ['id' => (int) $workflow->owner->id, 'name' => $workflow->owner->name] : null,
            'cover' => $workflow->cover ? ['id' => (int) $workflow->cover->id, 'name' => $workflow->cover->name] : null,
            'template' => ['name' => $workflow->templateVersion?->contract['name'] ?? 'Original template',
                'version' => $workflow->templateVersion?->version],
            'source_type' => $workflow->source_type, 'source_id' => $workflow->source_id,
            'source_href' => $this->sourceHref($workflow, $actor),
            'progress' => ['total' => (clone $visible)->count(), 'done' => (clone $visible)->where('status', 'done')->count(),
                'failed' => (clone $visible)->where('status', 'failed')->count(), 'cancelled' => (clone $visible)->where('status', 'cancelled')->count()],
            'cancelled_at' => $workflow->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->access->canManageWorkflow($actor, $workflow) ? $workflow->cancellation_reason : null,
            'can_manage' => $this->readiness->storageReady() && $this->access->canManageWorkflow($actor, $workflow)];
    }

    /** The HR source link is separate from permission to see IT work. */
    private function sourceHref(ItProvisioningWorkflow $workflow, User $actor): ?string
    {
        if (! $actor->canDo('hr.onboarding.view')) {
            return null;
        }
        $access = app(HrLifecycleAccessService::class);
        if ($workflow->source_type === 'hr_onboarding'
            && $access->visibleOnboardingChecklists($actor)->whereKey($workflow->source_id)->exists()) {
            return '/hr/onboarding/'.$workflow->source_id;
        }
        if ($workflow->source_type === 'hr_offboarding'
            && $access->visibleOffboardingChecklists($actor)->whereKey($workflow->source_id)->exists()) {
            return '/hr/offboarding/'.$workflow->source_id;
        }

        return null;
    }
}
