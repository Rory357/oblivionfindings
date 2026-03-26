<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CarePlanReviewDueNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $clientName,
        public string $reviewDate,
        public ?int $carePlanId = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Care Plan Review Due: {$this->clientName}")
            ->greeting('Hello ' . ($notifiable->name ?? 'there') . ',')
            ->line("A care plan review is due for **{$this->clientName}** on **{$this->reviewDate}**.")
            ->line('Please ensure the review is completed before the due date to maintain compliance.')
            ->action('View Care Plans', url('/operations/care-plans'))
            ->line('Thank you for staying on top of reviews.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Care Plan Review Due',
            'message' => "Care plan review for {$this->clientName} is due on {$this->reviewDate}.",
            'client_name' => $this->clientName,
            'review_date' => $this->reviewDate,
            'care_plan_id' => $this->carePlanId,
        ];
    }
}
