<?php

namespace App\Notifications\HealthSafety;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Minimum notice content. This envelope identifies retained delivery evidence; it is not a read grant. */
final class WorkerParticipationMeetingNotice extends Notification
{
    public const KIND = 'worker_participation_meeting_delivery';
    public const SCHEMA_VERSION = 1;

    public function __construct(private readonly array $envelope) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toArray(object $notifiable): array
    {
        $attendee = $this->envelope['audience'] === 'attendee';

        return [...$this->envelope, 'kind' => self::KIND, 'schema_version' => self::SCHEMA_VERSION,
            'title' => 'H&S meeting update',
            'body' => $attendee ? 'Open your current meeting information.' : 'An H&S meeting update has been recorded for your Site.',
            'url' => $attendee ? '/my-day/safety/meetings/'.$this->envelope['meeting_id'] : null];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $payload = $this->toArray($notifiable);
        $mail = (new MailMessage)->subject($payload['title'])->line($payload['body']);
        if ($payload['url'] !== null) { $mail->action('Open meeting', url($payload['url'])); }

        return $mail;
    }
}
