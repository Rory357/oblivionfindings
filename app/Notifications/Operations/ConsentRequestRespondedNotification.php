<?php

namespace App\Notifications\Operations;

use App\Models\ConsentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the requesting staff member when the recipient responds to a
 * consent request (approved or declined).
 */
class ConsentRequestRespondedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ConsentRequest $consentRequest,
        public string $outcome, // 'approved' | 'declined'
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->consentRequest;
        $client = $request->client;
        $recipientName = $request->recipient?->name ?? 'The recipient';
        $verb = $this->outcome === 'approved' ? 'approved' : 'declined';

        return (new MailMessage)
            ->subject("Consent {$verb}: {$client?->full_name}")
            ->greeting("Kia ora {$notifiable->name},")
            ->line("{$recipientName} has {$verb} the consent request for {$client?->full_name}.")
            ->line('Consent type: '.($request->consentType?->name ?? 'N/A'))
            ->when($request->response_notes, fn ($m) => $m->line("Notes: {$request->response_notes}"))
            ->action('View request', url("/operations/clients/{$request->client_id}/consent-requests/{$request->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'consent_request_id' => $this->consentRequest->id,
            'client_id' => $this->consentRequest->client_id,
            'client_name' => $this->consentRequest->client?->full_name,
            'outcome' => $this->outcome,
            'consent_type' => $this->consentRequest->consentType?->name,
            'resulting_consent_id' => $this->consentRequest->resulting_consent_id,
            'action_url' => "/operations/clients/{$this->consentRequest->client_id}/consent-requests/{$this->consentRequest->id}",
        ];
    }
}
