<?php

namespace App\Notifications\Fleet;

use App\Models\Asset;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * A vehicle/asset compliance date needs attention — WOF or registration
 * expiring (or expired), or scheduled maintenance overdue. One class,
 * parameterised by $kind, sent to fleet managers at the same thresholds
 * FleetAutoAlertJob emits its wof_expiring / registration_expiring /
 * maintenance_overdue signals.
 */
class FleetComplianceDueNotification extends Notification
{
    use Queueable;

    public const KINDS = ['wof', 'rego', 'maintenance'];

    public function __construct(
        public Asset $asset,
        public string $kind,          // wof | rego | maintenance
        public ?Carbon $dueAt,
        public string $severity,      // matches the signal's severity_hint
        public ?int $daysRemaining = null, // null = already expired/overdue
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $assetName = $this->asset->name ?? 'Vehicle';
        $dueLabel = optional($this->dueAt)->format('d M Y') ?? 'unknown';

        return (new MailMessage)
            ->subject($this->subjectLine($assetName))
            ->greeting('Kia ora ' . ($notifiable->name ?? 'there') . ',')
            ->line($this->headline($assetName, $dueLabel))
            ->action('View Vehicle', url('/fleet-assets/vehicles/' . $this->asset->id))
            ->line('Please arrange the required work so the vehicle stays compliant and on the road.');
    }

    public function toArray(object $notifiable): array
    {
        $assetName = $this->asset->name ?? 'Vehicle';
        $dueLabel = optional($this->dueAt)->format('d M Y') ?? 'unknown';

        return [
            'title' => $this->title(),
            'message' => $this->headlinePlain($assetName, $dueLabel),
            'module' => 'fleet',
            'kind' => $this->kind,
            'asset_id' => $this->asset->id,
            'due_at' => $this->dueAt?->toISOString(),
            'severity' => $this->severity,
            'days_remaining' => $this->daysRemaining,
        ];
    }

    private function title(): string
    {
        return match ($this->kind) {
            'wof' => $this->isOverdue() ? 'WOF Expired' : 'WOF Expiring',
            'rego' => $this->isOverdue() ? 'Registration Expired' : 'Registration Expiring',
            default => 'Maintenance Overdue',
        };
    }

    private function subjectLine(string $assetName): string
    {
        return $this->title() . ': ' . $assetName;
    }

    private function headline(string $assetName, string $dueLabel): string
    {
        return '**' . $assetName . '** — ' . $this->describe($dueLabel);
    }

    private function headlinePlain(string $assetName, string $dueLabel): string
    {
        return $assetName . ' — ' . $this->describe($dueLabel);
    }

    private function describe(string $dueLabel): string
    {
        $noun = match ($this->kind) {
            'wof' => 'WOF',
            'rego' => 'registration',
            default => 'scheduled maintenance',
        };

        if ($this->kind === 'maintenance' || $this->isOverdue()) {
            return "{$noun} was due on {$dueLabel} and is now overdue.";
        }

        $days = $this->daysRemaining === 1 ? '1 day' : "{$this->daysRemaining} days";

        return "{$noun} expires on {$dueLabel} ({$days} remaining).";
    }

    private function isOverdue(): bool
    {
        return $this->daysRemaining === null || $this->daysRemaining < 0;
    }
}
