<?php

namespace App\Domain\Hr\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to an employee when a compensation review changes THEIR pay — pay
 * changes carry a statutory expectation of notice, and until now applying a
 * review updated the profile silently.
 */
class CompensationAppliedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private float $newAnnualSalary,
        private ?string $effectiveDate,
        private ?float $changePercentage,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $annual = number_format($this->newAnnualSalary, 2);
        $effective = $this->effectiveDate
            ? \Illuminate\Support\Carbon::parse($this->effectiveDate)->format('l, F j, Y')
            : 'your next pay period';

        $mail = (new MailMessage)
            ->subject('Your pay has been updated')
            ->greeting("Hello {$notifiable->name},")
            ->line("A compensation review affecting your pay has been applied. Your new annual salary is **NZD {$annual}**, effective {$effective}.");

        if ($this->changePercentage !== null) {
            $pct = ($this->changePercentage >= 0 ? '+' : '').round($this->changePercentage, 2).'%';
            $mail->line("**Change:** {$pct}");
        }

        return $mail
            ->line('Your full compensation history is available to HR, and your payslips will reflect the change from the effective date.')
            ->action('View My Pay', url('/my/payslips'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'compensation_applied',
            'new_annual_salary' => $this->newAnnualSalary,
            'effective_date' => $this->effectiveDate,
            'change_percentage' => $this->changePercentage,
            'action_url' => '/my/payslips',
        ];
    }
}
