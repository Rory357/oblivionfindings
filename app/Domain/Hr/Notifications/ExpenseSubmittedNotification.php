<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrExpenseClaim;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ExpenseSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrExpenseClaim $claim
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $employeeName = $this->claim->user?->name ?? 'An employee';

        return (new MailMessage)
            ->subject("Expense Claim Submitted: {$this->claim->claim_number}")
            ->greeting('Hello,')
            ->line("An expense claim requires your approval:")
            ->line("**Employee:** {$employeeName}")
            ->line("**Claim:** {$this->claim->claim_number} - {$this->claim->title}")
            ->line("**Amount:** \${$this->claim->total_amount} {$this->claim->currency}")
            ->action('Review Claim', url("/hr/expenses/{$this->claim->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'         => 'expense_submitted',
            'claim_id'     => $this->claim->id,
            'claim_number' => $this->claim->claim_number,
            'title'        => $this->claim->title,
            'amount'       => (float) $this->claim->total_amount,
            'user_name'    => $this->claim->user?->name,
            'action_url'   => "/hr/expenses/{$this->claim->id}",
        ];
    }
}
