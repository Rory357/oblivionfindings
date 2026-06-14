<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOffboardingChecklist;
use App\Domain\Hr\Models\HrOffboardingTask;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Domain\Hr\Notifications\OnboardingChecklistAssignedNotification;
use App\Domain\Hr\Notifications\OnboardingTaskAssignedNotification;
use App\Models\AssetAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class OnboardingService
{
    /**
     * Generate an onboarding checklist for a new employee from the matching template.
     *
     * Looks up the active HrOnboardingTemplate by the employee's position_role and
     * creates an HrOnboardingChecklist with individual HrOnboardingTask rows cloned
     * from the template's tasks JSON.
     *
     * @param  int  $createdBy  User ID initiating the onboarding
     * @param  int|null  $templateId  Optional explicit template (overrides role/site auto-match)
     *
     * @throws \RuntimeException If no active template matches the employee's role
     */
    public function generateChecklist(HrEmployeeProfile $profile, int $createdBy, ?int $templateId = null): HrOnboardingChecklist
    {
        return DB::transaction(function () use ($profile, $createdBy, $templateId) {
            $profile->loadMissing('primarySite');

            if ($templateId !== null) {
                $template = HrOnboardingTemplate::query()
                    ->where('id', $templateId)
                    ->where('tenant_id', $profile->tenant_id)
                    ->where('is_active', true)
                    ->first();
            } else {
                $siteType = $profile->primarySite?->type ?? 'all';
                $template = $this->resolveTemplate($profile->tenant_id, $profile->position_role, $siteType);
            }

            if (! $template) {
                throw new \RuntimeException(
                    "No active onboarding template found for role '{$profile->position_role}'."
                );
            }

            $checklist = HrOnboardingChecklist::create([
                'tenant_id' => $profile->tenant_id,
                'employee_profile_id' => $profile->id,
                'template_key' => "{$template->role}:{$template->site_type}",
                'status' => 'pending',
                'started_at' => now(),
                'due_date' => Carbon::parse($profile->start_date ?? now())->addDays(30),
                'created_by' => $createdBy,
            ]);

            $tasks = $template->tasks ?? [];
            $taskByIndex = [];
            foreach ($tasks as $index => $taskDef) {
                $assigneeId = $this->resolveAssignee(
                    $taskDef['assigned_to_user_id'] ?? null,
                    $taskDef['assigned_to_role'] ?? null,
                    $profile,
                );
                $offsetDays = (int) ($taskDef['due_days_offset'] ?? $taskDef['offset_days'] ?? 0);
                $dueDate = $profile->start_date
                    ? Carbon::parse($profile->start_date)->addDays($offsetDays)->toDateString()
                    : null;

                $task = HrOnboardingTask::create([
                    'checklist_id' => $checklist->id,
                    'category' => $taskDef['category'] ?? 'general',
                    'title' => $taskDef['title'],
                    'description' => $taskDef['description'] ?? null,
                    'is_required' => $taskDef['is_required'] ?? true,
                    'sort_order' => $taskDef['sort_order'] ?? ($index + 1),
                    'assigned_to_user_id' => $assigneeId,
                    'assigned_to_role' => $taskDef['assigned_to_role'] ?? null,
                    'sign_off_required' => $taskDef['sign_off_required'] ?? false,
                    'due_date' => $dueDate,
                    'status' => 'pending',
                ]);

                $taskByIndex[$index] = $task;
            }

            foreach ($tasks as $index => $taskDef) {
                $dependencyIndexes = collect($taskDef['dependency_indexes'] ?? $taskDef['depends_on'] ?? [])
                    ->filter(fn ($value) => is_numeric($value))
                    ->map(fn ($value) => (int) $value)
                    ->values();

                if ($dependencyIndexes->isEmpty() || ! isset($taskByIndex[$index])) {
                    continue;
                }

                $dependencyIds = $dependencyIndexes
                    ->map(fn (int $dependencyIndex) => $taskByIndex[$dependencyIndex]->id ?? null)
                    ->filter()
                    ->values()
                    ->all();

                if ($dependencyIds !== []) {
                    $taskByIndex[$index]->update(['dependency_task_ids' => $dependencyIds]);
                }
            }

            foreach ($taskByIndex as $task) {
                if (! $task->assigned_to_user_id) {
                    continue;
                }

                $assignee = User::find($task->assigned_to_user_id);
                if (! $assignee) {
                    continue;
                }

                try {
                    $assignee->notify(new OnboardingTaskAssignedNotification($task));
                } catch (\Throwable $exception) {
                    Log::warning('Failed to notify onboarding task assignee', [
                        'task_id' => $task->id,
                        'assignee_id' => $assignee->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $subjectUser = $profile->user;
            if ($subjectUser) {
                try {
                    $subjectUser->notify(new OnboardingChecklistAssignedNotification($checklist->fresh('tasks')));
                } catch (\Throwable $exception) {
                    Log::warning('Failed to notify onboarding checklist subject', [
                        'checklist_id' => $checklist->id,
                        'user_id' => $subjectUser->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            return $checklist->load('tasks');
        });
    }

    /**
     * Complete an onboarding task with validation.
     *
     * Validates the task belongs to an active checklist, records evidence if
     * provided, and checks whether the entire checklist is now complete.
     *
     * @param  int  $completedBy  User ID completing the task
     * @param  array  $data  Optional data: evidence_path, notes, signed_off_by
     *
     * @throws \LogicException If task is already completed or checklist is not active
     */
    public function completeTask(HrOnboardingTask $task, int $completedBy, array $data = []): HrOnboardingTask
    {
        if ($task->status === 'completed') {
            throw new \LogicException("Task '{$task->title}' is already completed.");
        }

        $checklist = $task->checklist()->with('tasks')->firstOrFail();
        if (! in_array($checklist->status, ['pending', 'in_progress'], true)) {
            throw new \LogicException("Cannot complete tasks on a '{$checklist->status}' checklist.");
        }

        $dependencyTaskIds = collect($task->dependency_task_ids ?? [])->map(fn ($id) => (int) $id)->filter();
        if ($dependencyTaskIds->isNotEmpty()) {
            $completedDependencies = $checklist->tasks
                ->whereIn('id', $dependencyTaskIds->all())
                ->where('status', 'completed')
                ->count();

            if ($completedDependencies !== $dependencyTaskIds->count()) {
                throw new \LogicException('This task cannot be completed until all dependency tasks are complete.');
            }
        }

        if ($task->sign_off_required && empty($data['signed_off_by'])) {
            throw new \LogicException("Task '{$task->title}' requires sign-off.");
        }

        $task->update([
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by' => $completedBy,
            'evidence_path' => $data['evidence_path'] ?? $task->evidence_path,
            'notes' => $data['notes'] ?? $task->notes,
            'signed_off_by' => $data['signed_off_by'] ?? $task->signed_off_by,
            'signed_off_at' => isset($data['signed_off_by']) ? now() : $task->signed_off_at,
        ]);

        if ($checklist->status === 'pending') {
            $checklist->update(['status' => 'in_progress']);
        }

        $this->checkChecklistCompletion($checklist);

        return $task->fresh();
    }

    /**
     * Complete an offboarding task with dependency + sign-off validation.
     *
     * @throws \LogicException
     */
    public function completeOffboardingTask(HrOffboardingTask $task, int $completedBy, array $data = []): HrOffboardingTask
    {
        if ($task->status === 'completed') {
            throw new \LogicException("Task '{$task->title}' is already completed.");
        }

        $checklist = $task->checklist()->with(['tasks', 'employeeProfile'])->firstOrFail();
        if (! in_array($checklist->status, ['pending', 'in_progress'], true)) {
            throw new \LogicException("Cannot complete tasks on a '{$checklist->status}' checklist.");
        }

        $dependencyTaskIds = collect($task->dependency_task_ids ?? [])->map(fn ($id) => (int) $id)->filter();
        if ($dependencyTaskIds->isNotEmpty()) {
            $completedDependencies = $checklist->tasks
                ->whereIn('id', $dependencyTaskIds->all())
                ->where('status', 'completed')
                ->count();

            if ($completedDependencies !== $dependencyTaskIds->count()) {
                throw new \LogicException('This task cannot be completed until all dependency tasks are complete.');
            }
        }

        if ($task->sign_off_required && empty($data['signed_off_by'])) {
            throw new \LogicException("Task '{$task->title}' requires sign-off.");
        }

        $task->update([
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by' => $completedBy,
            'evidence_path' => $data['evidence_path'] ?? $task->evidence_path,
            'notes' => $data['notes'] ?? $task->notes,
            'signed_off_by' => $data['signed_off_by'] ?? $task->signed_off_by,
            'signed_off_at' => isset($data['signed_off_by']) ? now() : $task->signed_off_at,
        ]);

        if ($checklist->status === 'pending') {
            $checklist->update(['status' => 'in_progress']);
        }

        $this->checkOffboardingChecklistCompletion($checklist);

        return $task->fresh();
    }

    /**
     * Generate an offboarding checklist for a departing employee.
     *
     * Creates an HrOffboardingChecklist with standard departure tasks
     * (IT access revocation, equipment return, exit interview, etc.).
     *
     * @param  int  $createdBy  User ID initiating the offboarding
     * @param  array  $options  Optional overrides: end_date, termination_reason
     */
    public function generateOffboardingChecklist(HrEmployeeProfile $profile, int $createdBy, array $options = []): HrOffboardingChecklist
    {
        return DB::transaction(function () use ($profile, $createdBy, $options) {
            $endDate = $options['end_date'] ?? $profile->end_date ?? now()->addWeeks(2);
            $offboardingTemplate = HrOnboardingTemplate::query()
                ->forTenant($profile->tenant_id)
                ->active()
                ->where('role', 'offboarding:'.$profile->position_role)
                ->first();

            $checklist = HrOffboardingChecklist::create([
                'tenant_id' => $profile->tenant_id,
                'employee_profile_id' => $profile->id,
                'template_key' => $offboardingTemplate?->role ?? 'offboarding:default',
                'status' => 'pending',
                'started_at' => now(),
                'due_date' => $endDate,
                'created_by' => $createdBy,
            ]);

            $tasks = $offboardingTemplate?->tasks ?: $this->getDefaultOffboardingTasks();
            $taskByIndex = [];
            $equipmentCollectionTaskId = null;
            $equipmentCollectionRole = 'hr_admin';
            foreach ($tasks as $index => $taskDef) {
                $assigneeId = $this->resolveAssignee(
                    $taskDef['assigned_to_user_id'] ?? null,
                    $taskDef['assigned_to_role'] ?? null,
                    $profile,
                );
                $offsetDays = (int) ($taskDef['due_days_offset'] ?? 0);
                $dueDate = Carbon::parse($endDate)->subDays(max($offsetDays, 0))->toDateString();

                $task = HrOffboardingTask::create([
                    'offboarding_checklist_id' => $checklist->id,
                    'category' => $taskDef['category'],
                    'title' => $taskDef['title'],
                    'description' => $taskDef['description'],
                    'is_required' => $taskDef['is_required'],
                    'sort_order' => $index + 1,
                    'assigned_to_user_id' => $assigneeId,
                    'assigned_to_role' => $taskDef['assigned_to_role'] ?? null,
                    'sign_off_required' => $taskDef['sign_off_required'] ?? false,
                    'due_date' => $dueDate,
                    'status' => 'pending',
                ]);

                $taskByIndex[$index] = $task;

                $taskTitle = strtolower((string) ($taskDef['title'] ?? ''));
                if ($equipmentCollectionTaskId === null && str_contains($taskTitle, 'collect company equipment')) {
                    $equipmentCollectionTaskId = $task->id;
                    $equipmentCollectionRole = (string) ($taskDef['assigned_to_role'] ?? 'hr_admin');
                }
            }

            foreach ($tasks as $index => $taskDef) {
                $dependencyIndexes = collect($taskDef['dependency_indexes'] ?? [])
                    ->filter(fn ($value) => is_numeric($value))
                    ->map(fn ($value) => (int) $value)
                    ->values();

                if ($dependencyIndexes->isEmpty() || ! isset($taskByIndex[$index])) {
                    continue;
                }

                $dependencyIds = $dependencyIndexes
                    ->map(fn (int $dependencyIndex) => $taskByIndex[$dependencyIndex]->id ?? null)
                    ->filter()
                    ->values()
                    ->all();

                if ($dependencyIds !== []) {
                    $taskByIndex[$index]->update(['dependency_task_ids' => $dependencyIds]);
                }
            }

            $activeAssignments = $this->getActiveStaffAssetAssignments($profile);
            if ($activeAssignments->isNotEmpty()) {
                $nextSortOrder = count($taskByIndex) + 1;
                $assetTaskAssigneeId = $this->resolveAssignee(
                    null,
                    $equipmentCollectionRole !== '' ? $equipmentCollectionRole : 'hr_admin',
                    $profile,
                );

                foreach ($activeAssignments as $assignment) {
                    $assetName = trim((string) ($assignment->asset?->name ?? 'Assigned asset'));
                    $assetMeta = collect([
                        $assignment->asset?->asset_tag ? 'Tag '.$assignment->asset->asset_tag : null,
                        $assignment->asset?->serial_number ? 'Serial '.$assignment->asset->serial_number : null,
                    ])->filter()->implode(', ');

                    $description = 'Recover this assigned asset as part of offboarding.';
                    if ($assetMeta !== '') {
                        $description .= ' '.$assetMeta.'.';
                    }

                    $assetTask = HrOffboardingTask::create([
                        'offboarding_checklist_id' => $checklist->id,
                        'category' => 'assets',
                        'title' => "Return asset: {$assetName}",
                        'description' => $description,
                        'is_required' => true,
                        'sort_order' => $nextSortOrder++,
                        'assigned_to_user_id' => $assetTaskAssigneeId,
                        'assigned_to_role' => $equipmentCollectionRole !== '' ? $equipmentCollectionRole : 'hr_admin',
                        'sign_off_required' => true,
                        'due_date' => Carbon::parse($endDate)->toDateString(),
                        'status' => 'pending',
                        'notes' => "asset_assignment_id={$assignment->id};asset_id={$assignment->asset_id}",
                    ]);

                    if ($equipmentCollectionTaskId) {
                        $assetTask->update([
                            'dependency_task_ids' => [$equipmentCollectionTaskId],
                        ]);
                    }
                }
            }

            return $checklist->load('tasks');
        });
    }

    /**
     * Get the progress summary for a checklist.
     *
     * @return array{total: int, completed: int, pending: int, percent: float}
     */
    public function getProgress(HrOnboardingChecklist|HrOffboardingChecklist $checklist): array
    {
        $tasks = $checklist->tasks;
        $total = $tasks->count();
        $completed = $tasks->where('status', 'completed')->count();

        return [
            'total' => $total,
            'completed' => $completed,
            'pending' => $total - $completed,
            'percent' => $total > 0 ? round(($completed / $total) * 100, 1) : 0,
        ];
    }

    /**
     * Check if all required tasks are completed and update the checklist status accordingly.
     */
    protected function checkChecklistCompletion(HrOnboardingChecklist $checklist): void
    {
        $pendingRequired = $checklist->tasks()
            ->where('is_required', true)
            ->where('status', '!=', 'completed')
            ->count();

        if ($pendingRequired === 0) {
            $checklist->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            try {
                app(HrWebhookService::class)->publish($checklist->tenant_id, 'onboarding.checklist.completed', [
                    'checklist_id' => $checklist->id,
                    'employee_profile_id' => $checklist->employee_profile_id,
                    'template_key' => $checklist->template_key,
                    'completed_at' => optional($checklist->completed_at)->toDateTimeString(),
                ]);
            } catch (\Throwable $exception) {
                Log::warning('Failed to queue onboarding completion webhook.', [
                    'checklist_id' => $checklist->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        } elseif ($checklist->status !== 'in_progress') {
            $checklist->update(['status' => 'in_progress']);
        }
    }

    /**
     * Check if all required offboarding tasks are complete and close the checklist.
     */
    protected function checkOffboardingChecklistCompletion(HrOffboardingChecklist $checklist): void
    {
        $pendingRequired = $checklist->tasks()
            ->where('is_required', true)
            ->where('status', '!=', 'completed')
            ->count();

        if ($pendingRequired === 0) {
            $checklist->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            try {
                app(HrWebhookService::class)->publish($checklist->tenant_id, 'offboarding.checklist.completed', [
                    'checklist_id' => $checklist->id,
                    'employee_profile_id' => $checklist->employee_profile_id,
                    'template_key' => $checklist->template_key,
                    'completed_at' => optional($checklist->completed_at)->toDateTimeString(),
                ]);
            } catch (\Throwable $exception) {
                Log::warning('Failed to queue offboarding completion webhook.', [
                    'checklist_id' => $checklist->id,
                    'error' => $exception->getMessage(),
                ]);
            }

            $profile = $checklist->employeeProfile;
            if ($profile && $profile->is_active) {
                $profile->update([
                    'is_active' => false,
                    'end_date' => $profile->end_date ?? ($checklist->due_date ?? now()->toDateString()),
                ]);
            }
        } elseif ($checklist->status !== 'in_progress') {
            $checklist->update(['status' => 'in_progress']);
        }
    }

    protected function resolveTemplate(?int $tenantId, ?string $positionRole, string $siteType): ?HrOnboardingTemplate
    {
        $query = HrOnboardingTemplate::query()
            ->forTenant($tenantId)
            ->active()
            ->where(function ($builder) use ($positionRole) {
                $builder->where('role', $positionRole)
                    ->orWhere('role', 'default');
            })
            ->where(function ($builder) use ($siteType) {
                $builder->where('site_type', $siteType)
                    ->orWhere('site_type', 'all');
            })
            ->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [$positionRole])
            ->orderByRaw('CASE WHEN site_type = ? THEN 0 ELSE 1 END', [$siteType]);

        return $query->first();
    }

    protected function resolveAssignee(?int $assignedToUserId, ?string $assignedToRole, HrEmployeeProfile $profile): ?int
    {
        if ($assignedToUserId) {
            return $assignedToUserId;
        }

        if (! $assignedToRole) {
            return null;
        }

        $users = User::query()
            ->when(
                $profile->tenant_id !== null && Schema::hasColumn('users', 'tenant_id'),
                fn ($query) => $query->where('tenant_id', $profile->tenant_id)
            );

        if ($assignedToRole === 'employee') {
            return $profile->user_id;
        }

        if ($assignedToRole === 'manager') {
            return (clone $users)
                ->where('role', 'team_lead')
                ->value('id');
        }

        return (clone $users)
            ->where('role', $assignedToRole)
            ->value('id');
    }

    /**
     * Default offboarding tasks used when no template is configured.
     *
     * @return array<int, array{category: string, title: string, description: string, is_required: bool, assigned_to_role?: string, sign_off_required?: bool}>
     */
    protected function getDefaultOffboardingTasks(): array
    {
        return [
            [
                'category' => 'it',
                'title' => 'Revoke system access',
                'description' => 'Deactivate all IT accounts, email, and system logins.',
                'is_required' => true,
                'assigned_to_role' => 'it_admin',
                'sign_off_required' => true,
            ],
            [
                'category' => 'it',
                'title' => 'Collect company equipment',
                'description' => 'Recover laptop, phone, keys, ID badge, and any other company property.',
                'is_required' => true,
                'assigned_to_role' => 'hr_admin',
            ],
            [
                'category' => 'payroll',
                'title' => 'Final pay calculation',
                'description' => 'Calculate final pay including outstanding leave balance payout.',
                'is_required' => true,
                'assigned_to_role' => 'payroll_admin',
                'sign_off_required' => true,
            ],
            [
                'category' => 'hr',
                'title' => 'Exit interview',
                'description' => 'Schedule and conduct exit interview with departing employee.',
                'is_required' => false,
                'assigned_to_role' => 'hr_admin',
            ],
            [
                'category' => 'operations',
                'title' => 'Knowledge transfer',
                'description' => 'Ensure handover documentation and knowledge transfer is completed.',
                'is_required' => true,
                'assigned_to_role' => 'manager',
            ],
            [
                'category' => 'operations',
                'title' => 'Remove from rosters',
                'description' => 'Remove employee from all active and future shift rosters.',
                'is_required' => true,
                'assigned_to_role' => 'roster_admin',
            ],
            [
                'category' => 'hr',
                'title' => 'Archive employee documents',
                'description' => 'Archive all employee documents per retention policy.',
                'is_required' => true,
                'assigned_to_role' => 'hr_admin',
            ],
            [
                'category' => 'hr',
                'title' => 'Update employee profile',
                'description' => 'Set is_active to false, record end_date and termination_reason.',
                'is_required' => true,
                'assigned_to_role' => 'hr_admin',
                'sign_off_required' => true,
            ],
        ];
    }

    protected function getActiveStaffAssetAssignments(HrEmployeeProfile $profile)
    {
        if (! $profile->user_id) {
            return collect();
        }

        return AssetAssignment::query()
            ->with('asset:id,name,asset_tag,serial_number')
            ->whereIn('assignee_type', ['staff', 'user', User::class])
            ->where('assignee_id', $profile->user_id)
            ->whereNull('released_at')
            ->orderBy('assigned_at')
            ->orderBy('id')
            ->get();
    }
}
