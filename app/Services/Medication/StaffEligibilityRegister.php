<?php

namespace App\Services\Medication;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationCompetencyExemption;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Settings\MedicationSettingsStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Safety & oversight › Staff eligibility (eMAR P11 chunk 6): each person's
 * medication competency as the app decides it, for the register, renewals,
 * the assessment viewer and the worker's own My eligibility.
 *
 * The status comes from MedicationAdministratorCompetencyPolicy — the same
 * decision recording a dose uses — plus the facts the page shows: the
 * assessment on file and its 12 area results, a newer assessment waiting for
 * acknowledgement, an active exemption, and the witness PIN status. Who can
 * witness is not worked out here: that rule is enforced with P07b.
 */
class StaffEligibilityRegister
{
    /** The 12 areas in v5's order: label, core, what checks it, description. */
    public const AREAS = [
        'medication_knowledge' => ['Medication knowledge', true, null, null],
        'five_rights' => ['The five rights', true, null, 'Right person, medicine, dose, time and route'],
        'safety_checks' => ['Safety checks', true, null, null],
        'documentation' => ['Documentation', true, null, null],
        'controlled_drugs' => ['Controlled drugs', false, 'area', null],
        'prn_assessment' => ['As-needed (PRN) assessment', false, null, null],
        'insulin_competent' => ['Insulin', false, 'notyet', null],
        'inhaler_competent' => ['Inhaler technique', false, null, null],
        'topical_competent' => ['Topical medicines', false, null, null],
        'covert_admin_knowledge' => ['Covert administration', false, 'area', null],
        'error_reporting' => ['Error reporting', true, null, null],
        'allergy_awareness' => ['Allergy awareness', true, null, null],
    ];

    public const TYPE_LABELS = [
        'initial' => 'First assessment',
        'annual' => 'Renewal',
        'remedial' => 'Remedial',
        'return_to_work' => 'Return to work',
    ];

    public function __construct(
        private readonly MedicationAdministratorCompetencyPolicy $policy,
        private readonly CompetencyPolicySettings $settings,
        private readonly WitnessPinService $witnessPins,
        private readonly HrCurrentStaffService $currentStaff,
    ) {}

    /**
     * Everyone at these houses who records doses, or has an assessment on
     * file, with their eligibility.
     *
     * @param  list<int>  $siteIds
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(array $siteIds): Collection
    {
        if ($siteIds === []) {
            return collect();
        }
        $users = $this->currentStaff->currentUsersQuery()
            ->with([
                'hrEmployeeProfile:id,user_id,primary_site_id,secondary_site_ids,start_date',
                'medicationCompetencyAssessments.assessor:id,name',
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'approved_at'])
            ->filter(fn (User $user): bool => collect($this->siteIdsOf($user))->intersect($siteIds)->isNotEmpty()
                && ($user->canDo('medications.administer.record') || $user->medicationCompetencyAssessments->isNotEmpty()))
            ->values();

        return $this->build($users);
    }

    /** One person's eligibility (My eligibility). */
    public function rowFor(User $user): array
    {
        // A fresh copy: loading the assessments onto the caller's User would
        // leave later competency checks on it reading this snapshot.
        $fresh = User::query()
            ->with([
                'hrEmployeeProfile:id,user_id,primary_site_id,secondary_site_ids,start_date',
                'medicationCompetencyAssessments.assessor:id,name',
            ])
            ->findOrFail($user->id);

        return $this->build(collect([$fresh]))->first();
    }

    /** Meds today's "My eligibility": the person, the rules, and an assessment waiting for them. */
    public function myEligibility(User $user): array
    {
        return [
            'person' => $this->rowFor($user),
            'policy' => $this->policy(),
            'areas' => $this->areas(),
            'pending' => app(CompetencyAcknowledgement::class)->pendingFor($user),
            'checked_at' => now($this->timezone())->toIso8601String(),
        ];
    }

    /** The organisation's competency and Safety checks rules the page words things with. */
    public function policy(): array
    {
        $safety = app(MedicationSafetyPolicySettings::class);
        $reviewed = app(MedicationSettingsStore::class)->reviewed()['elig'] ?? [];

        return [
            'pass_mark' => $this->settings->passMark(),
            'core_must_pass' => $this->settings->coreAreasMustPass(),
            'observed_minimum' => $this->settings->observedMinimum(),
            'validity_months' => $this->settings->validityMonths(),
            'renewal_days' => $this->settings->renewalReminderDays(),
            'longest_exemption_days' => $this->settings->longestExemptionDays(),
            // Saved or kept in Settings › Staff & PINs, or still the default.
            'longest_exemption_reviewed' => ($reviewed['longest_exemption'] ?? null) !== null,
            'validity_reviewed' => ($reviewed['validity'] ?? null) !== null,
            'restricted_mode' => $safety->restrictedCompetencyMode(),
            'area_mode' => $safety->competencyAreaEnforcement(),
        ];
    }

    /** @return list<array{key: string, label: string, core: bool, rule: string|null, description: string|null}> */
    public function areas(): array
    {
        return collect(self::AREAS)
            ->map(fn (array $a, string $key): array => ['key' => $key, 'label' => $a[0], 'core' => $a[1], 'rule' => $a[2], 'description' => $a[3]])
            ->values()
            ->all();
    }

    /**
     * Exemptions at these houses: active first, then the most recent.
     *
     * @param  list<int>  $siteIds
     * @return Collection<int, array<string, mixed>>
     */
    public function exemptions(array $siteIds): Collection
    {
        if ($siteIds === []) {
            return collect();
        }
        $timezone = $this->timezone();
        $now = now();

        return MedicationCompetencyExemption::query()
            ->with(['user:id,name,role', 'approver:id,name', 'revoker:id,name', 'site:id,name'])
            ->whereIn('site_id', $siteIds)
            ->orderByDesc('approved_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(function (MedicationCompetencyExemption $e) use ($timezone, $now): array {
                $status = $e->revoked_at !== null ? 'revoked' : ($e->expires_at !== null && $e->expires_at->lte($now) ? 'ended' : 'active');

                return [
                    'id' => (int) $e->id,
                    'user_id' => (int) $e->user_id,
                    'person' => $e->user?->name ?? 'Unknown',
                    'role' => $this->roleLabel($e->user?->role),
                    'house_id' => (int) $e->site_id,
                    'house' => $e->site?->name,
                    'reason' => (string) $e->reason,
                    'from' => $e->starts_at?->copy()->timezone($timezone)->toDateString(),
                    'until' => $e->expires_at?->copy()->timezone($timezone)->toDateString(),
                    'by' => $e->approver?->name,
                    'at' => $e->approved_at?->copy()->timezone($timezone)->toIso8601String(),
                    'status' => $status,
                    'ended_at' => $e->revoked_at?->copy()->timezone($timezone)->toIso8601String(),
                    'ended_by' => $e->revoker?->name,
                    'end_reason' => $e->revocation_reason,
                ];
            })
            ->sortBy(fn (array $e): int => $e['status'] === 'active' ? 0 : 1)
            ->values();
    }

    /**
     * @param  Collection<int, User>  $users
     * @return Collection<int, array<string, mixed>>
     */
    private function build(Collection $users): Collection
    {
        $ids = $users->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $pins = $this->witnessPins->statuses($ids);
        $houseIds = $users->map(fn (User $u): ?int => $u->hrEmployeeProfile?->primary_site_id ? (int) $u->hrEmployeeProfile->primary_site_id : null)->filter()->unique()->values()->all();
        $houses = Site::query()->whereIn('id', $houseIds)->pluck('name', 'id');
        $roles = Role::query()->pluck('label', 'name');
        $now = now();
        $today = CarbonImmutable::now($this->timezone())->startOfDay();
        $renewalDays = $this->settings->renewalReminderDays();

        $activeExemptions = MedicationCompetencyExemption::query()
            ->with(['approver:id,name', 'site:id,name'])
            ->whereIn('user_id', $ids)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $now)
            ->orderBy('expires_at')
            ->get()
            ->groupBy('user_id');

        return $users->map(function (User $user) use ($pins, $houses, $roles, $now, $today, $renewalDays, $activeExemptions): array {
            $houseId = $user->hrEmployeeProfile?->primary_site_id ? (int) $user->hrEmployeeProfile->primary_site_id : null;
            $decision = $this->policy->evaluate($user, $houseId, $now);
            $established = $decision['assessment_id'] !== null
                ? $user->medicationCompetencyAssessments->firstWhere('id', $decision['assessment_id'])
                : null;
            $declared = $user->medicationCompetencyAssessments
                ->filter(fn (MedicationCompetencyAssessment $a): bool => $a->assessor_declared_at !== null
                    && $a->assessor_id !== null
                    && (int) $a->assessor_id !== (int) $user->id
                    && $a->assessment_date !== null
                    && $a->assessment_date->toDateString() <= $today->toDateString())
                ->sortByDesc(fn (MedicationCompetencyAssessment $a): array => [$a->assessment_date->toDateString(), $a->id])
                ->first();
            $pending = $declared !== null
                && $declared->status === 'passed'
                && $declared->staff_acknowledged_at === null
                && ($declared->expiry_date === null || $declared->expiry_date->toDateString() >= $today->toDateString())
                ? $declared : null;

            // The person's state, then what the register shows (v5 effStatus).
            $valid = $decision['state'] === 'valid';
            $st = match (true) {
                $pending !== null => 'ack',
                $valid => $established?->restricted ? 'restricted' : 'current',
                $declared !== null && $declared->status !== 'passed' => 'failed',
                in_array($decision['state'], ['expired', 'missing_expiry'], true) => 'expired',
                default => 'none',
            };
            // With nothing that counts, an assessment the assessor never
            // declared is still shown (it never counts until they do).
            $shown = match ($st) {
                'ack' => $pending,
                'failed' => $declared,
                'none' => $user->medicationCompetencyAssessments
                    ->sortByDesc(fn (MedicationCompetencyAssessment $a): array => [$a->assessment_date?->toDateString() ?? '', $a->id])
                    ->first(),
                default => $established,
            };
            $prevValid = $st === 'ack' && $valid ? $decision['valid_until']?->toDateString() : null;
            $exemption = $activeExemptions->get($user->id)?->first();
            $until = $shown?->expiry_date?->toDateString();
            $days = $until !== null ? (int) $today->diffInDays(CarbonImmutable::parse($until, $this->timezone())->startOfDay(), false) : null;
            $status = match (true) {
                $exemption !== null && in_array($st, ['expired', 'none', 'failed', 'ack'], true) && $prevValid === null => 'exempt',
                $st === 'current' && $days !== null && $days <= $renewalDays => 'due',
                default => $st,
            };

            return [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'role' => $this->roleLabel($user->role, $roles),
                'house_id' => $houseId,
                'house' => $houseId !== null ? ($houses[$houseId] ?? null) : null,
                'started' => $user->hrEmployeeProfile?->start_date?->toDateString(),
                'records_doses' => $user->canDo('medications.administer.record'),
                'st' => $st,
                'status' => $status,
                'until' => $until,
                'days' => $days,
                'prev_valid' => $prevValid,
                'pin' => $pins[(int) $user->id] ?? WitnessPinService::STATUS_NOT_SET,
                'exemption' => $exemption ? [
                    'id' => (int) $exemption->id,
                    'house' => $exemption->site?->name,
                    'from' => $exemption->starts_at?->copy()->timezone($this->timezone())->toDateString(),
                    'until' => $exemption->expires_at?->copy()->timezone($this->timezone())->toDateString(),
                    'by' => $exemption->approver?->name,
                    'reason' => (string) $exemption->reason,
                ] : null,
                'assessment' => $shown ? $this->assessment($shown) : null,
                // Their other assessments, newest first (the old register listed every one).
                'history' => $user->medicationCompetencyAssessments
                    ->reject(fn (MedicationCompetencyAssessment $a): bool => $shown !== null && (int) $a->id === (int) $shown->id)
                    ->sortByDesc(fn (MedicationCompetencyAssessment $a): array => [$a->assessment_date?->toDateString() ?? '', $a->id])
                    ->take(10)
                    ->map(fn (MedicationCompetencyAssessment $a): array => [
                        'id' => (int) $a->id,
                        'type_label' => self::TYPE_LABELS[$a->assessment_type] ?? ucfirst(str_replace('_', ' ', (string) $a->assessment_type)),
                        'status' => (string) $a->status,
                        'assessed' => $a->assessment_date?->toDateString(),
                        'until' => $a->expiry_date?->toDateString(),
                        'assessor' => $a->assessor?->name,
                    ])
                    ->values()
                    ->all(),
            ];
        });
    }

    /** The assessment the register shows, with each area's result. */
    public function assessment(MedicationCompetencyAssessment $a): array
    {
        $notSeen = collect($a->not_seen_areas ?? [])->map(fn ($k): string => (string) $k)->all();
        $res = collect(self::AREAS)->keys()->mapWithKeys(fn (string $k): array => [
            $k => in_array($k, $notSeen, true) ? 'unseen' : ($a->{$k} ? 'yes' : 'no'),
        ])->all();

        return [
            'id' => (int) $a->id,
            'type' => (string) $a->assessment_type,
            'type_label' => self::TYPE_LABELS[$a->assessment_type] ?? ucfirst(str_replace('_', ' ', (string) $a->assessment_type)),
            'status' => (string) $a->status,
            'assessed' => $a->assessment_date?->toDateString(),
            'until' => $a->expiry_date?->toDateString(),
            'assessor' => $a->assessor?->name,
            'assessor_id' => $a->assessor_id ? (int) $a->assessor_id : null,
            'declared_at' => $a->assessor_declared_at?->copy()->timezone($this->timezone())->toDateString(),
            'acknowledged_at' => $a->staff_acknowledged_at?->copy()->timezone($this->timezone())->toDateString(),
            'res' => $res,
            'passed' => collect($res)->filter(fn (string $r): bool => $r === 'yes')->count(),
            'pass_threshold' => $a->pass_threshold ? (int) $a->pass_threshold : null,
            'restricted' => (bool) $a->restricted,
            'restriction_notes' => $a->restriction_notes,
            'can_witness' => (bool) $a->can_witness_controlled,
            'unsupervised' => (bool) $a->can_administer_unsupervised,
            'observed' => collect($a->observed_rounds ?? [])->values()->all(),
            'strengths' => $a->strengths,
            'to_work_on' => $a->areas_for_improvement,
            'action_plan' => $a->action_plan,
            'comments' => $a->assessor_comments,
        ];
    }

    /** @return list<int> */
    private function siteIdsOf(User $user): array
    {
        $profile = $user->hrEmployeeProfile;

        return collect([$profile?->primary_site_id, ...(is_array($profile?->secondary_site_ids) ? $profile->secondary_site_ids : [])])
            ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @param  Collection<string, string>|null  $roles */
    private function roleLabel(?string $role, ?Collection $roles = null): ?string
    {
        if ($role === null) {
            return null;
        }
        $roles ??= Role::query()->pluck('label', 'name');

        return $roles[$role] ?? ucfirst(str_replace('_', ' ', $role));
    }

    private function timezone(): string
    {
        return (string) config('app.worker_timezone', 'Pacific/Auckland');
    }
}
