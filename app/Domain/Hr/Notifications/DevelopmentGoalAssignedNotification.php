<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Models\NotificationTemplate;
use App\Services\TemplateRenderService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class DevelopmentGoalAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly HrDevelopmentGoal $goal,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $template = NotificationTemplate::findByKey('development_goal_assigned');

        if ($template && $template->is_active) {
            $service = app(TemplateRenderService::class);
            $context = [
                'document_name' => $this->goal->title,
                'due_date' => optional($this->goal->due_date)->format('d/m/Y') ?? 'Not set',
            ];

            $body = $service->render($template, $notifiable, $context);
            $subject = $service->renderSubject($template, $notifiable, $context);

            return (new MailMessage)
                ->subject($subject)
                ->line(new HtmlString(nl2br(e($body))));
        }

        return (new MailMessage)
            ->subject('New Development Goal Assigned')
            ->line('A new development goal has been assigned to you.')
            ->line('Goal: ' . $this->goal->title)
            ->action('View Goal', url('/hr/goals/development'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'hr_development_goal_assigned',
            'title' => "Development Goal: {$this->goal->title}",
            'message' => 'A new development goal has been assigned to you.',
            'url' => '/hr/goals/development',
            'context' => [
                'Goal' => $this->goal->title,
                'Due date' => optional($this->goal->due_date)->toDateString() ?? 'Not set',
            ],
            'goal_id' => $this->goal->id,
            'due_date' => optional($this->goal->due_date)->toDateString(),
        ];
    }
}
