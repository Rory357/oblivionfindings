<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Models\HrGoal;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Notifications\ComplianceExpiryNotification;
use App\Domain\Hr\Notifications\ExpenseApprovedNotification;
use App\Domain\Hr\Notifications\ExpenseSubmittedNotification;
use App\Domain\Hr\Notifications\GoalCompletedNotification;
use App\Domain\Hr\Notifications\HrAssetAlertNotification;
use App\Domain\Hr\Notifications\LeaveApprovedNotification;
use App\Domain\Hr\Notifications\LeaveDeclinedNotification;
use App\Domain\Hr\Notifications\LeaveRequestNotification;
use App\Domain\Hr\Notifications\OnboardingTaskAssignedNotification;
use App\Domain\Hr\Notifications\PerformanceReviewDueNotification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class HrNotificationService
{
    /**
     * Notify the employee's manager (or all users with hr.leave.approve) about a new leave request.
     */
    public function notifyLeaveRequest(HrLeaveRequest $request): void
    {
        $request->loadMissing('user');

        $recipients = $this->getLeaveApprovers($request);

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new LeaveRequestNotification($request));
            } catch (\Throwable $e) {
                Log::warning('Failed to send leave request notification', [
                    'leave_request_id' => $request->id,
                    'recipient_id' => $recipient->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Notify the employee that their leave request was approved.
     */
    public function notifyLeaveApproved(HrLeaveRequest $request): void
    {
        $employee = $request->user ?? User::find($request->user_id);

        if ($employee) {
            try {
                $employee->notify(new LeaveApprovedNotification($request));
            } catch (\Throwable $e) {
                Log::warning('Failed to send leave approved notification', [
                    'leave_request_id' => $request->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Notify the employee that their leave request was declined.
     */
    public function notifyLeaveDeclined(HrLeaveRequest $request): void
    {
        $employee = $request->user ?? User::find($request->user_id);

        if ($employee) {
            try {
                $employee->notify(new LeaveDeclinedNotification($request));
            } catch (\Throwable $e) {
                Log::warning('Failed to send leave declined notification', [
                    'leave_request_id' => $request->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Notify the assigned user about an onboarding task.
     */
    public function notifyOnboardingTask(HrOnboardingTask $task): void
    {
        $assignee = $task->assignedTo ?? User::find($task->assigned_to_user_id);

        if ($assignee) {
            try {
                $assignee->notify(new OnboardingTaskAssignedNotification($task));
            } catch (\Throwable $e) {
                Log::warning('Failed to send onboarding task notification', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Notify both the employee and reviewer about a performance review that's due.
     */
    public function notifyPerformanceReviewDue(HrPerformanceReview $review): void
    {
        $review->loadMissing(['employee', 'reviewer']);

        $employeeName = $review->employee?->name ?? 'Unknown';
        $dueDate = $review->review_period_end?->toDateString() ?? 'N/A';

        $recipients = collect([$review->employee, $review->reviewer])->filter();

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new PerformanceReviewDueNotification(
                    $review->review_type ?? 'annual',
                    $employeeName,
                    $dueDate,
                    $review->id,
                ));
            } catch (\Throwable $e) {
                Log::warning('Failed to send performance review notification', [
                    'review_id' => $review->id,
                    'recipient_id' => $recipient->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Notify the employee and HR about a compliance item expiring.
     */
    public function notifyComplianceExpiry(HrStaffComplianceStatus $status): void
    {
        $status->loadMissing(['user', 'requirement']);

        $employee = $status->user;
        $requirementData = [
            'name' => $status->requirement?->name ?? 'Unknown Requirement',
            'requirement_code' => $status->requirement?->code ?? null,
            'expires_at' => $status->expires_at?->toDateString(),
        ];

        // Notify the employee
        if ($employee) {
            try {
                $employee->notify(new ComplianceExpiryNotification($employee, $requirementData));
            } catch (\Throwable $e) {
                Log::warning('Failed to send compliance expiry notification to employee', [
                    'status_id' => $status->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Notify HR users with compliance management permissions
        $hrUsers = $this->getUsersWithPermission(['hr.compliance.manage'], $status->tenant_id);
        foreach ($hrUsers as $hrUser) {
            if ($hrUser->id === $employee?->id) {
                continue;
            }
            try {
                $hrUser->notify(new ComplianceExpiryNotification(
                    $employee ?? $hrUser,
                    $requirementData,
                ));
            } catch (\Throwable $e) {
                Log::warning('Failed to send compliance expiry notification to HR', [
                    'status_id' => $status->id,
                    'hr_user_id' => $hrUser->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Notify approvers about a submitted expense claim.
     */
    public function notifyExpenseSubmitted(HrExpenseClaim $claim): void
    {
        $claim->loadMissing('user');

        $approvers = $this->getUsersWithPermission(['hr.expenses.approve'], $claim->tenant_id);

        foreach ($approvers as $approver) {
            try {
                $approver->notify(new ExpenseSubmittedNotification($claim));
            } catch (\Throwable $e) {
                Log::warning('Failed to send expense submitted notification', [
                    'claim_id' => $claim->id,
                    'approver_id' => $approver->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Notify the employee that their expense claim was approved.
     */
    public function notifyExpenseApproved(HrExpenseClaim $claim): void
    {
        $employee = $claim->user ?? User::find($claim->user_id);

        if ($employee) {
            try {
                $employee->notify(new ExpenseApprovedNotification($claim));
            } catch (\Throwable $e) {
                Log::warning('Failed to send expense approved notification', [
                    'claim_id' => $claim->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Notify the manager when an employee completes a goal.
     */
    public function notifyGoalCompleted(HrGoal $goal): void
    {
        $goal->loadMissing('user');

        $employee = $goal->user;
        if (! $employee) {
            return;
        }

        // Notify the employee's manager (created_by is often the manager)
        $manager = $goal->created_by && $goal->created_by !== $employee->id
            ? User::find($goal->created_by)
            : null;

        if ($manager) {
            try {
                $manager->notify(new GoalCompletedNotification($goal));
            } catch (\Throwable $e) {
                Log::warning('Failed to send goal completed notification', [
                    'goal_id' => $goal->id,
                    'manager_id' => $manager->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Get users who can approve leave requests for the given request.
     */
    protected function getLeaveApprovers(HrLeaveRequest $request): Collection
    {
        return $this->getUsersWithPermission(['hr.leave.approve'], $request->tenant_id)
            ->reject(fn (User $u) => $u->id === $request->user_id);
    }

    /**
     * Deliver HR asset attention alerts (warranty / overdue / repair / leaver) to
     * every user who can manage assets in the alert's tenant. Suppresses repeats
     * per the alert's dedupe scope: 'once' = never re-send the same key, 'daily' =
     * at most one per day (so ongoing states keep nudging until resolved).
     *
     * @param  array<int,array<string,mixed>>  $alerts
     * @return int Number of notifications sent.
     */
    public function sendAssetAlerts(array $alerts): int
    {
        // Resolve asset managers via a candidate query + canDo() filter. We avoid
        // getUsersWithPermission() here: its wherePivot() inside a whereHas closure
        // emits an invalid `pivot` column reference on this branch. canDo() applies
        // the role + allow/deny-override precedence correctly.
        $recipients = $this->assetManagers();
        if ($recipients->isEmpty()) {
            return 0;
        }

        $sent = 0;

        foreach ($alerts as $alert) {
            foreach ($recipients as $recipient) {
                $query = $recipient->notifications()
                    ->where('type', HrAssetAlertNotification::class)
                    ->where('data->dedupe_key', $alert['dedupe_key']);

                if (($alert['scope'] ?? 'daily') === 'daily') {
                    $query->whereDate('created_at', now()->toDateString());
                }

                if ($query->exists()) {
                    continue;
                }

                try {
                    $recipient->notify(new HrAssetAlertNotification([
                        'kind' => $alert['kind'],
                        'title' => $alert['title'],
                        'message' => $alert['message'],
                        'asset_id' => $alert['asset_id'] ?? null,
                        'action_url' => $alert['action_url'],
                        'dedupe_key' => $alert['dedupe_key'],
                    ]));
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning('Failed to send HR asset alert notification', [
                        'dedupe_key' => $alert['dedupe_key'],
                        'recipient_id' => $recipient->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $sent;
    }

    /**
     * Users who may manage HR assets, resolved correctly via canDo() (role grant +
     * allow/deny override precedence). The candidate query narrows to users who
     * either hold a role granting the permission or carry an explicit override.
     */
    protected function assetManagers(): Collection
    {
        $key = 'hr.assets.manage';

        return User::query()
            ->where(function ($query) use ($key) {
                $query->whereHas('roles.permissions', fn ($p) => $p->where('key', $key))
                    ->orWhereHas('permissionOverrides', fn ($p) => $p->where('permissions.key', $key));
            })
            ->get()
            ->filter(fn (User $user) => $user->canDo($key))
            ->values();
    }

    /**
     * Get all users who have any of the supplied permissions, optionally scoped to a tenant.
     *
     * @param  array<int, string>  $permissions
     */
    protected function getUsersWithPermission(array $permissions, ?int $tenantId): Collection
    {
        return User::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where(function ($query) use ($permissions) {
                $query->whereHas('roles.permissions', fn ($permissionQuery) => $permissionQuery->whereIn('key', $permissions))
                    ->orWhereHas('permissionOverrides', fn ($permissionQuery) => $permissionQuery
                        ->whereIn('permissions.key', $permissions)
                        ->wherePivot('allowed', true));
            })
            ->whereDoesntHave('permissionOverrides', fn ($permissionQuery) => $permissionQuery
                ->whereIn('permissions.key', $permissions)
                ->wherePivot('allowed', false))
            ->distinct()
            ->get();
    }
}
