<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Notifications\InvoiceEmailNotification;
use App\Domain\Finance\Services\InvoicePdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SendInvoiceEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $invoiceId,
    ) {}

    public function handle(InvoicePdfService $pdfService): void
    {
        $invoice = FinInvoice::findOrFail($this->invoiceId);

        try {
            // Generate PDF if not already generated
            if (! $invoice->pdf_path) {
                $pdfService->generate($invoice);
                $invoice->refresh();
            }

            if (! $invoice->client_email) {
                Log::warning("Invoice {$invoice->invoice_number} has no client email address. Skipping send.");

                return;
            }

            // Send the notification to the client email
            Notification::route('mail', $invoice->client_email)
                ->notify(new InvoiceEmailNotification($invoice));

            $invoice->update([
                'status' => $invoice->status === 'draft' ? 'sent' : $invoice->status,
                'sent_at' => $invoice->sent_at ?? now(),
            ]);

            Log::info("Invoice {$invoice->invoice_number} emailed to {$invoice->client_email}.");
        } catch (\Exception $e) {
            Log::error("Failed to send invoice {$invoice->invoice_number}: {$e->getMessage()}");
            throw $e;
        }
    }
}
