<?php

namespace App\Domain\Hr\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveBalanceAdjustedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string $leaveType,
        private int $year,
        private float $hoursDelta,
        private float $balanceAfter,
        private ?string $reason,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $typeLabel = ucfirst(str_replace('_', ' ', $this->leaveType));
        $deltaLabel = ($this->hoursDelta >= 0 ? '+' : '').$this->hoursDelta.'h';

        $mail = (new MailMessage)
            ->subject("Your {$typeLabel} leave balance was adjusted")
            ->greeting("Hello {$notifiable->name},")
            ->line("An adjustment of **{$deltaLabel}** was applied to your {$typeLabel} balance for {$this->year}.")
            ->line("**New balance:** {$this->balanceAfter}h");

        if ($this->reason) {
            $mail->line("**Reason:** {$this->reason}");
        }

        return $mail->action('View My Leave', url('/hr/my/leave'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'leave_balance_adjusted',
            'leave_type' => $this->leaveType,
            'year' => $this->year,
            'hours_delta' => $this->hoursDelta,
            'balance_after' => $this->balanceAfter,
            'reason' => $this->reason,
            'action_url' => '/hr/my/leave',
        ];
    }
}
