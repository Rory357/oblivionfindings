<?php

namespace App\Domain\Governance\Services;

use Illuminate\Support\Facades\DB;

class GovernanceAuditService
{
    public static function log(string $action, string $resourceType, int $resourceId, ?array $metadata = null): void
    {
        DB::table('governance_audit_log')->insert([
            'user_id' => auth()->id() ?? 0,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'metadata' => $metadata ? json_encode($metadata) : null,
            'ip_address' => request()->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function logChange(string $changeType, string $entityType, int $entityId, string $description, ?array $oldValues = null, ?array $newValues = null): void
    {
        DB::table('governance_change_log')->insert([
            'change_type' => $changeType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'user_id' => auth()->id() ?? 0,
            'description' => $description,
            'old_values' => $oldValues ? json_encode($oldValues) : null,
            'new_values' => $newValues ? json_encode($newValues) : null,
            'ip_address' => request()->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
