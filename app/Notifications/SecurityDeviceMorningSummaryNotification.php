<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\UserNotificationPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The 6:00 am Security & devices morning summary (pg-sdr-15g, 13d, 15p): device and hub alerts
 * held during quiet hours, low batteries, and overnight hub software update results, scoped to
 * the recipient's Sites.
 */
class SecurityDeviceMorningSummaryNotification extends Notification
{
    use Queueable;

    public const string PREFERENCE_KEY = 'security_devices.morning_summary';

    /**
     * @param  list<string>  $lines  plain-English items from the night, for this recipient's Sites
     * @param  list<string>  $channels  database, mail, push (from Setup › Alerts & checks)
     * @param  list<int>  $alertIds  held DeviceAlert ids included in this summary
     */
    public function __construct(
        public readonly string $day,
        public readonly string $title,
        public readonly array $lines,
        public readonly array $channels,
        public readonly array $alertIds = [],
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $resolved = [];
        if (in_array('database', $this->channels, true)) {
            $resolved[] = 'database';
        }
        if (in_array('mail', $this->channels, true) && $this->allowsEmail($notifiable)) {
            $resolved[] = 'mail';
        }
        if (in_array('push', $this->channels, true)) {
            $resolved[] = Channels\PushChannel::class;
        }

        return $resolved;
    }

    /** @return array{title: string, body: string, data: array<string, string>} */
    public function toPush(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->summaryLine(),
            'data' => ['url' => $this->url()],
        ];
    }

    public function url(): string
    {
        return '/security-devices/alerts';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Morning summary: '.$this->title)
            ->greeting('Kia ora '.($notifiable->name ?? 'team').',')
            ->line('Here is what happened overnight across your Sites:');

        foreach ($this->lines as $line) {
            $mail->line('• '.$line);
        }

        return $mail->action('Open Alerts', url($this->url()));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $body = $this->summaryLine();

        return [
            'type' => 'security_device_morning_summary',
            'severity' => 'info',
            'title' => $this->title,
            'body' => $body,
            'message' => $body,
            'lines' => $this->lines,
            'url' => $this->url(),
            'action_url' => $this->url(),
            'context' => [
                'day' => $this->day,
                'alert_ids' => $this->alertIds,
            ],
        ];
    }

    private function summaryLine(): string
    {
        if (count($this->lines) <= 2) {
            return implode(' · ', $this->lines);
        }

        return implode(' · ', array_slice($this->lines, 0, 2)).' · and '.(count($this->lines) - 2).' more';
    }

    private function allowsEmail(object $notifiable): bool
    {
        if (empty($notifiable->email)) {
            return false;
        }
        if (($notifiable->email_digest_frequency ?? 'instant') === 'off') {
            return false;
        }
        if ($notifiable instanceof User && $notifiable->exists) {
            $blocked = UserNotificationPreference::query()
                ->where('user_id', $notifiable->id)
                ->whereIn('key', [self::PREFERENCE_KEY, 'security_devices.alerts'])
                ->get(['enabled', 'channel_email'])
                ->contains(fn (UserNotificationPreference $pref): bool => $pref->enabled === false || $pref->channel_email === false);
            if ($blocked) {
                return false;
            }
        }

        return true;
    }
}
