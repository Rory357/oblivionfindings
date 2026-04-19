<?php

namespace App\Notifications\Operations;

use App\Models\ConsentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Friendly reminder to the family-portal recipient that their pending
 * consent review is approaching the expires_at deadline. Mail + database.
 */
class ConsentRequestReminderNotification extends Notification
{
    use Queueable;

    public function __construct(public ConsentRequest $consentRequest) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->consentRequest;
        $client = $request->client;
        $consentTypeName = $request->consentType?->name ?? 'a care decision';
        $daysRemaining = max(1, (int) ceil($this->hoursRemaining() / 24));
        $clientName = $client?->full_name ?? 'your whānau member';

        return (new MailMessage)
            ->subject("Reminder — consent review for {$clientName} closes in {$daysRemaining} days")
            ->greeting("Kia ora {$notifiable->name},")
            ->line("This is a friendly reminder that a consent review for {$clientName} is still waiting on your response.")
            ->line("Consent type: {$consentTypeName}")
            ->line("Purpose: {$request->purpose}")
            ->line('Please review and respond by '.$request->expires_at->format('d F Y')." ({$daysRemaining} day(s) remaining).")
            ->action('Review request', $this->actionUrl())
            ->line('If you have any questions, please contact the care team before the deadline.')
            ->salutation('Ngā mihi, Oblivion Findings');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'consent_request_id' => $this->consentRequest->id,
            'hours_remaining' => $this->hoursRemaining(),
            'action_url' => $this->actionUrl(),
        ];
    }

    private function hoursRemaining(): int
    {
        $expiresAt = $this->consentRequest->expires_at;

        if ($expiresAt === null) {
            return 0;
        }

        return max(0, (int) now()->diffInHours($expiresAt, false));
    }

    private function actionUrl(): string
    {
        return "/portal/clients/{$this->consentRequest->client_id}/consent-requests/{$this->consentRequest->id}";
    }
}
