<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrPayslip;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PayslipAvailableNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrPayslip $payslip,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your payslip is ready')
            ->greeting("Hello {$notifiable->name},")
            ->line("Your payslip for the pay period {$this->periodLabel()} is now available to view.")
            ->action('View my payslip', url("/my/payslips/{$this->payslip->id}"))
            ->line('This is an automated notice — no reply is needed.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payslip_available',
            'payslip_id' => $this->payslip->id,
            'period_start' => optional($this->payslip->pay_period_start)->toDateString(),
            'period_end' => optional($this->payslip->pay_period_end)->toDateString(),
            'action_url' => "/my/payslips/{$this->payslip->id}",
        ];
    }

    /**
     * Human "01 Jul 2026 – 14 Jul 2026" period label for the email body.
     */
    private function periodLabel(): string
    {
        $start = optional($this->payslip->pay_period_start)->format('d M Y');
        $end = optional($this->payslip->pay_period_end)->format('d M Y');

        return trim(($start ?? '').' – '.($end ?? ''), ' –');
    }
}
