<?php

namespace App\Services\Medication\PharmacyConnect;

use App\Models\MedicationPharmacyConnection;
use App\Models\MedicationPharmacyDispatch;

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
}
