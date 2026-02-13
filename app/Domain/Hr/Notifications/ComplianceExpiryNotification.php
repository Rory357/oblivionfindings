<?php

namespace App\Domain\Hr\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ComplianceExpiryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private User $user,
        private array $requirement
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->requirement['name'] ?? 'Unknown Requirement';
        $expiresAt = $this->requirement['expires_at'] ?? null;
        $formattedDate = $expiresAt ? \Carbon\Carbon::parse($expiresAt)->format('l, F j, Y') : 'N/A';

        return (new MailMessage)
            ->subject("Compliance Item Expiring: {$name}")
            ->greeting("Hello {$this->user->name},")
            ->line("A compliance requirement is approaching its expiry date:")
            ->line("**{$name}**")
            ->line("Expires: {$formattedDate}")
            ->line('Please ensure this item is renewed or updated before the expiry date to maintain compliance.')
            ->action('View Compliance Status', url('/hr/compliance'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'             => 'compliance_expiry',
            'message'          => "Compliance item expiring: {$this->requirement['name']}",
            'requirement_code' => $this->requirement['requirement_code'] ?? null,
            'expires_at'       => $this->requirement['expires_at'] ?? null,
            'user_id'          => $this->user->id,
            'action_url'       => '/hr/compliance',
        ];
    }
}
