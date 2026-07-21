<?php

namespace App\Domain\It\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOffboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\AssetAssignment;
use App\Models\ItProvisioningRequest;
use App\Models\ItProvisioningTemplate;
use App\Models\ItProvisioningTemplateTask;
use App\Models\ItProvisioningWorkflow;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\LegacyStorageContext;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ItProvisioningWorkflowService
{
    public function resolveTemplate(HrEmployeeProfile $profile, string $lifecycleType): ?ItProvisioningTemplate
    {
        if (! in_array($lifecycleType, ItProvisioningTemplate::LIFECYCLE_TYPES, true)) {
            throw new DomainException('Unsupported provisioning lifecycle type.');
        }

        return ItProvisioningTemplate::query()
            ->active()
            ->where('lifecycle_type', $lifecycleType)
            ->where(fn ($query) => $query
                ->whereNull('position_role')
                ->orWhere('position_role', $profile->position_role))
            ->where(fn ($query) => $query
                ->whereNull('site_id')
                ->orWhere('site_id', $profile->primary_site_id))
            ->where(fn ($query) => $query
                ->whereNull('employment_type')
                ->orWhere('employment_type', $profile->employment_type))
            ->with('tasks')
            ->get()
            ->sortByDesc(fn (ItProvisioningTemplate $template) => [
                $this->specificity($template, $profile),
                (int) $template->selection_priority,
                (int) $template->id,
            ])
            ->first();
    }

    /**
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     */
    public function launch(
        HrEmployeeProfile $profile,
        string $lifecycleType,
        string $sourceType,
        int $sourceId,
        string $sourceEventKey,
        int $actorId,
        ?CarbonInterface $effectiveAt = null,
        array $changes = [],
        ?HrOnboardingChecklist $onboardingChecklist = null,
        ?HrOffboardingChecklist $offboardingChecklist = null,
    ): ItProvisioningWorkflow {
        $sourceEventKey = trim($sourceEventKey);
        if ($sourceEventKey === '' || strlen($sourceEventKey) > 191) {
            throw new DomainException('A stable HR source event key is required for provisioning.');
        }

        $existing = ItProvisioningWorkflow::query()
            ->where('source_event_key', $sourceEventKey)
            ->first();
        if ($existing) {
            return $this->loaded($existing);
        }

        $template = $this->resolveTemplate($profile, $lifecycleType);
        if (! $template) {
            throw new DomainException("No active {$lifecycleType} provisioning template matches this employee.");
        }

        return DB::transaction(function () use (
            $profile,
            $lifecycleType,
            $sourceType,
            $sourceId,
            $sourceEventKey,
            $actorId,
            $effectiveAt,
            $changes,
            $onboardingChecklist,
            $offboardingChecklist,
            $template,
        ): ItProvisioningWorkflow {
            $locked = ItProvisioningWorkflow::query()
                ->where('source_event_key', $sourceEventKey)
                ->lockForUpdate()
                ->first();
            if ($locked) {
                return $this->loaded($locked);
            }

            $profile->loadMissing(['primarySite:id,name', 'user:id,name,email', 'manager:id,name']);
            $effective = $this->effectiveAt($profile, $lifecycleType, $effectiveAt, $offboardingChecklist);
            $safeChanges = array_intersect_key($changes, array_flip(ItProvisioningTemplateTask::TRIGGER_FIELDS));

            $workflow = ItProvisioningWorkflow::query()->create([
                'tenant_id' => LegacyStorageContext::id(),
                'employee_profile_id' => $profile->id,
                'provisioning_template_id' => $template->id,
                'lifecycle_type' => $lifecycleType,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_event_key' => $sourceEventKey,
                'status' => 'pending',
                'effective_at' => $effective,
                'role_snapshot' => $profile->position_role,
                'site_id_snapshot' => $profile->primary_site_id,
                'employment_type_snapshot' => $profile->employment_type,
                'changes' => $safeChanges,
                'created_by_user_id' => $actorId,
            ]);

            $tasks = $template->tasks
                ->filter(fn (ItProvisioningTemplateTask $task) => $this->appliesToChanges($task, $lifecycleType, $safeChanges))
                ->values();
            $created = collect();
            foreach ($tasks as $task) {
                $created->put($task->task_key, $this->createRequest(
                    workflow: $workflow,
                    task: $task,
                    profile: $profile,
                    actorId: $actorId,
                    effectiveAt: $effective,
                    onboardingChecklist: $onboardingChecklist,
                    offboardingChecklist: $offboardingChecklist,
                    changes: $safeChanges,
                ));
            }

            foreach ($tasks as $task) {
                $request = $created->get($task->task_key);
                if (! $request instanceof ItProvisioningRequest) {
                    continue;
                }

                $dependencyIds = collect($task->dependency_task_keys ?? [])
                    ->map(fn (string $key) => $created->get($key)?->id)
                    ->filter()->values()->all();
                if ($dependencyIds !== []) {
                    $request->update(['dependency_request_ids' => $dependencyIds]);
                }
            }

            if ($lifecycleType === 'leaver') {
                $this->appendRecoveryRequests($workflow, $profile, $actorId, $effective, $offboardingChecklist);
            }

            if (! $workflow->requests()->exists()) {
                $workflow->update(['status' => 'completed']);
            }

            AuditLogger::logOrFail('it.provisioning.workflow.created', $workflow, [
                'application_scope' => 'single_installation',
                'actor_id' => $actorId,
                'lifecycle_type' => $lifecycleType,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'template_id' => $template->id,
                'request_count' => $workflow->requests()->count(),
            ]);

            return $this->loaded($workflow);
        });
    }

    public function tryLaunchFromOnboarding(HrOnboardingChecklist $checklist, int $actorId): ?ItProvisioningWorkflow
    {
        $checklist->loadMissing(['employeeProfile.primarySite', 'tasks']);
        if (! $checklist->employeeProfile || ! $this->resolveTemplate($checklist->employeeProfile, 'joiner')) {
            return null;
        }

        return $this->launchFromOnboarding($checklist, $actorId);
    }

    public function launchFromOnboarding(HrOnboardingChecklist $checklist, int $actorId): ItProvisioningWorkflow
    {
        $checklist->loadMissing(['employeeProfile.primarySite', 'tasks']);
        $profile = $checklist->employeeProfile;
        if (! $profile) {
            throw new DomainException('The onboarding checklist has no employee profile.');
        }

        return $this->launch(
            profile: $profile,
            lifecycleType: 'joiner',
            sourceType: 'hr_onboarding',
            sourceId: $checklist->id,
            sourceEventKey: "onboarding:{$checklist->id}:generated",
            actorId: $actorId,
            effectiveAt: $profile->start_date ? Carbon::parse($profile->start_date) : null,
            onboardingChecklist: $checklist,
        );
    }

    public function tryLaunchFromOffboarding(HrOffboardingChecklist $checklist, int $actorId): ?ItProvisioningWorkflow
    {
        $checklist->loadMissing(['employeeProfile.primarySite', 'tasks']);
        if (! $checklist->employeeProfile || ! $this->resolveTemplate($checklist->employeeProfile, 'leaver')) {
            return null;
        }

        return $this->launchFromOffboarding($checklist, $actorId);
    }

    public function launchFromOffboarding(HrOffboardingChecklist $checklist, int $actorId): ItProvisioningWorkflow
    {
        $checklist->loadMissing(['employeeProfile.primarySite', 'tasks']);
        $profile = $checklist->employeeProfile;
        if (! $profile) {
            throw new DomainException('The offboarding checklist has no employee profile.');
        }

        return $this->launch(
            profile: $profile,
            lifecycleType: 'leaver',
            sourceType: 'hr_offboarding',
            sourceId: $checklist->id,
            sourceEventKey: "offboarding:{$checklist->id}:generated",
            actorId: $actorId,
            effectiveAt: $checklist->due_date ? Carbon::parse($checklist->due_date) : null,
            offboardingChecklist: $checklist,
        );
    }

    /** @param array<string, array{from: mixed, to: mixed}> $changes */
    public function tryLaunchMover(
        HrEmployeeProfile $profile,
        array $changes,
        string $sourceEventKey,
        int $actorId,
        ?CarbonInterface $effectiveAt = null,
    ): ?ItProvisioningWorkflow {
        if ($changes === [] || ! $this->resolveTemplate($profile, 'mover')) {
            return null;
        }

        return $this->launch(
            profile: $profile,
            lifecycleType: 'mover',
            sourceType: 'hr_profile_update',
            sourceId: $profile->id,
            sourceEventKey: $sourceEventKey,
            actorId: $actorId,
            effectiveAt: $effectiveAt,
            changes: $changes,
        );
    }

    private function specificity(ItProvisioningTemplate $template, HrEmployeeProfile $profile): int
    {
        return ($template->position_role === $profile->position_role ? 4 : 0)
            + ((int) $template->site_id === (int) $profile->primary_site_id && $template->site_id !== null ? 2 : 0)
            + ($template->employment_type === $profile->employment_type ? 1 : 0);
    }

    /** @param array<string, mixed> $changes */
    private function appliesToChanges(
        ItProvisioningTemplateTask $task,
        string $lifecycleType,
        array $changes,
    ): bool {
        if ($lifecycleType !== 'mover') {
            return true;
        }

        $triggers = $task->trigger_fields ?? [];

        return $triggers === [] || array_intersect($triggers, array_keys($changes)) !== [];
    }

    /** @param array<string, mixed> $changes */
    private function createRequest(
        ItProvisioningWorkflow $workflow,
        ItProvisioningTemplateTask $task,
        HrEmployeeProfile $profile,
        int $actorId,
        CarbonInterface $effectiveAt,
        ?HrOnboardingChecklist $onboardingChecklist,
        ?HrOffboardingChecklist $offboardingChecklist,
        array $changes,
    ): ItProvisioningRequest {
        return ItProvisioningRequest::query()->create([
            'tenant_id' => LegacyStorageContext::id(),
            'employee_profile_id' => $profile->id,
            'provisioning_workflow_id' => $workflow->id,
            'provisioning_template_task_id' => $task->id,
            'onboarding_task_id' => $this->matchingTaskId($onboardingChecklist?->tasks, $task->title),
            'offboarding_task_id' => $this->matchingTaskId($offboardingChecklist?->tasks, $task->title),
            'type' => $task->request_type,
            'task_key' => $task->task_key,
            'action' => $task->action,
            'category' => $task->category,
            'item' => $task->title,
            'responsible_team_id' => $task->responsible_team_id,
            'stage' => $task->stage,
            'dependency_request_ids' => [],
            'approval_required' => $task->approval_required,
            'approval_status' => $task->approval_required ? 'pending' : 'not_required',
            'evidence_required' => $task->evidence_required,
            'fulfiller_context' => $this->fulfillerContext($profile, $task->fulfiller_fields ?? [], $changes),
            'status' => 'pending',
            'priority' => 'normal',
            'due_date' => $effectiveAt->copy()->addDays($task->due_offset_days)->toDateString(),
            'notes' => $task->description,
            'created_by' => $actorId,
        ]);
    }

    private function appendRecoveryRequests(
        ItProvisioningWorkflow $workflow,
        HrEmployeeProfile $profile,
        int $actorId,
        CarbonInterface $effectiveAt,
        ?HrOffboardingChecklist $checklist,
    ): void {
        if (! $profile->user_id) {
            return;
        }

        $stage = max(1, ((int) $workflow->requests()->max('stage')) + 1);
        $sourceTaskId = $checklist?->tasks
            ?->first(fn ($task) => Str::contains(Str::lower($task->title), ['collect company equipment', 'return equipment']))
            ?->id;
        $baseContext = $this->fulfillerContext(
            $profile,
            ItProvisioningTemplateTask::FULFILLER_FIELDS,
            [],
        );

        AssetAssignment::query()
            ->with('asset:id,name,asset_tag,serial_number')
            ->whereIn('assignee_type', ['staff', 'user', User::class])
            ->where('assignee_id', $profile->user_id)
            ->whereNull('released_at')
            ->each(function (AssetAssignment $assignment) use (
                $workflow, $profile, $actorId, $effectiveAt, $stage, $sourceTaskId, $baseContext,
            ): void {
                ItProvisioningRequest::query()->create([
                    'tenant_id' => LegacyStorageContext::id(),
                    'employee_profile_id' => $profile->id,
                    'provisioning_workflow_id' => $workflow->id,
                    'offboarding_task_id' => $sourceTaskId,
                    'type' => 'equipment',
                    'task_key' => "recover-asset-assignment-{$assignment->id}",
                    'action' => 'recover',
                    'category' => 'equipment',
                    'item' => 'Recover asset: '.($assignment->asset?->name ?? 'Assigned asset'),
                    'stage' => $stage,
                    'dependency_request_ids' => [],
                    'approval_required' => false,
                    'approval_status' => 'not_required',
                    'evidence_required' => true,
                    'fulfiller_context' => [
                        ...$baseContext,
                        'asset' => [
                            'id' => $assignment->asset_id,
                            'name' => $assignment->asset?->name,
                            'asset_tag' => $assignment->asset?->asset_tag,
                            'serial_number' => $assignment->asset?->serial_number,
                        ],
                        'canonical_owner' => 'assets',
                    ],
                    'canonical_target_type' => 'asset_assignment',
                    'canonical_target_id' => $assignment->id,
                    'status' => 'pending',
                    'priority' => 'high',
                    'due_date' => $effectiveAt->toDateString(),
                    'created_by' => $actorId,
                ]);
            });

        DeviceAssignment::query()
            ->with('device:id,name,device_uid,asset_tag,serial_number')
            ->where('assignable_type', DeviceAssignment::TARGET_STAFF)
            ->where('assignable_id', $profile->user_id)
            ->whereNull('released_at')
            ->each(function (DeviceAssignment $assignment) use (
                $workflow, $profile, $actorId, $effectiveAt, $stage, $sourceTaskId, $baseContext,
            ): void {
                ItProvisioningRequest::query()->create([
                    'tenant_id' => LegacyStorageContext::id(),
                    'employee_profile_id' => $profile->id,
                    'provisioning_workflow_id' => $workflow->id,
                    'offboarding_task_id' => $sourceTaskId,
                    'type' => 'equipment',
                    'task_key' => "recover-device-assignment-{$assignment->id}",
                    'action' => 'recover',
                    'category' => 'equipment',
                    'item' => 'Recover device: '.($assignment->device?->name ?? 'Assigned device'),
                    'stage' => $stage,
                    'dependency_request_ids' => [],
                    'approval_required' => false,
                    'approval_status' => 'not_required',
                    'evidence_required' => true,
                    'fulfiller_context' => [
                        ...$baseContext,
                        'device' => [
                            'id' => $assignment->device_id,
                            'uid' => $assignment->device?->device_uid,
                            'name' => $assignment->device?->name,
                            'asset_tag' => $assignment->device?->asset_tag,
                            'serial_number' => $assignment->device?->serial_number,
                        ],
                        'canonical_owner' => 'security_devices',
                    ],
                    'canonical_target_type' => 'device_assignment',
                    'canonical_target_id' => $assignment->id,
                    'status' => 'pending',
                    'priority' => 'high',
                    'due_date' => $effectiveAt->toDateString(),
                    'created_by' => $actorId,
                ]);
            });
    }

    /** @param Collection<int, mixed>|null $tasks */
    private function matchingTaskId(?Collection $tasks, string $title): ?int
    {
        $normal = Str::lower(trim($title));
        $task = $tasks?->first(fn ($candidate) => Str::lower(trim((string) $candidate->title)) === $normal);

        return $task?->id;
    }

    /**
     * @param  array<int, string>  $fields
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function fulfillerContext(HrEmployeeProfile $profile, array $fields, array $changes): array
    {
        $allowed = array_intersect($fields, ItProvisioningTemplateTask::FULFILLER_FIELDS);
        $values = [
            'employee_number' => $profile->employee_number,
            'work_email' => $profile->work_email,
            'position_title' => $profile->position_title,
            'position_role' => $profile->position_role,
            'employment_type' => $profile->employment_type,
            'primary_site' => $profile->primarySite
                ? ['id' => $profile->primarySite->id, 'name' => $profile->primarySite->name]
                : null,
            'manager' => $profile->manager
                ? ['id' => $profile->manager->id, 'name' => $profile->manager->name]
                : null,
        ];
        $context = array_intersect_key($values, array_flip($allowed));
        if ($changes !== []) {
            $context['changes'] = $changes;
        }

        return $context;
    }

    private function effectiveAt(
        HrEmployeeProfile $profile,
        string $lifecycleType,
        ?CarbonInterface $effectiveAt,
        ?HrOffboardingChecklist $checklist,
    ): CarbonInterface {
        if ($effectiveAt) {
            return $effectiveAt->copy();
        }
        if ($lifecycleType === 'joiner' && $profile->start_date) {
            return Carbon::parse($profile->start_date);
        }
        if ($lifecycleType === 'leaver' && $checklist?->due_date) {
            return Carbon::parse($checklist->due_date);
        }

        return now();
    }

    private function loaded(ItProvisioningWorkflow $workflow): ItProvisioningWorkflow
    {
        return $workflow->load([
            'template:id,name,lifecycle_type',
            'employeeProfile.user:id,name,email',
            'requests.responsibleTeam:id,name',
        ]);
    }
}
