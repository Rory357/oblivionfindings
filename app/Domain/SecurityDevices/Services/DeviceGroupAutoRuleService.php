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
 * Rules are authored through the governed Device Groups builder. The service
 * retains defensive whitelist checks so imported or historic rows cannot turn
 * rule values into arbitrary query columns or operators.
 */
class DeviceGroupAutoRuleService
{
    public const ALLOWED_FIELDS = [
        'domain',
        'category',
        'subcategory',
        'provider',
        'status',
        'health_status',
    ];

    public const ALLOWED_OPS = ['equals', 'not_equals', 'in'];

    /**
     * Build a device-matching query from a rules array.
     *
     * Returns an Eloquent Builder the caller can page / count / get off.
     * Unrecognised fields / ops are skipped silently.
     */
    public function queryFromRules(array $rules, ?Builder $deviceScope = null): Builder
    {
        $match = $rules['match'] ?? null;
        $conditions = is_array($rules['conditions'] ?? null) ? $rules['conditions'] : [];

        $query = $deviceScope === null ? Device::query() : clone $deviceScope;

        if (! $this->areRulesSupported($rules)) {
            // An empty rule set must NOT match everything — that would be a
            // footgun on sync. Historic malformed rules also fail closed.
            return $query->whereRaw('1 = 0');
        }

        $query->where(function (Builder $outer) use ($conditions, $match) {
            foreach ($conditions as $condition) {
                $field = $condition['field'];
                $op = $condition['op'];
                $value = $condition['value'];

                $method = $match === 'any' ? 'orWhere' : 'where';
                $outer->{$method}(function (Builder $c) use ($field, $op, $value) {
                    $this->applyCondition($c, $field, $op, $value);
                });
            }
        });

        return $query;
    }

    /** Historic/imported JSON must fail closed before any membership plan. */
    public function areRulesSupported(array $rules): bool
    {
        if (! in_array($rules['match'] ?? null, ['all', 'any'], true)
            || array_diff(array_keys($rules), ['match', 'conditions']) !== []
            || ! is_array($rules['conditions'] ?? null)
            || count($rules['conditions']) < 1
            || count($rules['conditions']) > 8) {
            return false;
        }

        foreach ($rules['conditions'] as $condition) {
            if (! is_array($condition)
                || array_diff(array_keys($condition), ['field', 'op', 'value']) !== []
                || ! in_array($condition['field'] ?? null, self::ALLOWED_FIELDS, true)
                || ! in_array($condition['op'] ?? null, self::ALLOWED_OPS, true)) {
                return false;
            }

            $operation = $condition['op'];
            $value = $condition['value'] ?? null;
            if ($operation === 'in') {
                if (! is_array($value) || count($value) < 1 || count($value) > 20) {
                    return false;
                }

                $normalised = [];
                foreach ($value as $item) {
                    if (! is_string($item) || trim($item) === '' || mb_strlen(trim($item)) > 100) {
                        return false;
                    }
                    $normalised[] = trim($item);
                }

                if (count(array_unique($normalised)) !== count($normalised)) {
                    return false;
                }

                continue;
            }

            if (! is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 100) {
                return false;
            }
        }

        return true;
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
    public function preview(DeviceGroup $group, int $limit = 20, ?Builder $deviceScope = null): array
    {
        $rules = is_array($group->auto_rules) ? $group->auto_rules : [];

        return $this->previewRules($rules, $limit, $deviceScope);
    }

    /**
     * Preview a proposed rule set before a group exists or changes are saved.
     *
     * @return array{count: int, sample: Collection<int, Device>}
     */
    public function previewRules(array $rules, int $limit = 20, ?Builder $deviceScope = null): array
    {
        if (empty($rules) || ! $this->areRulesSupported($rules)) {
            return ['count' => 0, 'sample' => collect()];
        }

        $query = $this->queryFromRules($rules, $deviceScope);

        return [
            'count' => (clone $query)->count(),
            'sample' => $query->orderBy('name')->limit($limit)->get(),
        ];
    }

    /**
     * Report the exact visible membership delta without changing the group.
     *
     * @return array{added: int, removed: int, kept: int, total: int}
     */
    public function previewChanges(DeviceGroup $group, ?Builder $deviceScope = null): array
    {
        $plan = $this->membershipPlan($group, $deviceScope);

        return $plan['counts'];
    }

    /**
     * Sync the group's membership to match its auto_rules. Returns the
     * delta counts so the UI can show "added X, removed Y, kept Z".
     *
     * No-ops (returns zeros) when auto_rules is empty or malformed.
     *
     * @return array{added: int, removed: int, kept: int, total: int}
     */
    public function applyToGroup(DeviceGroup $group, ?Builder $deviceScope = null): array
    {
        $plan = $this->membershipPlan($group, $deviceScope);

        if (! empty($plan['to_add'])) {
            $group->devices()->attach($plan['to_add']);
        }
        if (! empty($plan['to_remove'])) {
            $group->devices()->detach($plan['to_remove']);
        }

        return $plan['counts'];
    }

    /**
     * @return array{
     *     to_add: list<int>,
     *     to_remove: list<int>,
     *     counts: array{added: int, removed: int, kept: int, total: int}
     * }
     */
    private function membershipPlan(DeviceGroup $group, ?Builder $deviceScope = null): array
    {
        $rules = is_array($group->auto_rules) ? $group->auto_rules : [];
        if (empty($rules) || ! $this->areRulesSupported($rules)) {
            return [
                'to_add' => [],
                'to_remove' => [],
                'counts' => ['added' => 0, 'removed' => 0, 'kept' => 0, 'total' => 0],
            ];
        }

        $matchingIds = $this->queryFromRules($rules, $deviceScope)
            ->pluck('devices.id')
            ->all();

        $existing = $group->devices();
        if ($deviceScope !== null) {
            $existing->whereIn('devices.id', (clone $deviceScope)->select('devices.id'));
        }
        $existingIds = $existing->pluck('devices.id')->all();

        $toAdd = array_values(array_diff($matchingIds, $existingIds));
        $toRemove = array_values(array_diff($existingIds, $matchingIds));

        return [
            'to_add' => $toAdd,
            'to_remove' => $toRemove,
            'counts' => [
                'added' => count($toAdd),
                'removed' => count($toRemove),
                'kept' => count(array_intersect($matchingIds, $existingIds)),
                'total' => count($matchingIds),
            ],
        ];
    }
}
