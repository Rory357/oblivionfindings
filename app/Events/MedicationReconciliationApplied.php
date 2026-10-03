<?php

namespace App\Events;

/** Transactional seam for P03 reassessment. No duplicate assessment or task. */
final readonly class MedicationReconciliationApplied
{
    public function __construct(public int $clientId, public int $reconciliationId, public int $actorId) {}
}
