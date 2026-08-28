<?php

namespace App\Http\Controllers\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\PeopleMutationLockService;
use App\Http\Controllers\Controller;
use App\Models\MedicationAdminRule;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
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
    ) {}

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
        abort_unless($this->canManageSettings($actor), 403);

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
