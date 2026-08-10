<?php

namespace App\Domain\Roadmap\Services;

use App\Domain\Roadmap\Models\ChangeLogEntry;

class RoadmapChangeLogService
{
    public function log(
        string $entityType,
        int $entityId,
        string $changeType,
        array $fieldDeltas = [],
        ?string $reason = null,
        ?int $changedBy = null,
        ?string $correlationId = null
    ): ChangeLogEntry {
        return ChangeLogEntry::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'change_type' => $changeType,
            'field_deltas' => $fieldDeltas,
            'reason' => $reason,
            'changed_by' => $changedBy,
            'correlation_id' => $correlationId,
        ]);
    }
}
