<?php

namespace App\Http\Controllers\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\PeopleMutationLockService;
use App\Http\Controllers\Controller;
use App\Models\MedicationAdminRule;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\Settings\MedicationSettingDefinition;
use App\Services\Medication\Settings\MedicationSettingsRegistry;
use App\Services\Medication\Settings\MedicationSettingsStore;
use App\Services\Medication\WitnessPinService;
use App\Services\MedicationRuleService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Medication › Settings (eMAR P11).
 *
 * Settings values (safety checks, witness PIN rules, …) are edited as a draft
 * and saved a view at a time through "Review changes"; every save and every
 * "Keep today's value" is recorded in the change history and the audit log
 * (MedicationSettingsStore).
 *
 * Facility medication administration rules (1CHART §6.1) let clinical
 * managers require a countersignature and/or a clinical observation (BSL,
 * pulse, BP) whenever a medicine's name / route / NZULM code matches — without
 * a code change. Enforcement lives in MedicationRuleService + EnhancedMarService.
 */
class MedicationSettingsController extends Controller
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly MedicationRuleService $ruleService,
        private readonly PeopleMutationLockService $peopleLocks,
        private readonly WitnessPinService $witnessPins,
        private readonly MedicationSettingsRegistry $settingsRegistry,
        private readonly MedicationSettingsStore $settingsStore,
    ) {}

    private const WITNESS_PIN_STAFF_LIMIT = 500;

    /** Permissions that make someone a possible second person (witness, co-signer, read-back, waiver). */
    private const SECOND_PERSON_PERMISSIONS = [
        'medications.controlled.witness',
        'medications.administer.record',
        'medications.orders.verify',
    ];

    /**
     * Save one Settings view's draft. Organisation settings need eMAR settings
     * management with all-Sites authority; a house setting needs authority
     * over that house. Each change names the saved value it was edited from,
     * so a change someone else saved meanwhile is never overwritten.
     */
    public function saveChanges(Request $request)
    {
        $actor = $request->user();
        abort_unless($this->canManageSettings($actor), 403);

        $validated = $request->validate([
            'view' => ['required', 'string', Rule::in($this->settingsRegistry->views())],
            'changes' => ['required', 'array', 'min:1', 'max:200'],
            'changes.*.group' => ['required', 'string', 'max:40'],
            'changes.*.key' => ['required', 'string', 'max:80'],
            'changes.*.site_id' => ['nullable', 'integer'],
            'changes.*.value' => ['present', 'nullable', 'string', 'max:255'],
            'changes.*.from' => ['present', 'nullable', 'string', 'max:255'],
            'confirm_loosening' => ['sometimes', 'boolean'],
        ]);

        $changes = $this->resolveSettings($validated['changes'], 'changes', $validated['view']);
        foreach ($changes as $index => $change) {
            if (! $change['definition']->accepts($change['value'])) {
                throw ValidationException::withMessages([
                    "changes.{$index}.value" => 'Choose one of the listed values for “'.$change['definition']->label.'”.',
                ]);
            }
        }
        $this->assertSettingsAuthority($actor, $changes);

        $result = DB::transaction(function () use ($actor, $changes, $validated): array {
            $lockedActor = $this->lockCurrentRuleActor($actor);
            $this->assertCurrentSettingsAuthority($lockedActor, $changes);

            return $this->settingsStore->apply($lockedActor, $changes, (bool) ($validated['confirm_loosening'] ?? false));
        }, 3);

        $message = $result['saved'] === 0
            ? 'Nothing was saved — these settings already had those values.'
            : sprintf('%d %s saved. %s.', $result['saved'], $result['saved'] === 1 ? 'change' : 'changes', $result['effect']);

        return redirect()->back()->with('medication_settings_saved', $message);
    }

    /**
     * "Keep today's value": confirm defaults nobody has reviewed, one at a
     * time or from the "Review the defaults" walkthrough. Nothing about how
     * doses are recorded changes; each is recorded and audited.
     */
    public function keepDefaults(Request $request)
    {
        $actor = $request->user();
        abort_unless($this->canManageSettings($actor), 403);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.group' => ['required', 'string', 'max:40'],
            'items.*.key' => ['required', 'string', 'max:80'],
            'items.*.site_id' => ['nullable', 'integer'],
        ]);

        $items = $this->resolveSettings($validated['items'], 'items');
        $this->assertSettingsAuthority($actor, $items);

        $kept = DB::transaction(function () use ($actor, $items): int {
            $lockedActor = $this->lockCurrentRuleActor($actor);
            $this->assertCurrentSettingsAuthority($lockedActor, $items);

            return $this->settingsStore->keep($lockedActor, $items);
        }, 3);

        $message = $kept === 1
            ? 'Kept today’s value for “'.$items[0]['definition']->label.'”. It now shows as reviewed.'
            : $kept.' values kept. They now show as reviewed, and each is in the change history.';

        return redirect()->back()->with('medication_settings_saved', $message);
    }

    /**
     * Match each requested setting to its definition: it must exist, belong to
     * the view being saved, name a house only when it is a house setting, and
     * appear once.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{definition: MedicationSettingDefinition, site_id: int|null, value: string, from: string}>
     */
    private function resolveSettings(array $rows, string $field, ?string $view = null): array
    {
        $seen = [];
        $resolved = [];
        foreach (array_values($rows) as $index => $row) {
            $definition = $this->settingsRegistry->definition((string) $row['group'], (string) $row['key']);
            $siteId = isset($row['site_id']) ? (int) $row['site_id'] : null;
            $group = $definition ? $this->settingsRegistry->group($definition->group) : null;
            if (! $definition
                || ($view !== null && $group?->view !== $view)
                || $definition->isSiteScoped() !== ($siteId !== null)) {
                throw ValidationException::withMessages([
                    "{$field}.{$index}.key" => 'This setting can’t be changed here.',
                ]);
            }
            $slot = $definition->id().'@'.($siteId ?? 'org');
            if (isset($seen[$slot])) {
                throw ValidationException::withMessages([
                    "{$field}.{$index}.key" => '“'.$definition->label.'” is listed twice.',
                ]);
            }
            $seen[$slot] = true;
            $resolved[] = [
                'definition' => $definition,
                'site_id' => $siteId,
                'value' => (string) ($row['value'] ?? ''),
                'from' => (string) ($row['from'] ?? ''),
            ];
        }

        return $resolved;
    }

    /** @param  list<array{definition: MedicationSettingDefinition, site_id: int|null}>  $items */
    private function assertSettingsAuthority(User $actor, array $items): void
    {
        if (collect($items)->contains(fn (array $item): bool => ! $item['definition']->isSiteScoped())) {
            abort_unless($this->canManageGlobalRules($actor), 403);
        }
    }

    /**
     * Re-check authority with the actor and the houses locked, inside the save.
     *
     * @param  list<array{definition: MedicationSettingDefinition, site_id: int|null}>  $items
     */
    private function assertCurrentSettingsAuthority(User $lockedActor, array $items): void
    {
        $this->assertSettingsAuthority($lockedActor, $items);
        $siteIds = collect($items)->pluck('site_id')->filter()->unique()->values()->all();
        if ($siteIds === []) {
            return;
        }
        $lockedSites = $this->lockCurrentRuleSites($siteIds);
        foreach ($siteIds as $siteId) {
            $this->assertCurrentRuleSite($lockedActor, (int) $siteId, $lockedSites, false);
        }
    }

    /**
     * Reset another person's witness PIN: they must choose a new one before
     * they can witness or co-sign. Nobody sees the old or new PIN. Audited.
     */
    public function resetWitnessPin(Request $request, User $user)
    {
        $actor = $request->user();
        abort_unless($actor?->canDo('medications.witness_pin.reset'), 403);
        abort_if((int) $user->id === (int) $actor->id, 403);

        $target = $this->witnessPinStaffQuery($actor)?->whereKey($user->id)->first(['id', 'name', 'approved_at', 'role']);
        abort_unless($target !== null && $this->isSecondPerson($target), 404);
        abort_unless($this->canResetPinOf($actor, $target), 403);

        $this->witnessPins->resetByAdmin($user, $actor);

        return redirect()->back()->with('success', $user->name.'’s witness PIN was reset. They must choose a new one before they can co-sign or witness.');
    }

    /**
     * Staff in the actor's approved Sites who may be chosen as a second person,
     * filtered by permission before the cap so the list isn't cut short.
     *
     * @return Collection<int, User>
     */
    private function witnessPinStaff(User $actor): Collection
    {
        return $this->witnessPinStaffQuery($actor)
            ?->lazy(200)
            ->filter(fn (User $user): bool => $this->isSecondPerson($user))
            ->take(self::WITNESS_PIN_STAFF_LIMIT)
            ->collect()
            ->values() ?? collect();
    }

    /** Null when the actor can see no Site's staff. */
    private function witnessPinStaffQuery(User $actor): ?Builder
    {
        $query = User::query()->staff()->whereNotNull('approved_at')->orderBy('name')->orderBy('id')
            ->select(['id', 'name', 'approved_at', 'role']);
        if (! $this->canManageGlobalRules($actor)) {
            $siteIds = $this->siteAccess->accessibleSiteIds($actor, MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS);
            if ($siteIds === []) {
                return null;
            }
            $query->where(function (Builder $scoped) use ($siteIds): void {
                foreach ($siteIds as $siteId) {
                    $scoped->orWhere(fn (Builder $site) => $this->siteAccess->applyFleetRecipientEligibility($site, (int) $siteId));
                }
            });
        }

        return $query;
    }

    /**
     * Status-only rows for the staff PIN list (never the actor), each saying
     * whether this actor may reset that person's PIN.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function witnessPinStaffRows(User $actor, bool $canResetPins): Collection
    {
        $staff = $this->witnessPinStaff($actor)
            ->reject(fn (User $user): bool => (int) $user->id === (int) $actor->id)
            ->values();
        $resettable = $canResetPins
            ? $staff->filter(fn (User $user): bool => $this->canResetPinOf($actor, $user))->pluck('id')->flip()
            : collect();

        return $this->witnessPins->statusRows($staff)
            ->map(fn (array $row): array => $row + ['can_reset' => $resettable->has($row['id'])])
            ->values();
    }

    private function isSecondPerson(User $user): bool
    {
        return collect(self::SECOND_PERSON_PERMISSIONS)->contains(fn (string $key): bool => $user->canDo($key));
    }

    /**
     * Organisation-wide leads may reset anyone in their list. A house lead may
     * not reset someone with broader authority than theirs: another PIN
     * resetter, an eMAR settings manager or an all-Sites user.
     */
    private function canResetPinOf(User $actor, User $target): bool
    {
        if ($this->canManageGlobalRules($actor)) {
            return true;
        }

        return ! collect([
            'medications.witness_pin.reset',
            'medications.settings.manage',
            ...MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
        ])->contains(fn (string $key): bool => $target->canDo($key));
    }

    /**
     * Observation tokens must match EnhancedMarService::validateRequiredObservations().
     */
    public const OBSERVATION_OPTIONS = [
        ['value' => 'blood_glucose', 'label' => 'Blood glucose (BSL)'],
        ['value' => 'pulse', 'label' => 'Pulse rate'],
        ['value' => 'blood_pressure', 'label' => 'Blood pressure'],
    ];

    public const MATCH_TYPES = [
        ['value' => 'medicine_name', 'label' => 'Medicine name'],
        ['value' => 'route', 'label' => 'Route'],
        ['value' => 'nzulm_code', 'label' => 'NZULM code'],
    ];

    public function index(Request $request)
    {
        $actor = $request->user();
        $canResetPins = (bool) $actor?->canDo('medications.witness_pin.reset');
        $canManage = $this->canManageSettings($actor);
        abort_unless($canManage || $canResetPins, 403);

        $canManageGlobal = $this->canManageGlobalRules($actor);
        $siteIds = $canManage ? $this->accessibleSiteIds($actor) : [];
        $settings = [
            ...$this->settingsRegistry->toClient(),
            'values' => $this->settingsStore->organisationValues(),
            // null = still the default; nobody has deliberately saved or kept it.
            'reviewed' => $this->settingsStore->reviewed(),
            'site_values' => $this->settingsStore->siteValues($siteIds),
            'site_reviewed' => $this->settingsStore->siteReviewed($siteIds),
            // Organisation-wide changes, plus changes at the houses this person can see.
            'history' => $canManage ? $this->settingsStore->history($canManageGlobal ? null : $siteIds) : [],
            'can_manage_organisation' => $canManage && $canManageGlobal,
        ];
        $witnessPin = [
            'can_reset' => $canResetPins,
            'staff' => $this->witnessPinStaffRows($actor, $canResetPins),
        ];

        // House leads can reset staff PINs without managing medication rules:
        // they see only Staff & PINs, read-only apart from resets.
        if (! $canManage) {
            return Inertia::render('emar/Settings', [
                'settings' => $settings,
                'witnessPin' => $witnessPin,
                'settingsAccess' => false,
                'rules' => [],
                'sites' => [],
                'observationOptions' => self::OBSERVATION_OPTIONS,
                'matchTypes' => self::MATCH_TYPES,
                'can' => ['manage' => false, 'manage_global' => false],
            ]);
        }

        $rules = $this->visibleRulesQuery($actor, $siteIds, $canManageGlobal)
            ->with(['site:id,name', 'creator:id,name'])
            ->orderByDesc('active')
            ->orderBy('match_type')
            ->orderBy('match_value')
            ->get()
            ->map(fn (MedicationAdminRule $rule) => [
                'id' => $rule->id,
                'site_id' => $rule->site_id,
                'site_name' => $rule->site?->name,
                'match_type' => $rule->match_type,
                'match_value' => $rule->match_value,
                'requires_countersign' => $rule->requires_countersign,
                'required_observations' => $rule->required_observations ?? [],
                'active' => $rule->active,
                'created_by' => $rule->creator?->name,
                'created_at' => $rule->created_at?->toDateString(),
            ]);

        return Inertia::render('emar/Settings', [
            'settings' => $settings,
            'witnessPin' => $witnessPin,
            'settingsAccess' => true,
            'rules' => $rules,
            'sites' => Site::query()
                ->whereIn('id', $siteIds)
                ->orderBy('name')
                ->get(['id', 'name']),
            'observationOptions' => self::OBSERVATION_OPTIONS,
            'matchTypes' => self::MATCH_TYPES,
            'can' => [
                'manage' => $canManageGlobal || $siteIds !== [],
                'manage_global' => $canManageGlobal,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $actor = $request->user();
        abort_unless($this->canManageSettings($actor), 403);

        $siteIds = $this->accessibleSiteIds($actor);
        $validated = $this->validateRule($request, $siteIds);
        $this->assertCanUseRuleSite($actor, $validated['site_id']);

        DB::transaction(function () use ($actor, $validated): void {
            $this->ruleService->lockRuleSet();
            $lockedActor = $this->lockCurrentRuleActor($actor);
            $lockedSites = $this->lockCurrentRuleSites([$validated['site_id']]);
            $this->assertCurrentRuleSite($lockedActor, $validated['site_id'], $lockedSites, false);

            MedicationAdminRule::create([
                ...$validated,
                'created_by' => $lockedActor->id,
            ]);
        }, 3);

        return redirect()->back()->with('success', 'Medication administration rule added.');
    }

    public function update(Request $request, MedicationAdminRule $rule)
    {
        $actor = $request->user();
        abort_unless($this->canManageSettings($actor), 403);
        $siteIds = $this->accessibleSiteIds($actor);
        $validated = $this->validateRule($request, $siteIds);

        DB::transaction(function () use ($actor, $rule, $validated): void {
            $rules = $this->ruleService->lockRuleSet();
            /** @var MedicationAdminRule|null $lockedRule */
            $lockedRule = $rules->get((int) $rule->getKey());
            abort_unless($lockedRule instanceof MedicationAdminRule, 404);
            $lockedActor = $this->lockCurrentRuleActor($actor);
            $lockedSites = $this->lockCurrentRuleSites([
                $lockedRule->site_id,
                $validated['site_id'],
            ]);
            $this->assertCurrentRuleSite($lockedActor, $lockedRule->site_id, $lockedSites, true);
            $this->assertCurrentRuleSite($lockedActor, $validated['site_id'], $lockedSites, false);
            $lockedRule->update($validated);
        }, 3);

        return redirect()->back()->with('success', 'Medication administration rule updated.');
    }

    public function destroy(Request $request, MedicationAdminRule $rule)
    {
        $actor = $request->user();
        abort_unless($this->canManageSettings($actor), 403);

        DB::transaction(function () use ($actor, $rule): void {
            $rules = $this->ruleService->lockRuleSet();
            /** @var MedicationAdminRule|null $lockedRule */
            $lockedRule = $rules->get((int) $rule->getKey());
            abort_unless($lockedRule instanceof MedicationAdminRule, 404);
            $lockedActor = $this->lockCurrentRuleActor($actor);
            $lockedSites = $this->lockCurrentRuleSites([$lockedRule->site_id]);
            $this->assertCurrentRuleSite($lockedActor, $lockedRule->site_id, $lockedSites, true);
            $lockedRule->delete();
        }, 3);

        return redirect()->back()->with('success', 'Medication administration rule removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRule(Request $request, array $siteIds): array
    {
        $validated = $request->validate([
            'site_id' => ['nullable', 'integer', Rule::in($siteIds)],
            'match_type' => ['required', 'string', Rule::in(['medicine_name', 'route', 'nzulm_code'])],
            'match_value' => ['required', 'string', 'max:255'],
            'requires_countersign' => ['nullable', 'boolean'],
            'required_observations' => ['nullable', 'array'],
            'required_observations.*' => ['string', Rule::in(['blood_glucose', 'pulse', 'blood_pressure'])],
            'active' => ['nullable', 'boolean'],
        ]);

        return [
            'site_id' => $validated['site_id'] ?? null,
            'match_type' => $validated['match_type'],
            'match_value' => trim($validated['match_value']),
            'requires_countersign' => (bool) ($validated['requires_countersign'] ?? false),
            'required_observations' => array_values(array_unique($validated['required_observations'] ?? [])),
            'active' => (bool) ($validated['active'] ?? true),
        ];
    }

    /**
     * @param  array<int, int>  $siteIds
     * @return Builder<MedicationAdminRule>
     */
    private function visibleRulesQuery(
        User $actor,
        array $siteIds,
        ?bool $canManageGlobal = null,
    ): Builder {
        $canManageGlobal ??= $this->canManageGlobalRules($actor);

        return MedicationAdminRule::query()
            ->where(function (Builder $scope) use ($canManageGlobal, $siteIds): void {
                $scope->whereIn('site_id', $siteIds);
                if ($canManageGlobal) {
                    $scope->orWhereNull('site_id');
                }
            });
    }

    /** @return array<int, int> */
    private function accessibleSiteIds(User $actor): array
    {
        return $this->siteAccess->accessibleSiteIds(
            $actor,
            MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
        );
    }

    private function assertCanUseRuleSite(User $actor, ?int $siteId): void
    {
        if ($siteId === null) {
            abort_unless($this->canManageGlobalRules($actor), 403);
        }
    }

    private function canManageGlobalRules(User $actor): bool
    {
        return $this->siteAccess->canBypass(
            $actor,
            MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
        );
    }

    private function canManageSettings(?User $user): bool
    {
        return (bool) $user && $user->canDo('medications.settings.manage');
    }

    private function lockCurrentRuleActor(User $actor): User
    {
        $locks = $this->peopleLocks->lock([(int) $actor->id]);
        /** @var User|null $lockedActor */
        $lockedActor = $locks['users']->get((int) $actor->id);
        abort_unless(
            $lockedActor instanceof User
                && $lockedActor->approved_at !== null
                && $lockedActor->canDo('medications.settings.manage'),
            403,
        );
        $profile = $lockedActor->hrEmployeeProfile;
        $clinicalDate = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
        abort_unless(
            $profile instanceof HrEmployeeProfile
                && $profile->is_active
                && ($profile->start_date === null || $profile->start_date->toDateString() <= $clinicalDate)
                && ($profile->end_date === null || $profile->end_date->toDateString() >= $clinicalDate),
            403,
        );

        return $lockedActor;
    }

    /** @param array<int, mixed> $siteIds @return Collection<int, Site> */
    private function lockCurrentRuleSites(array $siteIds): Collection
    {
        $ids = collect($siteIds)
            ->map(fn (mixed $siteId): int => (int) $siteId)
            ->filter(fn (int $siteId): bool => $siteId > 0)
            ->unique()
            ->sort()
            ->values();

        return Site::query()
            ->whereIn('id', $ids->all())
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id'])
            ->keyBy(fn (Site $site): int => (int) $site->id);
    }

    private function assertCurrentRuleSite(
        User $actor,
        ?int $siteId,
        Collection $lockedSites,
        bool $conceal,
    ): void {
        $deny = static function () use ($conceal): never {
            abort($conceal ? 404 : 403);
        };

        if ($siteId === null) {
            if (! $this->canManageGlobalRules($actor)) {
                $deny();
            }

            return;
        }
        if (! $lockedSites->has((int) $siteId)) {
            $deny();
        }
        if ($this->canManageGlobalRules($actor)) {
            return;
        }

        $profile = $actor->hrEmployeeProfile;
        if (! $profile instanceof HrEmployeeProfile
            || ! collect([
                $profile->primary_site_id,
                ...($profile->secondary_site_ids ?? []),
            ])->contains(fn (mixed $assignedSiteId): bool => (int) $assignedSiteId === (int) $siteId)) {
            $deny();
        }
    }
}
