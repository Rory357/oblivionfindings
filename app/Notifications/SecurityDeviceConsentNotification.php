<?php

namespace App\Notifications;

use App\Domain\SecurityDevices\Care\DevicePersonLinks;
use App\Models\Client;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Gate;

/**
 * Safety fix round (7 Nov 2026, F2): a resident's consent to a monitoring sensor is ending soon, or
 * has ended and the sensor's care alerts have stopped. To the house manager, in the app and by
 * email. The resident is named only to someone who may see their record.
 */
class SecurityDeviceConsentNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $linkId,
        public readonly int $clientId,
        public readonly int $deviceId,
        public readonly string $deviceName,
        /** ending | ended */
        public readonly string $what,
        /** Y-m-d, NZ date, for "ending". */
        public readonly ?string $endsOn = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ! empty($notifiable->email) ? ['database', 'mail'] : ['database'];
    }

    public function title(object $notifiable): string
    {
        $who = $this->who($notifiable);

        return $this->what === 'ended'
            ? "Care alerts stopped: {$who}’s consent ended"
            : "{$who}’s sensor consent ends ".$this->when();
    }

    public function line(object $notifiable): string
    {
        return $this->what === 'ended'
            ? "{$this->deviceName} is no longer linked to their care plan, so it sends no care alerts. Record new consent with them (or the person who decides for them), then link it again."
            : "When it ends, {$this->deviceName} comes off their care plan and its care alerts stop. Review it with them before then.";
    }

    public function url(): string
    {
        return '/security-devices/devices/'.$this->deviceId;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title($notifiable))
            ->greeting('Kia ora '.($notifiable->name ?? 'team').',')
            ->line($this->title($notifiable).'.')
            ->line($this->line($notifiable))
            ->action('Open the sensor', url($this->url()));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'security_device_consent',
            'severity' => $this->what === 'ended' ? 'critical' : 'warning',
            'title' => $this->title($notifiable),
            'body' => $this->line($notifiable),
            'message' => $this->line($notifiable),
            'url' => $this->url(),
            'action_url' => $this->url(),
            'context' => ['link_id' => $this->linkId, 'device_id' => $this->deviceId],
        ];
    }

    private function who(object $notifiable): string
    {
        $client = Client::query()->find($this->clientId);

        return $client instanceof Client && Gate::forUser($notifiable)->allows('view', $client) ? DevicePersonLinks::name($client) : 'A resident';
    }

    private function when(): string
    {
        if ($this->endsOn === null) {
            return 'soon';
        }
        $zone = (string) config('app.worker_timezone', 'Pacific/Auckland');

        return 'on '.CarbonImmutable::parse($this->endsOn, $zone)->format('D j M Y');
    }
}
