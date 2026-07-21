<?php

namespace App\Domain\SecurityDevices\Services;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Materialises DeviceGroup.auto_rules into actual membership.
 *
 * Rule schema (auto_rules column, JSON):
 *
 *   {
 *     "match": "all",                 // or "any"
 *     "conditions": [
 *       { "field": "domain",     "op": "equals",    "value": "security" },
 *       { "field": "category",   "op": "in",        "value": ["camera", "nvr"] },
 *       { "field": "provider",   "op": "not_equals","value": "manual" }
 *     ]
 *   }
 *
 * Supported fields: domain, category, subcategory, provider, status, health_status.
 * Supported ops:    equals, not_equals, in.
 *
 * Anything outside that whitelist is silently ignored — rules are
 * operator-authored JSON today so the service guards against SQL-injection
 * risk and malformed input. A future v1.5 rule-builder UI can lean on this
 * service for preview and commit.
 */
class DeviceGroupAutoRuleService
{
    private const ALLOWED_FIELDS = [
        'domain',
        'category',
        'subcategory',
        'provider',
        'status',
        'health_status',
    ];

    private const ALLOWED_OPS = ['equals', 'not_equals', 'in'];

    /**
     * Build a device-matching query from a rules array.
     *
     * Returns an Eloquent Builder the caller can page / count / get off.
     * Unrecognised fields / ops are skipped silently.
     */
    public function queryFromRules(array $rules): Builder
    {
        $match = ($rules['match'] ?? 'all') === 'any' ? 'any' : 'all';
        $conditions = is_array($rules['conditions'] ?? null) ? $rules['conditions'] : [];

        $query = Device::query();

        if (empty($conditions)) {
            // An empty rule set must NOT match everything — that would be a
            // footgun on sync. Match nothing instead.
            return $query->whereRaw('1 = 0');
        }

        $query->where(function (Builder $outer) use ($conditions, $match) {
            foreach ($conditions as $condition) {
                if (! is_array($condition)) {
                    continue;
                }
                $field = $condition['field'] ?? null;
                $op = $condition['op'] ?? null;
                $value = $condition['value'] ?? null;

                if (! in_array($field, self::ALLOWED_FIELDS, true)) {
                    continue;
                }
                if (! in_array($op, self::ALLOWED_OPS, true)) {
                    continue;
                }

                $method = $match === 'any' ? 'orWhere' : 'where';
                $outer->{$method}(function (Builder $c) use ($field, $op, $value) {
                    $this->applyCondition($c, $field, $op, $value);
                });
            }
        });

        return $query;
    }

    private function applyCondition(Builder $query, string $field, string $op, mixed $value): void
    {
        match ($op) {
            'equals' => $query->where($field, '=', is_scalar($value) ? $value : null),
            'not_equals' => $query->where($field, '!=', is_scalar($value) ? $value : null),
            'in' => is_array($value) && ! empty($value)
                ? $query->whereIn($field, array_values(array_filter($value, 'is_scalar')))
                : $query->whereRaw('1 = 0'),
            default => null,
        };
    }

    /**
     * Return up to $limit matching devices plus the total match count.
     * Used by the group's "preview auto-rules" action before the user
     * commits to syncing membership.
     *
     * @return array{count: int, sample: Collection<int, Device>}
     */
    public function preview(DeviceGroup $group, int $limit = 20): array
    {
        $rules = is_array($group->auto_rules) ? $group->auto_rules : [];
        if (empty($rules)) {
            return ['count' => 0, 'sample' => collect()];
        }

        $query = $this->queryFromRules($rules);

        return [
            'count' => (clone $query)->count(),
            'sample' => $query->orderBy('name')->limit($limit)->get(),
        ];
    }

    /**
     * Sync the group's membership to match its auto_rules. Returns the
     * delta counts so the UI can show "added X, removed Y, kept Z".
     *
     * No-ops (returns zeros) when auto_rules is empty or malformed.
     *
     * @return array{added: int, removed: int, kept: int, total: int}
     */
    public function applyToGroup(DeviceGroup $group): array
    {
        $rules = is_array($group->auto_rules) ? $group->auto_rules : [];
        if (empty($rules)) {
            return ['added' => 0, 'removed' => 0, 'kept' => 0, 'total' => 0];
        }

        $matchingIds = $this->queryFromRules($rules)
            ->pluck('id')
            ->all();

        $existingIds = $group->devices()->pluck('devices.id')->all();

        $toAdd = array_values(array_diff($matchingIds, $existingIds));
        $toRemove = array_values(array_diff($existingIds, $matchingIds));
        $kept = count(array_intersect($matchingIds, $existingIds));

        if (! empty($toAdd)) {
            $group->devices()->attach($toAdd);
        }
        if (! empty($toRemove)) {
            $group->devices()->detach($toRemove);
        }

        return [
            'added' => count($toAdd),
            'removed' => count($toRemove),
            'kept' => $kept,
            'total' => count($matchingIds),
        ];
    }
}
