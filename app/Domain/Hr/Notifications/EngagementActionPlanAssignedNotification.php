<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrEngagementActionPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the owner of a wellbeing / engagement action plan the moment it is
 * created and assigned to them. Previously an owner only heard about a plan
 * once EngagementActionPlanDueNotification fired as the due date approached —
 * so a duty-of-care follow-up with no due date (or one weeks out) could sit
 * unseen by the very person made responsible for it.
 */
class EngagementActionPlanAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly HrEngagementActionPlan $plan,
        private readonly string $assignedByName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You have been assigned a wellbeing action plan')
            ->line($this->assignedByName . ' has assigned you an engagement action plan.')
            ->line('Plan: ' . $this->plan->title)
            ->line('Priority: ' . ucfirst((string) $this->plan->priority))
            ->line('Due date: ' . (optional($this->plan->due_date)->format('d/m/Y') ?? 'Not set'))
            ->action('Open Wellbeing Dashboard', url('/hr/wellbeing'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'hr_engagement_action_plan_assigned',
            'title' => 'Action plan assigned: ' . $this->plan->title,
            'message' => $this->assignedByName . ' assigned you a wellbeing action plan.',
            'url' => '/hr/wellbeing',
            'context' => [
                'Priority' => $this->plan->priority,
                'Status' => $this->plan->status,
                'Due date' => optional($this->plan->due_date)->toDateString() ?? 'Not set',
            ],
            'action_plan_id' => $this->plan->id,
            'survey_id' => $this->plan->survey_id,
            'priority' => $this->plan->priority,
            'status' => $this->plan->status,
            'due_date' => optional($this->plan->due_date)->toDateString(),
        ];
    }
}
