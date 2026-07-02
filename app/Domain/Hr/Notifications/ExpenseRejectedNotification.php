<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrExpenseClaim;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ExpenseRejectedNotification extends Notification implements ShouldQueue
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
        $reviewer = $this->claim->approver?->name ?? 'Your manager';

        return (new MailMessage)
            ->subject("Expense Claim Rejected: {$this->claim->claim_number}")
            ->greeting("Hello {$notifiable->name},")
            ->line('Your expense claim has been rejected.')
            ->line("**Claim:** {$this->claim->claim_number} - {$this->claim->title}")
            ->line("**Amount:** \${$this->claim->total_amount} {$this->claim->currency}")
            ->line("**Rejected by:** {$reviewer}")
            ->line('**Reason:** '.($this->claim->rejection_reason ?: 'No reason provided'))
            ->action('View Claim', url('/hr/my/expenses'))
            ->line('You can amend and resubmit the claim, or contact your manager if you have questions.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'expense_rejected',
            'claim_id' => $this->claim->id,
            'claim_number' => $this->claim->claim_number,
            'title' => $this->claim->title,
            'amount' => (float) $this->claim->total_amount,
            'status' => 'rejected',
            'rejection_reason' => $this->claim->rejection_reason,
            'action_url' => '/hr/my/expenses',
        ];
    }
}
