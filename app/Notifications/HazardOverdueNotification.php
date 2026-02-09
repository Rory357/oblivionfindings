<?php

namespace App\Notifications;

use App\Models\SiteHazard;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class HazardOverdueNotification extends Notification
{
    use Queueable;

    public function __construct(
        private SiteHazard $hazard,
        private string $type // 'warning' or 'overdue' or 'overdue_escalation'
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $siteName = $this->hazard->site?->name ?? 'Unknown Site';
        
        if ($this->type === 'overdue_escalation') {
            return (new MailMessage)
                ->subject("ESCALATION: Overdue Hazard - {$siteName}")
                ->line("A hazard has become overdue and requires attention:")
                ->line("**{$this->hazard->hazard_type}**")
                ->line("Site: {$siteName}")
                ->line("Risk Rating: " . strtoupper($this->hazard->risk_rating))
                ->line("Due Date: {$this->hazard->due_date->format('l, F j, Y')}")
                ->action('View Hazard', url("/hazards/{$this->hazard->id}"));
        }
        
        if ($this->type === 'overdue') {
            return (new MailMessage)
                ->subject("OVERDUE: Hazard - {$siteName}")
                ->line("A hazard you are assigned to is now overdue:")
                ->line("**{$this->hazard->hazard_type}**")
                ->line("Site: {$siteName}")
                ->line("Risk Rating: " . strtoupper($this->hazard->risk_rating))
                ->line("Due Date: {$this->hazard->due_date->format('l, F j, Y')}")
                ->action('View Hazard', url("/hazards/{$this->hazard->id}"))
                ->line('Please take corrective action or update the status.');
        }
        
        return (new MailMessage)
            ->subject("Due Soon: Hazard - {$siteName}")
            ->line("A hazard you are assigned to is due in 2 days:")
            ->line("**{$this->hazard->hazard_type}**")
            ->line("Site: {$siteName}")
            ->line("Risk Rating: " . strtoupper($this->hazard->risk_rating))
            ->line("Due Date: {$this->hazard->due_date->format('l, F j, Y')}")
            ->action('View Hazard', url("/hazards/{$this->hazard->id}"));
    }

    public function toDatabase(object $notifiable): array
    {
        $siteName = $this->hazard->site?->name ?? 'Unknown Site';
        
        return [
            'title' => match($this->type) {
                'overdue_escalation' => "ESCALATION: Overdue Hazard",
                'overdue' => "OVERDUE: Hazard",
                default => "Due Soon: Hazard",
            },
            'message' => match($this->type) {
                'overdue_escalation' => "Overdue hazard at {$siteName} requires attention",
                'overdue' => "Hazard at {$siteName} is overdue",
                default => "Hazard at {$siteName} is due in 2 days",
            },
            'hazard_id' => $this->hazard->id,
            'site_id' => $this->hazard->site_id,
            'type' => $this->type === 'overdue_escalation' ? 'hazard_escalation' : 'hazard_' . $this->type,
            'action_url' => "/hazards/{$this->hazard->id}",
        ];
    }
}
