<?php

namespace App\Notifications;

use App\Models\SiteChecklistRun;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class ChecklistDueNotification extends Notification
{
    use Queueable;

    public function __construct(
        private SiteChecklistRun $run,
        private string $type // 'reminder' or 'overdue'
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $siteName = $this->run->site?->name ?? 'Unknown Site';
        $templateName = $this->run->template?->name ?? 'Checklist';
        
        if ($this->type === 'overdue') {
            return (new MailMessage)
                ->subject("OVERDUE: {$templateName} - {$siteName}")
                ->line("A checklist is now overdue:")
                ->line("**{$templateName}**")
                ->line("Site: {$siteName}")
                ->line("Due Date: {$this->run->scheduled_date->format('l, F j, Y')}")
                ->action('Complete Now', url("/checklists/runs/{$this->run->id}"))
                ->line('Please complete this checklist as soon as possible.');
        }
        
        return (new MailMessage)
            ->subject("Reminder: {$templateName} due tomorrow - {$siteName}")
            ->line("You have a checklist due tomorrow:")
            ->line("**{$templateName}**")
            ->line("Site: {$siteName}")
            ->line("Due Date: {$this->run->scheduled_date->format('l, F j, Y')}")
            ->action('View Checklist', url("/sites/{$this->run->site_id}/checklists"));
    }

    public function toDatabase(object $notifiable): array
    {
        $templateName = $this->run->template?->name ?? 'Checklist';
        
        return [
            'title' => $this->type === 'overdue' 
                ? "OVERDUE: {$templateName}"
                : "Due Tomorrow: {$templateName}",
            'message' => $this->type === 'overdue'
                ? "Checklist at {$this->run->site?->name} is overdue"
                : "Checklist at {$this->run->site?->name} is due tomorrow",
            'run_id' => $this->run->id,
            'site_id' => $this->run->site_id,
            'type' => $this->type === 'overdue' ? 'checklist_overdue' : 'checklist_due',
            'action_url' => $this->type === 'overdue' 
                ? "/checklists/runs/{$this->run->id}"
                : "/sites/{$this->run->site_id}/checklists",
        ];
    }
}
