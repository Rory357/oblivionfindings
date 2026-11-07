<?php

namespace App\Notifications;

use App\Models\HsEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * O3 (Stephan, 6 Nov 2026: "Daily reminder + task"): a safety review is WorkSafe-notifiable and WorkSafe hasn't been
 * told. It names the review and its Site only, never the person: the review page shows them to people who may see them.
 */
class HsWorksafeNotToldNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly HsEvent $event) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Tell WorkSafe: safety review {$this->event->reference_number}")
            ->line($this->sentence())
            ->line('The law says to tell WorkSafe as soon as possible. Once you have, record it on the safety review.')
            ->action('Open the safety review', url($this->link()));
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Tell WorkSafe',
            'message' => $this->sentence(),
            'hs_event_id' => $this->event->id,
            'site_id' => $this->event->site_id,
            'type' => 'hs_worksafe_not_told',
            'action_url' => $this->link(),
        ];
    }

    private function sentence(): string
    {
        $site = $this->event->site?->name;

        return "Safety review {$this->event->reference_number}".($site ? " at {$site}" : '')
            .' is a notifiable event and WorkSafe hasn’t been told yet.';
    }

    private function link(): string
    {
        return "/health-safety/events/{$this->event->id}?action=worksafe-notify";
    }
}
