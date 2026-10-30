<?php

namespace App\Services\Medication;

use App\Models\ClientMedication;
use App\Models\MedicationOrderRevision;
use Illuminate\Support\Facades\Schema;

/** A confirmation applies only to the checked version and its current allergy evidence. */
final class CheckedOrderAllergyConfirmation
{
    public function forOrder(ClientMedication $order): ?array
    {
        if (! Schema::hasTable('medication_order_revisions') || $order->approval_status !== 'verified') {
            return null;
        }
        $revision = MedicationOrderRevision::query()->where('client_id', $order->client_id)
            ->where('client_medication_id', $order->id)->whereIn('status', ['checked', 'checked_alone'])
            ->whereHas('version', fn ($q) => $q->where('version_number', $order->version))
            ->whereNotNull('allergy_confirmation')->with('version')->first();
        if ($revision === null) {
            return null;
        }
        $inspection = app(OrderAllergyMatcher::class)->inspect($order->client, $order->name);
        if (! hash_equals($inspection['match_sha256'], (string) data_get($revision->allergy_confirmation, 'match_sha256', ''))) {
            return null;
        }

        return $revision->allergy_confirmation;
    }
}
