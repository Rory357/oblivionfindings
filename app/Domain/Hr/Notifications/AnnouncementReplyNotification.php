<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrAnnouncement;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class AnnouncementReplyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrAnnouncement $announcement,
        private User $replier,
        private string $body,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'announcement_reply',
            'announcement_id' => $this->announcement->id,
            'announcement_title' => $this->announcement->title,
            'replier_name' => $this->replier->name,
            'message' => Str::limit($this->body, 140),
            'action_url' => "/hr/announcements/{$this->announcement->id}",
        ];
    }
}
