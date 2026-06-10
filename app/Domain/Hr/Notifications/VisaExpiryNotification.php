<?php

namespace App\Domain\Hr\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class VisaExpiryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{profile_id: int, employee_name: string, visa_type: string|null, expires_at: string, reminder_days: int}  $payload
     */
    public function __construct(
        private readonly array $payload,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $visa = $this->payload['visa_type'] ?: 'visa';

        return [
            'title' => 'Work visa expiring soon',
            'message' => "{$this->payload['employee_name']}'s {$visa} expires on {$this->payload['expires_at']} ({$this->payload['reminder_days']} days away). Confirm continued right to work.",
            'profile_id' => $this->payload['profile_id'],
            'visa_type' => $this->payload['visa_type'],
            'expires_at' => $this->payload['expires_at'],
            'reminder_days' => $this->payload['reminder_days'],
            'url' => "/hr/people/{$this->payload['profile_id']}",
        ];
    }
}
