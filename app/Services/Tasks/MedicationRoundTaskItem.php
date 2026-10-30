<?php

namespace App\Services\Tasks;

/** The actual round instant stays separate from its latest recording deadline. */
final class MedicationRoundTaskItem extends TaskItem
{
    public string $scheduledAt;
}
