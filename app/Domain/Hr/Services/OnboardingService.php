<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOffboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OnboardingService
{
    /**
     * Generate an onboarding checklist for a new employee from the matching template.
     *
     * Looks up the active HrOnboardingTemplate by the employee's position_role and
     * creates an HrOnboardingChecklist with individual HrOnboardingTask rows cloned
     * from the template's tasks JSON.
     *
     * @param  HrEmployeeProfile  $profile
     * @param  int                $createdBy  User ID initiating the onboarding
     * @return HrOnboardingChecklist
     *
     * @throws \RuntimeException If no active template matches the employee's role
     */
    public function generateChecklist(HrEmployeeProfile $profile, int $createdBy): HrOnboardingChecklist
    {
        // TODO: Look up the active template by role (position_role) and optionally site_type
        // TODO: Fall back to a 'default' template if no role-specific template exists
        // TODO: Create HrOnboardingChecklist record with status 'pending'
        // TODO: Clone each task from the template's tasks JSON array into HrOnboardingTask rows
        // TODO: Set default due_date based on start_date + offset days per task
        // TODO: Assign tasks to roles/users where specified in the template
        // TODO: Fire OnboardingStarted event for notification listeners
        // TODO: Log audit trail entry

        return DB::transaction(function () use ($profile, $createdBy) {
            $template = HrOnboardingTemplate::where('tenant_id', $profile->tenant_id)
                ->active()
                ->where(function ($q) use ($profile) {
                    $q->where('role', $profile->position_role)
                      ->orWhere('role', 'default');
                })
                ->orderByRaw("CASE WHEN role = ? THEN 0 ELSE 1 END", [$profile->position_role])
                ->first();

            if (! $template) {
                throw new \RuntimeException(
                    "No active onboarding template found for role '{$profile->position_role}'."
                );
            }

            $checklist = HrOnboardingChecklist::create([
                'tenant_id' => $profile->tenant_id,
                'employee_profile_id' => $profile->id,
                'template_key' => $template->role,
                'status' => 'pending',
                'started_at' => now(),
                'due_date' => $profile->start_date?->addDays(30),
                'created_by' => $createdBy,
            ]);

            $tasks = $template->tasks ?? [];
            foreach ($tasks as $index => $taskDef) {
                HrOnboardingTask::create([
                    'checklist_id' => $checklist->id,
                    'category' => $taskDef['category'] ?? 'general',
                    'title' => $taskDef['title'],
                    'description' => $taskDef['description'] ?? null,
                    'is_required' => $taskDef['is_required'] ?? true,
                    'sort_order' => $taskDef['sort_order'] ?? ($index + 1),
                    'assigned_to_role' => $taskDef['assigned_to_role'] ?? null,
                    'sign_off_required' => $taskDef['sign_off_required'] ?? false,
                    'status' => 'pending',
                ]);
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
     * @param  HrOnboardingTask  $task
     * @param  int               $completedBy  User ID completing the task
     * @param  array             $data         Optional data: evidence_path, notes, signed_off_by
     * @return HrOnboardingTask
     *
     * @throws \LogicException If task is already completed or checklist is not active
     */
    public function completeTask(HrOnboardingTask $task, int $completedBy, array $data = []): HrOnboardingTask
    {
        // TODO: Verify the task is not already completed
        // TODO: Verify the parent checklist is in 'pending' or 'in_progress' status
        // TODO: If sign_off_required, verify that signed_off_by is provided
        // TODO: Update task status to 'completed' with timestamp and completed_by
        // TODO: Store evidence_path if provided
        // TODO: Check if all required tasks in the checklist are now complete
        // TODO: If all required tasks done, mark checklist as 'completed'
        // TODO: Fire TaskCompleted / ChecklistCompleted events
        // TODO: Log audit trail entry

        if ($task->status === 'completed') {
            throw new \LogicException("Task '{$task->title}' is already completed.");
        }

        $checklist = $task->checklist;
        if (! in_array($checklist->status, ['pending', 'in_progress'], true)) {
            throw new \LogicException("Cannot complete tasks on a '{$checklist->status}' checklist.");
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
     * Generate an offboarding checklist for a departing employee.
     *
     * Creates an HrOffboardingChecklist with standard departure tasks
     * (IT access revocation, equipment return, exit interview, etc.).
     *
     * @param  HrEmployeeProfile  $profile
     * @param  int                $createdBy   User ID initiating the offboarding
     * @param  array              $options     Optional overrides: end_date, termination_reason
     * @return HrOffboardingChecklist
     */
    public function generateOffboardingChecklist(HrEmployeeProfile $profile, int $createdBy, array $options = []): HrOffboardingChecklist
    {
        // TODO: Look up offboarding template by role (similar to onboarding)
        // TODO: If no template exists, use a hardcoded default set of offboarding tasks:
        //       - Revoke system access / deactivate accounts
        //       - Collect company equipment (laptop, phone, keys, ID badge)
        //       - Final pay calculation and leave payout
        //       - Exit interview scheduling
        //       - Knowledge transfer / handover documentation
        //       - Remove from rosters and shift schedules
        //       - Archive employee documents
        //       - Update employee profile (is_active = false, end_date, termination_reason)
        // TODO: Create HrOffboardingChecklist with associated tasks
        // TODO: Set due_date based on end_date or options
        // TODO: Notify relevant managers (HR, IT, direct supervisor)
        // TODO: Fire OffboardingStarted event
        // TODO: Log audit trail entry

        return DB::transaction(function () use ($profile, $createdBy, $options) {
            $endDate = $options['end_date'] ?? $profile->end_date ?? now()->addWeeks(2);

            $checklist = HrOffboardingChecklist::create([
                'tenant_id' => $profile->tenant_id,
                'employee_profile_id' => $profile->id,
                'template_key' => 'offboarding',
                'status' => 'pending',
                'started_at' => now(),
                'due_date' => $endDate,
                'created_by' => $createdBy,
            ]);

            $defaultTasks = $this->getDefaultOffboardingTasks();
            foreach ($defaultTasks as $index => $taskDef) {
                HrOnboardingTask::create([
                    'checklist_id' => $checklist->id,
                    'category' => $taskDef['category'],
                    'title' => $taskDef['title'],
                    'description' => $taskDef['description'],
                    'is_required' => $taskDef['is_required'],
                    'sort_order' => $index + 1,
                    'assigned_to_role' => $taskDef['assigned_to_role'] ?? null,
                    'sign_off_required' => $taskDef['sign_off_required'] ?? false,
                    'status' => 'pending',
                ]);
            }

            return $checklist->load('tasks');
        });
    }

    /**
     * Get the progress summary for a checklist.
     *
     * @param  HrOnboardingChecklist|HrOffboardingChecklist  $checklist
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
        }
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
}
