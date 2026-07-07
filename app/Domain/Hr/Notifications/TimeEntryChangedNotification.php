<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrTimeEntry;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the entry's owner when someone ELSE touches their time record —
 * clocked on their behalf, amended, corrected, or voided. Time entries feed
 * pay, so silent third-party changes are never OK.
 */
class TimeEntryChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $action  created|amended|corrected|voided
     */
    public function __construct(
        private HrTimeEntry $entry,
        private User $actor,
        private string $action,
        private ?string $reason = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $date = $this->entry->entry_date
            ? \Illuminate\Support\Carbon::parse($this->entry->entry_date)->format('l, F j, Y')
            : 'an unknown date';

        $mail = (new MailMessage)
            ->subject("Your time entry was {$this->actionLabel()}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->actor->name} {$this->actionLabel()} your time entry for {$date}.");

        if ($this->reason) {
            $mail->line("**Reason:** {$this->reason}");
        }

        return $mail
            ->line('The full change history is available on the entry.')
            ->action('View My Time', url('/hr/my/time'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'time_entry_changed',
            'entry_id' => $this->entry->id,
            'entry_date' => $this->entry->entry_date,
            'action' => $this->action,
            'actor_name' => $this->actor->name,
            'reason' => $this->reason,
            'action_url' => '/hr/my/time',
        ];
    }

    private function actionLabel(): string
    {
        return match ($this->action) {
            'created' => 'created on your behalf',
            'amended' => 'amended',
            'corrected' => 'corrected',
            'voided' => 'voided',
            default => $this->action,
        };
    }
}
