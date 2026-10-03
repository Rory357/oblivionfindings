<?php

namespace App\Services\Medication\Recording;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationAdminRule;
use App\Models\MedicationRefusalFollowup;
use App\Models\Shift;
use App\Models\User;
use App\Services\MarScheduleService;
use App\Services\Medication\Alerts\OnCallResolver;
use App\Services\Medication\ClientAllergyRecordService;
use App\Services\Medication\Controlled\ControlledDoseOverrideService;
use App\Services\Medication\ControlledMedicationTransportWitnessService;
use App\Services\Medication\DoseSlots\DoseOrderTimelineFactory;
use App\Services\Medication\DoseSlots\DoseWindowResolver;
use App\Services\Medication\ForgottenWitnessPinService;
use App\Services\Medication\MedicationAdministratorCompetencyPolicy;
use App\Services\Medication\MedicationCompetencyRestrictionRules;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\Medication\MedicationSafetyPolicySettings;
use App\Services\Medication\MedicationScopeDecisionService;
use App\Services\Medication\MedicationSecondPersonService;
use App\Services\Medication\Settings\MedicineRuleWording;
use App\Services\Medication\WitnessPinService;
use App\Services\MedicationRuleService;
use App\Services\MedicationSafetyService;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * eMAR P01 — what recording one dose needs and allows, for the person
 * recording it, now. The one place every recording screen asks (the dialog,
 * Meds today's rows, the MAR's one-click "Mark given"), so they all say the
 * same thing. It only reads: the record path re-checks everything under its
 * locks when the dose is saved ("Checked again when you save").
 *
 * The answer is facts and keys, never copy: the dialog words them (P01).
 *
 *  - block_all: nothing can be recorded (not clocked in, not on this
 *    person's shift, house outside your access, a controlled medicine you
 *    may not record, order waiting to be checked, as-needed limit reached).
 *    The answer is then only the reason and the house: nothing about the
 *    person or the order;
 *  - block_given: "given" can't be recorded, a refusal, withhold or absence
 *    still can (covert authorisation overdue, an allergy or other safety
 *    block, no eligible witness);
 *  - competency: current, expired / not current, restricted (Block),
 *    co-signer (restricted, Co-signer mode) or a competency area not passed;
 *  - second person, rule readings, allergy status and match, covert plan,
 *    the dose window, who on shift can give it, and the house lead;
 *  - not_simple: why the MAR doesn't offer one-click "Mark given" (Q6).
 *
 * Callers decide visibility first (assertVisible): someone outside the
 * viewer's access is "not found", never named (no existence leak).
 */
final class DoseRecordingRequirements
{
    public const BLOCK_NOT_CLOCKED_IN = 'notClockedIn';

    public const BLOCK_NOT_ON_SHIFT = 'notOnShift';

    public const BLOCK_SITE_NOT_APPROVED = 'siteNotApproved';

    public const BLOCK_AWAITING_VERIFICATION = 'awaitingVerification';

    public const BLOCK_COVERT_MISSING = 'covertMissing';

    public const BLOCK_ALLERGY = 'allergyBlocked';

    public const BLOCK_SAFETY = 'safetyBlocked';

    public const BLOCK_NO_WITNESS = 'noWitness';

    public const BLOCK_PRN_LIMIT = 'prnLimit';

    public const BLOCK_CONTROLLED_NOT_ALLOWED = 'controlledNotAllowed';

    /** Rule observation token => what the dialog asks for. */
    public const OBSERVATIONS = [
        'blood_glucose' => ['label' => 'Blood sugar (BSL)', 'unit' => 'mmol/L', 'fields' => ['blood_glucose_level']],
        'pulse' => ['label' => 'Pulse', 'unit' => 'bpm', 'fields' => ['pulse_bpm']],
        'blood_pressure' => ['label' => 'Blood pressure', 'unit' => 'mmHg', 'fields' => ['blood_pressure_systolic', 'blood_pressure_diastolic']],
    ];

    private const SITE_BYPASS = ['clinical.accessAllSites', 'sites.viewAll'];

    private const PRESENCE_SHIFT_STATUSES = ['in_progress', 'active', 'clocked_in', 'started'];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly MedicationScopeDecisionService $scope,
        private readonly MedicationAdministratorCompetencyPolicy $competency,
        private readonly MedicationCompetencyRestrictionRules $restrictions,
        private readonly MedicationSafetyService $safety,
        private readonly MedicationRuleService $rules,
        private readonly ControlledMedicationTransportWitnessService $witnesses,
        private readonly WitnessPinService $pins,
        private readonly ClientAllergyRecordService $allergies,
        private readonly DoseWindowResolver $windows,
        private readonly MarScheduleService $schedule,
        private readonly RecordingContractEnforcer $contract,
        private readonly MedicationRecordAccess $recordAccess,
    ) {}

    /**
     * Not found unless the viewer may see this order: they may read this
     * person's medicines (the person gate every chart read uses), and the
     * person's house is in their access or a clocked-in shift of theirs
     * includes the person (someone who moved house mid-shift). Controlled
     * orders also need controlled-medicine access.
     */
    public function assertVisible(User $viewer, ClientMedication $order): void
    {
        abort_unless($this->isVisible($viewer, $order), 404);
    }

    /** assertVisible() as a yes/no, for lists that leave unseen rows out. */
    public function isVisible(User $viewer, ClientMedication $order, ?RequirementsCache $cache = null): bool
    {
        $client = $order->client;
        if (! $client instanceof Client
            || $order->deleted_at !== null
            || $order->superseded_by !== null
            || ! $viewer->canDo('medications.administer.record')
            || ($order->controlled_drug && ! $viewer->canDo('medications.controlled.view'))) {
            return false;
        }

        $clientId = (int) $client->id;
        if ($cache !== null && array_key_exists($clientId, $cache->personVisible)) {
            return $cache->personVisible[$clientId];
        }

        $visible = $this->personReadable($viewer, $client)
            && (in_array((int) $client->site_id, $cache?->siteIds ?? $this->siteAccess->accessibleSiteIds($viewer, self::SITE_BYPASS), true)
                || $this->hasShiftWith($viewer, $client, CarbonImmutable::now()));
        if ($cache !== null) {
            $cache->personVisible[$clientId] = $visible;
        }

        return $visible;
    }

    /** The one per-person medication record gate (MedicationRecordAccess, P02). */
    private function personReadable(User $viewer, Client $client): bool
    {
        try {
            $this->recordAccess->assertReadable($viewer, $client);

            return true;
        } catch (HttpExceptionInterface) {
            return false;
        }
    }

    /**
     * Everything the recording dialog needs for one scheduled dose.
     *
     * @return array<string, mixed>
     */
    public function forScheduledDose(User $viewer, ClientMedication $order, CarbonInterface $dueAt, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now())->utc();
        $dueAt = CarbonImmutable::instance($dueAt)->utc();

        return $this->build($viewer, $order, $now, $dueAt);
    }

    /**
     * Everything the recording dialog needs for one as-needed dose.
     *
     * @return array<string, mixed>
     */
    public function forAsNeeded(User $viewer, ClientMedication $order, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now())->utc();

        return $this->build($viewer, $order, $now, null);
    }

    /**
     * The row-level facts for a list of doses (Meds today's state lines,
     * "Needs help", the MAR's one-click "Mark given"): block keys, competency,
     * witness need, allergy match and why one-click isn't offered. Facts that
     * belong to the viewer or the house are read once.
     *
     * @param  iterable<array{order: ClientMedication, due_at: ?CarbonInterface}>  $doses
     * @return array<string, array{block_all: ?string, block_given: ?string, competency: ?string, second_person: ?string, witness_available: bool, allergy_match: bool, not_simple: list<string>, window: ?string}>
     */
    public function forBoard(User $viewer, iterable $doses, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now())->utc();
        $cache = new RequirementsCache;
        $cache->siteIds = $this->siteAccess->accessibleSiteIds($viewer, self::SITE_BYPASS);
        $rows = [];

        foreach ($doses as $dose) {
            $order = $dose['order'];
            // The board answers only for doses the viewer may see (person
            // gate, controlled access): anything else is left out, unnamed.
            if (! $this->isVisible($viewer, $order, $cache)) {
                continue;
            }
            $dueAt = $dose['due_at'] !== null ? CarbonImmutable::instance($dose['due_at'])->utc() : null;
            $full = $this->build($viewer, $order, $now, $dueAt, $cache, detail: false);
            $rows[self::boardKey((int) $order->id, $dueAt)] = $full['block_all'] !== null
                ? [
                    'block_all' => $full['block_all']['key'],
                    'block_given' => null,
                    'competency' => null,
                    'second_person' => null,
                    'witness_available' => false,
                    'allergy_match' => false,
                    'not_simple' => ['blocked'],
                    'window' => null,
                ]
                : [
                    'block_all' => null,
                    'block_given' => $full['block_given']['key'] ?? null,
                    'competency' => $full['competency']['state'],
                    'second_person' => $full['second_person']['kind'],
                    'witness_available' => $full['second_person']['anyone_available'],
                    'allergy_match' => $full['allergy']['match'] !== null,
                    'not_simple' => $full['not_simple'],
                    'window' => $full['due']['state'] ?? null,
                ];
        }

        return $rows;
    }

    public static function boardKey(int $orderId, ?CarbonInterface $dueAt): string
    {
        return $orderId.'|'.($dueAt !== null ? CarbonImmutable::instance($dueAt)->utc()->format('Y-m-d\TH:i') : 'prn');
    }

    /** @return array<string, mixed> */
    private function build(
        User $viewer,
        ClientMedication $order,
        CarbonImmutable $now,
        ?CarbonImmutable $dueAt,
        ?RequirementsCache $cache = null,
        bool $detail = true,
    ): array {
        $cache ??= new RequirementsCache;
        $order->loadMissing(['client.site:id,name', 'covertAuthorisation']);
        /** @var Client $client */
        $client = $order->client;
        $siteId = (int) $client->site_id;

        $blockAll = $this->blockAll($viewer, $client, $now, $cache);
        // The record path refuses every outcome on a controlled medicine
        // without controlled-recording access, and (P1-2) on an order waiting
        // for its check, so neither can be recorded at all.
        if ($blockAll === null && $order->controlled_drug && ! $viewer->canDo('medications.controlled.record')) {
            $blockAll = ['key' => self::BLOCK_CONTROLLED_NOT_ALLOWED, 'facts' => []];
        }
        if ($blockAll === null && ! $order->isVerifiedForAdministration()) {
            $blockAll = ['key' => self::BLOCK_AWAITING_VERIFICATION, 'facts' => []];
        }
        if ($blockAll !== null) {
            return $this->blockedOnly($order, $client, $blockAll, $now, $detail);
        }

        $safetyCheck = $this->safety->performSafetyCheck($client, $order, null, null, $viewer->canDo('medications.controlled.view'));
        if ($order->is_prn && $this->prnLimitReached($safetyCheck)) {
            return $this->blockedOnly($order, $client, $this->prnLimitBlock($order, $safetyCheck), $now, $detail);
        }

        $adminRules = $this->rules->requirementsFor($order);
        $competency = $this->competencyFor($viewer, $order, $siteId, $now, $cache);
        $requiresCosigner = $competency['state'] === 'cosigner';

        $doseOverride = app(ControlledDoseOverrideService::class)->preview($viewer, $order, $now);
        $kind = match (true) {
            $doseOverride === null && $order->requiresWitness() => RecordingContract::SECOND_WITNESS,
            $requiresCosigner => RecordingContract::SECOND_COSIGNER,
            (bool) ($adminRules['requires_countersign'] ?? false) => RecordingContract::SECOND_RULE,
            default => null,
        };
        $candidateKey = $siteId.($order->controlled_drug ? '/controlled' : '/ordinary');
        $candidates = $cache->candidates[$candidateKey] ??= $this->candidates($viewer, $siteId, $now, (bool) $order->controlled_drug);
        $anyoneAvailable = $candidates->contains(fn (array $c): bool => $c['can_confirm']);

        $blockGiven = $this->blockGiven($order, $safetyCheck, $kind, $anyoneAvailable);

        $allergy = $this->allergyFor($client, $safetyCheck, $cache);
        $covert = $this->covertFor($order);
        $due = $dueAt !== null ? $this->dueFor($order, $dueAt, $now) : null;
        $orderFacts = $this->orderFacts($order);

        $result = [
            'kind' => $order->is_prn ? 'prn' : 'scheduled',
            'order' => $orderFacts,
            'due' => $due,
            'block_all' => null,
            'block_given' => $blockGiven,
            'competency' => $competency,
            'witness_override' => $doseOverride === null ? null : ['id' => (int) $doseOverride->id, 'expires_at' => $doseOverride->expires_at->toIso8601String(), 'followup_due_at' => $doseOverride->followup_due_at->toIso8601String()],
            'second_person' => [
                'kind' => $kind,
                // PIN-2 stays off until its own-login consumer and expiry job
                // are integrated; the recorder never advertises a dead flow.
                'forgotten_pin_allowed' => app(ForgottenWitnessPinService::class)->available()
                    && ! $order->controlled_drug
                    && in_array($kind, [RecordingContract::SECOND_RULE, RecordingContract::SECOND_AMOUNT, RecordingContract::SECOND_COSIGNER], true),
                'confirm_within_minutes' => ForgottenWitnessPinService::CONFIRM_WITHIN_MINUTES,
                'rule_sentences' => $detail && $kind === RecordingContract::SECOND_RULE
                    ? $this->ruleSentences($adminRules, true)
                    : [],
                'anyone_available' => $anyoneAvailable,
                // Q2: a rule's second person (or a smaller amount) may go
                // unconfirmed only when nobody on shift can confirm. A
                // witness or a restricted worker's co-signer never can.
                'may_go_unconfirmed' => $doseOverride === null && ! $anyoneAvailable
                    && ! in_array($kind, [RecordingContract::SECOND_WITNESS, RecordingContract::SECOND_COSIGNER], true),
                'candidates' => $detail ? $candidates->values()->all() : [],
            ],
            'observations' => array_values(array_filter(array_map(
                fn (string $token): ?array => isset(self::OBSERVATIONS[$token])
                    ? ['key' => $token, ...self::OBSERVATIONS[$token]]
                    : null,
                $adminRules['required_observations'] ?? [],
            ))),
            'observation_rule_sentences' => $detail && ($adminRules['required_observations'] ?? []) !== []
                ? $this->ruleSentences($adminRules, false)
                : [],
            'allergy' => $allergy,
            'covert' => $covert,
            // P03 owns support modes; until then every order is "Administer".
            'support' => 'administer',
            // P04 owns variable doses; orders carry one amount today.
            'variable' => false,
        ];
        $result['not_simple'] = $this->notSimple($order, $result);

        if ($detail) {
            $result['person'] = $this->personFacts($client);
            $result['who_can_give'] = $this->whoCanGive($viewer, $siteId, $now, $candidates, $cache);
            $result['house_lead'] = $cache->houseLead[$siteId] ??= $this->houseLead($siteId, $now);
            $result['on_call'] = $this->onCallFor($siteId, $now);
            $result['prn'] = $order->is_prn ? $this->prnFacts($order) : null;
            $result['reoffer'] = $due !== null ? $this->reofferFacts($client, $order, $dueAt, $now) : null;
            $result['options'] = [
                'late_reasons' => RecordingContract::LATE_REASONS,
                'amount_reasons' => RecordingContract::AMOUNT_REASONS,
                'withheld_reasons' => RecordingContract::WITHHELD_REASONS,
                'away_reasons' => RecordingContract::AWAY_REASONS,
                'more_severities' => RecordingContract::MORE_SEVERITIES,
            ];
            $result['checked_at'] = $now->toIso8601String();
        }

        return $result;
    }

    /**
     * P0-1: when nothing can be recorded the answer is the reason and the
     * house only — no person, allergy, order, covert plan or colleague
     * details. An as-needed limit also says when it was last given, so the
     * worker knows when it can be given again.
     *
     * @param  array{key: string, facts: array<string, mixed>}  $blockAll
     * @return array<string, mixed>
     */
    private function blockedOnly(ClientMedication $order, Client $client, array $blockAll, CarbonImmutable $now, bool $detail): array
    {
        $result = [
            'kind' => $order->is_prn ? 'prn' : 'scheduled',
            'block_all' => [
                'key' => $blockAll['key'],
                'facts' => ['house' => $client->site?->name, ...$blockAll['facts']],
            ],
        ];
        if ($detail) {
            $result['checked_at'] = $now->toIso8601String();
        }

        return $result;
    }

    /** @return array{key: string, facts: array<string, mixed>}|null */
    private function blockAll(User $viewer, Client $client, CarbonImmutable $now, RequirementsCache $cache): ?array
    {
        $clientId = (int) $client->id;
        $authorised = in_array(
            $clientId,
            $this->scope->clientIdsWithCurrentAuthority($viewer, [$clientId], $now->toMutable()),
            true,
        );
        if ($authorised) {
            return null;
        }

        $siteIds = $cache->siteIds ??= $this->siteAccess->accessibleSiteIds($viewer, self::SITE_BYPASS);
        if (! in_array((int) $client->site_id, $siteIds, true)) {
            return ['key' => self::BLOCK_SITE_NOT_APPROVED, 'facts' => []];
        }

        if ($this->clockedInShiftAtSite($viewer, (int) $client->site_id, $now) !== null) {
            return ['key' => self::BLOCK_NOT_ON_SHIFT, 'facts' => []];
        }

        return ['key' => self::BLOCK_NOT_CLOCKED_IN, 'facts' => []];
    }

    /** @return array{key: string, facts: array<string, mixed>}|null */
    private function blockGiven(ClientMedication $order, array $safetyCheck, ?string $kind, bool $anyoneAvailable): ?array
    {
        $covert = $order->covertAuthorisation;
        if ($covert !== null && $covert->isExpired()) {
            return ['key' => self::BLOCK_COVERT_MISSING, 'facts' => [
                'review_date' => $covert->review_date?->toDateString(),
            ]];
        }

        if ($safetyCheck['blocked'] ?? false) {
            $warnings = collect($safetyCheck['warnings'] ?? [])->filter(fn ($w): bool => is_array($w));
            $danger = $warnings->filter(fn (array $w): bool => ($w['severity'] ?? null) === 'danger');
            // What blocks "given" for an allergy: a severe or life-threatening
            // register allergy (the safety check blocks both; only the second
            // is a danger-level warning), or a health-profile match when the
            // organisation blocks matches (P11: Block).
            $allergy = $warnings->first(fn (array $w): bool => ($w['type'] ?? null) === 'allergy'
                && (($w['severity'] ?? null) === 'danger'
                    || in_array($w['details']['severity'] ?? null, ['severe', 'life_threatening'], true)));
            if ($allergy !== null) {
                return ['key' => self::BLOCK_ALLERGY, 'facts' => [
                    'allergen' => $allergy['details']['allergen'] ?? null,
                    'source' => $allergy['details']['source'] ?? null,
                    'severity' => $allergy['details']['severity'] ?? null,
                ]];
            }

            $first = $danger->first();

            return ['key' => self::BLOCK_SAFETY, 'facts' => [
                'type' => $first['type'] ?? 'blocked',
                'reason' => $safetyCheck['block_reason'] ?? null,
            ]];
        }

        if ($kind === RecordingContract::SECOND_WITNESS && ! $anyoneAvailable) {
            return ['key' => self::BLOCK_NO_WITNESS, 'facts' => []];
        }

        return null;
    }

    private function prnLimitReached(array $safetyCheck): bool
    {
        return collect($safetyCheck['warnings'] ?? [])->contains(
            fn ($w): bool => is_array($w) && in_array($w['type'] ?? null, ['prn_limit', 'prn_interval'], true),
        );
    }

    /** @return array{key: string, facts: array<string, mixed>} */
    private function prnLimitBlock(ClientMedication $order, array $safetyCheck): array
    {
        $warning = collect($safetyCheck['warnings'] ?? [])->first(
            fn ($w): bool => is_array($w) && in_array($w['type'] ?? null, ['prn_limit', 'prn_interval'], true),
        );

        $prn = $this->prnFacts($order);

        return ['key' => self::BLOCK_PRN_LIMIT, 'facts' => [
            'type' => $warning['type'] ?? 'prn_limit',
            'count_24h' => $prn['count_24h'],
            'max_24h' => $prn['max_24h'],
            'min_hours_between' => $prn['min_hours_between'],
            'last_at' => $prn['last_at'],
        ]];
    }

    /** @return array{state: string, message: ?string, expires_on: ?string} */
    private function competencyFor(User $viewer, ClientMedication $order, int $siteId, CarbonImmutable $now, RequirementsCache $cache): array
    {
        $decision = $cache->competency[$siteId] ??= $this->competency->evaluate($viewer, $siteId ?: null, $now);
        if (! ($decision['allowed'] ?? false)) {
            return [
                'state' => ($decision['state'] ?? null) === 'expired' ? 'expired' : 'not_current',
                'message' => $decision['message'] ?? null,
                'expires_on' => null,
            ];
        }

        $violation = $this->restrictions->violation($decision, $order, $siteId ?: null, $now, null);

        return [
            'state' => match ($violation['state'] ?? null) {
                'restricted' => 'restricted',
                'restricted_cosigner_required' => 'cosigner',
                'area_not_passed' => 'area',
                default => 'current',
            },
            'message' => $violation['message'] ?? null,
            'expires_on' => null,
        ];
    }

    /**
     * Colleagues on shift at the house now (clocked in, or on a shift that
     * proves presence), and whether each can confirm this dose: qualified,
     * current and holding a usable witness PIN. Never why not — a colleague's
     * competency isn't the recorder's to read.
     *
     * @return Collection<int, array{id: int, name: string, can_confirm: bool}>
     */
    private function candidates(User $viewer, int $siteId, CarbonImmutable $now, bool $controlledDrug): Collection
    {
        if ($siteId <= 0) {
            return collect();
        }

        $presentIds = $this->presentUserIdsAtSite($siteId, $now)
            ->reject(fn (int $id): bool => $id === (int) $viewer->id)
            ->values();
        if ($presentIds->isEmpty()) {
            return collect();
        }

        $users = User::query()->whereIn('id', $presentIds)->orderBy('name')->get();
        $eligibleIds = ($controlledDrug
            ? $this->witnesses->eligibleWitnessesForSite($siteId, $now, (int) $viewer->id)
            : app(MedicationSecondPersonService::class)->candidatesForSite($siteId, $now, (int) $viewer->id))
            ->map(fn (User $user): int => (int) $user->id)
            ->all();
        $pins = $this->pins->pickerRows($users)->keyBy('id');

        return $users->map(fn (User $user): array => [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'can_confirm' => in_array((int) $user->id, $eligibleIds, true)
                && ($pins[(int) $user->id]['witness_pin'] ?? WitnessPinService::STATUS_NOT_SET) === WitnessPinService::STATUS_SET,
        ])->values();
    }

    /** @return Collection<int, int> */
    private function presentUserIdsAtSite(int $siteId, CarbonImmutable $now): Collection
    {
        $now = $now->utc();
        $attendance = HrAttendanceSession::query()
            ->where('site_id', $siteId)
            ->where('clock_in_at', '<=', $now)
            ->where(function (Builder $coverage) use ($now): void {
                $coverage->where(function (Builder $open): void {
                    $open->where('status', 'open')->whereNull('clock_out_at');
                })->orWhere(function (Builder $closed) use ($now): void {
                    $closed->where('status', 'closed')->where('clock_out_at', '>=', $now);
                });
            })
            ->pluck('user_id');

        $shifts = Shift::query()
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->whereIn('status', self::PRESENCE_SHIFT_STATUSES)
            ->where(function (Builder $site) use ($siteId): void {
                $site->where('site_id', $siteId)
                    ->orWhere(function (Builder $derived) use ($siteId): void {
                        $derived->whereNull('site_id')
                            ->whereHas('client', fn (Builder $client): Builder => $client->where('site_id', $siteId));
                    });
            })
            ->pluck('user_id');

        return $attendance->merge($shifts)
            ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * Who on shift can give a dose the viewer can't sign as given: present,
     * allowed to record, current competency and not restricted (Block).
     *
     * @param  Collection<int, array{id: int, name: string}>  $candidates
     * @return list<array{id: int, name: string}>
     */
    private function whoCanGive(User $viewer, int $siteId, CarbonImmutable $now, Collection $candidates, RequirementsCache $cache): array
    {
        if ($siteId <= 0 || $candidates->isEmpty()) {
            return [];
        }

        return $cache->whoCanGive[$siteId] ??= User::query()
            ->whereIn('id', $candidates->pluck('id'))
            ->orderBy('name')
            ->get()
            ->filter(function (User $user) use ($siteId, $now): bool {
                if (! $user->canDo('medications.administer.record')) {
                    return false;
                }
                $decision = $this->competency->evaluate($user, $siteId, $now);
                if (! ($decision['allowed'] ?? false)) {
                    return false;
                }

                return ! ($this->restrictions->noticeFor($user, $siteId, $now)['blocked'] ?? false);
            })
            ->map(fn (User $user): array => ['id' => (int) $user->id, 'name' => (string) $user->name])
            ->values()
            ->all();
    }

    /**
     * The house's on-call contact now (Settings › On-call, P11 B2): who, their
     * work phone, or why there's nobody ("Nobody — … is on leave").
     *
     * @return array{configured: bool, name: ?string, phone: ?string, warning: ?string}
     */
    private function onCallFor(int $siteId, CarbonImmutable $now): array
    {
        if ($siteId <= 0) {
            return ['configured' => false, 'name' => null, 'phone' => null, 'warning' => null];
        }
        // A fresh resolver: it keeps each house's rule for its own lifetime,
        // and this service can outlive a request (a cached controller).
        $onCall = app(OnCallResolver::class);
        $resolved = $onCall->at($siteId, $now);

        return [
            'configured' => $resolved['configured'],
            'name' => $resolved['user']?->name,
            'phone' => $onCall->phoneOf($resolved['user']),
            'warning' => $resolved['warning'],
        ];
    }

    /** The house lead rostered at the house today (for "Message the house lead"). */
    private function houseLead(int $siteId, CarbonImmutable $now): ?array
    {
        if ($siteId <= 0) {
            return null;
        }

        $tz = $this->schedule->workerTimezone();
        $dayStart = $now->setTimezone($tz)->startOfDay()->utc();
        $dayEnd = $now->setTimezone($tz)->endOfDay()->utc();

        $userIds = Shift::query()
            ->where('site_id', $siteId)
            ->where('starts_at', '<=', $dayEnd)
            ->where('ends_at', '>=', $dayStart)
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('starts_at')
            ->pluck('user_id')
            ->filter()
            ->unique();

        $lead = User::query()
            ->whereIn('id', $userIds)
            ->orderBy('name')
            ->get()
            ->first(fn (User $user): bool => $user->hasRole('team_lead'));

        return $lead === null ? null : ['id' => (int) $lead->id, 'name' => (string) $lead->name];
    }

    /** @return array<string, mixed> */
    private function allergyFor(Client $client, array $safetyCheck, RequirementsCache $cache): array
    {
        $clientId = (int) $client->id;
        if (! array_key_exists($clientId, $cache->allergyLabels)) {
            try {
                $cache->allergyLabels[$clientId] = $this->allergies->labelsForClients([$clientId])[$clientId] ?? [];
            } catch (\Throwable $e) {
                report($e);
                $cache->allergyLabels[$clientId] = null;
            }
        }
        $labels = $cache->allergyLabels[$clientId];

        $match = collect($safetyCheck['warnings'] ?? [])->first(
            fn ($w): bool => is_array($w) && ($w['type'] ?? null) === 'allergy',
        );

        return [
            // recorded | none_recorded | unavailable — never a confirmed
            // "no known allergies" (EM-07).
            'status' => match (true) {
                $labels === null => 'unavailable',
                $labels !== [] => 'recorded',
                default => 'none_recorded',
            },
            'list' => $labels ?? [],
            'match' => $match === null ? null : [
                'allergen' => $match['details']['allergen'] ?? null,
                'source' => $match['details']['source'] ?? null,
                'severity' => $match['details']['severity'] ?? null,
            ],
            'rule' => app(MedicationSafetyPolicySettings::class)->profileAllergyMatch(),
        ];
    }

    /** @return array{state: string, plan: ?string, review_date: ?string} */
    private function covertFor(ClientMedication $order): array
    {
        $covert = $order->covertAuthorisation;
        if ($covert === null) {
            return ['state' => 'none', 'plan' => null, 'review_date' => null];
        }

        return [
            'state' => $covert->isExpired() ? 'missing' : 'active',
            'plan' => $covert->administration_method,
            'review_date' => $covert->review_date?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function dueFor(ClientMedication $order, CarbonImmutable $dueAt, CarbonImmutable $now): array
    {
        $window = $this->windows->forOrder((int) $order->id);
        $opens = $window->opensAt($dueAt);
        $closes = $window->closesAt($dueAt);

        return [
            'due_at' => $dueAt->toIso8601String(),
            'window_opens_at' => $opens->toIso8601String(),
            'window_closes_at' => $closes->toIso8601String(),
            'before_minutes' => $window->beforeMinutes,
            'after_minutes' => $window->afterMinutes,
            'state' => match (true) {
                $now->lt($opens) => 'notdue',
                $now->lte($closes) => 'due',
                default => 'late',
            },
            'late_minutes' => $now->gt($dueAt) ? (int) $dueAt->diffInMinutes($now) : 0,
        ];
    }

    /** @return array<string, mixed> */
    private function orderFacts(ClientMedication $order): array
    {
        $verifiedBy = $order->verified_by ? User::query()->whereKey((int) $order->verified_by)->value('name') : null;

        return [
            'id' => (int) $order->id,
            'name' => (string) $order->name,
            'dosage' => $order->dosage,
            'dose_amount' => $this->contract->orderedAmount($order),
            'dose_unit' => $order->dose_unit,
            'route' => $order->route,
            'form' => $order->form,
            'instructions' => $order->instructions,
            'prescriber' => $order->prescriber,
            'controlled' => (bool) $order->controlled_drug,
            'high_risk' => (bool) $order->high_risk,
            'witness_required' => $order->requiresWitness(),
            'is_prn' => (bool) $order->is_prn,
            'version' => $order->version !== null ? (int) $order->version : null,
            'verified' => $order->isVerifiedForAdministration() && $order->verified_at !== null ? [
                'at' => DoseOrderTimelineFactory::rawInstant($order->getRawOriginal('verified_at'))?->toIso8601String(),
                'by' => $verifiedBy,
            ] : null,
            'awaiting_check' => ! $order->isVerifiedForAdministration(),
            // NF-18: for a controlled medicine, whether the stock taken can be
            // worked out from the order (its amount is in the stock's unit);
            // otherwise the dialog asks for it.
            'stock' => $order->controlled_drug ? $this->stockFacts($order) : null,
        ];
    }

    /** @return array{unit: ?string, from_order: bool} */
    private function stockFacts(ClientMedication $order): array
    {
        $unit = $order->stock()->value('unit');

        return [
            'unit' => $unit,
            'from_order' => $this->contract->stockQuantity(
                ['status' => 'given', 'amount_mode' => RecordingContract::AMOUNT_AS_ORDERED],
                $order,
                $unit,
            ) !== null,
        ];
    }

    /** @return array<string, mixed> */
    private function personFacts(Client $client): array
    {
        $tz = $this->schedule->workerTimezone();

        return [
            'id' => (int) $client->id,
            'preferred_name' => $client->preferred_name ?: $client->first_name,
            'legal_name' => trim($client->first_name.' '.$client->last_name),
            'house' => $client->site?->name,
            'born' => $client->date_of_birth?->toDateString(),
            'age' => $client->date_of_birth ? (int) $client->date_of_birth->copy()->timezone($tz)->diffInYears(now($tz)) : null,
            'nhi' => $client->nhi_number,
            'photo_url' => $client->profile_photo_path ? $client->profile_photo_url : null,
        ];
    }

    /** @return array<string, mixed> */
    private function prnFacts(ClientMedication $order): array
    {
        $recent = $order->prnLast24Hours;
        $last = $recent->first();
        $max = $order->max_per_day ? (int) filter_var($order->max_per_day, FILTER_SANITIZE_NUMBER_INT) : null;

        return [
            'count_24h' => $recent->count(),
            'max_24h' => $max && $max > 0 ? $max : null,
            'min_hours_between' => $order->min_hours_between_doses !== null ? (float) $order->min_hours_between_doses : null,
            'last_at' => $last ? DoseOrderTimelineFactory::rawInstant($last->getRawOriginal('administered_at'))?->toIso8601String() : null,
            'last_by' => $last ? User::query()->whereKey((int) $last->administered_by)->value('name') : null,
            'reasons' => array_values(array_filter([$order->prn_reason, $order->indication])),
        ];
    }

    /**
     * The refusal a re-offer would follow, when "Record re-offer" is offered:
     * the dose's current record is a refusal from today (NZ) whose follow-up
     * is still open (Main, P01 Q8).
     *
     * @return array{refusal_id: int, refused_at: ?string, follow_up_due_at: ?string}|null
     */
    private function reofferFacts(Client $client, ClientMedication $order, CarbonImmutable $dueAt, CarbonImmutable $now): ?array
    {
        [$from, $to] = $this->schedule->utcSlotWindow($dueAt->toMutable());
        $existing = ClientMedicationAdministration::query()
            ->effectiveClinicalEvidence()
            ->where('client_id', $client->id)
            ->where('client_medication_id', $order->id)
            ->whereBetween('scheduled_for', [$from, $to])
            ->latest('id')
            ->first();
        if ($existing === null || $existing->status !== 'refused') {
            return null;
        }

        $rootId = $existing->is_correction && $existing->corrected_of_id !== null
            ? (int) $existing->corrected_of_id
            : (int) $existing->id;
        $refusedAt = DoseOrderTimelineFactory::rawInstant($existing->getRawOriginal('administered_at'));
        $tz = $this->schedule->workerTimezone();
        if ($refusedAt === null || $refusedAt->setTimezone($tz)->toDateString() !== $now->setTimezone($tz)->toDateString()) {
            return null;
        }

        $followUp = MedicationRefusalFollowup::query()
            ->whereIn('client_medication_administration_id', array_values(array_unique([$rootId, (int) $existing->id])))
            ->whereNull('follow_up_completed_at')
            ->latest('id')
            ->first();
        if ($followUp === null) {
            return null;
        }

        return [
            'refusal_id' => $rootId,
            'refused_at' => $refusedAt->toIso8601String(),
            'follow_up_due_at' => DoseOrderTimelineFactory::rawInstant($followUp->getRawOriginal('follow_up_due_at'))?->toIso8601String(),
        ];
    }

    /**
     * The approved list for one-click "Mark given" (P01 Q6), as keys the
     * screens word. Empty means the MAR may offer it.
     *
     * @param  array<string, mixed>  $req
     * @return list<string>
     */
    private function notSimple(ClientMedication $order, array $req): array
    {
        $out = [];
        if ($req['block_all'] !== null) {
            $out[] = 'blocked';
        }
        if ($req['block_given'] !== null) {
            $out[] = 'blocked_given';
        }
        if ($order->is_prn) {
            $out[] = 'as_needed';
        }
        if (($req['due']['state'] ?? null) === 'notdue') {
            $out[] = 'not_yet_due';
        }
        if (($req['due']['state'] ?? null) === 'late') {
            $out[] = 'outside_window';
        }
        if ($req['competency']['state'] !== 'current') {
            $out[] = 'competency';
        }
        if ($order->controlled_drug || $order->requiresWitness()) {
            $out[] = 'witness';
        }
        if ($req['observations'] !== [] || $req['second_person']['kind'] === RecordingContract::SECOND_RULE) {
            $out[] = 'rules';
        }
        if ($req['second_person']['kind'] === RecordingContract::SECOND_COSIGNER) {
            $out[] = 'cosigner';
        }
        if ($req['support'] !== 'administer') {
            $out[] = 'support';
        }
        if ($req['variable']) {
            $out[] = 'variable';
        }
        if ($req['covert']['state'] !== 'none') {
            $out[] = 'covert';
        }
        if ($req['allergy']['status'] === 'unavailable') {
            $out[] = 'allergy_unavailable';
        }
        if ($req['allergy']['match'] !== null) {
            $out[] = 'allergy_match';
        }
        if ($req['order']['awaiting_check']) {
            $out[] = 'order_check';
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    private function ruleSentences(array $adminRules, bool $countersign): array
    {
        $ids = collect($adminRules['matched_rules'] ?? [])
            ->filter(fn (array $rule): bool => $countersign
                ? (bool) ($rule['requires_countersign'] ?? false)
                : ($rule['required_observations'] ?? []) !== [])
            ->pluck('id')
            ->all();
        if ($ids === []) {
            return [];
        }

        $wording = app(MedicineRuleWording::class);

        return MedicationAdminRule::query()
            ->with('site:id,name')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get()
            ->map(fn (MedicationAdminRule $rule): string => $wording->sentence($rule))
            ->values()
            ->all();
    }

    private function hasShiftWith(User $viewer, Client $client, CarbonImmutable $now): bool
    {
        return Shift::query()
            ->where('user_id', $viewer->id)
            ->whereNotNull('actual_starts_at')
            ->where('actual_starts_at', '<=', $now)
            ->where(fn (Builder $live) => $live->whereNull('actual_ends_at')->orWhere('actual_ends_at', '>=', $now))
            ->where(function (Builder $binding) use ($client): void {
                $binding->where('client_id', $client->id)
                    ->orWhereExists(function ($pivot) use ($client): void {
                        $pivot->selectRaw('1')
                            ->from('shift_clients')
                            ->whereColumn('shift_clients.shift_id', 'shifts.id')
                            ->where('shift_clients.client_id', $client->id);
                    });
            })
            ->exists();
    }

    private function clockedInShiftAtSite(User $viewer, int $siteId, CarbonImmutable $now): ?Shift
    {
        return Shift::query()
            ->where('user_id', $viewer->id)
            ->whereNotNull('actual_starts_at')
            ->where('actual_starts_at', '<=', $now)
            ->where(fn (Builder $live) => $live->whereNull('actual_ends_at')->orWhere('actual_ends_at', '>=', $now))
            ->where(function (Builder $site) use ($siteId): void {
                $site->where('site_id', $siteId)
                    ->orWhere(function (Builder $derived) use ($siteId): void {
                        $derived->whereNull('site_id')
                            ->whereHas('client', fn (Builder $client): Builder => $client->where('site_id', $siteId));
                    });
            })
            ->orderByDesc('actual_starts_at')
            ->first();
    }
}
