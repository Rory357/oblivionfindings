<?php

namespace App\Services\Fleet;

/** Display totals for separate stages of the same spend; never add stages together. */
final class MaintenanceCostSummary
{
    public function totals(array $bills, int $unavailableBillCount): array
    {
        // A visible subtotal must not look like the complete cost of this work.
        if ($unavailableBillCount > 0) {
            return ['committed' => null, 'invoice' => null, 'posted' => null, 'paid' => null, 'incomplete' => true];
        }

        $orders = [];
        $invoice = $posted = $paid = '0.00';
        $activeBills = 0;
        foreach ($bills as $bill) {
            $order = $bill['purchase_order'];
            if ($order && in_array($order['status'], ['approved', 'sent', 'partially_received', 'received'], true)) {
                $orders[$order['id']] = $order['total_amount'];
            }
            if ($bill['status'] === 'cancelled') {
                continue;
            }
            $activeBills++;
            $invoice = bcadd($invoice, (string) $bill['total'], 2);
            $paid = bcadd($paid, (string) $bill['paid'], 2);
            if (($bill['journal']['status'] ?? null) === 'posted') {
                $posted = bcadd($posted, (string) $bill['total'], 2);
            }
        }

        $committed = null;
        foreach ($orders as $amount) {
            $committed = bcadd($committed ?? '0.00', (string) $amount, 2);
        }

        return ['committed' => $committed, 'invoice' => $activeBills ? $invoice : null,
            'posted' => $activeBills ? $posted : null, 'paid' => $activeBills ? $paid : null, 'incomplete' => false];
    }
}
