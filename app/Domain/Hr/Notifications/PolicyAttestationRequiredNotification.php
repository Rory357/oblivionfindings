<?php

namespace App\Domain\Hr\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PolicyAttestationRequiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{policy_id:int, policy_version_id:?int, policy_title:string, version_number:?int, kind:string}  $payload
     *                                                                                                                        kind: 'published' (sent when the version goes live) | 'reminder' (overdue nudge)
     */
    public function __construct(
        private array $payload
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->payload['policy_title'] ?? 'a policy';
        $isReminder = ($this->payload['kind'] ?? 'published') === 'reminder';

        $mail = (new MailMessage)
            ->subject($isReminder
                ? "Reminder — policy attestation outstanding: {$title}"
                : "Policy attestation required: {$title}")
            ->greeting("Hello {$notifiable->name},");

        if ($isReminder) {
            $mail->line('You still have an outstanding policy attestation:');
        } else {
            $mail->line('A policy has been published that requires your attestation:');
        }

        $mail->line("**{$title}**");

        if (! empty($this->payload['version_number'])) {
            $mail->line('Version: v'.$this->payload['version_number']);
        }

        return $mail
            ->action('Review & attest', url('/hr/my/policies'))
            ->line('Please read the policy and record your attestation.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'policy_attestation_required',
            'message' => (($this->payload['kind'] ?? 'published') === 'reminder' ? 'Attestation outstanding: ' : 'Attestation required: ')
                .($this->payload['policy_title'] ?? 'Policy'),
            'policy_id' => $this->payload['policy_id'] ?? null,
            'policy_version_id' => $this->payload['policy_version_id'] ?? null,
            'policy_title' => $this->payload['policy_title'] ?? null,
            'kind' => $this->payload['kind'] ?? 'published',
            'action_url' => '/hr/my/policies',
        ];
    }
}
