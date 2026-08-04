<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Models\HrGoal;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Domain\Hr\Notifications\ComplianceExpiryNotification;
use App\Domain\Hr\Notifications\DevelopmentGoalCompletedNotification;
use App\Domain\Hr\Notifications\ExpenseApprovedNotification;
use App\Domain\Hr\Notifications\ExpenseRejectedNotification;
use App\Domain\Hr\Notifications\ExpenseSubmittedNotification;
use App\Domain\Hr\Notifications\GoalCompletedNotification;
use App\Domain\Hr\Notifications\HrAssetAlertNotification;
use App\Domain\Hr\Notifications\LeaveApprovedNotification;
use App\Domain\Hr\Notifications\LeaveCancelledNotification;
use App\Domain\Hr\Notifications\LeaveDeclinedNotification;
use App\Domain\Hr\Notifications\LeaveRequestNotification;
use App\Domain\Hr\Notifications\OnboardingTaskAssignedNotification;
use App\Domain\Hr\Notifications\PerformanceReviewDueNotification;
use App\Domain\Hr\Notifications\ReviewReadyForAcknowledgementNotification;
use App\Domain\Hr\Notifications\ReviewSignedOffNotification;
use App\Domain\Hr\Notifications\SupervisionAcknowledgedNotification;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class HrNotificationService
{
    public function __construct(
        private readonly HrCurrentStaffService $currentStaff,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

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

        if ($employee && app(HrLeaveAccessService::class)->isCurrentStaff($employee)) {
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
     * Notify the right party that a leave request was cancelled. Employee
     * self-cancel → the approvers hear about it (an approved booking coming off
     * the roster changes their plans); manager/admin cancel → the employee hears.
     */
    public function notifyLeaveCancelled(HrLeaveRequest $request, bool $wasApproved, int $cancelledBy): void
    {
        $request->loadMissing('user');

        if ($cancelledBy === $request->user_id) {
            // Self-cancel → tell whoever was going to (or did) action it: the
            // assigned approver when one exists, otherwise the approver pool.
            $assigned = $request->escalated_to && $request->escalated_to !== $request->user_id
                ? User::find($request->escalated_to)
                : null;
            $eligibleAssigned = $assigned
                && $request->user
                && app(HrLeaveAccessService::class)->isEligibleApprover($request->user, $assigned);
            $recipients = $eligibleAssigned
                ? collect([$assigned])
                : $this->getLeaveApprovers($request);
        } else {
            // Manager/admin cancel → tell the employee.
            $recipients = collect([$request->user ?? User::find($request->user_id)])
                ->filter(fn (?User $employee): bool => $employee !== null
                    && app(HrLeaveAccessService::class)->isCurrentStaff($employee));
        }

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new LeaveCancelledNotification($request, $wasApproved));
            } catch (\Throwable $e) {
                Log::warning('Failed to send leave cancelled notification', [
                    'leave_request_id' => $request->id,
                    'recipient_id' => $recipient->id,
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

        if ($employee && app(HrLeaveAccessService::class)->isCurrentStaff($employee)) {
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
        if (! $employee || ! $this->currentStaff->isCurrent($employee)) {
            return;
        }

        $requirementData = [
            'name' => $status->requirement?->name ?? 'Unknown Requirement',
            'requirement_code' => $status->requirement?->code ?? null,
            'expires_at' => $status->expires_at?->toDateString(),
        ];

        // Notify the employee
        try {
            $employee->notify(new ComplianceExpiryNotification($employee, $requirementData));
        } catch (\Throwable $e) {
            Log::warning('Failed to send compliance expiry notification to employee', [
                'status_id' => $status->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Notify current HR users who may see this employee's Site.
        $hrUsers = $this->currentRecipientsForSubject(['hr.compliance.manage'], $employee);
        foreach ($hrUsers as $hrUser) {
            try {
                $hrUser->notify(new ComplianceExpiryNotification(
                    $employee,
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
        $employee = $claim->user;
        if (! $employee || ! $this->currentStaff->isCurrent($employee)) {
            return;
        }

        $approvers = $this->currentRecipientsForSubject(['hr.expenses.approve'], $employee);

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

        if ($employee && $this->currentStaff->isCurrent($employee)) {
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
     * Notify the employee that their expense claim was rejected (with reason).
     */
    public function notifyExpenseRejected(HrExpenseClaim $claim): void
    {
        $employee = $claim->user ?? User::find($claim->user_id);

        if ($employee && $this->currentStaff->isCurrent($employee)) {
            try {
                $employee->notify(new ExpenseRejectedNotification($claim));
            } catch (\Throwable $e) {
                Log::warning('Failed to send expense rejected notification', [
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

    /**
     * Notify the manager when a development goal is completed. Recipient is the
     * assigned manager (falling back to the creator); skips the case where the
     * manager is the one who marked it complete. Called from both the manager
     * hub edit and the employee's self-service update.
     */
    public function notifyDevelopmentGoalCompleted(HrDevelopmentGoal $goal, ?int $actingUserId = null): void
    {
        $goal->loadMissing('employee');

        $managerId = $goal->manager_user_id ?: $goal->created_by;
        $manager = $managerId && $managerId !== $actingUserId
            ? User::find($managerId)
            : null;

        if ($manager) {
            try {
                $manager->notify(new DevelopmentGoalCompletedNotification($goal));
            } catch (\Throwable $e) {
                Log::warning('Failed to send development-goal completed notification', [
                    'goal_id' => $goal->id,
                    'manager_id' => $manager->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Notify the employee when their manager signs off their review — they are
     * now the waiting party and need to read it and acknowledge. Skips the
     * self-review edge (employee is their own reviewer).
     */
    public function notifyReviewReadyForAcknowledgement(HrPerformanceReview $review): void
    {
        $review->loadMissing('employee');

        $employee = $review->employee_user_id && $review->employee_user_id !== $review->reviewer_user_id
            ? User::find($review->employee_user_id)
            : null;

        if ($employee) {
            try {
                $employee->notify(new ReviewReadyForAcknowledgementNotification($review));
            } catch (\Throwable $e) {
                Log::warning('Failed to send review ready-for-acknowledgement notification', [
                    'review_id' => $review->id,
                    'employee_id' => $employee->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Notify the reviewer when the employee signs off on their performance
     * review — the reviewer is waiting on that acknowledgement to close it out.
     */
    public function notifyReviewSignedOff(HrPerformanceReview $review): void
    {
        $review->loadMissing('employee');

        $reviewer = $review->reviewer_user_id && $review->reviewer_user_id !== $review->employee_user_id
            ? User::find($review->reviewer_user_id)
            : null;

        if ($reviewer) {
            try {
                $reviewer->notify(new ReviewSignedOffNotification($review));
            } catch (\Throwable $e) {
                Log::warning('Failed to send review signed-off notification', [
                    'review_id' => $review->id,
                    'reviewer_id' => $reviewer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Notify the supervisor when the employee acknowledges a supervision / 1:1
     * note — the supervisor is waiting on that acknowledgement to close it out.
     * Skips the edge where the supervisor is also the note's subject.
     */
    public function notifySupervisionAcknowledged(HrSupervisionNote $note): void
    {
        $note->loadMissing('employee');

        $supervisor = $note->supervisor_user_id && $note->supervisor_user_id !== $note->employee_user_id
            ? User::find($note->supervisor_user_id)
            : null;

        if ($supervisor) {
            try {
                $supervisor->notify(new SupervisionAcknowledgedNotification($note));
            } catch (\Throwable $e) {
                Log::warning('Failed to send supervision acknowledged notification', [
                    'note_id' => $note->id,
                    'supervisor_id' => $supervisor->id,
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
        $request->loadMissing('user');
        if (! $request->user) {
            return collect();
        }

        return app(HrLeaveAccessService::class)->eligibleApprovers($request->user);
    }

    /**
     * Deliver HR asset attention alerts (warranty / overdue / repair / leaver) to
     * current asset managers who can still access the alert's complete Site
     * provenance. Suppresses repeats per the alert's dedupe scope: 'once' =
     * never re-send the same key, 'daily' =
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
        $access = app(HrAssetAccessService::class);

        foreach ($alerts as $alert) {
            foreach ($recipients->filter(fn (User $recipient): bool => $access->canReceiveAlert($recipient, $alert)) as $recipient) {
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
     * Resolve current permission holders who may see the subject through their
     * canonical Site assignments. Effective permission checks preserve direct
     * allow and explicit-deny precedence after the candidate query is narrowed.
     *
     * @param  array<int, string>  $permissions
     * @return Collection<int, User>
     */
    protected function currentRecipientsForSubject(array $permissions, User $subject): Collection
    {
        if ($permissions === [] || ! $this->currentStaff->isCurrent($subject)) {
            return collect();
        }

        return $this->currentStaff->currentUsersQuery()
            ->whereKeyNot($subject->getKey())
            ->where(function ($query) use ($permissions) {
                $query->whereHas('roles.permissions', fn ($permissionQuery) => $permissionQuery->whereIn('key', $permissions))
                    ->orWhereHas('permissionOverrides', fn ($permissionQuery) => $permissionQuery->whereIn(
                        'permissions.key',
                        $permissions,
                    ));
            })
            ->with(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile'])
            ->distinct()
            ->get()
            ->filter(fn (User $candidate): bool => collect($permissions)->contains(
                fn (string $permission): bool => $candidate->canDo($permission),
            ))
            ->filter(fn (User $candidate): bool => $this->siteAccess
                ->applyStaffScope(User::query()->whereKey($subject->getKey()), $candidate)
                ->exists())
            ->values();
    }
}
