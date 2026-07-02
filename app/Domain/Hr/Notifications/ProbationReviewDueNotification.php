<?php

namespace App\Domain\Hr\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to an employee's manager (fallback: provider managers) when the
 * employee's probation end date is within 14 days — or already past — and no
 * concluding probation review (passed / failed) has been recorded.
 * Fired by {@see \App\Console\Commands\Hr\SendProbationRemindersCommand}.
 */
class ProbationReviewDueNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $employeeName,
        public int $employeeUserId,
        public string $probationEndDate,
        public bool $overdue = false,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->overdue
            ? "Probation review overdue: {$this->employeeName}"
            : "Probation review due: {$this->employeeName}";

        return (new MailMessage)
            ->subject($subject)
            ->greeting('Kia ora ' . ($notifiable->name ?? 'there') . ',')
            ->line($this->overdue
                ? "**{$this->employeeName}**'s probation period ended on **{$this->probationEndDate}** and no completed probation review has been recorded."
                : "**{$this->employeeName}**'s probation period ends on **{$this->probationEndDate}** and no completed probation review has been recorded.")
            ->line('Please schedule and record their probation review (pass, extend, or end employment) before the period lapses.')
            ->action('Open Performance hub', url('/hr/performance?tab=probation'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->overdue ? 'Probation review overdue' : 'Probation review due',
            'message' => "{$this->employeeName}'s probation "
                . ($this->overdue ? "ended {$this->probationEndDate}" : "ends {$this->probationEndDate}")
                . ' — no completed probation review on file.',
            'employee_user_id' => $this->employeeUserId,
            'probation_end_date' => $this->probationEndDate,
            'overdue' => $this->overdue,
            'action_url' => '/hr/performance?tab=probation',
            'category' => 'hr_performance',
        ];
    }
}
