<?php

namespace App\Domain\Finance\Notifications;

use App\Domain\Finance\Models\FinGstReturn;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GstReturnDueNotification extends Notification
{
    use Queueable;

    public function __construct(
        public FinGstReturn $gstReturn,
        public string $dueDate,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $return = $this->gstReturn;
        $periodEnd = $return->period_end->format('d M Y');
        $gstPayable = (float) $return->gst_payable;
        $formattedAmount = number_format(abs($gstPayable), 2);
        $dueFormatted = Carbon::parse($this->dueDate)->format('d M Y');

        $amountLine = $gstPayable >= 0
            ? "**GST Payable:** NZD \${$formattedAmount}"
            : "**GST Refund Due:** NZD \${$formattedAmount}";

        return (new MailMessage)
            ->subject("GST Return Due - Period ending {$periodEnd}")
            ->line("A GST return is due for the period ending {$periodEnd}.")
            ->line('')
            ->line("**Period:** {$return->period_start->format('d M Y')} to {$periodEnd}")
            ->line("**Filing Frequency:** " . ucfirst(str_replace('_', '-', $return->filing_frequency)))
            ->line($amountLine)
            ->line("**Filing Deadline:** {$dueFormatted}")
            ->action('View GST Return', url("/finance/gst-returns/{$return->id}"))
            ->line('Please review and file this GST return before the deadline.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'gst_return_due',
            'gst_return_id' => $this->gstReturn->id,
            'period_end' => $this->gstReturn->period_end->toDateString(),
            'gst_payable' => (float) $this->gstReturn->gst_payable,
            'due_date' => $this->dueDate,
        ];
    }
}
