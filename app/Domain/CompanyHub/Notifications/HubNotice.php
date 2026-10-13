<?php

namespace App\Domain\CompanyHub\Notifications;

use Illuminate\Notifications\Notification;

class HubNotice extends Notification
{
    public function __construct(private readonly string $url, private readonly bool $review = false) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        // Keep restricted page titles and body text out of retained notifications.
        return [
            'type' => 'company_hub',
            'title' => $this->review ? 'A hub page is ready for review' : 'New in Company Hub',
            'message' => $this->review ? 'A page you own has reached its review date.' : 'There is a new company story for you to read.',
            'url' => $this->url, 'action_url' => $this->url,
        ];
    }
}
