<?php

namespace App\Services\Medication;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationCompetencyExemption;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Models\UserWitnessPin;
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
 * acknowledgement, an active exemption, and the witness PIN status.
 *
 * EA-156 (Lane C9h): each row also says whether the person can witness
 * controlled drugs, and why not, by the rule P07b enforces
 * (ControlledMedicationTransportWitnessService::qualification and
 * isValidWitnessAssessment, plus a usable witness PIN). Being on shift at the
 * house is checked when they witness; onShiftNow() shows who is, and asks
 * that service who it accepts right now.
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

        return $this->build($this->users($siteIds));
    }

    /**
     * The shared current-staff population for the register and competency report.
     *
     * @param  list<int>  $siteIds
     * @return Collection<int, User>
     */
    public function users(array $siteIds): Collection
    {
        if ($siteIds === []) {
            return collect();
        }

        return $this->currentStaff->currentUsersQuery()
            ->with([
                'hrEmployeeProfile:id,user_id,primary_site_id,secondary_site_ids,start_date',
                'medicationCompetencyAssessments.assessor:id,name',
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'approved_at'])
            ->filter(fn (User $user): bool => collect($this->siteIdsOf($user))->intersect($siteIds)->isNotEmpty()
                && ($user->canDo('medications.administer.record') || $user->medicationCompetencyAssessments->isNotEmpty()))
            ->values();
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
        $pinSetAt = UserWitnessPin::query()->whereIn('user_id', $ids)->get(['user_id', 'set_at'])->keyBy('user_id');
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

        return $users->map(function (User $user) use ($pins, $pinSetAt, $houses, $roles, $now, $today, $renewalDays, $activeExemptions): array {
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
                'pin_set_at' => $pinSetAt->get($user->id)?->set_at?->copy()->timezone($this->timezone())->toDateString(),
                'witness' => $this->witness(
                    $user,
                    $decision,
                    $st,
                    $established ?? $shown,
                    $pins[(int) $user->id] ?? WitnessPinService::STATUS_NOT_SET,
                ),
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

    /**
     * EA-156 (Lane C9h): can this person witness controlled drugs, and why
     * not — the P07b rule without the moment's presence on shift. Mirrors
     * ControlledMedicationTransportWitnessService::qualification (the
     * controlled witness permission and a current competency decision) and
     * isValidWitnessAssessment (passed, not restricted, controlled drugs area
     * passed and seen, "can witness controlled drugs" on), plus a usable PIN.
     * An exemption never covers witnessing.
     *
     * @param  array<string, mixed>  $decision
     * @return array{can: bool, competency: bool, reasons: list<string>}
     */
    private function witness(User $user, array $decision, string $st, ?MedicationCompetencyAssessment $assessment, string $pin): array
    {
        $reasons = [];
        if (! $user->canDo('medications.controlled.witness')) {
            $reasons[] = 'their role can’t witness controlled drugs';
        }
        if ($decision['state'] !== 'valid' || $decision['assessment_id'] === null) {
            $reasons[] = match (true) {
                $decision['state'] === 'exempt' => 'an exemption doesn’t cover witnessing',
                $st === 'ack' => 'assessment waiting for their acknowledgement',
                $st === 'failed' || $decision['state'] === 'failed' => 'not passed',
                in_array($decision['state'], ['expired', 'missing_expiry'], true) => 'assessment ended',
                default => 'not assessed',
            };
        }
        if ($assessment !== null) {
            if ($assessment->restricted) {
                $reasons[] = 'restricted — can’t witness while restricted';
            }
            if (in_array('controlled_drugs', $assessment->not_seen_areas ?? [], true)) {
                $reasons[] = 'controlled drugs area not assessed';
            } elseif (! $assessment->controlled_drugs) {
                $reasons[] = 'controlled drugs area not passed';
            }
            if (! $assessment->can_witness_controlled) {
                $reasons[] = '“can witness controlled drugs” not on';
            }
        }
        $competency = $reasons === [];
        $reasons = [...$reasons, ...match ($pin) {
            WitnessPinService::STATUS_SET => [],
            WitnessPinService::STATUS_LOCKED => ['PIN locked'],
            WitnessPinService::STATUS_RESET => ['PIN reset — must set a new one'],
            WitnessPinService::STATUS_EXPIRED => ['PIN renewal due — must set a new one'],
            default => ['no witness PIN set'],
        }];

        return ['can' => $reasons === [], 'competency' => $competency, 'reasons' => $reasons];
    }

    /**
     * EA-156 (Lane C9h): the witness view's "on shift now" for each house —
     * who from the register is clocked in, or on a shift in progress, at the
     * house now (read only), and whether P07b accepts them as a controlled-drug
     * witness there now (ControlledMedicationTransportWitnessService, the rule
     * recording enforces) with a usable witness PIN.
     *
     * @param  list<int>  $siteIds
     * @param  Collection<int, array<string, mixed>>  $rows  register rows
     * @return list<array{id: int, name: string, can_now: int, people: list<array<string, mixed>>}>
     */
    public function onShiftNow(array $siteIds, Collection $rows): array
    {
        if ($siteIds === []) {
            return [];
        }
        $byId = $rows->keyBy('id');
        $ids = $byId->keys()->map(fn ($id): int => (int) $id)->all();
        $now = now();
        $storage = $now->copy()->utc();
        $present = [];
        foreach (HrAttendanceSession::query()->whereIn('site_id', $siteIds)->whereIn('user_id', $ids)
            ->where('clock_in_at', '<=', $storage)->where('status', 'open')->whereNull('clock_out_at')
            ->orderBy('clock_in_at')->get(['user_id', 'site_id', 'clock_in_at']) as $session) {
            $present[(int) $session->site_id][(int) $session->user_id] ??= ['since' => $session->clock_in_at, 'until' => null, 'clocked' => true];
        }
        foreach (Shift::query()->with('client:id,site_id')->whereIn('user_id', $ids)
            ->where('starts_at', '<=', $storage)->where('ends_at', '>=', $storage)
            ->whereIn('status', ['in_progress', 'active', 'clocked_in', 'started'])
            ->where(fn ($at) => $at->whereIn('site_id', $siteIds)
                ->orWhere(fn ($derived) => $derived->whereNull('site_id')->whereHas('client', fn ($client) => $client->whereIn('site_id', $siteIds))))
            ->get(['id', 'user_id', 'site_id', 'client_id', 'starts_at', 'ends_at', 'actual_starts_at']) as $shift) {
            $siteId = (int) ($shift->site_id ?? $shift->client?->site_id);
            $seen = $present[$siteId][(int) $shift->user_id] ?? ['since' => null, 'until' => null, 'clocked' => false];
            $present[$siteId][(int) $shift->user_id] = [
                'since' => $seen['since'] ?? $shift->actual_starts_at ?? $shift->starts_at,
                'until' => $shift->ends_at,
                'clocked' => $seen['clocked'],
            ];
        }
        $witnesses = app(ControlledMedicationTransportWitnessService::class);
        $timezone = $this->timezone();

        return Site::query()->whereIn('id', $siteIds)->orderBy('name')->get(['id', 'name'])
            ->map(function (Site $site) use ($present, $byId, $witnesses, $now, $timezone): array {
                $here = $present[(int) $site->id] ?? [];
                $accepted = $here === [] ? collect() : $witnesses->eligibleWitnessesForSite((int) $site->id, $now)->pluck('id')->map(fn ($id): int => (int) $id);
                $people = collect($here)->map(function (array $at, int $userId) use ($byId, $accepted, $timezone): array {
                    $row = $byId->get($userId);
                    $canNow = $accepted->contains($userId) && $row['pin'] === WitnessPinService::STATUS_SET;
                    $reasons = $canNow ? [] : ($row['witness']['reasons'] ?: ['can’t witness at this house now']);

                    return [
                        'id' => $userId,
                        'name' => (string) $row['name'],
                        'role' => $row['role'],
                        'since' => $at['since']?->copy()->timezone($timezone)->toIso8601String(),
                        'until' => $at['until']?->copy()->timezone($timezone)->toIso8601String(),
                        'clocked_in' => (bool) $at['clocked'],
                        'can_now' => $canNow,
                        'reasons' => $reasons,
                    ];
                })->sortBy('name')->values();

                return [
                    'id' => (int) $site->id,
                    'name' => (string) $site->name,
                    'can_now' => $people->where('can_now', true)->count(),
                    'people' => $people->all(),
                ];
            })->values()->all();
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
