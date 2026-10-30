<?php

namespace App\Models\Concerns;

use LogicException;

/** Paper facts and signatures are evidence; corrections use the canonical eMAR correction workflow. */
trait ImmutableMedicationPaperEvidence
{
    public static function bootImmutableMedicationPaperEvidence(): void
    {
        static::updating(fn () => throw new LogicException('Medication paper evidence is append-only.'));
        static::deleting(fn () => throw new LogicException('Medication paper evidence is append-only.'));
    }
}
