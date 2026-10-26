<?php

namespace App\Services\Incidents;

use LogicException;

/** Fixed physical evidence expressions only. Neither a grant nor a serializable admission token. */
final class IncidentIndependenceBindingSql
{
    public static function table(string $family): string
    {
        return match ($family) {
            'root' => 'client_incidents', 'declaration' => 'incident_independent_handling_declarations',
            'exclusion' => 'incident_independent_handling_exclusions', 'owner_exclusion' => 'incident_owner_exclusions',
            'acceptance' => 'health_safety_setting_changes', 'audit' => 'audit_logs', 'policy_mutex' => 'app_settings',
            default => throw new LogicException('Unsupported incident independence evidence family.'),
        };
    }

    public static function fingerprint(string $family, string $alias): string
    {
        self::table($family);
        if (! preg_match('/^[a-z][a-z0-9_]*$/D', $alias)) { throw new LogicException('Unsupported evidence alias.'); }
        $columns = match ($family) {
            'root' => [...IncidentIndependenceIntakeService::ROOT_COLUMNS, 'is_hr_confidential'],
            'declaration' => ['id', 'incident_id', 'revision', 'source_tuple', 'handling', 'policy_snapshot',
                'actor_id', 'actor_snapshot', 'recorded_at', 'content_digest', 'acceptance_id'],
            'exclusion' => ['id', 'declaration_id', 'user_id', 'exclusion_id', 'prior_existing', 'recorded_at'],
            // Only the exact canonical association is certified, not a pre-existing exclusion's prose/attribution.
            'owner_exclusion' => ['id', 'incident_id', 'user_id'],
            'acceptance' => ['id', 'setting_key', 'revision', 'schema_version', 'action', 'origin', 'before_value', 'after_value',
                'before_digest', 'after_digest', 'actor_id', 'actor_snapshot', 'recorded_at', 'reason', 'audit_id',
                'command_key', 'fingerprint', 'accepted_result'],
            'audit' => ['id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'meta'],
            // The retained policy references this identity; today's policy revision/value does not rewrite it.
            'policy_mutex' => ['id', 'key'],
        };
        $parts = array_map(fn ($column) => "'".$column."', `".$alias.'`.`'.$column.'`', $columns);
        return 'SHA2(CAST(JSON_OBJECT('.implode(', ', $parts).') AS CHAR CHARACTER SET utf8mb4), 256)';
    }
}
