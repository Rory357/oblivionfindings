<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrAnnouncement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class AnnouncementPublishedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly HrAnnouncement $announcement,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'announcement_published',
            'announcement_id' => $this->announcement->id,
            'title' => $this->announcement->title,
            'priority' => $this->announcement->priority,
            'requires_acknowledgement' => (bool) $this->announcement->requires_acknowledgement,
            'action_url' => "/hr/announcements/{$this->announcement->id}",
        ];
    }
}
