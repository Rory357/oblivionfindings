<?php

namespace App\Notifications;

use App\Models\MedicationAlert;
use App\Notifications\Channels\PushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A medication alert as one person receives it (eMAR P11 B2). Every alert in
 * Medication Settings › Alerts & access is sent through this notification,
 * built from the alert log's record, so what people see and what the log
 * keeps never disagree.
 *
 * Channels (B2 chunk 2): in the bell (database), by email to the person's
 * work email, and by push to the phones and browsers they've allowed. The
 * caller passes only the channels this person can be reached on.
 *
 * Privacy (v5 Delivery › "Keep client names and medicines out of email and
 * push", on by default): email and push then say what happened and link to
 * the app — the subject, the push title and the body carry no client name or
 * medicine. The bell always shows the full message: it's inside the app.
 *
 * Follow up (B2 chunk 3): a re-alert says "Reminder:" and an escalation
 * "Escalated:" before the title. A Follow up alert that counts as attended
 * when someone acknowledges it asks for an acknowledgement in the bell
 * (`ack_required`, the inbox's acknowledge action).
 */
class MedicationAlertNotification extends Notification
{
    use Queueable;

    public const FIRST = 'first';

    public const REALERT = 'realert';

    public const ESCALATION = 'escalation';

    /** @param list<string> $channels inapp, email and/or push: how this person is told. */
    public function __construct(
        public readonly MedicationAlert $alert,
        public readonly array $channels = ['inapp'],
        public readonly bool $private = true,
        public readonly string $kind = self::FIRST,
        public readonly bool $ackRequired = false,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return array_values(array_filter([
            in_array('inapp', $this->channels, true) ? 'database' : null,
            in_array('email', $this->channels, true) ? 'mail' : null,
            in_array('push', $this->channels, true) ? PushChannel::class : null,
        ]));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'medication_alert',
            'alert_key' => $this->alert->type,
            'medication_alert_id' => $this->alert->id,
            'module' => 'medication',
            'title' => $this->title(),
            'message' => $this->alert->message,
            'severity' => $this->alert->severity,
            'action_url' => $this->alert->action_url,
            'controlled' => $this->alert->controlled,
            'follow_up_step' => $this->kind,
            'ack_required' => $this->ackRequired,
            ...($this->alert->subject ?? []),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->line($this->body())
            ->action('Open in Oblivion Care', url($this->alert->action_url ?: '/emar'))
            ->line(self::MAIL_FOOTER);
    }

    /** @return array{title: string, body: string, data: array<string, string>} */
    public function toPush(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'body' => $this->body(),
            'data' => [
                'url' => (string) ($this->alert->action_url ?: '/emar'),
                'medication_alert_id' => (string) $this->alert->id,
            ],
        ];
    }

    public const MAIL_FOOTER = 'Open Oblivion Care to see the details and respond.';

    /**
     * The email subject: what happened; with privacy off, who or what it's
     * about too — the message up to its first " — " (v5: "Overdue dose —
     * Aroha N."), skipping a part that only repeats the title.
     */
    public function subject(): string
    {
        $title = $this->title();
        if ($this->private) {
            return $title;
        }
        $parts = array_values(array_filter(
            array_map('trim', explode(' — ', (string) $this->alert->message)),
            fn (string $part): bool => $part !== '' && $part !== (string) $this->alert->title,
        ));

        return $parts === [] ? $title : Str::limit($title.' — '.$parts[0], 150);
    }

    /** The title, with "Reminder:" or "Escalated:" for a follow-up step. */
    public function title(): string
    {
        $title = (string) $this->alert->title;

        return match ($this->kind) {
            self::REALERT => 'Reminder: '.$title,
            self::ESCALATION => 'Escalated: '.$title,
            default => $title,
        };
    }

    /** Email and push text: the short message with privacy on, else the full one. */
    public function body(): string
    {
        return $this->private
            ? (string) ($this->alert->short_message ?: $this->alert->title)
            : (string) $this->alert->message;
    }
}
