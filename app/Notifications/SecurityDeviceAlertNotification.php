<?php

namespace App\Notifications;

use App\Domain\SecurityDevices\Alerts\DeviceAlertEngine;
use App\Domain\SecurityDevices\Screens\Alerts\AlertsPresenter;
use App\Domain\SecurityDevices\Screens\Http\CareAlertController;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A Security & devices alert needs someone (S&D Phase D): it's still open after an hour, the
 * person who acknowledged it asked to be reminded, or someone was asked to look after it.
 */
class SecurityDeviceAlertNotification extends Notification
{
    use Queueable;

    /** @param  list<string>  $channels  database and/or mail */
    public function __construct(
        public readonly int $alertId,
        public readonly string $severity,
        public readonly string $title,
        public readonly string $line,
        public readonly array $channels,
        /** The alert's kind, so a care or safety alert opens the right view for the person told. */
        public readonly ?string $kind = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $resolved = [];
        if (in_array('database', $this->channels, true)) {
            $resolved[] = 'database';
        }
        if (in_array('mail', $this->channels, true) && ! empty($notifiable->email)) {
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
        return ['title' => $this->title, 'body' => $this->line, 'data' => ['url' => $this->urlFor($notifiable)]];
    }

    public function url(): string
    {
        return '/security-devices/alerts?alert='.$this->alertId;
    }

    /**
     * Safety fix round (7 Nov 2026, Stephan: "Open just that alert"): someone who can't open the
     * Alerts page but may attend to care and safety alerts (support workers, team leads) gets a link
     * to that one alert. Everyone else opens it on the Alerts page, as before.
     */
    public function urlFor(object $notifiable): string
    {
        $canOpenAlerts = $notifiable instanceof User
            && AlertsPresenter::canOpen($notifiable);
        if (! $canOpenAlerts
            && $this->kind !== null
            && in_array($this->kind, DeviceAlertEngine::safetyCareKinds(), true)
            && $notifiable instanceof User
            && $notifiable->canDo(CareAlertController::KEY)) {
            return '/security-devices/care-alerts/'.$this->alertId;
        }

        return $this->url();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(($this->severity === 'critical' ? 'Needs someone: ' : 'Alert: ').$this->title)
            ->greeting('Kia ora '.($notifiable->name ?? 'team').',')
            ->line($this->title.'.')
            ->line($this->line.'.')
            ->action('Open the alert', url($this->urlFor($notifiable)));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'security_device_alert',
            'severity' => $this->severity,
            'title' => $this->title,
            'body' => $this->line,
            'message' => $this->line,
            'url' => $this->urlFor($notifiable),
            'action_url' => $this->urlFor($notifiable),
            'context' => ['alert_id' => $this->alertId],
        ];
    }
}
