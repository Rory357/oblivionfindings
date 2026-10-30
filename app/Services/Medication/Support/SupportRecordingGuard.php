<?php

namespace App\Services\Medication\Support;

use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** No administration, refusal or missed record is fabricated for a self-managed dose. */
final class SupportRecordingGuard
{
    public function saving(ClientMedicationAdministration $record): void
    {
        // Corrections keep their existing evidence/permission workflow.
        if (($record->exists && ! $record->isDirty('status')) || $record->is_correction || ! in_array($record->status, ['given', 'refused', 'withheld', 'missed'], true)) {
            return;
        }
        $order = ClientMedication::query()->find($record->client_medication_id);
        if (! $order || (int) $order->client_id !== (int) $record->client_id) {
            return;
        }
        $now = CarbonImmutable::now('UTC');
        $at = $record->scheduled_for ? CarbonImmutable::instance($record->scheduled_for)->utc() : $now;
        if ($at->setTimezone('Pacific/Auckland')->toDateString() === $now->setTimezone('Pacific/Auckland')->toDateString() && $at->lessThan($now)) {
            $at = $now;
        }
        if (app(MedicationSupport::class)->mode($order, $at, true) === 'self_managed') {
            throw ValidationException::withMessages(['status' => 'This dose is Self-managed and is listed for information. Record a change in support if the person asks staff to take over.']);
        }
        if ($record->reason_code === 'self_administered') {
            throw ValidationException::withMessages(['reason_code' => 'Self-managed doses are informational, not refusals or withheld doses. Record the person’s support plan.']);
        }
    }
}
