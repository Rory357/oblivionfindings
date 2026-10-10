<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\PushChannel;
use Illuminate\Notifications\Notification;

/**
 * "Were you there?" — the named second person has 30 minutes to answer
 * before the dose is flagged, so the request also goes by push to any phone
 * or browser they have allowed (EA-139). No clinical details are copied into
 * the bell's enduring payload or the push text.
 */
class MedicationSecondPersonConfirmationNotification extends Notification
{
    private const TITLE = 'Were you there?';

    private const BODY = 'A dose names you as the second person. Answer in your own login within 30 minutes. Reset your witness PIN if you have forgotten it.';

    public function __construct(public int $followupId) {}

    public function via(object $notifiable): array
    {
        return $notifiable instanceof User
            && $notifiable->pushSubscriptions()->where('enabled', true)->exists()
            ? ['database', PushChannel::class]
            : ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'medication_second_person_confirmation',
            'title' => self::TITLE,
            'message' => self::BODY,
            'severity' => 'warning',
            'action_url' => $this->url(),
        ];
    }

    /** @return array{title: string, body: string, data: array<string, string>} */
    public function toPush(object $notifiable): array
    {
        return [
            'title' => self::TITLE,
            'body' => self::BODY,
            'data' => ['url' => $this->url()],
        ];
    }

    private function url(): string
    {
        return '/medication-followups?open='.$this->followupId;
    }
}
