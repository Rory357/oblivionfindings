<?php

namespace App\Notifications;

use App\Models\SiteInspectionSchedule;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class InspectionDueNotification extends Notification
{
    use Queueable;

    public function __construct(
        private SiteInspectionSchedule $schedule,
        private string $type // 'upcoming' or 'overdue'
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $siteName = $this->schedule->site?->name ?? 'Unknown Site';
        
        if ($this->type === 'overdue') {
            return (new MailMessage)
                ->subject("OVERDUE: {$this->schedule->title} - {$siteName}")
                ->line("An inspection is now overdue:")
                ->line("**{$this->schedule->title}**")
                ->line("Site: {$siteName}")
                ->line("Due Date: {$this->schedule->next_due_date->format('l, F j, Y')}")
                ->action('Record Inspection', url("/sites/{$this->schedule->site_id}/inspections"))
                ->line('Please complete this inspection and record the results.');
        }
        
        return (new MailMessage)
            ->subject("Due Soon: {$this->schedule->title} - {$siteName}")
            ->line("You have an inspection due soon:")
            ->line("**{$this->schedule->title}**")
            ->line("Site: {$siteName}")
            ->line("Due Date: {$this->schedule->next_due_date->format('l, F j, Y')}")
            ->action('View Schedule', url("/sites/{$this->schedule->site_id}/inspections"));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->type === 'overdue' 
                ? "OVERDUE: {$this->schedule->title}"
                : "Due Soon: {$this->schedule->title}",
            'message' => $this->type === 'overdue'
                ? "Inspection at {$this->schedule->site?->name} is overdue"
                : "Inspection at {$this->schedule->site?->name} is due soon",
            'schedule_id' => $this->schedule->id,
            'site_id' => $this->schedule->site_id,
            'type' => $this->type === 'overdue' ? 'inspection_overdue' : 'inspection_due',
            'action_url' => "/sites/{$this->schedule->site_id}/inspections",
        ];
    }
}
