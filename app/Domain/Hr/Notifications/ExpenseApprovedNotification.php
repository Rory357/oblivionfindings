<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrExpenseClaim;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ExpenseApprovedNotification extends Notification implements ShouldQueue
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
        $approver = $this->claim->approver?->name ?? 'Your manager';

        return (new MailMessage)
            ->subject("Expense Claim Approved: {$this->claim->claim_number}")
            ->greeting("Hello {$notifiable->name},")
            ->line('Your expense claim has been approved.')
            ->line("**Claim:** {$this->claim->claim_number} - {$this->claim->title}")
            ->line("**Amount:** \${$this->claim->total_amount} {$this->claim->currency}")
            ->line("**Approved by:** {$approver}")
            ->action('View Claim', url("/hr/expenses/{$this->claim->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'         => 'expense_approved',
            'claim_id'     => $this->claim->id,
            'claim_number' => $this->claim->claim_number,
            'title'        => $this->claim->title,
            'amount'       => (float) $this->claim->total_amount,
            'status'       => 'approved',
            'action_url'   => "/hr/expenses/{$this->claim->id}",
        ];
    }
}
