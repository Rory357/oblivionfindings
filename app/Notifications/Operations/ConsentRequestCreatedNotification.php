<?php

namespace App\Notifications\Operations;

use App\Models\ConsentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the family-portal recipient when staff creates a new consent
 * request. Mail + in-portal database notification.
 */
class ConsentRequestCreatedNotification extends Notification
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
        $requesterName = $request->requestedBy?->name ?? 'The care team';

        return (new MailMessage)
            ->subject("Consent review requested for {$client?->full_name}")
            ->greeting("Kia ora {$notifiable->name},")
            ->line("{$requesterName} has asked you to review a consent decision for {$client?->full_name}.")
            ->line("Consent type: {$consentTypeName}")
            ->line("Purpose: {$request->purpose}")
            ->when($request->retention_period_days, fn ($m) => $m->line("Data retention: {$request->retention_period_days} days"))
            ->line('Please review and respond by '.$request->expires_at->format('d F Y').'.')
            ->action('Review request', url("/portal/clients/{$request->client_id}/consent-requests/{$request->id}"))
            ->line('You can approve, decline, or contact the care team for more information.')
            ->salutation('Ngā mihi, Oblivion Findings');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'consent_request_id' => $this->consentRequest->id,
            'client_id' => $this->consentRequest->client_id,
            'client_name' => $this->consentRequest->client?->full_name,
            'consent_type' => $this->consentRequest->consentType?->name,
            'expires_at' => $this->consentRequest->expires_at?->toIso8601String(),
            'action_url' => "/portal/clients/{$this->consentRequest->client_id}/consent-requests/{$this->consentRequest->id}",
        ];
    }
}
