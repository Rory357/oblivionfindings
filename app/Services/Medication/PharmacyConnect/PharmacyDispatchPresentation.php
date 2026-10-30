<?php

namespace App\Services\Medication\PharmacyConnect;

use App\Models\MedicationPharmacyConnection;
use App\Models\MedicationPharmacyDispatch;
use Illuminate\Support\Facades\Schema;

final class PharmacyDispatchPresentation
{
    public const LABELS = [
        'queued' => 'Waiting to send', 'sending' => 'Sending', 'sent' => 'Sent — awaiting pharmacy response',
        'accepted' => 'Pharmacy accepted', 'rejected' => 'Pharmacy declined', 'failed' => 'Not sent',
        'unknown' => 'Delivery unknown — check with pharmacy', 'cancelled' => 'Sending stopped',
    ];

    public function connection(MedicationPharmacyConnection $connection): array
    {
        return ['id' => $connection->id, 'name' => $connection->name, 'partner_key' => $connection->partner_key,
            'site_ids' => array_map('intval', $connection->site_ids ?? []), 'enabled' => $connection->enabled,
            'version' => $connection->version, 'protocol_label' => 'Generic pharmacy bridge (requires partner agreement)'];
    }

    public function dispatch(MedicationPharmacyDispatch $dispatch): array
    {
        return ['id' => $dispatch->id, 'uuid' => $dispatch->uuid, 'connection_id' => $dispatch->connection_id,
            'order_id' => $dispatch->pharmacy_order_id, 'state' => $dispatch->state,
            'label' => self::LABELS[$dispatch->state] ?? 'Check pharmacy delivery', 'result_code' => $dispatch->result_code,
            'attempt_count' => $dispatch->attempt_count, 'queued_at' => $dispatch->created_at?->toIso8601String(),
            'sending_at' => $dispatch->sending_at?->toIso8601String(), 'sent_at' => $dispatch->sent_at?->toIso8601String(),
            'acknowledged_at' => $dispatch->acknowledged_at?->toIso8601String(),
            'supplier_reference' => $dispatch->supplier_reference, 'acknowledgment_outcome' => $dispatch->acknowledgment_outcome,
            'acknowledgment_applied' => $dispatch->acknowledgment_applied, 'acknowledgment_code' => $dispatch->acknowledgment_code,
            'vendor_cancellation_available' => false];
    }

    /**
     * EA-138: each order's connected pharmacy send, for order lists (the
     * Stock overview's Pharmacy orders and the Stock hub's Deliveries &
     * orders), so a declined, unknown or contradicted send is seen on the row
     * and not only in the order's own panel.
     *
     * @param  list<int>  $orderIds
     * @return array<int, array{state: string, label: string, short_label: string, tone: string, note: ?string}>
     */
    public function rowStates(array $orderIds): array
    {
        if ($orderIds === [] || ! Schema::hasTable('medication_pharmacy_dispatches')) {
            return [];
        }

        return MedicationPharmacyDispatch::query()->whereIn('pharmacy_order_id', $orderIds)
            ->get(['id', 'pharmacy_order_id', 'state', 'result_code', 'acknowledgment_outcome', 'acknowledgment_applied', 'acknowledgment_code'])
            ->mapWithKeys(fn (MedicationPharmacyDispatch $dispatch) => [(int) $dispatch->pharmacy_order_id => $this->rowState($dispatch)])->all();
    }

    /** @return array{state: string, label: string, short_label: string, tone: string, note: ?string} */
    public function rowState(MedicationPharmacyDispatch $dispatch): array
    {
        $label = self::LABELS[$dispatch->state] ?? 'Check pharmacy delivery';
        // EA-136: a pharmacy answer that arrived after staff settled the order
        // by hand is kept as conflicting evidence and needs a person's check.
        if ($dispatch->acknowledgment_code === 'contradicts_recorded_check') {
            $accepted = $dispatch->acknowledgment_outcome === 'accepted';

            return ['state' => $dispatch->state, 'tone' => 'warning',
                'label' => $accepted ? 'Pharmacy accepted after a manual check' : 'Pharmacy declined after a manual check',
                'short_label' => $accepted ? 'Accepted — check' : 'Declined — check',
                'note' => $accepted ? 'Check the pharmacy is not now holding two orders for this medicine.' : 'Check the order with the pharmacy.'];
        }
        [$short, $tone, $note] = match (true) {
            $dispatch->state === 'accepted' && ! $dispatch->acknowledgment_applied => ['Accepted — check', 'warning',
                'The pharmacy accepted, but the order or connection changed after it was sent. Check the order with the pharmacy.'],
            $dispatch->state === 'accepted' => ['Pharmacy accepted', 'success', null],
            $dispatch->state === 'rejected' => ['Pharmacy declined', 'critical', 'Contact the pharmacy and arrange the supply another way.'],
            $dispatch->state === 'unknown' => ['Delivery unknown', 'warning', 'The send may not have arrived. Check with the pharmacy before ordering again.'],
            $dispatch->state === 'failed' && $dispatch->result_code === 'pharmacy_confirmed_not_received' => ['Not received', 'neutral', 'Staff confirmed the pharmacy did not receive it.'],
            $dispatch->state === 'failed' => ['Not sent', 'warning', 'The send did not go through. Open the order to send again or contact the pharmacy another way.'],
            $dispatch->state === 'cancelled' => ['Sending stopped', 'neutral', null],
            $dispatch->state === 'sent' => ['Sent', 'info', 'Waiting for the pharmacy to respond.'],
            default => [$label, 'info', null],
        };

        return ['state' => $dispatch->state, 'label' => $label, 'short_label' => $short, 'tone' => $tone, 'note' => $note];
    }
}
