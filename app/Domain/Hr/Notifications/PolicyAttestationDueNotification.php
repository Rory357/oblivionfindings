<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrPolicy;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PolicyAttestationDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrPolicy $policy,
        private ?Carbon $dueDate = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->policy->title;
        $formattedDueDate = $this->dueDate?->format('l, F j, Y') ?? 'as soon as possible';

        return (new MailMessage)
            ->subject("Policy Attestation Required: {$title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("You are required to review and attest to the following policy:")
            ->line("**Policy:** {$title}")
            ->line("**Category:** " . ucfirst($this->policy->category ?? 'General'))
            ->line("**Due by:** {$formattedDueDate}")
            ->action('Review & Attest', url("/hr/policies/{$this->policy->id}"))
            ->line('Please review the policy and confirm your attestation before the due date.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'         => 'policy_attestation_due',
            'policy_title' => $this->policy->title,
            'policy_id'    => $this->policy->id,
            'due_date'     => $this->dueDate?->toIso8601String(),
            'action_url'   => "/hr/policies/{$this->policy->id}",
        ];
    }
}
