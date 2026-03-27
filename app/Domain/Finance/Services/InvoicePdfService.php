<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinInvoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class InvoicePdfService
{
    /**
     * Generate a PDF for the given invoice and store it locally.
     *
     * @return string The storage path of the generated PDF.
     */
    public function generate(FinInvoice $invoice): string
    {
        $invoice->loadMissing(['lines.taxRate', 'lines.account']);

        $pdf = Pdf::loadView('finance.invoices.pdf', [
            'invoice' => $invoice,
        ]);

        $path = "invoices/{$invoice->organization_id}/{$invoice->invoice_number}.pdf";
        Storage::disk('local')->put($path, $pdf->output());

        $invoice->update(['pdf_path' => $path]);

        return $path;
    }
}
