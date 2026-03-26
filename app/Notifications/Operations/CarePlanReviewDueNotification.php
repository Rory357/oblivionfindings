<?php

namespace App\Notifications\Operations;

use App\Models\CarePlan;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CarePlanReviewDueNotification extends Notification
{
    use Queueable;

    public function __construct(
        public CarePlan $carePlan
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Care Plan Review Due: {$this->carePlan->title}")
            ->greeting("Kia ora {$notifiable->name},")
            ->line(sprintf(
                'The care plan "%s" for %s is due for review by %s.',
                $this->carePlan->title,
                $this->carePlan->client?->full_name ?? 'a client',
                $this->carePlan->next_review_at?->format('d M Y') ?? 'soon'
            ))
            ->action('Review Care Plan', url("/operations/care-plans/{$this->carePlan->id}"))
            ->line('Please complete the review as soon as possible.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'care_plan_id' => $this->carePlan->id,
            'title' => $this->carePlan->title,
            'client_id' => $this->carePlan->client_id,
            'review_due' => $this->carePlan->next_review_at?->toDateString(),
        ];
    }
}
