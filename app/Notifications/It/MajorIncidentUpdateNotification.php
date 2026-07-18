<?php

namespace App\Notifications\It;

use App\Models\ItMajorIncident;
use App\Models\ItMajorIncidentUpdate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MajorIncidentUpdateNotification extends Notification
{
    use Queueable;

    public function __construct(public ItMajorIncident $majorIncident, public ItMajorIncidentUpdate $update) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Major incident update · '.$this->majorIncident->ticket->reference,
            'message' => $this->update->summary,
            'url' => "/it/major-incidents/{$this->majorIncident->id}/status",
            'severity' => $this->majorIncident->severity,
        ];
    }
}
