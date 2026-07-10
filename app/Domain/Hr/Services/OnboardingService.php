<?php

namespace App\Domain\Hr\Services;

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
use App\Domain\Hr\Notifications\OnboardingTaskAssignedNotification;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\ItProvisioningRequest;
use App\Models\User;
use App\Services\AuditLogger;
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

            // Cross-loop: raise IT provisioning requests (/it queue) for the
            // checklist's account/access IT tasks. Equipment tasks keep their
            // asset-issue path (provisionAssetForTask).
            $this->createItProvisioningRequests($checklist, array_values($taskByIndex), $createdBy);

            // Cross-loop: auto-enrol the new hire in training for any induction
            // tasks (explicit course_code, else the tenant's mandatory courses).
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

        // Document + signature + task update + rollup commit together.
        return DB::transaction(function () use ($task, $checklist, $completedBy, $data) {
            // Cross-loop: turn an uploaded evidence file into a gated HrDocument
            // (+ a sign-off signature request) and link it to the task.
            if (isset($data['evidence_file'])) {
                $data['hr_document_id'] = $this->storeEvidenceAsDocument(
                    $task,
                    $checklist,
                    $data['evidence_file'],
                    $completedBy,
                    $data['signed_off_by'] ?? null,
                );
            }

            $task->update([
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by' => $completedBy,
                'evidence_path' => $data['evidence_path'] ?? $task->evidence_path,
                'hr_document_id' => $data['hr_document_id'] ?? $task->hr_document_id,
                'notes' => $data['notes'] ?? $task->notes,
                'signed_off_by' => $data['signed_off_by'] ?? $task->signed_off_by,
                'signed_off_at' => isset($data['signed_off_by']) ? now() : $task->signed_off_at,
            ]);

            if ($checklist->status === 'pending') {
                $checklist->update(['status' => 'in_progress']);
            }

            $this->checkChecklistCompletion($checklist);

            return $task->fresh();
        });
    }

    /**
     * Reopen a completed onboarding task and roll the checklist back to
     * in_progress (a completed checklist becomes in_progress again once any
     * required task is reopened).
     */
    public function uncompleteTask(HrOnboardingTask $task): HrOnboardingTask
    {
        if ($task->status !== 'completed') {
            return $task;
        }

        $task->update([
            'status' => 'pending',
            'completed_at' => null,
            'completed_by' => null,
            'signed_off_by' => null,
            'signed_off_at' => null,
        ]);

        $this->recomputeChecklistStatus($task->checklist()->with('tasks')->firstOrFail());

        return $task->fresh();
    }

    /**
     * Edit an onboarding task (title/description/category/due date/flags) and/or
     * reassign it. When the assignee changes, the new owner is notified.
     *
     * @param  array<string, mixed>  $data
     */
    public function editTask(HrOnboardingTask $task, array $data): HrOnboardingTask
    {
        $reassigned = array_key_exists('assigned_to_user_id', $data)
            && (int) $data['assigned_to_user_id'] !== (int) $task->assigned_to_user_id;

        $task->update(array_filter([
            'title' => $data['title'] ?? null,
            'category' => $data['category'] ?? null,
        ], fn ($value) => $value !== null) + [
            // Nullable fields + booleans are set explicitly so they can be cleared.
            'due_date' => array_key_exists('due_date', $data) ? $data['due_date'] : $task->due_date,
            'assigned_to_role' => array_key_exists('assigned_to_role', $data) ? $data['assigned_to_role'] : $task->assigned_to_role,
            'description' => array_key_exists('description', $data) ? $data['description'] : $task->description,
            'is_required' => array_key_exists('is_required', $data) ? (bool) $data['is_required'] : $task->is_required,
            'sign_off_required' => array_key_exists('sign_off_required', $data) ? (bool) $data['sign_off_required'] : $task->sign_off_required,
            'assigned_to_user_id' => array_key_exists('assigned_to_user_id', $data) ? $data['assigned_to_user_id'] : $task->assigned_to_user_id,
        ]);

        if ($reassigned && $task->assigned_to_user_id) {
            $assignee = User::find($task->assigned_to_user_id);
            if ($assignee) {
                try {
                    $assignee->notify(new OnboardingTaskAssignedNotification($task->fresh()));
                } catch (\Throwable $exception) {
                    Log::warning('Failed to notify reassigned onboarding task owner', [
                        'task_id' => $task->id,
                        'assignee_id' => $assignee->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return $task->fresh();
    }

    /**
     * Add an ad-hoc task to an existing checklist, appended to the end of its
     * category group. Reopens a completed checklist if the new task is required.
     *
     * @param  array<string, mixed>  $data
     */
    public function addTask(HrOnboardingChecklist $checklist, array $data): HrOnboardingTask
    {
        $nextOrder = (int) $checklist->tasks()->max('sort_order') + 1;

        $assigneeId = $this->resolveAssignee(
            $data['assigned_to_user_id'] ?? null,
            $data['assigned_to_role'] ?? null,
            $checklist->employeeProfile,
        );

        $task = HrOnboardingTask::create([
            'checklist_id' => $checklist->id,
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

        if ($assigneeId) {
            $assignee = User::find($assigneeId);
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

        $this->recomputeChecklistStatus($checklist->fresh('tasks'));

        return $task;
    }

    /**
     * Delete an ad-hoc task, then recompute the parent checklist (deleting the
     * last outstanding required task may complete the checklist).
     */
    public function deleteTask(HrOnboardingTask $task): void
    {
        $checklist = $task->checklist;
        $task->delete();

        if ($checklist) {
            $this->recomputeChecklistStatus($checklist->fresh('tasks'));
        }
    }

    /**
     * Persist a new task order. `$orderedIds` is the full set of task ids for
     * the checklist in their desired sequence; sort_order is rewritten 1..n.
     *
     * @param  array<int, int>  $orderedIds
     */
    public function reorderTasks(HrOnboardingChecklist $checklist, array $orderedIds): void
    {
        $valid = $checklist->tasks()->pluck('id')->all();
        $order = 1;

        DB::transaction(function () use ($orderedIds, $valid, &$order) {
            foreach ($orderedIds as $id) {
                if (! in_array((int) $id, $valid, true)) {
                    continue;
                }
                HrOnboardingTask::where('id', $id)->update(['sort_order' => $order++]);
            }
        });

        // The sort_order writes above are mass query updates (no Eloquent
        // events → AuditableChanges never fires), so record the reorder as a
        // single summary entry rather than one noisy row per task.
        AuditLogger::log('onboardingchecklist.tasks_reordered', $checklist, [
            'ordered_task_ids' => array_values(array_map('intval', $orderedIds)),
        ]);
    }

    /**
     * Manually close a checklist (Mark complete) regardless of outstanding
     * optional tasks.
     */
    public function markChecklistComplete(HrOnboardingChecklist $checklist): HrOnboardingChecklist
    {
        if (! in_array($checklist->status, ['completed', 'cancelled', 'archived'], true)) {
            $checklist->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        }

        return $checklist->fresh();
    }

    /**
     * Cancel or archive a checklist without deleting it (append-only history).
     */
    public function setChecklistStatus(HrOnboardingChecklist $checklist, string $status): HrOnboardingChecklist
    {
        $checklist->update([
            'status' => $status,
            'completed_at' => $status === 'completed' ? now() : $checklist->completed_at,
        ]);

        return $checklist->fresh();
    }

    /**
     * Recompute a checklist's derived status from its tasks: completed when no
     * required task is outstanding, in_progress when any task is done, else
     * pending. Never overrides a terminal cancelled/archived state.
     */
    public function recomputeChecklistStatus(HrOnboardingChecklist $checklist): void
    {
        if (in_array($checklist->status, ['cancelled', 'archived'], true)) {
            return;
        }

        $tasks = $checklist->tasks;
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
    /*  Cross-loop integrations (training · documents · assets)            */
    /* ================================================================== */

    /**
     * Auto-enrol a new hire in training for any induction tasks. Templates may
     * carry an explicit `course_code` per task; otherwise the tenant's mandatory
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
            ? HrCourse::query()->forTenant($profile->tenant_id)->active()->whereIn('code', $codes->all())->get()
            : HrCourse::query()->forTenant($profile->tenant_id)->active()->where('is_mandatory', true)->get();

        if ($courses->isEmpty()) {
            return;
        }

        $training = app(TrainingService::class);

        foreach ($courses as $course) {
            $alreadyEnrolled = HrCourseEnrollment::query()
                ->where('tenant_id', $profile->tenant_id)
                ->where('user_id', $profile->user_id)
                ->where('course_id', $course->id)
                ->whereIn('status', ['enrolled', 'completed'])
                ->exists();

            if ($alreadyEnrolled) {
                continue;
            }

            try {
                $training->enroll(
                    $profile->tenant_id,
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
                    'tenant_id' => $checklist->tenant_id,
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
     * mint a pending signature request. Returns the new document id.
     */
    protected function storeEvidenceAsDocument(
        HrOnboardingTask $task,
        HrOnboardingChecklist $checklist,
        \Illuminate\Http\UploadedFile $file,
        int $uploadedBy,
        ?int $signOffBy = null,
    ): int {
        $tenantId = $checklist->tenant_id;
        $profileId = $checklist->employee_profile_id;

        $path = $file->store("hr-documents/{$tenantId}/{$profileId}", 'private');

        $document = HrDocument::create([
            'tenant_id' => $tenantId,
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

        return $document->id;
    }

    /**
     * Active company assets the new hire could be issued, for the IT-provisioning
     * preview. (The inverse of the offboarding asset-return surface.)
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function previewItProvisioningTasks(HrOnboardingChecklist $checklist): \Illuminate\Support\Collection
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
        $checklist = $task->checklist()->with('employeeProfile')->firstOrFail();
        $profile = $checklist->employeeProfile;

        if (! $profile || ! $profile->user_id) {
            throw new \LogicException('Cannot provision an asset for a hire with no linked user account.');
        }

        $assignment = AssetAssignment::query()
            ->where('asset_id', $asset->id)
            ->where('assignee_type', 'staff')
            ->where('assignee_id', $profile->user_id)
            ->whereNull('released_at')
            ->first();

        if (! $assignment) {
            $assignment = AssetAssignment::create([
                'asset_id' => $asset->id,
                'assignee_type' => 'staff',
                'assignee_id' => $profile->user_id,
                'purpose' => $purpose ?: 'Onboarding provisioning',
                'assigned_at' => now(),
            ]);
        }

        $note = trim((string) ($task->notes ?? ''));
        $stamp = "asset_assignment_id={$assignment->id};asset_id={$asset->id}";
        if (! str_contains($note, $stamp)) {
            $task->update(['notes' => trim($note.' '.$stamp)]);
        }

        return $this->completeTask($task, $actorId, array_filter([
            'signed_off_by' => $task->sign_off_required ? $signOffBy : null,
        ], fn ($v) => $v !== null));
    }

    /**
     * Pick the first free company asset for auto-provisioning: an asset in a
     * usable status (not retired/lost/in maintenance) with no active (unreleased)
     * assignment, optionally filtered to a category. Returns null when the pool
     * is empty so the caller can surface a clear "nothing to assign" message.
     */
    public function autoPickAvailableAsset(?string $category = null): ?Asset
    {
        return Asset::query()
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

        $this->checkOffboardingChecklistCompletion($checklist, $completedBy);

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
        $result = DB::transaction(function () use ($profile, $createdBy, $options) {
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

        // Notify assignees after commit (onboarding notifies too; without this,
        // offboarding tasks sat silently until someone opened the hub —
        // dangerous when the tasks gate access revocation + asset recovery).
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

        return $result;
    }

    /**
     * Reopen a mistakenly-completed offboarding task (mirror of the onboarding
     * uncomplete). Reverts a completed checklist to in_progress but NEVER
     * restores revoked system access — rehire owns restoration.
     */
    public function uncompleteOffboardingTask(HrOffboardingTask $task): HrOffboardingTask
    {
        if ($task->status !== 'completed') {
            return $task;
        }

        $task->update([
            'status' => 'pending',
            'completed_at' => null,
            'completed_by' => null,
            'signed_off_by' => null,
            'signed_off_at' => null,
        ]);

        $checklist = $task->checklist()->firstOrFail();
        if ($checklist->status === 'completed') {
            $checklist->update(['status' => 'in_progress', 'completed_at' => null]);
        }

        return $task->fresh();
    }

    /**
     * Cancel or archive an offboarding checklist without deleting it — e.g. a
     * retracted resignation (append-only history, mirrors the onboarding
     * status setter).
     */
    public function setOffboardingChecklistStatus(HrOffboardingChecklist $checklist, string $status): HrOffboardingChecklist
    {
        $checklist->update([
            'status' => $status,
            'completed_at' => $status === 'completed' ? ($checklist->completed_at ?? now()) : $checklist->completed_at,
        ]);

        return $checklist->fresh();
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

            // Notify the checklist owner (creator) that onboarding is complete.
            $owner = $checklist->created_by ? User::find($checklist->created_by) : null;
            if ($owner) {
                try {
                    $owner->notify(new \App\Domain\Hr\Notifications\OnboardingChecklistCompletedNotification(
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

        $roleName = $assignedToRole === 'manager' ? 'team_lead' : $assignedToRole;

        // Prefer an assignee who can actually action the task: login approved
        // and not a former employee (inactive profile). Fall back to the plain
        // role lookup so a template never silently loses its assignee when no
        // "clean" candidate exists.
        $eligibleId = (clone $users)
            ->where('role', $roleName)
            ->whereNotNull('approved_at')
            ->whereDoesntHave('hrEmployeeProfile', fn ($q) => $q->where('is_active', false))
            ->value('id');

        return $eligibleId ?? (clone $users)
            ->where('role', $roleName)
            ->value('id');
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
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function previewAssetReturns(HrEmployeeProfile $profile): \Illuminate\Support\Collection
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
