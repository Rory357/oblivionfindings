<?php

namespace App\Services\Medication\PharmacyConnect;

use App\Models\MedicationPharmacyDispatch;
use App\Models\MedicationPharmacyOrder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Invoked inside the existing locked supply writers; receipt and close-short remain canonical. */
final class PharmacyDispatchGuard
{
    public function assertContentEditable(MedicationPharmacyOrder $order, array $changes): void
    {
        $dispatch = $this->dispatch($order);
        if (! $dispatch || ! in_array($dispatch->state, ['queued', 'sending', 'sent', 'accepted', 'unknown'], true)) {
            return;
        }
        foreach (['order_notes', 'pharmacy_name', 'pharmacy_phone', 'pharmacy_email', 'quantity_ordered', 'needed_by', 'order_type'] as $field) {
            if (array_key_exists($field, $changes) && (string) $changes[$field] !== (string) $order->getAttribute($field)) {
                throw ValidationException::withMessages([$field => 'This supply order has a connected delivery record. Contact the pharmacy and create a corrected order instead of changing its sent contents.']);
            }
        }
    }

    public function assertManualContact(MedicationPharmacyOrder $order): void
    {
        $dispatch = $this->dispatch($order);
        if ($dispatch && in_array($dispatch->state, ['queued', 'sending', 'sent', 'accepted', 'unknown'], true)) {
            throw ValidationException::withMessages(['status' => 'This order is being delivered through the pharmacy connection. Check its delivery status before recording another contact.']);
        }
    }

    public function assertManualAdvance(MedicationPharmacyOrder $order): void
    {
        $dispatch = $this->dispatch($order);
        if ($dispatch && in_array($order->status, ['draft', 'submitted'], true)
            && in_array($dispatch->state, ['queued', 'sending', 'sent', 'unknown'], true)) {
            throw ValidationException::withMessages(['status' => 'Await the pharmacy response or record its dispensing evidence. An uncertain connected delivery cannot be advanced as confirmed.']);
        }
    }

    public function localClosure(MedicationPharmacyOrder $order): void
    {
        $dispatch = $this->dispatch($order);
        if ($dispatch?->state === 'queued') {
            $dispatch->forceFill(['state' => 'cancelled', 'result_code' => $dispatch->attempt_count > 0 ? 'local_supply_closed_before_retry' : 'local_supply_closed_before_send'])->save();
        }
        // Failed/sending/sent/unknown/accepted are retained truthfully. Local closure does not cancel the supplier's order.
    }

    private function dispatch(MedicationPharmacyOrder $order): ?MedicationPharmacyDispatch
    {
        return Schema::hasTable('medication_pharmacy_dispatches')
            ? MedicationPharmacyDispatch::where('pharmacy_order_id', $order->id)->lockForUpdate()->first() : null;
    }
}
