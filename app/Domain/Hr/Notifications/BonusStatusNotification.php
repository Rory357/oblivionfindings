<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrBonusPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BonusStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $action  approved|cancelled
     */
    public function __construct(
        private HrBonusPayment $bonus,
        private string $action,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = number_format((float) $this->bonus->amount, 2);
        $currency = $this->bonus->currency ?: 'NZD';
        $typeLabel = ucfirst(str_replace('_', ' ', (string) $this->bonus->bonus_type));

        if ($this->action === 'approved') {
            return (new MailMessage)
                ->subject('Your bonus has been approved')
                ->greeting("Hello {$notifiable->name},")
                ->line("Your {$typeLabel} bonus of {$currency} {$amount} has been approved.")
                ->line('It will be included with the payroll for its payment date.')
                ->action('View My Pay', url('/my/payslips'));
        }

        return (new MailMessage)
            ->subject('A bonus for you was cancelled')
            ->greeting("Hello {$notifiable->name},")
            ->line("The {$typeLabel} bonus of {$currency} {$amount} previously raised for you has been cancelled.")
            ->line('If you were expecting this payment, please talk to your manager or HR.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'bonus_'.$this->action,
            'bonus_id' => $this->bonus->id,
            'bonus_type' => $this->bonus->bonus_type,
            'amount' => (float) $this->bonus->amount,
            'currency' => $this->bonus->currency ?: 'NZD',
            'action_url' => '/my/payslips',
        ];
    }
}
