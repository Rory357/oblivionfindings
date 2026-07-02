<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrPerformanceImprovementPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a Performance Improvement Plan is created — to the subject
 * employee (review & acknowledge) and to the plan's manager (confirmation).
 * NZ good-faith process: the employee must be told, in plain language, what
 * the plan covers and where to read it.
 */
class PipCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrPerformanceImprovementPlan $pip,
        private string $managerName,
        private bool $forSubject = true,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $period = $this->pip->start_date?->format('j M Y').' – '.$this->pip->end_date?->format('j M Y');
        $url = url("/hr/performance/pips/{$this->pip->id}");

        if (! $this->forSubject) {
            return (new MailMessage)
                ->subject("Support plan created — {$this->pip->title}")
                ->greeting('Kia ora,')
                ->line("The support plan **{$this->pip->title}** ({$period}) has been created and the employee has been asked to review and acknowledge it.")
                ->action('Open the plan', $url)
                ->salutation('Ngā mihi, The People Team');
        }

        return (new MailMessage)
            ->subject("A support plan has been set up for you — {$this->pip->title}")
            ->greeting('Kia ora,')
            ->line("Your manager, {$this->managerName}, has set up a support plan for you: **{$this->pip->title}**.")
            ->line("It runs {$period} and sets out the expectations, the support you'll receive, and the milestones along the way.")
            ->line('Please take the time to read it carefully and acknowledge it. If anything is unclear, or you would like a support person or representative involved, talk to your manager — you are welcome to do so at any stage.')
            ->action('Review and acknowledge', $url)
            ->salutation('Ngā mihi, The People Team');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'pip_created',
            'pip_id' => $this->pip->id,
            'title' => $this->pip->title,
            'manager_name' => $this->managerName,
            'start_date' => $this->pip->start_date?->toDateString(),
            'end_date' => $this->pip->end_date?->toDateString(),
            'for_subject' => $this->forSubject,
            'action_url' => "/hr/performance/pips/{$this->pip->id}",
        ];
    }
}
