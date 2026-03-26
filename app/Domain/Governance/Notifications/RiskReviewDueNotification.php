<?php

namespace App\Domain\Governance\Notifications;

use App\Domain\Governance\Models\RiskRegisterEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RiskReviewDueNotification extends Notification
{
    use Queueable;

    public function __construct(
        public RiskRegisterEntry $risk
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Risk Review Due: {$this->risk->risk_reference}")
            ->line("The following risk is due for review:")
            ->line("")
            ->line("**{$this->risk->title}**")
            ->line("Reference: {$this->risk->risk_reference}")
            ->line("Current Score: {$this->risk->residual_score}")
            ->line("Review Due: {$this->risk->next_review_date->format('j F Y')}")
            ->action('View Risk', url("/governance/risks/{$this->risk->id}"))
            ->line('Please review and update the risk assessment as needed.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'risk_review_due',
            'risk_id' => $this->risk->id,
            'risk_reference' => $this->risk->risk_reference,
            'title' => $this->risk->title,
            'due_date' => $this->risk->next_review_date->toDateString(),
        ];
    }
}
