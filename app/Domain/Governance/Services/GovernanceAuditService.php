<?php

namespace App\Domain\Governance\Services;

use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Writes governance audit entries.
 *
 * Two tables:
 * - `governance_audit_log` — discrete actions (viewed, downloaded, approved, voted, exported).
 *   Use `log()` from controller actions that don't correspond to a model write.
 * - `governance_change_log` — CRUD on governance entities (auto-written via the
 *   `LogsGovernanceWrites` trait; rarely called directly from controllers).
 */
class GovernanceAuditService
{
    public static function log(string $action, string $resourceType, int $resourceId, ?array $metadata = null): void
    {
        if (! Schema::hasTable('governance_audit_log')) {
            return;
        }

        DB::table('governance_audit_log')->insert([
            'user_id' => auth()->id(),
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'metadata' => $metadata ? json_encode($metadata) : null,
            'ip_address' => request()?->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function logChange(string $changeType, string $entityType, int $entityId, string $description, ?array $oldValues = null, ?array $newValues = null): void
    {
        if (! Schema::hasTable('governance_change_log')) {
            return;
        }

        DB::table('governance_change_log')->insert([
            'change_type' => $changeType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'user_id' => auth()->id(),
            'description' => $description,
            'old_values' => $oldValues ? json_encode($oldValues) : null,
            'new_values' => $newValues ? json_encode($newValues) : null,
            'ip_address' => request()?->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Read both tables as a unified stream of audit entries (newest first).
     *
     * @param  array{user_id?: int, entity_type?: string, entity_id?: int, action?: string, change_type?: string, from?: string, to?: string}  $filters
     * @param  array<int, string>  $excludedEntityTypes
     */
    public static function paginate(
        array $filters = [],
        int $perPage = 50,
        array $excludedEntityTypes = [],
    ): LengthAwarePaginator {
        $actions = DB::table('governance_audit_log')->select([
            DB::raw("'action' as kind"),
            'id',
            'user_id',
            DB::raw('action as type'),
            'resource_type as entity_type',
            'resource_id as entity_id',
            DB::raw('NULL as description'),
            DB::raw('NULL as old_values'),
            DB::raw('NULL as new_values'),
            'metadata',
            'ip_address',
            'created_at',
        ]);

        $changes = DB::table('governance_change_log')->select([
            DB::raw("'change' as kind"),
            'id',
            'user_id',
            DB::raw('change_type as type'),
            'entity_type',
            'entity_id',
            'description',
            'old_values',
            'new_values',
            DB::raw('NULL as metadata'),
            'ip_address',
            'created_at',
        ]);

        if ($excludedEntityTypes !== []) {
            $actions->whereNotIn('resource_type', $excludedEntityTypes);
            $changes->whereNotIn('entity_type', $excludedEntityTypes);
        }

        if (! empty($filters['user_id'])) {
            $actions->where('user_id', $filters['user_id']);
            $changes->where('user_id', $filters['user_id']);
        }
        if (! empty($filters['entity_type'])) {
            $actions->where('resource_type', $filters['entity_type']);
            $changes->where('entity_type', $filters['entity_type']);
        }
        if (! empty($filters['entity_id'])) {
            $actions->where('resource_id', $filters['entity_id']);
            $changes->where('entity_id', $filters['entity_id']);
        }
        if (! empty($filters['action'])) {
            $actions->where('action', $filters['action']);
        }
        if (! empty($filters['change_type'])) {
            $changes->where('change_type', $filters['change_type']);
        }
        if (! empty($filters['from'])) {
            $actions->where('created_at', '>=', $filters['from']);
            $changes->where('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $actions->where('created_at', '<=', $filters['to']);
            $changes->where('created_at', '<=', $filters['to']);
        }

        $union = $actions->unionAll($changes);

        $sub = DB::query()->fromSub($union, 'audit')->orderByDesc('created_at');

        return $sub->paginate($perPage);
    }

    /**
     * Recent audit + change events since a given timestamp (newest first).
     * Used by the dashboard "what changed since last meeting" timeline.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recentEventsSince(Carbon $since, int $limit = 100): array
    {
        if (! Schema::hasTable('governance_audit_log') || ! Schema::hasTable('governance_change_log')) {
            return [];
        }

        $actions = DB::table('governance_audit_log as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.created_at', '>=', $since)
            ->select([
                DB::raw("'action' as kind"),
                'a.id',
                'a.user_id',
                DB::raw('u.name as actor_name'),
                DB::raw('a.action as type'),
                DB::raw('a.resource_type as entity_type'),
                DB::raw('a.resource_id as entity_id'),
                DB::raw('NULL as description'),
                'a.created_at',
            ]);

        $changes = DB::table('governance_change_log as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.created_at', '>=', $since)
            ->select([
                DB::raw("'change' as kind"),
                'c.id',
                'c.user_id',
                DB::raw('u.name as actor_name'),
                DB::raw('c.change_type as type'),
                'c.entity_type',
                'c.entity_id',
                'c.description',
                'c.created_at',
            ]);

        $union = $actions->unionAll($changes);

        return DB::query()
            ->fromSub($union, 'events')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }
}
