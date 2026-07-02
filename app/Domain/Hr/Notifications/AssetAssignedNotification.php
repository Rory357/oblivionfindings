<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrAssetAssignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an employee a company asset has just been assigned to them —
 * what it is, its condition at handover, and when it's due back.
 */
class AssetAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrAssetAssignment $assignment,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $asset = $this->assignment->asset;
        $assetName = $asset?->name ?? 'A company asset';
        $assetTag = $asset?->asset_tag;
        $condition = $this->assignment->condition_on_assign;
        $dueAt = $this->assignment->due_at?->format('l, F j, Y');

        $mail = (new MailMessage)
            ->subject('An asset has been assigned to you')
            ->greeting("Hello {$notifiable->name},")
            ->line("**{$assetName}**" . ($assetTag ? " ({$assetTag})" : '') . ' has been assigned to you.');

        if ($condition) {
            $mail->line('**Condition at handover:** ' . ucfirst($condition));
        }

        if ($dueAt) {
            $mail->line("**Return by:** {$dueAt}");
        }

        return $mail
            ->action('View in My HR', url('/hr/my'))
            ->line('If anything about this assignment looks wrong, contact your manager or HR.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $asset = $this->assignment->asset;

        return [
            'type' => 'hr_asset_assigned',
            'title' => 'Asset assigned to you',
            'message' => trim(
                ($asset?->name ?? 'A company asset')
                . ($asset?->asset_tag ? " ({$asset->asset_tag})" : '')
                . ' has been assigned to you.'
            ),
            'asset_id' => $this->assignment->asset_id,
            'assignment_id' => $this->assignment->id,
            'condition_on_assign' => $this->assignment->condition_on_assign,
            'due_at' => $this->assignment->due_at?->toDateString(),
            'action_url' => '/hr/my',
        ];
    }
}
