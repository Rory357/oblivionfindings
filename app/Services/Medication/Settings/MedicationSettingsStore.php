<?php

namespace App\Services\Medication\Settings;

use App\Models\AppSetting;
use App\Models\MedicationAdminRule;
use App\Models\MedicationSettingChange;
use App\Models\MedicationSiteSetting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reads and saves Medication Settings values (eMAR P11 B1) and keeps their
 * change history.
 *
 * Organisation values live in `app_settings`, where the rules read them; a
 * setting with no stored row is still its default and shows "Default — not
 * yet reviewed". Per-house values live in `medication_site_settings`. Every
 * write takes one lock, checks that nobody saved the same setting since the
 * person started editing, records one history row per setting (with the
 * structured value before, so it can be put back) and audits each group.
 *
 * Callers check who may change what; this class only stores.
 */
class MedicationSettingsStore
{
    /** One row that every Medication Settings write locks; its value counts saves. */
    public const REVISION_KEY = 'medications.settings.revision';

    public const NOT_YET_REVIEWED = 'Default — not yet reviewed';

    /** History entries for medicine rules use this group, keyed "rule:{id}". */
    public const RULES_GROUP = 'medicine_rules';

    public function __construct(private readonly MedicationSettingsRegistry $registry) {}

    /** @return array<string, array<string, string>> Saved organisation values, by group then key. */
    public function organisationValues(): array
    {
        $definitions = $this->organisationDefinitions();
        $stored = AppSetting::query()
            ->whereIn('key', $definitions->pluck('storageKey')->all())
            ->pluck('value', 'key');

        return $this->nest($definitions->map(fn (MedicationSettingDefinition $d): array => [
            $d, $d->normalise($stored[$d->storageKey] ?? null),
        ]));
    }

    /**
     * Values houses have chosen for themselves, by Site, group and key. A
     * house with no value follows the organisation, or the default.
     *
     * @param  list<int>  $siteIds
     * @return array<int, array<string, array<string, string>>>
     */
    public function siteValues(array $siteIds): array
    {
        return $this->siteRows($siteIds)
            ->map(fn (Collection $rows): array => $this->nest($rows->map(fn (array $row): array => [
                $row['definition'], $row['value'],
            ])))
            ->all();
    }

    /**
     * Who last deliberately saved or kept each organisation setting, by group
     * and key. Null means it is still the default and nobody has reviewed it.
     * A value saved before the change history existed reads as reviewed, with
     * no name.
     *
     * @return array<string, array<string, array{by: string|null, at: string|null}|null>>
     */
    public function reviewed(): array
    {
        $definitions = $this->organisationDefinitions();
        $stored = AppSetting::query()
            ->whereIn('key', $definitions->pluck('storageKey')->all())
            ->pluck('key')
            ->flip();
        $latest = $this->latestChanges(null);

        return $this->nest($definitions->map(fn (MedicationSettingDefinition $d): array => [
            $d, $stored->has($d->storageKey) ? $this->reviewer($latest->get($this->slot($d, null))) : null,
        ]));
    }

    /**
     * Per house: who last saved or kept each house setting that has a value.
     *
     * @param  list<int>  $siteIds
     * @return array<int, array<string, array<string, array{by: string|null, at: string|null}>>>
     */
    public function siteReviewed(array $siteIds): array
    {
        $latest = $this->latestChanges($siteIds);

        return $this->siteRows($siteIds)
            ->map(fn (Collection $rows, int $siteId): array => $this->nest($rows->map(fn (array $row): array => [
                $row['definition'], $this->reviewer($latest->get($this->slot($row['definition'], $siteId))),
            ])))
            ->all();
    }

    /**
     * Save one view's changes. Each change carries the saved value it was
     * edited from: if anyone has saved that setting since, nothing is saved
     * and the person is told who. A change that loosens a check is saved only
     * when the person confirmed it.
     *
     * @param  list<array{definition: MedicationSettingDefinition, site_id: int|null, value: string, from: string}>  $changes
     * @return array{saved: int, effect: string|null}
     */
    public function apply(User $actor, array $changes, bool $confirmLoosening): array
    {
        return DB::transaction(function () use ($actor, $changes, $confirmLoosening): array {
            $revision = $this->lockForWrite();
            $current = $this->lockedCurrent($changes);

            $planned = [];
            foreach ($changes as $change) {
                $definition = $change['definition'];
                $before = $current[$this->slot($definition, $change['site_id'])];
                if ($before['value'] !== $change['from']) {
                    throw $this->conflict($definition, $change['site_id']);
                }
                if ($before['value'] === $change['value']) {
                    continue;
                }
                $planned[] = $change + [
                    'before' => $before['value'],
                    'loosens' => $definition->loosens($before['value'], $change['value']),
                ];
            }

            if (! $confirmLoosening && collect($planned)->contains('loosens', true)) {
                throw ValidationException::withMessages([
                    'confirm_loosening' => 'One of these changes loosens a check. Review it and confirm before saving.',
                ]);
            }
            if ($planned === []) {
                return ['saved' => 0, 'effect' => null];
            }

            $this->record($actor, MedicationSettingChange::ACTION_CHANGED, $planned, $revision);

            return [
                'saved' => count($planned),
                'effect' => $this->registry->group($planned[0]['definition']->group)?->effect,
            ];
        });
    }

    /**
     * "Keep today's value": mark defaults nobody has reviewed as deliberately
     * kept, without changing how anything behaves. All or nothing.
     *
     * @param  list<array{definition: MedicationSettingDefinition, site_id: int|null}>  $items
     */
    public function keep(User $actor, array $items): int
    {
        return DB::transaction(function () use ($actor, $items): int {
            $revision = $this->lockForWrite();
            $current = $this->lockedCurrent($items);

            $planned = [];
            foreach ($items as $item) {
                $state = $current[$this->slot($item['definition'], $item['site_id'])];
                if ($state['exists']) {
                    throw $this->conflict($item['definition'], $item['site_id']);
                }
                $planned[] = [
                    'definition' => $item['definition'],
                    'site_id' => $item['site_id'],
                    'before' => $state['value'],
                    'value' => $state['value'],
                    'loosens' => false,
                ];
            }

            $this->record($actor, MedicationSettingChange::ACTION_KEPT, $planned, $revision);

            return count($planned);
        });
    }

    /**
     * Record a medicine-rule change (added, changed, paused, turned back on)
     * in the change history, worded like the rule itself. The rule's own
     * audit entry is written by the model; a rule can't be "put back".
     */
    public function recordRuleChange(
        User $actor,
        MedicationAdminRule $rule,
        string $label,
        string $before,
        string $after,
        bool $loosens,
        string $auditEvent,
    ): MedicationSettingChange {
        return MedicationSettingChange::query()->create([
            'setting_group' => self::RULES_GROUP,
            'setting_key' => 'rule:'.$rule->id,
            'site_id' => $rule->site_id,
            'action' => MedicationSettingChange::ACTION_CHANGED,
            'view' => MedicationSettingsRegistry::VIEW_RULES,
            'section' => 'medicines',
            'label' => $label,
            'before_value' => null,
            'after_value' => null,
            'before_text' => $before,
            'after_text' => $after,
            'loosens' => $loosens,
            'actor_id' => $actor->id,
            'audit_event' => $auditEvent,
        ]);
    }

    /**
     * The newest history entry for each medicine rule, keyed by rule id.
     *
     * @param  list<int>  $ruleIds
     * @return Collection<int, MedicationSettingChange>
     */
    public function latestRuleChanges(array $ruleIds): Collection
    {
        if ($ruleIds === []) {
            return collect();
        }
        $keys = array_map(fn (int $id): string => 'rule:'.$id, $ruleIds);
        $latestIds = MedicationSettingChange::query()
            ->selectRaw('MAX(id) as id')
            ->where('setting_group', self::RULES_GROUP)
            ->whereIn('setting_key', $keys)
            ->groupBy('setting_key')
            ->pluck('id');

        return MedicationSettingChange::query()
            ->with('actor:id,name')
            ->whereIn('id', $latestIds->all())
            ->get()
            ->keyBy(fn (MedicationSettingChange $change): int => (int) substr($change->setting_key, 5));
    }

    /**
     * The newest changes first, organisation-wide ones plus those at the
     * given houses (null = every house).
     *
     * @param  list<int>|null  $siteIds
     * @return Collection<int, array<string, mixed>>
     */
    public function history(?array $siteIds, int $limit = 500): Collection
    {
        return MedicationSettingChange::query()
            ->with(['actor:id,name', 'site:id,name'])
            ->when($siteIds !== null, fn ($query) => $query->where(fn ($scope) => $scope
                ->whereNull('site_id')
                ->orWhereIn('site_id', $siteIds)))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (MedicationSettingChange $change): array => [
                'id' => $change->id,
                'at' => $change->created_at?->toIso8601String(),
                'who' => $change->actor?->name,
                'action' => $change->action,
                'group' => $change->setting_group,
                'key' => $change->setting_key,
                'site_id' => $change->site_id,
                'site_name' => $change->site?->name,
                'view' => $change->view,
                'section' => $change->section,
                'label' => $change->label,
                'before_text' => $change->before_text,
                'after_text' => $change->after_text,
                // Only a saved change can be put back; a kept default changed nothing.
                'before_value' => $change->action === MedicationSettingChange::ACTION_CHANGED ? $change->before_value : null,
                'loosens' => $change->loosens,
                'note' => $change->note,
                'event' => $change->audit_event,
            ]);
    }

    /**
     * Write the values, then one history row per setting and one audit entry
     * per group (and house) with the whole group before and after.
     *
     * @param  list<array{definition: MedicationSettingDefinition, site_id: int|null, value: string, before: string, loosens: bool}>  $planned
     */
    private function record(User $actor, string $action, array $planned, AppSetting $revision): void
    {
        $byGroup = collect($planned)->groupBy(fn (array $p): string => $p['definition']->group.'@'.($p['site_id'] ?? 'org'));
        $before = $byGroup->map(fn (Collection $items): array => $this->groupSnapshot($items->first()['definition']->group, $items->first()['site_id']));

        foreach ($planned as $p) {
            $this->write($p['definition'], $p['site_id'], $p['value']);
        }

        foreach ($byGroup as $slot => $items) {
            $first = $items->first();
            $group = $this->registry->group($first['definition']->group);
            $changeIds = $items->map(fn (array $p): int => (int) MedicationSettingChange::query()->create([
                'setting_group' => $p['definition']->group,
                'setting_key' => $p['definition']->key,
                'site_id' => $p['site_id'],
                'action' => $action,
                'view' => $group->view,
                'section' => $p['definition']->section,
                'label' => $p['definition']->label,
                'before_value' => $p['before'],
                'after_value' => $p['value'],
                'before_text' => $action === MedicationSettingChange::ACTION_KEPT ? self::NOT_YET_REVIEWED : $p['definition']->format($p['before']),
                'after_text' => $action === MedicationSettingChange::ACTION_KEPT ? 'Kept: '.$p['definition']->format($p['value']) : $p['definition']->format($p['value']),
                'loosens' => $p['loosens'],
                'actor_id' => $actor->id,
                'audit_event' => $group->auditEvent,
            ])->id)->values()->all();

            AuditLogger::logOrFail($group->auditEvent, null, [
                'actor_id' => $actor->id,
                'action' => $action,
                'site_id' => $first['site_id'],
                'before' => $before[$slot],
                'after' => $this->groupSnapshot($group->key, $first['site_id']),
                'changed' => $items->map(fn (array $p): string => $p['definition']->key)->values()->all(),
                'loosened' => $items->where('loosens', true)->map(fn (array $p): string => $p['definition']->key)->values()->all(),
                'change_ids' => $changeIds,
            ]);
        }

        $revision->update(['value' => ((int) $revision->value) + 1]);
    }

    private function write(MedicationSettingDefinition $definition, ?int $siteId, string $value): void
    {
        if ($definition->isSiteScoped()) {
            MedicationSiteSetting::query()->updateOrCreate(
                ['site_id' => $siteId, 'key' => $definition->storageKey],
                ['value' => $value],
            );

            return;
        }

        AppSetting::query()->updateOrCreate(['key' => $definition->storageKey], ['value' => $value]);
    }

    /** Serialise every Medication Settings write behind one row. */
    private function lockForWrite(): AppSetting
    {
        $row = AppSetting::query()->where('key', self::REVISION_KEY)->lockForUpdate()->first();
        if ($row instanceof AppSetting) {
            return $row;
        }

        AppSetting::query()->insertOrIgnore([
            'key' => self::REVISION_KEY,
            'value' => json_encode(0),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return AppSetting::query()->where('key', self::REVISION_KEY)->lockForUpdate()->firstOrFail();
    }

    /**
     * Current stored values of the settings about to change, read under lock
     * so they are the latest committed values.
     *
     * @param  list<array{definition: MedicationSettingDefinition, site_id: int|null}>  $items
     * @return array<string, array{exists: bool, value: string}>
     */
    private function lockedCurrent(array $items): array
    {
        $items = collect($items);
        $organisation = $items->reject(fn (array $item): bool => $item['definition']->isSiteScoped());
        $site = $items->filter(fn (array $item): bool => $item['definition']->isSiteScoped());

        $orgRows = $organisation->isEmpty() ? collect() : AppSetting::query()
            ->whereIn('key', $organisation->map(fn (array $item): string => $item['definition']->storageKey)->unique()->all())
            ->orderBy('key')
            ->lockForUpdate()
            ->get()
            ->keyBy('key');
        $siteRows = $site->isEmpty() ? collect() : MedicationSiteSetting::query()
            ->whereIn('site_id', $site->pluck('site_id')->unique()->all())
            ->whereIn('key', $site->map(fn (array $item): string => $item['definition']->storageKey)->unique()->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (MedicationSiteSetting $row): string => $row->site_id.'|'.$row->key);

        return $items->mapWithKeys(function (array $item) use ($orgRows, $siteRows): array {
            $definition = $item['definition'];
            $row = $definition->isSiteScoped()
                ? $siteRows->get($item['site_id'].'|'.$definition->storageKey)
                : $orgRows->get($definition->storageKey);

            return [$this->slot($definition, $item['site_id']) => [
                'exists' => $row !== null,
                'value' => $definition->normalise($row?->value),
            ]];
        })->all();
    }

    /**
     * Every value of one group, read as the latest committed values.
     *
     * @return array<string, string>
     */
    private function groupSnapshot(string $groupKey, ?int $siteId): array
    {
        $definitions = collect($this->registry->group($groupKey)?->definitions ?? [])
            ->filter(fn (MedicationSettingDefinition $d): bool => $d->isSiteScoped() === ($siteId !== null))
            ->keyBy('storageKey');
        $stored = $siteId === null
            ? AppSetting::query()->whereIn('key', $definitions->keys()->all())->sharedLock()->pluck('value', 'key')
            : MedicationSiteSetting::query()->where('site_id', $siteId)->whereIn('key', $definitions->keys()->all())->sharedLock()->pluck('value', 'key');

        return $definitions
            ->mapWithKeys(fn (MedicationSettingDefinition $d): array => [$d->key => $d->normalise($stored[$d->storageKey] ?? null)])
            ->all();
    }

    private function conflict(MedicationSettingDefinition $definition, ?int $siteId): ValidationException
    {
        $latest = MedicationSettingChange::query()
            ->with('actor:id,name')
            ->where('setting_group', $definition->group)
            ->where('setting_key', $definition->key)
            ->when($siteId === null, fn ($query) => $query->whereNull('site_id'), fn ($query) => $query->where('site_id', $siteId))
            ->orderByDesc('id')
            ->sharedLock()
            ->first();
        $who = $latest?->actor?->name ?? 'Someone';
        $when = $latest?->created_at
            ? ' at '.$latest->created_at->copy()->timezone(config('app.worker_timezone', 'Pacific/Auckland'))->format('g:i a')
            : '';

        return ValidationException::withMessages([
            'conflict' => "{$who} saved a change in this view{$when}.",
        ]);
    }

    /**
     * The newest change for each setting, keyed by slot.
     *
     * @param  list<int>|null  $siteIds  Null for organisation settings.
     * @return Collection<string, MedicationSettingChange>
     */
    private function latestChanges(?array $siteIds): Collection
    {
        if ($siteIds === []) {
            return collect();
        }

        $latestIds = MedicationSettingChange::query()
            ->selectRaw('MAX(id) as id')
            ->when($siteIds === null, fn ($query) => $query->whereNull('site_id'), fn ($query) => $query->whereIn('site_id', $siteIds))
            ->groupBy('setting_group', 'setting_key', 'site_id')
            ->pluck('id');

        return MedicationSettingChange::query()
            ->with('actor:id,name')
            ->whereIn('id', $latestIds->all())
            ->get()
            ->keyBy(fn (MedicationSettingChange $change): string => $change->setting_group.'.'.$change->setting_key.'@'.($change->site_id ?? 'org'));
    }

    /** @return array{by: string|null, at: string|null} */
    private function reviewer(?MedicationSettingChange $change): array
    {
        return ['by' => $change?->actor?->name, 'at' => $change?->created_at?->toIso8601String()];
    }

    /**
     * @param  list<int>  $siteIds
     * @return Collection<int, Collection<int, array{definition: MedicationSettingDefinition, value: string}>>
     */
    private function siteRows(array $siteIds): Collection
    {
        $definitions = collect($this->registry->definitions())
            ->filter(fn (MedicationSettingDefinition $d): bool => $d->isSiteScoped())
            ->keyBy('storageKey');
        if ($siteIds === [] || $definitions->isEmpty()) {
            return collect();
        }

        return MedicationSiteSetting::query()
            ->whereIn('site_id', $siteIds)
            ->whereIn('key', $definitions->keys()->all())
            ->get()
            ->groupBy('site_id')
            ->map(fn (Collection $rows): Collection => $rows->map(fn (MedicationSiteSetting $row): array => [
                'definition' => $definitions[$row->key],
                'value' => $definitions[$row->key]->normalise($row->value),
            ])->values());
    }

    /** @return Collection<int, MedicationSettingDefinition> */
    private function organisationDefinitions(): Collection
    {
        return collect($this->registry->definitions())
            ->reject(fn (MedicationSettingDefinition $d): bool => $d->isSiteScoped())
            ->values();
    }

    /**
     * @template T
     *
     * @param  Collection<int, array{0: MedicationSettingDefinition, 1: T}>  $pairs
     * @return array<string, array<string, T>>
     */
    private function nest(Collection $pairs): array
    {
        $nested = [];
        foreach ($pairs as [$definition, $value]) {
            $nested[$definition->group][$definition->key] = $value;
        }

        return $nested;
    }

    private function slot(MedicationSettingDefinition $definition, ?int $siteId): string
    {
        return $definition->id().'@'.($siteId ?? 'org');
    }
}
