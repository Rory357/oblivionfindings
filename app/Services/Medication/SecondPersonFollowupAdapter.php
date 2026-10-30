<?php

namespace App\Services\Medication;

use App\Models\ClientMedicationAdministration;
use App\Models\MedicationSecondPersonConfirmation;
use App\Models\User;
use App\Services\Medication\Followups\MedicationFollowupService;

/** PIN-2 owns attestation; P08a owns the single follow-up, Task and lead workflow. */
class SecondPersonFollowupAdapter
{
    public function available(): bool
    {
        return class_exists(MedicationFollowupService::class)
            && method_exists(MedicationFollowupService::class, 'ensure')
            && method_exists(MedicationFollowupService::class, 'completeFromSource');
    }

    /** Call inside the source transaction; the returned ID is the canonical UI identity. */
    public function start(MedicationSecondPersonConfirmation $nomination, ClientMedicationAdministration $dose): int
    {
        $followup = app(MedicationFollowupService::class)->ensure(
            'confirm:'.$nomination->id, 'confirm', $dose->client, $dose->medication,
            $dose, (int) $nomination->nominated_user_id, $nomination->due_at,
            ['nomination_id' => (int) $nomination->id],
        );

        return (int) $followup->id;
    }

    /** After all attestation writes, before the final PIN-2 audit-chain append. */
    public function complete(MedicationSecondPersonConfirmation $nomination, ?User $actor, array &$auditEvents): void
    {
        $outcome = match ($nomination->status) {
            MedicationSecondPersonConfirmation::CONFIRMED => 'yes',
            MedicationSecondPersonConfirmation::DISPUTED => 'no',
            MedicationSecondPersonConfirmation::EXPIRED => 'expired',
            default => throw new \LogicException('Complete only a terminal second-person attestation.'),
        };
        app(MedicationFollowupService::class)->completeFromSource(
            'confirm:'.$nomination->id, $actor, $outcome,
            ['nomination_id' => (int) $nomination->id], $auditEvents,
        );
    }
}
