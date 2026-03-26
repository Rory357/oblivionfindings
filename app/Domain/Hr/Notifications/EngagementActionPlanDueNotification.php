<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrEngagementActionPlan;
use App\Models\NotificationTemplate;
use App\Services\TemplateRenderService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class EngagementActionPlanDueNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly HrEngagementActionPlan $plan,
        private readonly int $daysUntilDue,
        private readonly string $reminderKind,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $templateKey = $this->reminderKind === 'overdue' ? 'action_plan_overdue' : 'action_plan_due';
        $template = NotificationTemplate::findByKey($templateKey);

        if ($template && $template->is_active) {
            $service = app(TemplateRenderService::class);
            $context = [
                'document_name' => $this->plan->title,
                'due_date' => optional($this->plan->due_date)->format('d/m/Y') ?? 'Not set',
                'days_remaining' => (string) abs($this->daysUntilDue),
            ];

            $body = $service->render($template, $notifiable, $context);
            $subject = $service->renderSubject($template, $notifiable, $context);

            return (new MailMessage)
                ->subject($subject)
                ->line(new HtmlString(nl2br(e($body))));
        }

        $subject = $this->reminderKind === 'overdue'
            ? 'Action plan overdue'
            : 'Action plan due soon';

        $line = $this->reminderKind === 'overdue'
            ? "This engagement action plan is overdue by " . abs($this->daysUntilDue) . " day(s)."
            : "This engagement action plan is due in {$this->daysUntilDue} day(s).";

        return (new MailMessage)
            ->subject($subject)
            ->line($line)
            ->line('Plan: ' . $this->plan->title)
            ->line('Due date: ' . (optional($this->plan->due_date)->toDateString() ?? 'Not set'))
            ->action('Open Wellbeing Dashboard', url('/hr/wellbeing'));
    }

    public function toArray(object $notifiable): array
    {
        $message = $this->reminderKind === 'overdue'
            ? 'This action plan is overdue by ' . abs($this->daysUntilDue) . ' day(s).'
            : "This action plan is due in {$this->daysUntilDue} day(s).";

        return [
            'type' => 'hr_engagement_action_plan_due',
            'title' => "Action Plan {$this->reminderKind}: {$this->plan->title}",
            'message' => $message,
            'url' => '/hr/wellbeing',
            'context' => [
                'Priority' => $this->plan->priority,
                'Status' => $this->plan->status,
                'Progress' => ((int) $this->plan->progress_percent) . '%',
                'Due date' => optional($this->plan->due_date)->toDateString() ?? 'Not set',
            ],
            'action_plan_id' => $this->plan->id,
            'survey_id' => $this->plan->survey_id,
            'priority' => $this->plan->priority,
            'status' => $this->plan->status,
            'progress_percent' => (int) $this->plan->progress_percent,
            'due_date' => optional($this->plan->due_date)->toDateString(),
            'days_until_due' => $this->daysUntilDue,
            'reminder_kind' => $this->reminderKind,
        ];
    }
}
