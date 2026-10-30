<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinBillLine;

/** Binds an approval to the bill and allocation lines actually presented for review. */
final class BillApprovalSnapshot
{
    public static function token(FinBill $bill, bool $lockWorkEvidence = false): string
    {
        $bill->loadMissing('lines', 'documents');

        // Explicit fields keep loaded display relations out of the fingerprint.
        // Include line identities and allocations: an unchanged total is insufficient.
        $snapshot = [
            'work' => app(BillWorkContext::class)->snapshot($bill, $lockWorkEvidence),
            'documents' => $bill->documents->sortBy('id')->map(fn ($document) => $document->only(['id', 'sha256', 'state', 'size']))->values()->all(),
            'bill' => $bill->only([
                'id', 'vendor_id', 'purchase_order_id', 'spend_approval_id',
                'site_id', 'asset_id', 'allocation_event_type', 'bill_number',
                'vendor_reference', 'status', 'bill_date', 'due_date', 'subtotal',
                'gst_amount', 'total_amount', 'notes', 'updated_at',
            ]),
            'lines' => $bill->lines->sortBy('id')->map(fn (FinBillLine $line): array => $line->only([
                'id', 'description', 'quantity', 'unit_price', 'gst_rate', 'tax_rate_id',
                'gst_amount', 'line_total', 'account_id', 'cost_centre_id',
                'funding_stream_id', 'updated_at',
            ]))->values()->all(),
        ];

        return hash_hmac('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
