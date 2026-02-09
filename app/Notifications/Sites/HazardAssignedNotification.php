<?php

namespace App\Notifications\Sites;

use App\Models\SiteHazard;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class HazardAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private SiteHazard $hazard
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Hazard Assigned: ' . $this->hazard->reference_number,
            'message' => "A {$this->hazard->risk_rating} risk hazard has been assigned to you at {$this->hazard->site->name}",
            'hazard_id' => $this->hazard->id,
            'site_id' => $this->hazard->site_id,
            'risk_rating' => $this->hazard->risk_rating,
            'url' => "/hazards/{$this->hazard->id}",
        ];
    }
}
