<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrAsset as HrManagedAsset;
use App\Domain\Hr\Models\HrAssetAssignment as HrManagedAssetAssignment;
use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOffboardingChecklist;
use App\Domain\Hr\Models\HrOffboardingTask;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Domain\Hr\Notifications\OffboardingTaskAssignedNotification;
use App\Domain\Hr\Notifications\OnboardingChecklistAssignedNotification;
use App\Domain\Hr\Notifications\OnboardingChecklistCompletedNotification;
use App\Domain\Hr\Notifications\OnboardingTaskAssignedNotification;
use App\Domain\It\Services\ItProvisioningWorkflowService;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\ItProvisioningRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class OnboardingService
{
    public function __construct(
        private readonly HrCurrentStaffService $currentStaff,
        private readonly UserSiteAccessService $siteAccess,
        private readonly WorkforceAvailabilityCoverageService $coverage,
        private readonly PeopleMutationLockService $mutationLocks,
    ) {}

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
            $profile = HrEmployeeProfile::query()
                ->lockForUpdate()
                ->findOrFail($profile->getKey());
            $profile->loadMissing('primarySite');
            $this->assertOnboardingProfile($profile, $createdBy);

            $activeChecklistExists = HrOnboardingChecklist::query()
                ->where('employee_profile_id', $profile->id)
                ->whereIn('status', ['pending', 'in_progress'])
                ->lockForUpdate()
                ->exists();
            if ($activeChecklistExists) {
                throw ValidationException::withMessages([
                    'employee_profile_id' => 'An active onboarding checklist already exists for this employee.',
                ]);
            }

            if ($templateId !== null) {
                $template = HrOnboardingTemplate::query()
                    ->where('id', $templateId)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();
            } else {
                $siteType = $profile->primarySite?->type ?? 'all';
                $template = $this->resolveTemplate($profile->position_role, $siteType);
            }

            if (! $template) {
                throw new \RuntimeException(
                    "No active onboarding template found for role '{$profile->position_role}'."
                );
            }

            $checklist = HrOnboardingChecklist::create([
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
                if (($taskDef['is_required'] ?? true)
                    && (($taskDef['assigned_to_user_id'] ?? null) || ($taskDef['assigned_to_role'] ?? null))
                    && $assigneeId === null
                ) {
                    throw ValidationException::withMessages([
                        'tasks' => "No current Site-authorised owner is available for required task '{$taskDef['title']}'.",
                    ]);
                }
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

            // Cross-loop: raise IT provisioning requests (/it queue) for the
            // checklist's account/access IT tasks. Equipment tasks keep their
            // asset-issue path (provisionAssetForTask).
            $this->createItProvisioningRequests($checklist, array_values($taskByIndex), $createdBy);

            // Cross-loop: auto-enrol the new hire in training for any induction
            // tasks (explicit course_code, else the application's mandatory courses).
            $this->enrolInductionCourses($profile, $tasks);

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
        // Document + signature + task update + rollup commit together. Lock and
        // re-read every mutable row so stale tabs cannot bypass lifecycle gates.
        $storedEvidencePath = null;

        try {
            return DB::transaction(function () use ($task, $completedBy, $data, &$storedEvidencePath) {
                $lockedTask = HrOnboardingTask::query()->lockForUpdate()->findOrFail($task->getKey());
                $checklist = HrOnboardingChecklist::query()
                    ->lockForUpdate()
                    ->findOrFail($lockedTask->checklist_id);

                if ($lockedTask->status === 'completed') {
                    throw new \LogicException("Task '{$lockedTask->title}' is already completed.");
                }
                if (! in_array($checklist->status, ['pending', 'in_progress'], true)) {
                    throw new \LogicException("Cannot complete tasks on a '{$checklist->status}' checklist.");
                }

                $dependencyTaskIds = collect($lockedTask->dependency_task_ids ?? [])
                    ->map(fn ($id) => (int) $id)
                    ->filter()
                    ->unique()
                    ->values();
                if ($dependencyTaskIds->isNotEmpty()) {
                    $completedDependencies = HrOnboardingTask::query()
                        ->where('checklist_id', $checklist->id)
                        ->whereIn('id', $dependencyTaskIds)
                        ->where('status', 'completed')
                        ->lockForUpdate()
                        ->pluck('id')
                        ->count();
                    if ($completedDependencies !== $dependencyTaskIds->count()) {
                        throw new \LogicException('This task cannot be completed until all dependency tasks are complete.');
                    }
                }

                if ($lockedTask->sign_off_required && empty($data['signed_off_by'])) {
                    throw new \LogicException("Task '{$lockedTask->title}' requires sign-off.");
                }

                // Cross-loop: turn an uploaded evidence file into a gated HrDocument
                // (+ a sign-off signature request) and link it to the task.
                if (isset($data['evidence_file'])) {
                    $evidence = $this->storeEvidenceAsDocument(
                        $lockedTask,
                        $checklist,
                        $data['evidence_file'],
                        $completedBy,
                        $data['signed_off_by'] ?? null,
                    );
                    $data['hr_document_id'] = $evidence['document_id'];
                    $storedEvidencePath = $evidence['storage_path'];
                }

                $lockedTask->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'completed_by' => $completedBy,
                    'evidence_path' => $data['evidence_path'] ?? $lockedTask->evidence_path,
                    'hr_document_id' => $data['hr_document_id'] ?? $lockedTask->hr_document_id,
                    'notes' => $data['notes'] ?? $lockedTask->notes,
                    'signed_off_by' => $data['signed_off_by'] ?? $lockedTask->signed_off_by,
                    'signed_off_at' => isset($data['signed_off_by']) ? now() : $lockedTask->signed_off_at,
                ]);

                if ($checklist->status === 'pending') {
                    $checklist->update(['status' => 'in_progress']);
                }

                $this->checkChecklistCompletion($checklist);

                return $lockedTask->fresh();
            });
        } catch (\Throwable $exception) {
            if ($storedEvidencePath !== null) {
                Storage::disk('private')->delete($storedEvidencePath);
            }

            throw $exception;
        }
    }

    /**
     * Reopen a completed onboarding task and roll the checklist back to
     * in_progress (a completed checklist becomes in_progress again once any
     * required task is reopened).
     */
    public function uncompleteTask(HrOnboardingTask $task): HrOnboardingTask
    {
        return DB::transaction(function () use ($task): HrOnboardingTask {
            $lockedTask = HrOnboardingTask::query()->lockForUpdate()->findOrFail($task->getKey());
            $checklist = HrOnboardingChecklist::query()
                ->lockForUpdate()
                ->findOrFail($lockedTask->checklist_id);
            if ($lockedTask->status !== 'completed') {
                return $lockedTask;
            }

            $lockedTask->update([
                'status' => 'pending',
                'completed_at' => null,
                'completed_by' => null,
                'signed_off_by' => null,
                'signed_off_at' => null,
            ]);

            $this->recomputeLockedChecklistStatus($checklist);

            return $lockedTask->fresh();
        });
    }

    /**
     * Edit an onboarding task (title/description/category/due date/flags) and/or
     * reassign it. When the assignee changes, the new owner is notified.
     *
     * @param  array<string, mixed>  $data
     */
    public function editTask(HrOnboardingTask $task, array $data): HrOnboardingTask
    {
        [$updated, $reassigned] = DB::transaction(function () use ($task, $data): array {
            $lockedTask = HrOnboardingTask::query()->lockForUpdate()->findOrFail($task->getKey());
            HrOnboardingChecklist::query()->lockForUpdate()->findOrFail($lockedTask->checklist_id);
            $reassigned = array_key_exists('assigned_to_user_id', $data)
                && (int) $data['assigned_to_user_id'] !== (int) $lockedTask->assigned_to_user_id;

            $lockedTask->update(array_filter([
                'title' => $data['title'] ?? null,
                'category' => $data['category'] ?? null,
            ], fn ($value) => $value !== null) + [
                // Nullable fields + booleans are explicit so they can be cleared.
                'due_date' => array_key_exists('due_date', $data) ? $data['due_date'] : $lockedTask->due_date,
                'assigned_to_role' => array_key_exists('assigned_to_role', $data) ? $data['assigned_to_role'] : $lockedTask->assigned_to_role,
                'description' => array_key_exists('description', $data) ? $data['description'] : $lockedTask->description,
                'is_required' => array_key_exists('is_required', $data) ? (bool) $data['is_required'] : $lockedTask->is_required,
                'sign_off_required' => array_key_exists('sign_off_required', $data) ? (bool) $data['sign_off_required'] : $lockedTask->sign_off_required,
                'assigned_to_user_id' => array_key_exists('assigned_to_user_id', $data) ? $data['assigned_to_user_id'] : $lockedTask->assigned_to_user_id,
            ]);

            return [$lockedTask->fresh(), $reassigned];
        });

        if ($reassigned && $updated->assigned_to_user_id) {
            $assignee = User::find($updated->assigned_to_user_id);
            if ($assignee) {
                try {
                    $assignee->notify(new OnboardingTaskAssignedNotification($updated));
                } catch (\Throwable $exception) {
                    Log::warning('Failed to notify reassigned onboarding task owner', [
                        'task_id' => $updated->id,
                        'assignee_id' => $assignee->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return $updated;
    }

    /**
     * Add an ad-hoc task to an existing checklist, appended to the end of its
     * category group. Reopens a completed checklist if the new task is required.
     *
     * @param  array<string, mixed>  $data
     */
    public function addTask(HrOnboardingChecklist $checklist, array $data): HrOnboardingTask
    {
        $task = DB::transaction(function () use ($checklist, $data): HrOnboardingTask {
            $lockedChecklist = HrOnboardingChecklist::query()
                ->with('employeeProfile')
                ->lockForUpdate()
                ->findOrFail($checklist->getKey());
            $lastOrder = HrOnboardingTask::query()
                ->where('checklist_id', $lockedChecklist->id)
                ->lockForUpdate()
                ->orderByDesc('sort_order')
                ->value('sort_order');
            $nextOrder = (int) $lastOrder + 1;

            $assigneeId = $this->resolveAssignee(
                $data['assigned_to_user_id'] ?? null,
                $data['assigned_to_role'] ?? null,
                $lockedChecklist->employeeProfile,
            );
            if (($data['is_required'] ?? false)
                && (($data['assigned_to_user_id'] ?? null) || ($data['assigned_to_role'] ?? null))
                && $assigneeId === null
            ) {
                throw ValidationException::withMessages([
                    'assigned_to_user_id' => 'The required task owner must be current and able to access every employee Site.',
                ]);
            }

            $task = HrOnboardingTask::create([
                'checklist_id' => $lockedChecklist->id,
                'category' => $data['category'] ?? 'general',
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'is_required' => (bool) ($data['is_required'] ?? false),
                'sort_order' => $nextOrder,
                'assigned_to_user_id' => $assigneeId,
                'assigned_to_role' => $data['assigned_to_role'] ?? null,
                'sign_off_required' => (bool) ($data['sign_off_required'] ?? false),
                'due_date' => $data['due_date'] ?? null,
                'status' => 'pending',
            ]);

            $this->recomputeLockedChecklistStatus($lockedChecklist);

            return $task;
        });

        if ($task->assigned_to_user_id) {
            $assignee = User::find($task->assigned_to_user_id);
            if ($assignee) {
                try {
                    $assignee->notify(new OnboardingTaskAssignedNotification($task));
                } catch (\Throwable $exception) {
                    Log::warning('Failed to notify new onboarding task owner', [
                        'task_id' => $task->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return $task;
    }

    /**
     * Delete an ad-hoc task, then recompute the parent checklist (deleting the
     * last outstanding required task may complete the checklist).
     */
    public function deleteTask(HrOnboardingTask $task): void
    {
        DB::transaction(function () use ($task): void {
            $lockedTask = HrOnboardingTask::query()->lockForUpdate()->findOrFail($task->getKey());
            $checklist = HrOnboardingChecklist::query()
                ->lockForUpdate()
                ->findOrFail($lockedTask->checklist_id);
            $lockedTask->delete();
            $this->recomputeLockedChecklistStatus($checklist);
        });
    }

    /**
     * Persist a new task order. `$orderedIds` is the full set of task ids for
     * the checklist in their desired sequence; sort_order is rewritten 1..n.
     *
     * @param  array<int, int>  $orderedIds
     */
    public function reorderTasks(HrOnboardingChecklist $checklist, array $orderedIds): void
    {
        $orderedIds = collect($orderedIds)->map(fn ($id) => (int) $id)->unique()->values();
        DB::transaction(function () use ($checklist, $orderedIds): void {
            $lockedChecklist = HrOnboardingChecklist::query()->lockForUpdate()->findOrFail($checklist->getKey());
            $valid = HrOnboardingTask::query()
                ->where('checklist_id', $lockedChecklist->id)
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->sort()
                ->values();
            if ($valid->all() !== $orderedIds->sort()->values()->all()) {
                throw ValidationException::withMessages([
                    'task_ids' => 'The task order must contain every current checklist task exactly once.',
                ]);
            }

            foreach ($orderedIds as $offset => $id) {
                HrOnboardingTask::query()->whereKey($id)->update(['sort_order' => $offset + 1]);
            }
        });

        // The sort_order writes above are mass query updates (no Eloquent
        // events → AuditableChanges never fires), so record the reorder as a
        // single summary entry rather than one noisy row per task.
        AuditLogger::log('onboardingchecklist.tasks_reordered', $checklist, [
            'ordered_task_ids' => $orderedIds->all(),
        ]);
    }

    /**
     * Manually close a checklist (Mark complete) regardless of outstanding
     * optional tasks.
     */
    public function markChecklistComplete(HrOnboardingChecklist $checklist): HrOnboardingChecklist
    {
        return DB::transaction(function () use ($checklist): HrOnboardingChecklist {
            $locked = HrOnboardingChecklist::query()->lockForUpdate()->findOrFail($checklist->getKey());
            if (! in_array($locked->status, ['completed', 'cancelled', 'archived'], true)) {
                $locked->update(['status' => 'completed', 'completed_at' => now()]);
            }

            return $locked->fresh();
        });
    }

    /**
     * Cancel or archive a checklist without deleting it (append-only history).
     */
    public function setChecklistStatus(HrOnboardingChecklist $checklist, string $status): HrOnboardingChecklist
    {
        return DB::transaction(function () use ($checklist, $status): HrOnboardingChecklist {
            $locked = HrOnboardingChecklist::query()->lockForUpdate()->findOrFail($checklist->getKey());
            $locked->update([
                'status' => $status,
                'completed_at' => $status === 'completed' ? ($locked->completed_at ?? now()) : $locked->completed_at,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Recompute a checklist's derived status from its tasks: completed when no
     * required task is outstanding, in_progress when any task is done, else
     * pending. Never overrides a terminal cancelled/archived state.
     */
    public function recomputeChecklistStatus(HrOnboardingChecklist $checklist): void
    {
        DB::transaction(function () use ($checklist): void {
            $locked = HrOnboardingChecklist::query()->lockForUpdate()->findOrFail($checklist->getKey());
            $this->recomputeLockedChecklistStatus($locked);
        });
    }

    private function recomputeLockedChecklistStatus(HrOnboardingChecklist $checklist): void
    {
        if (in_array($checklist->status, ['cancelled', 'archived'], true)) {
            return;
        }

        $tasks = HrOnboardingTask::query()
            ->where('checklist_id', $checklist->id)
            ->lockForUpdate()
            ->get();
        $pendingRequired = $tasks->where('is_required', true)->where('status', '!=', 'completed')->count();
        $anyComplete = $tasks->where('status', 'completed')->count() > 0;

        if ($tasks->count() > 0 && $pendingRequired === 0) {
            if ($checklist->status !== 'completed') {
                $this->checkChecklistCompletion($checklist);
            }

            return;
        }

        $next = $anyComplete ? 'in_progress' : 'pending';
        if ($checklist->status !== $next) {
            $checklist->update(['status' => $next, 'completed_at' => null]);
        }
    }

    /* ================================================================== */
    /*  Cross-loop integrations (training · documents · assets) */
    /* ================================================================== */

    /**
     * Auto-enrol a new hire in training for any induction tasks. Templates may
     * carry an explicit `course_code` per task; otherwise the application's mandatory
     * active courses are used. Idempotent and fully best-effort.
     *
     * @param  array<int, array<string, mixed>>  $taskDefs  raw template task defs
     */
    protected function enrolInductionCourses(HrEmployeeProfile $profile, array $taskDefs): void
    {
        if (! $profile->user_id) {
            return;
        }

        $inductionDefs = collect($taskDefs)
            ->filter(fn ($def) => ($def['category'] ?? null) === 'induction');
        if ($inductionDefs->isEmpty()) {
            return;
        }

        $codes = $inductionDefs->pluck('course_code')->filter()->unique()->values();

        $courses = $codes->isNotEmpty()
            ? HrCourse::query()->active()->whereIn('code', $codes->all())->get()
            : HrCourse::query()->active()->where('is_mandatory', true)->get();

        if ($courses->isEmpty()) {
            return;
        }

        $training = app(TrainingService::class);

        foreach ($courses as $course) {
            $alreadyEnrolled = HrCourseEnrollment::query()
                ->where('user_id', $profile->user_id)
                ->where('course_id', $course->id)
                ->whereIn('status', ['enrolled', 'completed'])
                ->exists();

            if ($alreadyEnrolled) {
                continue;
            }

            try {
                $training->enroll(
                    $profile->user_id,
                    $course->id,
                    null,
                    'Auto-enrolled from onboarding induction.',
                );
            } catch (\Throwable $exception) {
                Log::warning('Onboarding induction enrolment failed', [
                    'course_id' => $course->id,
                    'profile_id' => $profile->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Raise a pending it_provisioning_request for every non-equipment IT task
     * on a freshly generated checklist, so the /it queue picks them up.
     * Equipment tasks are skipped — they are fulfilled via
     * provisionAssetForTask. Idempotent (one request per task) and best-effort
     * so a provisioning hiccup never rolls back checklist creation.
     *
     * @param  array<int, HrOnboardingTask>  $tasks
     */
    protected function createItProvisioningRequests(HrOnboardingChecklist $checklist, array $tasks, int $createdBy): void
    {
        // House rule: new-table writes are guarded so code deployed ahead of
        // the migration step degrades gracefully.
        if (! Schema::hasTable('it_provisioning_requests')) {
            return;
        }

        if (Schema::hasTable('it_provisioning_templates')
            && app(ItProvisioningWorkflowService::class)
                ->tryLaunchFromOnboarding($checklist->load('employeeProfile', 'tasks'), $createdBy)) {
            return;
        }

        foreach ($tasks as $task) {
            if (($task->category ?: '') !== 'it') {
                continue;
            }

            $type = ItProvisioningRequest::inferTypeFromTitle($task->title);
            if ($type === 'equipment') {
                continue;
            }

            try {
                $exists = ItProvisioningRequest::query()
                    ->where('onboarding_task_id', $task->id)
                    ->exists();
                if ($exists) {
                    continue;
                }

                ItProvisioningRequest::create([
                    'employee_profile_id' => $checklist->employee_profile_id,
                    'onboarding_task_id' => $task->id,
                    'type' => $type,
                    'item' => $task->title,
                    'assigned_to_user_id' => null,
                    'status' => 'pending',
                    'created_by' => $createdBy,
                ]);
            } catch (\Throwable $exception) {
                Log::warning('Failed to raise IT provisioning request from onboarding task', [
                    'task_id' => $task->id,
                    'checklist_id' => $checklist->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Persist an uploaded task-evidence file as a gated HrDocument on the
     * canonical private disk, and (for sign-off tasks with a sign-off user)
     * mint a pending signature request.
     *
     * @return array{document_id: int, storage_path: string}
     */
    protected function storeEvidenceAsDocument(
        HrOnboardingTask $task,
        HrOnboardingChecklist $checklist,
        UploadedFile $file,
        int $uploadedBy,
        ?int $signOffBy = null,
    ): array {
        $profileId = $checklist->employee_profile_id;

        $path = $file->store("hr-documents/onboarding/{$profileId}", 'private');

        try {
            $document = HrDocument::create([
                'employee_profile_id' => $profileId,
                'title' => "Onboarding evidence — {$task->title}",
                'category' => 'onboarding',
                'storage_disk' => 'private',
                'storage_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType() ?: $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'is_restricted' => false,
                'generated_from_template' => false,
                'created_by' => $uploadedBy,
                'uploaded_by' => $uploadedBy,
            ]);

            if ($task->sign_off_required && $signOffBy) {
                try {
                    app(ESignatureService::class)->requestSignature($document, (int) $signOffBy, $uploadedBy);
                } catch (\Throwable $exception) {
                    Log::warning('Onboarding evidence signature request failed', [
                        'document_id' => $document->id,
                        'task_id' => $task->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);

            throw $exception;
        }

        return [
            'document_id' => (int) $document->id,
            'storage_path' => $path,
        ];
    }

    /**
     * Active company assets the new hire could be issued, for the IT-provisioning
     * preview. (The inverse of the offboarding asset-return surface.)
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function previewItProvisioningTasks(HrOnboardingChecklist $checklist): Collection
    {
        return $checklist->tasks
            ->filter(fn (HrOnboardingTask $t) => ($t->category ?: '') === 'it' && $t->status !== 'completed')
            ->map(fn (HrOnboardingTask $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'sign_off_required' => (bool) $t->sign_off_required,
            ])
            ->values();
    }

    /**
     * Issue a specific company asset to the new hire and complete the IT task in
     * one action. Idempotent on an existing active assignment; stamps the link
     * into the task notes (mirroring the offboarding asset convention) and runs
     * the task through completeTask so dependency/rollup/webhook all fire.
     *
     * @throws \LogicException
     */
    public function provisionAssetForTask(
        HrOnboardingTask $task,
        Asset $asset,
        int $actorId,
        ?string $purpose = null,
        ?int $signOffBy = null,
    ): HrOnboardingTask {
        return DB::transaction(function () use ($task, $asset, $actorId, $purpose, $signOffBy): HrOnboardingTask {
            $lockedTask = HrOnboardingTask::query()->lockForUpdate()->findOrFail($task->getKey());
            $checklist = HrOnboardingChecklist::query()
                ->with('employeeProfile')
                ->lockForUpdate()
                ->findOrFail($lockedTask->checklist_id);
            Asset::query()->lockForUpdate()->findOrFail($asset->getKey());
            $profile = $checklist->employeeProfile;

            if (! $profile || ! $profile->user_id) {
                throw new \LogicException('Cannot provision an asset for a hire with no linked user account.');
            }

            $assignment = AssetAssignment::query()
                ->where('asset_id', $asset->id)
                ->whereNull('released_at')
                ->lockForUpdate()
                ->first();

            if ($assignment
                && ($assignment->assignee_type !== 'staff' || (int) $assignment->assignee_id !== (int) $profile->user_id)
            ) {
                throw new \LogicException('This asset is already assigned to another owner.');
            }

            if (! $assignment) {
                $assignment = AssetAssignment::create([
                    'asset_id' => $asset->id,
                    'assignee_type' => 'staff',
                    'assignee_id' => $profile->user_id,
                    'purpose' => $purpose ?: 'Onboarding provisioning',
                    'assigned_at' => now(),
                ]);
            }

            $note = trim((string) ($lockedTask->notes ?? ''));
            $stamp = "asset_assignment_id={$assignment->id};asset_id={$asset->id}";
            if (! str_contains($note, $stamp)) {
                $lockedTask->update(['notes' => trim($note.' '.$stamp)]);
            }

            return $this->completeTask($lockedTask, $actorId, array_filter([
                'signed_off_by' => $lockedTask->sign_off_required ? $signOffBy : null,
            ], fn ($value) => $value !== null));
        });
    }

    /**
     * Pick the first free company asset for auto-provisioning: an asset in a
     * usable status (not retired/lost/in maintenance) with no active (unreleased)
     * assignment, optionally filtered to a category. Returns null when the pool
     * is empty so the caller can surface a clear "nothing to assign" message.
     */
    /** @param list<int>|null $allowedAssetIds */
    public function autoPickAvailableAsset(?string $category = null, ?array $allowedAssetIds = null): ?Asset
    {
        return Asset::query()
            ->when($allowedAssetIds !== null, fn ($query) => $query->whereIn('id', $allowedAssetIds))
            ->when($category, fn ($q) => $q->where('category', $category))
            ->whereNotIn('status', ['retired', 'decommissioned', 'lost', 'maintenance'])
            ->whereDoesntHave('assignments', fn ($q) => $q->whereNull('released_at'))
            ->orderBy('id')
            ->first();
    }

    /**
     * Complete an offboarding task with dependency + sign-off validation.
     *
     * @throws \LogicException
     */
    public function completeOffboardingTask(HrOffboardingTask $task, int $completedBy, array $data = []): HrOffboardingTask
    {
        return DB::transaction(function () use ($task, $completedBy, $data): HrOffboardingTask {
            $taskIdentity = HrOffboardingTask::query()
                ->select(['id', 'offboarding_checklist_id'])
                ->findOrFail($task->getKey());
            $checklistIdentity = HrOffboardingChecklist::query()
                ->select(['id', 'employee_profile_id'])
                ->findOrFail($taskIdentity->offboarding_checklist_id);
            $profileIdentity = HrEmployeeProfile::query()
                ->withTrashed()
                ->select(['id', 'user_id'])
                ->findOrFail($checklistIdentity->employee_profile_id);
            $locks = $this->mutationLocks->lock(
                [
                    $completedBy,
                    $profileIdentity->user_id,
                    $data['signed_off_by'] ?? null,
                ],
                [$profileIdentity->id],
            );
            $profile = $locks['profiles']->get($profileIdentity->id);
            abort_unless($profile, 404);
            abort_unless($locks['users']->has((int) $profile->user_id), 404);
            $profile->setRelation('user', $locks['users']->get((int) $profile->user_id));
            $checklist = HrOffboardingChecklist::query()
                ->where('employee_profile_id', $profile->id)
                ->lockForUpdate()
                ->findOrFail($checklistIdentity->id);
            $lockedTask = HrOffboardingTask::query()
                ->where('offboarding_checklist_id', $checklist->id)
                ->lockForUpdate()
                ->findOrFail($taskIdentity->id);
            $checklist->setRelation('employeeProfile', $profile);

            if ($lockedTask->status === 'completed') {
                throw new \LogicException("Task '{$lockedTask->title}' is already completed.");
            }

            if (! in_array($checklist->status, ['pending', 'in_progress'], true)) {
                throw new \LogicException("Cannot complete tasks on a '{$checklist->status}' checklist.");
            }

            $dependencyTaskIds = collect($lockedTask->dependency_task_ids ?? [])
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values();
            if ($dependencyTaskIds->isNotEmpty()) {
                $completedDependencies = HrOffboardingTask::query()
                    ->where('offboarding_checklist_id', $checklist->id)
                    ->whereIn('id', $dependencyTaskIds)
                    ->where('status', 'completed')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id')
                    ->count();

                if ($completedDependencies !== $dependencyTaskIds->count()) {
                    throw new \LogicException('This task cannot be completed until all dependency tasks are complete.');
                }
            }

            if ($lockedTask->sign_off_required && empty($data['signed_off_by'])) {
                throw new \LogicException("Task '{$lockedTask->title}' requires sign-off.");
            }

            $lockedTask->update([
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by' => $completedBy,
                'evidence_path' => $data['evidence_path'] ?? $lockedTask->evidence_path,
                'notes' => $data['notes'] ?? $lockedTask->notes,
                'signed_off_by' => $data['signed_off_by'] ?? $lockedTask->signed_off_by,
                'signed_off_at' => isset($data['signed_off_by']) ? now() : $lockedTask->signed_off_at,
            ]);

            if ($checklist->status === 'pending') {
                $checklist->update(['status' => 'in_progress']);
            }

            $this->checkOffboardingChecklistCompletion($checklist, $completedBy);

            return $lockedTask->fresh();
        });
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
        $result = DB::transaction(function () use ($profile, $createdBy, $options) {
            $profile = HrEmployeeProfile::query()->lockForUpdate()->findOrFail($profile->getKey());
            $existing = HrOffboardingChecklist::query()
                ->where('employee_profile_id', $profile->id)
                ->whereIn('status', ['pending', 'in_progress'])
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                throw new \LogicException('An active offboarding checklist already exists for this employee.');
            }

            $endDate = Carbon::parse(
                $options['end_date'] ?? $profile->end_date ?? now()->addWeeks(2),
            )->toDateString();
            $previousEmployeeEndDate = $profile->end_date?->toDateString();
            $offboardingTemplate = HrOnboardingTemplate::query()
                ->active()
                ->where('role', 'offboarding:'.$profile->position_role)
                ->first();

            $tasks = $offboardingTemplate?->tasks ?: $this->getDefaultOffboardingTasks();
            $resolvedAssignees = [];
            $unownedRequiredTasks = [];

            foreach ($tasks as $index => $taskDef) {
                $resolvedAssignees[$index] = $this->resolveOffboardingAssignee(
                    $taskDef['assigned_to_user_id'] ?? null,
                    $taskDef['assigned_to_role'] ?? null,
                    $profile,
                    $createdBy,
                );

                if (($taskDef['is_required'] ?? false) && ! $resolvedAssignees[$index]) {
                    $unownedRequiredTasks[] = (string) ($taskDef['title'] ?? 'Untitled task');
                }
            }

            if ($unownedRequiredTasks !== []) {
                throw ValidationException::withMessages([
                    'tasks' => 'Required offboarding tasks need an owner before the checklist can be created: '.implode(', ', $unownedRequiredTasks),
                ]);
            }

            $checklist = HrOffboardingChecklist::create([
                'employee_profile_id' => $profile->id,
                'template_key' => $offboardingTemplate?->role ?? 'offboarding:default',
                'status' => 'pending',
                'started_at' => now(),
                'due_date' => $endDate,
                'previous_employee_end_date' => $previousEmployeeEndDate,
                'created_by' => $createdBy,
            ]);

            $taskByIndex = [];
            $equipmentCollectionTaskId = null;
            $equipmentCollectionRole = 'hr_admin';
            foreach ($tasks as $index => $taskDef) {
                $assigneeId = $resolvedAssignees[$index];
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
                    'notes' => isset($taskDef['workflow_key'])
                        ? 'workflow_key='.$taskDef['workflow_key']
                        : null,
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
                $assetTaskAssigneeId = $this->resolveOffboardingAssignee(
                    null,
                    $equipmentCollectionRole !== '' ? $equipmentCollectionRole : 'hr_admin',
                    $profile,
                    $createdBy,
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

            $checklist->load('employeeProfile', 'tasks');
            if (Schema::hasTable('it_provisioning_templates')) {
                app(ItProvisioningWorkflowService::class)
                    ->tryLaunchFromOffboarding($checklist, $createdBy);
            }

            $this->coverage->syncOffboarding(
                $checklist,
                User::query()->findOrFail($createdBy),
            );

            // Persist the staffing constraint after coverage has resolved the
            // worker's currently assigned shifts. Both writes remain atomic.
            $profile->update(['end_date' => $endDate]);

            return $checklist;
        });

        // Notify assignees after commit (onboarding notifies too; without this,
        // offboarding tasks sat silently until someone opened the hub —
        // dangerous when the tasks gate access revocation + asset recovery).
        DB::afterCommit(function () use ($result): void {
            foreach ($result->tasks as $task) {
                if (! $task->assigned_to_user_id) {
                    continue;
                }

                $assignee = User::find($task->assigned_to_user_id);
                if (! $assignee) {
                    continue;
                }

                try {
                    $assignee->notify(new OffboardingTaskAssignedNotification($task));
                } catch (\Throwable $exception) {
                    Log::warning('Failed to notify offboarding task assignee', [
                        'task_id' => $task->id,
                        'assignee_id' => $assignee->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        });

        return $result;
    }

    /**
     * Add the return task for an HR asset issued after offboarding started.
     * Assignment identity in notes makes the write idempotent without relying
     * on mutable asset names or task titles.
     */
    public function reconcileAssetReturnTask(
        HrOffboardingChecklist $checklist,
        HrManagedAsset $asset,
        int $actorId,
    ): ?HrOffboardingTask {
        if (! in_array($checklist->status, ['pending', 'in_progress'], true)) {
            return null;
        }

        $assignment = HrManagedAssetAssignment::query()
            ->where('asset_id', $asset->id)
            ->where('employee_profile_id', $checklist->employee_profile_id)
            ->whereNull('returned_at')
            ->latest('id')
            ->first();

        if (! $assignment) {
            return null;
        }

        $stamp = "asset_assignment_id={$assignment->id};asset_id={$asset->id}";
        $existing = $checklist->tasks()->where('notes', $stamp)->first();
        if ($existing) {
            return $existing;
        }

        $profile = $checklist->employeeProfile;
        $assigneeId = $this->resolveOffboardingAssignee(null, 'hr_admin', $profile, $actorId);
        if (! $assigneeId) {
            throw ValidationException::withMessages([
                'assigned_to_user_id' => 'The asset return task needs an owner before it can be created.',
            ]);
        }

        $dependencyId = $checklist->tasks()
            ->where('notes', 'like', '%workflow_key=asset_collection%')
            ->value('id');
        $meta = collect([
            $asset->asset_tag ? 'Tag '.$asset->asset_tag : null,
            $asset->serial_number ? 'Serial '.$asset->serial_number : null,
        ])->filter()->implode(', ');
        $description = 'Recover this assigned asset as part of offboarding.';
        if ($meta !== '') {
            $description .= ' '.$meta.'.';
        }

        $task = HrOffboardingTask::query()->create([
            'offboarding_checklist_id' => $checklist->id,
            'category' => 'assets',
            'title' => 'Return asset: '.trim((string) ($asset->name ?: 'Assigned asset')),
            'description' => $description,
            'is_required' => true,
            'sort_order' => ((int) $checklist->tasks()->max('sort_order')) + 1,
            'assigned_to_user_id' => $assigneeId,
            'assigned_to_role' => 'hr_admin',
            'status' => 'pending',
            'due_date' => $checklist->due_date,
            'dependency_task_ids' => $dependencyId ? [(int) $dependencyId] : null,
            'sign_off_required' => true,
            'notes' => $stamp,
        ]);

        try {
            User::find($assigneeId)?->notify(new OffboardingTaskAssignedNotification($task));
        } catch (\Throwable $exception) {
            Log::warning('Failed to notify late asset-return task owner', [
                'task_id' => $task->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return $task;
    }

    /**
     * Reopen a mistakenly-completed offboarding task (mirror of the onboarding
     * uncomplete). Reverts a completed checklist to in_progress but NEVER
     * restores revoked system access — rehire owns restoration.
     */
    public function uncompleteOffboardingTask(HrOffboardingTask $task): HrOffboardingTask
    {
        return DB::transaction(function () use ($task): HrOffboardingTask {
            $taskIdentity = HrOffboardingTask::query()
                ->select(['id', 'offboarding_checklist_id'])
                ->findOrFail($task->getKey());
            $checklistIdentity = HrOffboardingChecklist::query()
                ->select(['id', 'employee_profile_id'])
                ->findOrFail($taskIdentity->offboarding_checklist_id);
            $profile = HrEmployeeProfile::query()
                ->withTrashed()
                ->lockForUpdate()
                ->findOrFail($checklistIdentity->employee_profile_id);
            $checklist = HrOffboardingChecklist::query()
                ->where('employee_profile_id', $profile->id)
                ->lockForUpdate()
                ->findOrFail($checklistIdentity->id);
            $lockedTask = HrOffboardingTask::query()
                ->where('offboarding_checklist_id', $checklist->id)
                ->lockForUpdate()
                ->findOrFail($taskIdentity->id);

            if ($lockedTask->status !== 'completed') {
                return $lockedTask;
            }

            $lockedTask->update([
                'status' => 'pending',
                'completed_at' => null,
                'completed_by' => null,
                'signed_off_by' => null,
                'signed_off_at' => null,
            ]);

            if ($checklist->status === 'completed') {
                $checklist->update(['status' => 'in_progress', 'completed_at' => null]);
            }

            return $lockedTask->fresh();
        });
    }

    /**
     * Cancel or archive an offboarding checklist without deleting it — e.g. a
     * retracted resignation (append-only history, mirrors the onboarding
     * status setter).
     */
    public function setOffboardingChecklistStatus(
        HrOffboardingChecklist $checklist,
        string $status,
        User $actor,
        ?string $effectiveEndDate = null,
    ): HrOffboardingChecklist {
        return DB::transaction(function () use ($actor, $checklist, $effectiveEndDate, $status): HrOffboardingChecklist {
            $identity = HrOffboardingChecklist::query()
                ->select(['id', 'employee_profile_id'])
                ->findOrFail($checklist->getKey());
            $profile = HrEmployeeProfile::query()
                ->withTrashed()
                ->lockForUpdate()
                ->findOrFail($identity->employee_profile_id);
            $lockedChecklist = HrOffboardingChecklist::query()
                ->where('employee_profile_id', $profile->id)
                ->lockForUpdate()
                ->findOrFail($identity->id);

            if ($status === 'cancelled'
                && ! in_array($lockedChecklist->status, ['pending', 'in_progress', 'cancelled'], true)
            ) {
                throw ValidationException::withMessages([
                    'status' => "A '{$lockedChecklist->status}' offboarding cannot be cancelled.",
                ]);
            }

            if ($status === 'archived'
                && ! in_array($lockedChecklist->status, ['pending', 'in_progress', 'cancelled', 'archived'], true)
            ) {
                throw ValidationException::withMessages([
                    'status' => "A '{$lockedChecklist->status}' offboarding cannot be archived.",
                ]);
            }

            if ($status === 'in_progress'
                && ! in_array($lockedChecklist->status, ['pending', 'in_progress', 'cancelled'], true)
            ) {
                throw ValidationException::withMessages([
                    'status' => "A '{$lockedChecklist->status}' offboarding cannot be resumed.",
                ]);
            }

            if (in_array($status, ['cancelled', 'archived'], true)) {
                $this->coverage->cancelOffboarding($lockedChecklist, $actor);

                if ($profile->end_date?->toDateString() === $lockedChecklist->due_date?->toDateString()) {
                    $profile->update([
                        'end_date' => $lockedChecklist->previous_employee_end_date?->toDateString(),
                    ]);
                }

                $lockedChecklist->update([
                    'status' => $status,
                    'completed_at' => $lockedChecklist->completed_at,
                ]);

                return $lockedChecklist->fresh();
            }

            if ($status === 'in_progress') {
                $otherActiveChecklist = HrOffboardingChecklist::query()
                    ->where('employee_profile_id', $profile->id)
                    ->whereKeyNot($lockedChecklist->id)
                    ->whereIn('status', ['pending', 'in_progress'])
                    ->lockForUpdate()
                    ->exists();
                if ($otherActiveChecklist) {
                    throw ValidationException::withMessages([
                        'status' => 'Another active offboarding checklist already exists for this employee.',
                    ]);
                }

                if (! $effectiveEndDate && ! $lockedChecklist->due_date) {
                    throw ValidationException::withMessages([
                        'end_date' => 'A last working day is required to resume offboarding.',
                    ]);
                }

                $newEndDate = Carbon::parse(
                    $effectiveEndDate ?: $lockedChecklist->due_date,
                )->toDateString();
                $oldEndDate = $lockedChecklist->due_date?->copy();

                if ($oldEndDate && $oldEndDate->toDateString() !== $newEndDate) {
                    $dayDelta = $oldEndDate->diffInDays(Carbon::parse($newEndDate), false);
                    $lockedChecklist->tasks()
                        ->whereIn('status', ['pending', 'in_progress'])
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->each(function (HrOffboardingTask $task) use ($dayDelta): void {
                            if ($task->due_date) {
                                $task->update(['due_date' => $task->due_date->copy()->addDays($dayDelta)]);
                            }
                        });
                }

                $lockedChecklist->update([
                    'status' => 'in_progress',
                    'due_date' => $newEndDate,
                    'completed_at' => null,
                ]);
                $profile->update(['end_date' => $newEndDate]);
                $this->coverage->syncOffboarding($lockedChecklist->fresh(), $actor);

                return $lockedChecklist->fresh();
            }

            $lockedChecklist->update([
                'status' => $status,
                'completed_at' => $status === 'completed'
                    ? ($lockedChecklist->completed_at ?? now())
                    : $lockedChecklist->completed_at,
            ]);

            return $lockedChecklist->fresh();
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
                app(HrWebhookService::class)->publishApplicationEvent('onboarding.checklist.completed', [
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

            // Notify the checklist owner (creator) that onboarding is complete.
            $owner = $checklist->created_by ? User::find($checklist->created_by) : null;
            if ($owner) {
                try {
                    $owner->notify(new OnboardingChecklistCompletedNotification(
                        $checklist->loadMissing('employeeProfile.user')
                    ));
                } catch (\Throwable $exception) {
                    Log::warning('Failed to notify onboarding checklist owner of completion', [
                        'checklist_id' => $checklist->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        } elseif ($checklist->status !== 'in_progress') {
            $checklist->update(['status' => 'in_progress']);
        }
    }

    /**
     * Check if all required offboarding tasks are complete and close the checklist.
     */
    protected function checkOffboardingChecklistCompletion(HrOffboardingChecklist $checklist, int $actorId): void
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
                app(HrWebhookService::class)->publishApplicationEvent('offboarding.checklist.completed', [
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

            if ($profile) {
                $this->revokeSystemAccess($profile, $actorId);
            }
        } elseif ($checklist->status !== 'in_progress') {
            $checklist->update(['status' => 'in_progress']);
        }
    }

    /**
     * Revoke the leaver's system login once their offboarding checklist is
     * fully complete. Withdrawing approval blocks future logins (Fortify +
     * OAuth both check `approved_at`) and EnsureAccountStillApproved ends any
     * live session on their next request. Never revokes the acting user's own
     * account mid-session.
     */
    protected function revokeSystemAccess(HrEmployeeProfile $profile, int $actorId): void
    {
        $user = $profile->user;

        if (! $user || is_null($user->approved_at)) {
            return;
        }

        if ($actorId === $user->id) {
            Log::warning('Skipped login revocation on offboarding completion: actor is the leaver.', [
                'actor_id' => $actorId,
                'user_id' => $user->id,
                'employee_profile_id' => $profile->id,
            ]);

            return;
        }

        $user->forceFill([
            'approved_at' => null,
            'remember_token' => null,
        ])->save();

        // D-3: login revocation is a user write — audit it alongside the app log
        // (User deliberately doesn't carry AuditableChanges).
        AuditLogger::log('user.login_revoked', $user, [
            'actor_id' => $actorId,
            'employee_profile_id' => $profile->id,
            'reason' => 'offboarding_completed',
        ]);

        Log::info('Login access revoked on offboarding completion.', [
            'user_id' => $user->id,
            'employee_profile_id' => $profile->id,
        ]);
    }

    protected function resolveTemplate(?string $positionRole, string $siteType): ?HrOnboardingTemplate
    {
        $query = HrOnboardingTemplate::query()
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
            return $this->eligibleOnboardingOwnerId($assignedToUserId, $profile);
        }

        if (! $assignedToRole) {
            return null;
        }

        if ($assignedToRole === 'employee') {
            return $this->onboardingSubjectId($profile);
        }

        if ($assignedToRole === 'manager') {
            return $this->eligibleOnboardingOwnerId($profile->manager_user_id, $profile);
        }

        $candidateIds = User::query()
            ->where(function ($roles) use ($assignedToRole): void {
                $roles->where('role', $assignedToRole)
                    ->orWhereHas('roles', fn ($query) => $query->where('name', $assignedToRole));
            })
            ->orderBy('id')
            ->pluck('id');

        foreach ($candidateIds as $candidateId) {
            $eligibleId = $this->eligibleOnboardingOwnerId((int) $candidateId, $profile);
            if ($eligibleId !== null) {
                return $eligibleId;
            }
        }

        return null;
    }

    private function onboardingSubjectId(HrEmployeeProfile $profile): ?int
    {
        if (! $profile->is_active
            || ! $profile->user_id
            || ($profile->end_date && $profile->end_date->isBefore(today()))
            || ! $profile->primary_site_id
        ) {
            return null;
        }

        return User::query()->whereKey($profile->user_id)->exists()
            ? (int) $profile->user_id
            : null;
    }

    private function assertOnboardingProfile(HrEmployeeProfile $profile, int $createdBy): void
    {
        $siteIds = collect([
            $profile->primary_site_id,
            ...($profile->secondary_site_ids ?? []),
        ])
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if (! $profile->is_active
            || ! $profile->user_id
            || ($profile->end_date && $profile->end_date->isBefore(today()))
            || $siteIds->isEmpty()
        ) {
            throw ValidationException::withMessages([
                'employee_profile_id' => 'The selected employee is not an active Site-complete onboarding subject.',
            ]);
        }

        if ($this->eligibleOnboardingOwnerId($createdBy, $profile) === null) {
            throw ValidationException::withMessages([
                'employee_profile_id' => 'The initiating user cannot own onboarding work for every employee Site.',
            ]);
        }
    }

    private function eligibleOnboardingOwnerId(?int $userId, HrEmployeeProfile $profile): ?int
    {
        if (! $userId || ! $this->currentStaff->isCurrent($userId)) {
            return null;
        }

        $subjectSiteIds = collect([
            $profile->primary_site_id,
            ...($profile->secondary_site_ids ?? []),
        ])
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();
        if ($subjectSiteIds->isEmpty()) {
            return null;
        }

        $candidate = User::query()->find($userId);
        if (! $candidate) {
            return null;
        }

        return $subjectSiteIds->diff($this->siteAccess->accessibleSiteIds($candidate))->isEmpty()
            ? (int) $candidate->id
            : null;
    }

    /**
     * Offboarding tasks must remain actionable even when a configured legacy
     * role has no current holder: role, employee manager, then initiating HR.
     */
    protected function resolveOffboardingAssignee(
        ?int $assignedToUserId,
        ?string $assignedToRole,
        HrEmployeeProfile $profile,
        int $createdBy,
    ): ?int {
        $explicitOwner = $this->eligibleOffboardingOwnerId($assignedToUserId, $profile);
        if ($explicitOwner !== null) {
            return $explicitOwner;
        }

        $roleOwner = null;
        if ($assignedToRole && $assignedToRole !== 'manager') {
            $roleName = $assignedToRole === 'manager' ? 'team_lead' : $assignedToRole;
            $candidateIds = User::query()
                ->where(function ($roles) use ($roleName): void {
                    $roles->where('role', $roleName)
                        ->orWhereHas('roles', fn ($query) => $query->where('name', $roleName));
                })
                ->orderBy('id')
                ->pluck('id');

            foreach ($candidateIds as $candidateId) {
                $roleOwner = $this->eligibleOffboardingOwnerId((int) $candidateId, $profile);
                if ($roleOwner !== null) {
                    break;
                }
            }
        }

        if ($roleOwner) {
            return $roleOwner;
        }

        $managerOwner = $this->eligibleOffboardingOwnerId($profile->manager_user_id, $profile);
        if ($managerOwner !== null) {
            return $managerOwner;
        }

        return $this->eligibleOffboardingOwnerId($createdBy, $profile);
    }

    private function eligibleOffboardingOwnerId(?int $userId, HrEmployeeProfile $profile): ?int
    {
        if (! $userId || ! $this->currentStaff->isCurrent($userId)) {
            return null;
        }

        $subjectSiteIds = collect([
            $profile->primary_site_id,
            ...($profile->secondary_site_ids ?? []),
        ])
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();
        if ($subjectSiteIds->isEmpty()) {
            return null;
        }

        $candidate = User::query()->find($userId);
        if (! $candidate) {
            return null;
        }

        return $subjectSiteIds->diff($this->siteAccess->accessibleSiteIds($candidate))->isEmpty()
            ? (int) $candidate->id
            : null;
    }

    /**
     * Public view of the standard offboarding tasks, for previewing in the
     * offboarding wizard before a checklist is generated.
     *
     * @return array<int, array<string, mixed>>
     */
    public function defaultOffboardingTasks(): array
    {
        return $this->getDefaultOffboardingTasks();
    }

    /**
     * Active company assets assigned to a staff member, surfaced for the
     * offboarding wizard's asset-return preview.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function previewAssetReturns(HrEmployeeProfile $profile): Collection
    {
        return $this->getActiveStaffAssetAssignments($profile)->map(fn ($assignment) => [
            'id' => $assignment->id,
            'name' => $assignment->asset?->name ?? 'Asset',
            'asset_tag' => $assignment->asset?->asset_tag,
        ])->values();
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
                'workflow_key' => 'asset_collection',
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
                'workflow_key' => 'exit_interview',
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
