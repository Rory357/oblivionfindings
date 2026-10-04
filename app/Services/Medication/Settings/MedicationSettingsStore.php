<?php

namespace App\Services\Medication\Settings;

use App\Models\AppSetting;
use App\Models\MedicationAdminRule;
use App\Models\MedicationRoundTemplate;
use App\Models\MedicationSettingChange;
use App\Models\MedicationSiteSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Medication\Controlled\ControlledCountStatus;
use App\Services\Medication\Controlled\ControlledPolicy;
use App\Services\Medication\Controlled\WeeklyCountAnchorCodec;
use Carbon\CarbonImmutable;
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

    /** History entries for round templates use this group, keyed "template:{id}". */
    public const TEMPLATES_GROUP = 'round_templates';

    /** On-call contacts (P11 B2 chunk 4): saved straight away, one per house. */
    public const ONCALL_GROUP = 'oncall';

    public function __construct(private readonly MedicationSettingsRegistry $registry) {}

    /** @return array<string, array<string, string>> Saved organisation values, by group then key. */
    public function organisationValues(): array
    {
        $definitions = $this->organisationDefinitions();

        return $this->organisationValuesFrom($definitions, $this->organisationRows($definitions));
    }

    private function organisationRows(Collection $definitions): Collection
    {
        return AppSetting::query()
            ->whereIn('key', $definitions->pluck('storageKey')->all())
            ->pluck('value', 'key');
    }

    private function organisationValuesFrom(Collection $definitions, Collection $stored): array
    {
        $values = $this->nest($definitions->map(fn (MedicationSettingDefinition $d): array => [
            $d, $d->normalise($stored[$d->storageKey] ?? null),
        ]));
        if ($definitions->contains('group', 'ea')) {
            $values['ea'] = app(EmergencyAccessPolicySettings::class)->values();
        }

        return $values;
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
        return $this->siteValuesFrom($this->siteRows($siteIds));
    }

    private function siteValuesFrom(Collection $sites): array
    {
        return $sites
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

        return $this->reviewedFrom($definitions, $this->organisationRows($definitions));
    }

    private function reviewedFrom(Collection $definitions, Collection $stored): array
    {
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
        return $this->siteReviewedFrom($siteIds, $this->siteRows($siteIds));
    }

    private function siteReviewedFrom(array $siteIds, Collection $sites): array
    {
        $latest = $this->latestChanges($siteIds);

        return $sites
            ->map(fn (Collection $rows, int $siteId): array => $this->nest($rows->map(fn (array $row): array => [
                $row['definition'], $this->reviewer($latest->get($this->slot($row['definition'], $siteId))),
            ])))
            ->all();
    }

    /**
     * Read values and their review markers from the same saved rows for one
     * response. No cross-request cache: the next read sees subsequent saves.
     *
     * @param  list<int>  $siteIds
     * @return array{values: array, reviewed: array, site_values: array, site_reviewed: array}
     */
    public function valuesAndReviews(array $siteIds): array
    {
        $definitions = $this->organisationDefinitions();
        $stored = $this->organisationRows($definitions);
        $sites = $this->siteRows($siteIds);

        return [
            'values' => $this->organisationValuesFrom($definitions, $stored),
            'reviewed' => $this->reviewedFrom($definitions, $stored),
            'site_values' => $this->siteValuesFrom($sites),
            'site_reviewed' => $this->siteReviewedFrom($siteIds, $sites),
        ];
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
            $this->assertEmergencyPolicyConsistent($changes);

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

            $this->assertControlledCountPolicyConsistent($changes);

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
        }, 5);
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
        }, 5);
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
        bool $controlled = false,
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
            'controlled' => $controlled,
            'actor_id' => $actor->id,
            'audit_event' => $auditEvent,
        ]);
    }

    /**
     * Record an on-call contact saved or removed for a house (P11 B2 chunk 4),
     * at that house. The audit entry is written by the caller; it saves
     * straight away, so it isn't "put back" from the history.
     */
    public function recordOnCallChange(
        User $actor,
        int $siteId,
        string $label,
        string $before,
        string $after,
        bool $loosens,
        string $auditEvent,
    ): MedicationSettingChange {
        return MedicationSettingChange::query()->create([
            'setting_group' => self::ONCALL_GROUP,
            'setting_key' => 'oncall:'.$siteId,
            'site_id' => $siteId,
            'action' => MedicationSettingChange::ACTION_CHANGED,
            'view' => MedicationSettingsRegistry::VIEW_ALERTS,
            'section' => 'oncall',
            'label' => $label,
            'before_value' => null,
            'after_value' => null,
            'before_text' => $before,
            'after_text' => $after,
            'loosens' => $loosens,
            'controlled' => false,
            'actor_id' => $actor->id,
            'audit_event' => $auditEvent,
        ]);
    }

    /**
     * Record a round-template change (added, changed, paused, turned back on,
     * retired) in the change history at the template's house. The audit
     * entry is written by the caller; a template can't be "put back".
     */
    public function recordTemplateChange(
        User $actor,
        MedicationRoundTemplate $template,
        string $label,
        string $before,
        string $after,
        bool $loosens,
        string $auditEvent,
    ): MedicationSettingChange {
        return MedicationSettingChange::query()->create([
            'setting_group' => self::TEMPLATES_GROUP,
            'setting_key' => 'template:'.$template->id,
            'site_id' => $template->site_id,
            'action' => MedicationSettingChange::ACTION_CHANGED,
            'view' => MedicationSettingsRegistry::VIEW_ROUNDS,
            'section' => 'templates',
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
        return $this->latestChangesFor(self::RULES_GROUP, 'rule:', $ruleIds);
    }

    /**
     * The newest history entry for each round template, keyed by template id.
     *
     * @param  list<int>  $templateIds
     * @return Collection<int, MedicationSettingChange>
     */
    public function latestTemplateChanges(array $templateIds): Collection
    {
        return $this->latestChangesFor(self::TEMPLATES_GROUP, 'template:', $templateIds);
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, MedicationSettingChange>
     */
    private function latestChangesFor(string $group, string $prefix, array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }
        $keys = array_map(fn (int $id): string => $prefix.$id, $ids);
        $latestIds = MedicationSettingChange::query()
            ->selectRaw('MAX(id) as id')
            ->where('setting_group', $group)
            ->whereIn('setting_key', $keys)
            ->groupBy('setting_key')
            ->pluck('id');

        return MedicationSettingChange::query()
            ->with('actor:id,name')
            ->whereIn('id', $latestIds->all())
            ->get()
            ->keyBy(fn (MedicationSettingChange $change): int => (int) substr($change->setting_key, strlen($prefix)));
    }

    /**
     * The newest changes first, organisation-wide ones plus those at the
     * given houses (null = every house). For someone without
     * controlled-medicine access, a change to a rule that names a controlled
     * medicine — flagged when written, or a rule that does now — keeps only
     * who, when and where.
     *
     * @param  list<int>|null  $siteIds
     * @param  list<int>  $controlledRuleIds
     * @return Collection<int, array<string, mixed>>
     */
    public function history(?array $siteIds, int $limit = 500, bool $canSeeControlled = true, array $controlledRuleIds = []): Collection
    {
        $concealedKeys = array_flip(array_map(fn (int $id): string => 'rule:'.$id, $controlledRuleIds));

        return MedicationSettingChange::query()
            ->with(['actor:id,name', 'site:id,name'])
            ->when($siteIds !== null, fn ($query) => $query->where(fn ($scope) => $scope
                ->whereNull('site_id')
                ->orWhereIn('site_id', $siteIds)))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (MedicationSettingChange $change): array => $this->concealIfNeeded([
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
                'concealed' => false,
            ], ! $canSeeControlled && ($change->controlled || str_starts_with($change->setting_group, 'controlled_') || ($change->setting_group === self::RULES_GROUP && isset($concealedKeys[$change->setting_key])))));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function concealIfNeeded(array $row, bool $conceal): array
    {
        if (! $conceal) {
            return $row;
        }

        return [
            ...$row,
            'label' => 'Controlled-medicine rule',
            'before_text' => '',
            'after_text' => 'Details need controlled-medicine access',
            'note' => null,
            'concealed' => true,
        ];
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

        $eventChanges = [];
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

            array_push($eventChanges, ...$items->values()->map(fn (array $p, int $i): array => [
                'site_id' => $p['site_id'], 'group' => $p['definition']->group, 'key' => $p['definition']->key, 'change_id' => $changeIds[$i],
            ])->all());

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
        // Last operation: failures roll back values, review markers, history and audits.
        app(MedicationSettingsEvents::class)->settings($actor, $action, (int) $revision->value, $eventChanges);
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
        if ($definition->group === 'ea') {
            app(EmergencyAccessPolicySettings::class)->write($definition->key, $value);
        }
    }

    private function assertControlledCountPolicyConsistent(array $changes): void
    {
        $countChanges = array_filter($changes, fn (array $change): bool => $change['definition']->group === 'controlled_counts');
        if ($countChanges === []) {
            return;
        }
        // The global revision lock already serialises the complete prospective group.
        $before = $this->groupSnapshot('controlled_counts', null);
        $after = $before;
        foreach ($countChanges as $change) {
            $after[$change['definition']->key] = $change['value'];
        }
        $codec = new WeeklyCountAnchorCodec(config('app.worker_timezone', 'Pacific/Auckland'));
        if ($after['cadence'] === 'week' && $codec->decode($after['weekly_anchor']) === null) {
            throw ValidationException::withMessages([
                'controlled_counts.weekly_anchor' => 'Choose a weekly count day and time before saving weekly counts.',
            ]);
        }
        $anchor = $codec->decode($before['weekly_anchor']);
        if ($before['cadence'] === 'week' && $anchor !== null
            && ($before['weekly_anchor'] !== $after['weekly_anchor'] || $before['cadence'] !== $after['cadence'])) {
            $effectiveAt = AppSetting::query()->whereIn('key', [ControlledPolicy::COUNT_CADENCE, ControlledPolicy::COUNT_WEEKLY_ANCHOR])
                ->sharedLock()->get(['updated_at'])->max('updated_at');
            if (! app(ControlledCountStatus::class)->hasUnfinishedWeeklyCounts($anchor,
                $effectiveAt === null ? null : CarbonImmutable::instance($effectiveAt), (int) $before['overdue_minutes'])) {
                return;
            }
            // A timing change cannot erase already-due witnessed-count obligations.
            // Keep this generic: publication authority does not expose person details.
            throw ValidationException::withMessages([
                'controlled_counts.weekly_anchor' => 'Finish the due weekly witnessed counts before changing count timing.',
            ]);
        }
    }

    private function assertEmergencyPolicyConsistent(array $changes): void
    {
        if (! collect($changes)->contains(fn (array $c): bool => $c['definition']->group === 'ea')) {
            return;
        }
        $policy = app(EmergencyAccessPolicySettings::class)->values(true);
        foreach ($changes as $change) {
            if ($change['definition']->group === 'ea') {
                $policy[$change['definition']->key] = $change['value'];
            }
        }
        if ((int) $policy['default_minutes'] > (int) $policy['max_minutes']) {
            throw ValidationException::withMessages(['ea.default_minutes' => 'A grant cannot last longer than the longest time in all.']);
        }
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

        $policy = $items->contains(fn (array $item): bool => $item['definition']->group === 'ea') ? app(EmergencyAccessPolicySettings::class)->values(true) : [];

        return $items->mapWithKeys(function (array $item) use ($orgRows, $siteRows, $policy): array {
            $definition = $item['definition'];
            $row = $definition->isSiteScoped()
                ? $siteRows->get($item['site_id'].'|'.$definition->storageKey)
                : $orgRows->get($definition->storageKey);

            return [$this->slot($definition, $item['site_id']) => [
                'exists' => $row !== null,
                'value' => $definition->group === 'ea' ? $policy[$definition->key] : $definition->normalise($row?->value),
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
        if ($groupKey === 'ea') {
            return app(EmergencyAccessPolicySettings::class)->values(true);
        }
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
