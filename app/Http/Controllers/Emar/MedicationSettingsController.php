<?php

namespace App\Http\Controllers\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\PeopleMutationLockService;
use App\Http\Controllers\Controller;
use App\Models\MedicationAdminRule;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationSafetyPolicySettings;
use App\Services\Medication\WitnessPinService;
use App\Services\Medication\WitnessPinSettings;
use App\Services\MedicationRuleService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Facility medication administration rules (1CHART §6.1).
 *
 * Lets clinical managers define rules so that any medication whose name / route /
 * NZULM code matches a keyword will, at the point of administration, prompt for a
 * countersignature and/or require a clinical observation (BSL, pulse, BP) — without
 * a code change. Enforcement lives in MedicationRuleService + EnhancedMarService;
 * this controller is the authoring surface the plan (PR 4) was missing.
 */
class MedicationSettingsController extends Controller
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly MedicationRuleService $ruleService,
        private readonly PeopleMutationLockService $peopleLocks,
        private readonly MedicationSafetyPolicySettings $safetyPolicy,
        private readonly WitnessPinSettings $witnessPinSettings,
        private readonly WitnessPinService $witnessPins,
    ) {}

    /** Request field → app_settings key for the witness PIN rules (PIN-1). */
    private const WITNESS_PIN_FIELDS = [
        'max_attempts' => WitnessPinSettings::MAX_ATTEMPTS,
        'lockout_minutes' => WitnessPinSettings::LOCKOUT_MINUTES,
        'renewal_months' => WitnessPinSettings::RENEWAL_MONTHS,
    ];

    private const WITNESS_PIN_STAFF_LIMIT = 500;

    /** Permissions that make someone a possible second person (witness, co-signer, read-back, waiver). */
    private const SECOND_PERSON_PERMISSIONS = [
        'medications.controlled.witness',
        'medications.administer.record',
        'medications.orders.verify',
    ];

    /**
     * Witness PIN rules apply at every Site: organisation-wide eMAR settings
     * managers only, audited, like the safety rules.
     */
    public function updateWitnessPinRules(Request $request)
    {
        $actor = $request->user();
        abort_unless($this->canManageSettings($actor) && $this->canManageGlobalRules($actor), 403);

        $validated = $request->validate(collect(self::WITNESS_PIN_FIELDS)
            ->mapWithKeys(fn (string $key, string $field) => [
                $field => ['required', 'string', Rule::in(WitnessPinSettings::OPTIONS[$key])],
            ])
            ->all());

        DB::transaction(function () use ($actor, $validated): void {
            $lockedActor = $this->lockCurrentRuleActor($actor);
            abort_unless($this->canManageGlobalRules($lockedActor), 403);

            $before = $this->witnessPinSettings->all();
            $this->witnessPinSettings->save(collect(self::WITNESS_PIN_FIELDS)
                ->mapWithKeys(fn (string $key, string $field) => [$key => $validated[$field]])
                ->all());

            AuditLogger::logOrFail('medications.witness_pin_rules.updated', null, [
                'actor_id' => $lockedActor->id,
                'before' => $this->witnessPinRequestValues($before),
                'after' => $this->witnessPinRequestValues($this->witnessPinSettings->all()),
            ]);
        }, 3);

        return redirect()->back()->with('success', 'Witness PIN rules saved.');
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
     * @param  array<string, string|bool>  $values  Keyed by app_settings key.
     * @return array<string, string|bool> Keyed by request field.
     */
    private function witnessPinRequestValues(array $values): array
    {
        return collect(self::WITNESS_PIN_FIELDS)
            ->mapWithKeys(fn (string $key, string $field) => [$field => $values[$key]])
            ->all();
    }

    /**
     * Request field → app_settings key for the organisation-wide safety rules
     * (EM-07 profile allergies, NF-03 competency). Short request names keep
     * dotted setting keys out of the validator.
     */
    private const SAFETY_POLICY_FIELDS = [
        'profile_allergy_match' => MedicationSafetyPolicySettings::PROFILE_ALLERGY_MATCH,
        'restricted_competency' => MedicationSafetyPolicySettings::RESTRICTED_COMPETENCY,
        'competency_areas' => MedicationSafetyPolicySettings::COMPETENCY_AREAS,
    ];

    /**
     * Organisation-wide medication safety rules. They apply to every Site, so
     * only an eMAR settings manager with organisation-wide authority may
     * change them; every change is audited.
     */
    public function updateSafetyPolicy(Request $request)
    {
        $actor = $request->user();
        abort_unless($this->canManageSettings($actor) && $this->canManageGlobalRules($actor), 403);

        $validated = $request->validate(collect(self::SAFETY_POLICY_FIELDS)
            ->mapWithKeys(fn (string $key, string $field) => [
                $field => ['required', 'string', Rule::in(MedicationSafetyPolicySettings::OPTIONS[$key])],
            ])
            ->all());

        DB::transaction(function () use ($actor, $validated): void {
            $lockedActor = $this->lockCurrentRuleActor($actor);
            abort_unless($this->canManageGlobalRules($lockedActor), 403);

            $before = $this->safetyPolicy->all();
            $after = collect(self::SAFETY_POLICY_FIELDS)
                ->mapWithKeys(fn (string $key, string $field) => [$key => $validated[$field]])
                ->all();
            $this->safetyPolicy->save($after);

            AuditLogger::logOrFail('medications.safety_policy.updated', null, [
                'actor_id' => $lockedActor->id,
                'before' => $this->safetyPolicyRequestValues($before),
                'after' => $this->safetyPolicyRequestValues($this->safetyPolicy->all()),
            ]);
        }, 3);

        return redirect()->back()->with('success', 'Medication safety rules saved.');
    }

    /**
     * @template T of string|bool
     *
     * @param  array<string, T>  $values  Keyed by app_settings key.
     * @return array<string, T> Keyed by request field.
     */
    private function safetyPolicyRequestValues(array $values): array
    {
        return collect(self::SAFETY_POLICY_FIELDS)
            ->mapWithKeys(fn (string $key, string $field) => [$field => $values[$key]])
            ->all();
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
        abort_unless($this->canManageSettings($actor) || $canResetPins, 403);

        $witnessPin = [
            'values' => $this->witnessPinRequestValues($this->witnessPinSettings->all()),
            // false = still the shipped default, never deliberately saved.
            'reviewed' => $this->witnessPinRequestValues($this->witnessPinSettings->reviewed()),
            'can_manage' => $this->canManageSettings($actor) && $this->canManageGlobalRules($actor),
            'can_reset' => $canResetPins,
            'staff' => $this->witnessPinStaffRows($actor, $canResetPins),
        ];

        // House leads can reset staff PINs without managing medication rules:
        // they see only the second-person confirmation section.
        if (! $this->canManageSettings($actor)) {
            return Inertia::render('emar/Settings', [
                'witnessPin' => $witnessPin,
                'settingsAccess' => false,
                'safetyPolicy' => null,
                'rules' => [],
                'sites' => [],
                'observationOptions' => self::OBSERVATION_OPTIONS,
                'matchTypes' => self::MATCH_TYPES,
                'can' => ['manage' => false, 'manage_global' => false],
            ]);
        }

        $siteIds = $this->accessibleSiteIds($actor);
        $canManageGlobal = $this->canManageGlobalRules($actor);
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
            'witnessPin' => $witnessPin,
            'settingsAccess' => true,
            'safetyPolicy' => [
                'values' => $this->safetyPolicyRequestValues($this->safetyPolicy->all()),
                // false = still the default, never deliberately saved.
                'reviewed' => $this->safetyPolicyRequestValues($this->safetyPolicy->reviewed()),
                'can_manage' => $canManageGlobal,
            ],
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
