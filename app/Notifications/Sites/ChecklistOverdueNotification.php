<?php

namespace App\Notifications\Sites;

use App\Models\SiteChecklistRun;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ChecklistOverdueNotification extends Notification
{
    use Queueable;

    public function __construct(
        private SiteChecklistRun $run
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Overdue Checklist: ' . $this->run->template->name,
            'message' => "Checklist was due on {$this->run->scheduled_date->format('M j, Y')} at {$this->run->site->name}",
            'run_id' => $this->run->id,
            'site_id' => $this->run->site_id,
            'scheduled_date' => $this->run->scheduled_date->toDateString(),
            'url' => "/checklists/runs/{$this->run->id}",
        ];
    }
}
