<?php

namespace App\Domain\Finance\Notifications;

use App\Domain\Finance\Models\FinInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class InvoiceEmailNotification extends Notification
{
    use Queueable;

    public function __construct(
        public FinInvoice $invoice,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->invoice->email_subject
            ?? "Invoice {$this->invoice->invoice_number} from " . config('app.name');

        $body = $this->invoice->email_body
            ?? "Please find attached invoice {$this->invoice->invoice_number} for the amount of \${$this->formatAmount($this->invoice->total_amount)} {$this->invoice->currency_code}.";

        $message = (new MailMessage)
            ->subject($subject)
            ->line($body)
            ->line('')
            ->line("**Invoice Number:** {$this->invoice->invoice_number}")
            ->line("**Invoice Date:** {$this->invoice->invoice_date->format('j F Y')}")
            ->line("**Due Date:** {$this->invoice->due_date->format('j F Y')}")
            ->line("**Total Amount:** \${$this->formatAmount($this->invoice->total_amount)} {$this->invoice->currency_code}")
            ->line('')
            ->line('Please refer to the attached PDF for full details.');

        if ($this->invoice->pdf_path && Storage::disk('local')->exists($this->invoice->pdf_path)) {
            $message->attach(
                Storage::disk('local')->path($this->invoice->pdf_path),
                [
                    'as' => "Invoice-{$this->invoice->invoice_number}.pdf",
                    'mime' => 'application/pdf',
                ]
            );
        }

        return $message;
    }

    private function formatAmount($amount): string
    {
        return number_format((float) $amount, 2);
    }
}
