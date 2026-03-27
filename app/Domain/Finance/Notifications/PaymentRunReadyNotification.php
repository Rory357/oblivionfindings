<?php

namespace App\Domain\Finance\Notifications;

use App\Domain\Finance\Models\FinPaymentRun;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentRunReadyNotification extends Notification
{
    use Queueable;

    public function __construct(
        public FinPaymentRun $paymentRun,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $formattedTotal = number_format((float) $this->paymentRun->total_amount, 2);

        return (new MailMessage)
            ->subject("Payment Run {$this->paymentRun->run_number} is ready for processing")
            ->line("Payment Run **{$this->paymentRun->run_number}** has been approved and is ready to be processed.")
            ->line('')
            ->line("**Total Amount:** \${$formattedTotal} NZD")
            ->line("**Number of Payments:** {$this->paymentRun->item_count}")
            ->line("**Payment Date:** {$this->paymentRun->payment_date->format('j F Y')}")
            ->action('View Payment Run', url("/finance/payment-runs/{$this->paymentRun->id}"))
            ->line('Please review and process the payment run at your earliest convenience.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payment_run_ready',
            'payment_run_id' => $this->paymentRun->id,
            'run_number' => $this->paymentRun->run_number,
            'total_amount' => (float) $this->paymentRun->total_amount,
            'item_count' => $this->paymentRun->item_count,
        ];
    }
}
