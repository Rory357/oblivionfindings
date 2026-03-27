<?php

namespace App\Domain\Finance\Notifications;

use App\Domain\Finance\Models\FinBankAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BankReconciliationReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public FinBankAccount $bankAccount,
        public ?string $lastReconciledDate = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lastReconciled = $this->lastReconciledDate
            ? "Last reconciled on **{$this->lastReconciledDate}**."
            : 'This account has **never been reconciled**.';

        return (new MailMessage)
            ->subject("Bank reconciliation reminder for {$this->bankAccount->name}")
            ->line("A bank reconciliation is due for **{$this->bankAccount->name}** ({$this->bankAccount->bank_name}).")
            ->line($lastReconciled)
            ->action('Start Reconciliation', url('/finance/bank-reconciliation/create'))
            ->line('Regular bank reconciliation helps ensure your records are accurate.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'bank_reconciliation_reminder',
            'bank_account_id' => $this->bankAccount->id,
            'bank_account_name' => $this->bankAccount->name,
            'last_reconciled_date' => $this->lastReconciledDate,
        ];
    }
}
