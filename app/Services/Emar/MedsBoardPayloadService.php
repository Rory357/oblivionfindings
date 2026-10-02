<?php

namespace App\Services\Emar;

use App\Enums\Medication\NotGivenReason;
use App\Http\Controllers\Emar\EmarController;
use App\Http\Controllers\Emar\WorkerMedsController;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationCompetencyExemption;
use App\Models\User;
use App\Services\MarScheduleService;
use App\Services\Medication\ClientAllergyRecordService;
use App\Services\Medication\DoseSlots\ScheduledDoseStates;
use App\Services\Medication\MedicationAdministratorCompetencyPolicy;
use App\Services\Medication\MedicationCompetencyRestrictionRules;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\WitnessPinService;
use App\Services\UserSiteAccessService;
use App\Support\EmarUrl;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds the shared "medication board" payload — the scheduled time-grid rows,
 * PRN medications, client/site directories, witnesses and signing identity —
 * consumed by both the frontline board (`/meds/today`, {@see WorkerMedsController})
 * and the admin MAR chart (`/emar/mar`, {@see EmarController::mar}).
 *
 * Keeping one builder means the desktop `RecordDoseWizard` / `PrnWizard`
 * components and the single `EnhancedMarService` write path are reused
 * verbatim across both surfaces — there is no second administration pipeline.
 */
class MedsBoardPayloadService
{
    /** @var array<int, bool> the competency decision per viewer, for this request */
    private array $medCompetent = [];

    public function __construct(
        protected MarScheduleService $scheduleService,
        protected MedicationGovernanceScopeService $governanceScope,
        protected UserSiteAccessService $siteAccess,
    ) {}

    /**
     * The one detailed administrations query for a day — reused for matching
     * scheduled dose slots and deriving PRN follow-ups.
     *
     * @param  array<int, int>  $clientIds
     * @return Collection<int, ClientMedicationAdministration>
     */
    public function administrationsForDay(array $clientIds, Carbon $date, bool $includeControlled = false): Collection
    {
        if (empty($clientIds)) {
            return collect();
        }

        try {
            [$dayStartUtc, $dayEndUtc] = $this->scheduleService->utcDayWindow($date);

            return $this->canonicalAdministrations($includeControlled)
                ->whereIn('client_id', $clientIds)
                ->where(function ($query) use ($dayStartUtc, $dayEndUtc) {
                    $query->whereBetween('scheduled_for', [$dayStartUtc, $dayEndUtc])
                        ->orWhere(function ($query) use ($dayStartUtc, $dayEndUtc) {
                            $query->whereNull('scheduled_for')
                                ->whereBetween('administered_at', [$dayStartUtc, $dayEndUtc]);
                        });
                })
                ->with([
                    'administeredBy:id,name',
                    'witnessedBy:id,name',
                    'medication:id,client_id,name,dosage,route,is_prn,controlled_drug,witness_required',
                    'prnEffectiveness:id,client_medication_administration_id',
                ])
                ->orderBy('id')
                ->get();
        } catch (\Throwable $e) {
            report($e);

            return collect();
        }
    }

    /**
     * Index a day's administrations by scheduled-slot key, ignoring rows with
     * no scheduled_for. Used to match recorded doses onto scheduled slots.
     *
     * @param  Collection<int, ClientMedicationAdministration>  $dayAdministrations
     * @return Collection<string, ClientMedicationAdministration>
     */
    public function slotIndex(Collection $dayAdministrations): Collection
    {
        return $dayAdministrations
            ->filter(fn (ClientMedicationAdministration $a) => $a->getRawOriginal('scheduled_for') !== null)
            ->keyBy(fn (ClientMedicationAdministration $a) => $this->scheduleService->slotKey(
                (int) $a->client_id,
                (int) $a->client_medication_id,
                $this->rawUtcInstant($a, 'scheduled_for'),
            ));
    }

    /**
     * Every scheduled (non-PRN) dose slot for the selected day — recorded or
     * not — for the given clients.
     *
     * The doses and their states are the dose-slot projection's (C6b, via
     * ScheduledDoseStates): due at the slot's due time; "due" from the moment
     * a dose shows as due soon (DoseTimingSettings) through its window
     * (DoseWindowResolver); "overdue" once the window has ended with nothing
     * recorded; "pending_check" — Waiting for the order check — for a dose of
     * an order whose change waits for its check: shown, never overdue, and
     * not recordable until the order is checked. A recorded dose reads as its
     * record's outcome — "missed" is Missed (recorded), never overdue
     * (ScheduledDoseStates::statusFor, shared with My Day).
     *
     * @param  array<int, int>  $clientIds
     * @param  Collection<string, ClientMedicationAdministration>  $bySlot
     * @param  array{total: int, overdue: int}|null  $hidden  filled, when passed, with the controlled doses left out for this reader and how many are overdue
     * @return array<int, array<string, mixed>>
     */
    public function scheduleForDate(array $clientIds, Carbon $date, Carbon $now, Collection $bySlot, bool $includeControlled = false, ?array &$hidden = null): array
    {
        $hidden = ['total' => 0, 'overdue' => 0];
        if (empty($clientIds)) {
            return [];
        }

        try {
            $timezone = $this->scheduleService->workerTimezone();

            // One orders query; the controlled ones are counted, not listed,
            // for a reader without controlled-medicine access (EM-12).
            [$controlled, $medications] = $this->scheduledOrders($clientIds)
                ->with('client:id,first_name,last_name,site_id')
                ->get()
                ->partition(fn (ClientMedication $order): bool => ! $includeControlled && (bool) $order->controlled_drug);
            $doses = app(ScheduledDoseStates::class)->dosesOn($medications->concat($controlled), $date, $now);
            // Counted only for a caller that asks (passes $hidden).
            if (func_num_args() >= 6) {
                $hidden = $this->hiddenControlledDoses($controlled, $doses, $clientIds, $date);
            }

            $rows = [];

            foreach ($medications as $med) {
                foreach ($doses[(int) $med->id] ?? [] as $dose) {
                    $scheduled = $dose['due_at'];
                    $administration = $bySlot->get(
                        $this->scheduleService->slotKey((int) $med->client_id, (int) $med->id, $scheduled),
                    );

                    if ($administration && ! in_array($administration->status, ScheduledDoseStates::RECORDED_STATUSES, true)) {
                        $administration = null;
                    }

                    $status = ScheduledDoseStates::statusFor($dose, $administration?->status);

                    $clientName = $med->client
                        ? trim($med->client->first_name.' '.$med->client->last_name)
                        : 'Unknown';

                    $rows[] = [
                        'key' => $med->id.':'.$scheduled->copy()->utc()->format('YmdHi'),
                        'client_id' => $med->client_id,
                        'client_name' => $clientName,
                        'medication_id' => $med->id,
                        'medication_name' => $med->name,
                        'dose' => $med->dosage,
                        'route' => $med->route,
                        'is_controlled' => (bool) ($med->controlled_drug ?? false),
                        'requires_witness' => (bool) ($med->witness_required ?? false) || (bool) ($med->controlled_drug ?? false),
                        'scheduled_for' => $scheduled->toIso8601String(),
                        'time' => $scheduled->copy()->timezone($timezone)->format('H:i'),
                        'round_label' => $this->roundLabelFor($scheduled->copy()->timezone($timezone)),
                        'status' => $status,
                        'recorded' => $administration ? $this->recordedPayload($administration, $timezone) : null,
                        'mar_url' => EmarUrl::mar($med->client_id, $scheduled->toDateString()),
                    ];
                }
            }

            usort($rows, function ($a, $b) {
                $timeCmp = strcmp($a['scheduled_for'], $b['scheduled_for']);
                if ($timeCmp !== 0) {
                    return $timeCmp;
                }

                return strcmp($a['client_name'], $b['client_name']);
            });

            return $rows;
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * How many of the day's scheduled doses for these people are of a
     * controlled medicine — the doses left off the list for a reader without
     * controlled-medicine access (EM-12), so the list can say how many it
     * doesn't show without naming any.
     *
     * @param  array<int, int>  $clientIds
     */
    /**
     * The day's doses of controlled medicines left off the list for a reader
     * without controlled-medicine access, and how many of them are overdue —
     * counted, never named (EM-12), so the list reconciles with the badge.
     *
     * @param  Collection<int, ClientMedication>  $controlled
     * @param  array<int, list<array<string, mixed>>>  $doses
     * @param  array<int, int>  $clientIds
     * @return array{total: int, overdue: int}
     */
    private function hiddenControlledDoses(Collection $controlled, array $doses, array $clientIds, Carbon $date): array
    {
        $hidden = ['total' => 0, 'overdue' => 0];
        if ($controlled->isEmpty()) {
            return $hidden;
        }

        // Records of every medicine (the board's own query leaves controlled
        // ones out for this reader), to tell which hidden doses are overdue.
        $records = $this->scheduleService->administrationsForWindow($clientIds, $date, $date);
        foreach ($controlled as $order) {
            foreach ($doses[(int) $order->id] ?? [] as $dose) {
                $record = $records->get($this->scheduleService->slotKey((int) $order->client_id, (int) $order->id, $dose['due_at']));
                $hidden['total']++;
                if (ScheduledDoseStates::statusFor($dose, $record?->status) === 'overdue') {
                    $hidden['overdue']++;
                }
            }
        }

        return $hidden;
    }

    /**
     * The scheduled (non-PRN) orders whose doses a board lists: verified
     * orders, and orders whose change waits for the order check (their
     * verified version's doses are still owed).
     *
     * @param  array<int, int>  $clientIds
     * @return Builder<ClientMedication>
     */
    private function scheduledOrders(array $clientIds): Builder
    {
        return ScheduledDoseStates::listedOrders($clientIds);
    }

    /** @return array<string, mixed> */
    public function recordedPayload(ClientMedicationAdministration $administration, string $timezone): array
    {
        $administeredAt = $administration->getRawOriginal('administered_at')
            ? $this->rawUtcInstant($administration, 'administered_at')->setTimezone($timezone)
            : null;

        return [
            'id' => $administration->id,
            'status' => $administration->status,
            'administered_at' => $administeredAt?->toIso8601String(),
            'time' => $administeredAt?->format('H:i'),
            'by' => $administration->administeredBy?->name,
            'witness' => $administration->witnessedBy?->name,
            'reason' => $administration->reason,
            'reason_label' => $administration->reason_code
                ? NotGivenReason::tryFrom($administration->reason_code)?->label()
                : null,
            'notes' => $administration->notes,
        ];
    }

    /** Friendly time-of-day bucket shown under the slot time. */
    public function roundLabelFor(Carbon $localTime): string
    {
        $hour = (int) $localTime->format('G');

        return match (true) {
            $hour < 11 => 'Morning',
            $hour < 14 => 'Midday',
            $hour < 17 => 'Afternoon',
            $hour < 21 => 'Evening',
            default => 'Night',
        };
    }

    /** @param  array<int, int>  $clientIds
     *  @return array<int, array<string, mixed>> */
    public function clientsPayload(array $clientIds): array
    {
        if (empty($clientIds)) {
            return [];
        }

        // Allergies are read separately so a failed allergy read is reported
        // as "unavailable" rather than looking like nothing is recorded (EM-07).
        // Both the medication allergy register and the health profile count.
        try {
            $allergyLabels = app(ClientAllergyRecordService::class)
                ->labelsForClients(array_values(array_map('intval', $clientIds)));
        } catch (\Throwable $e) {
            report($e);
            $allergyLabels = null;
        }

        try {
            $timezone = $this->scheduleService->workerTimezone();

            return Client::query()
                ->whereIn('id', $clientIds)
                ->with(['site:id,name'])
                ->orderBy('first_name')
                ->get()
                ->map(function (Client $client) use ($timezone, $allergyLabels) {
                    $name = trim($client->first_name.' '.$client->last_name);
                    $dob = $client->date_of_birth;
                    $allergies = $allergyLabels[(int) $client->id] ?? [];

                    return [
                        'id' => $client->id,
                        'name' => $name,
                        'preferred' => $client->preferred_name ?: $client->first_name,
                        'nhi' => $client->nhi_number,
                        'dob' => $dob?->format('j M Y'),
                        'age' => $dob ? (int) $dob->copy()->timezone($timezone)->diffInYears(now($timezone)) : null,
                        'site_id' => $client->site_id,
                        'site_name' => $client->site?->name,
                        'allergies' => $allergies,
                        // recorded | none_recorded | unavailable — never a
                        // confirmed "no known allergies".
                        'allergy_status' => match (true) {
                            $allergyLabels === null => 'unavailable',
                            $allergies !== [] => 'recorded',
                            default => 'none_recorded',
                        },
                    ];
                })
                ->values()
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** @param  array<int, int>  $clientIds
     *  @return array<int, array{id: int, name: string}> */
    public function sitesPayload(array $clientIds): array
    {
        if (empty($clientIds)) {
            return [];
        }

        try {
            return Client::query()
                ->whereIn('id', $clientIds)
                ->whereNotNull('site_id')
                ->with('site:id,name')
                ->get()
                ->pluck('site')
                ->filter()
                ->unique('id')
                ->sortBy('name')
                ->map(fn ($site) => ['id' => $site->id, 'name' => $site->name])
                ->values()
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * PRN (as-needed) medications available for quick recording, scoped to the
     * given clients.
     *
     * @param  array<int, int>  $clientIds
     * @return array<int, array<string, mixed>>
     */
    public function prnMedications(array $clientIds, Carbon $now, bool $includeControlled = false): array
    {
        if (empty($clientIds)) {
            return [];
        }

        try {
            $timezone = $this->scheduleService->workerTimezone();

            $medications = ClientMedication::whereIn('client_id', $clientIds)
                ->active()
                ->prn()
                ->when(! $includeControlled, fn ($query) => $query->where('controlled_drug', false))
                ->with('client:id,first_name,last_name')
                ->orderBy('client_id')
                ->orderBy('name')
                ->get();

            $recentGivenByMed = $medications->isEmpty()
                ? collect()
                : $this->canonicalAdministrations($includeControlled)
                    ->whereIn('client_medication_id', $medications->pluck('id'))
                    ->where('status', 'given')
                    ->selectRaw(
                        'client_medication_id, SUM(CASE WHEN administered_at >= ? THEN 1 ELSE 0 END) as given_count, MAX(administered_at) as last_given_at',
                        // A bound Carbon is formatted without converting its
                        // timezone, and administered_at is stored in UTC: an
                        // NZ "now" here counted only the last ~11–13 hours.
                        [$now->copy()->utc()->subHours(24)],
                    )
                    ->groupBy('client_medication_id')
                    ->get()
                    ->keyBy('client_medication_id');

            $result = [];

            foreach ($medications as $med) {
                if (! $med->client) {
                    continue;
                }

                $maxPerDay = $med->max_per_day ? (int) $med->max_per_day : null;
                $recentGiven = $recentGivenByMed->get($med->id);
                $givenLast24h = (int) ($recentGiven?->given_count ?? 0);
                $remaining = $maxPerDay !== null ? max(0, $maxPerDay - $givenLast24h) : null;

                $lastGivenRaw = $recentGiven?->last_given_at;
                $lastGiven = $lastGivenRaw ? Carbon::parse((string) $lastGivenRaw, 'UTC')->setTimezone($timezone) : null;
                $minHours = $med->min_hours_between_doses ? (float) $med->min_hours_between_doses : null;
                $nextAllowed = ($lastGiven && $minHours)
                    ? $lastGiven->copy()->addMinutes((int) round($minHours * 60))
                    : null;

                $result[] = [
                    'id' => $med->id,
                    'client_id' => $med->client_id,
                    'client_name' => trim($med->client->first_name.' '.$med->client->last_name),
                    'name' => $med->name,
                    'dose' => $med->dosage,
                    'route' => $med->route,
                    'form' => $med->form,
                    'instructions' => $med->instructions,
                    'prn_reason' => $med->prn_reason,
                    'max_per_day' => $maxPerDay,
                    'given_last_24h' => $givenLast24h,
                    'remaining_today' => $remaining,
                    'near_limit' => $maxPerDay !== null && $givenLast24h >= ($maxPerDay * 0.75),
                    'over_limit' => $maxPerDay !== null && $givenLast24h >= $maxPerDay,
                    'is_controlled' => (bool) ($med->controlled_drug ?? false),
                    'requires_witness' => (bool) ($med->witness_required ?? false) || (bool) ($med->controlled_drug ?? false),
                    'min_hours_between' => $minHours,
                    'last_given_at' => $lastGiven?->toIso8601String(),
                    'last_given_label' => $lastGiven ? $this->friendlyTimeLabel($lastGiven, $now) : null,
                    'next_allowed_at' => $nextAllowed?->toIso8601String(),
                    'interval_blocked' => $nextAllowed !== null && $nextAllowed->isAfter($now),
                    'next_allowed_label' => $nextAllowed?->format('g:i a'),
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    public function friendlyTimeLabel(Carbon $instant, Carbon $now): string
    {
        if ($instant->isSameDay($now)) {
            return 'Today '.$instant->format('g:i a');
        }

        if ($instant->isSameDay($now->copy()->subDay())) {
            return 'Yesterday '.$instant->format('g:i a');
        }

        return $instant->format('j M, g:i a');
    }

    /** @return Builder<ClientMedicationAdministration> */
    private function canonicalAdministrations(bool $includeControlled = false): Builder
    {
        $query = $this->governanceScope->scopeCanonicalClientMedicationRows(
            ClientMedicationAdministration::query()->effectiveClinicalEvidence(),
            null,
            false,
        );

        if (! $includeControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($query);
        }

        return $query;
    }

    /**
     * Current staff eligible to witness for the clients on this board. A read-
     * only actor never receives a witness picker, and both actor and candidate
     * remain inside the canonical approved-Site boundary.
     *
     * @param  array<int, int>  $clientIds
     * @return array<int, array{id: int, name: string}>
     */
    public function witnesses(User $user, array $clientIds): array
    {
        if (! $user->canDo('medications.administer.record') || empty($clientIds)) {
            return [];
        }

        try {
            $approvedSiteIds = $this->siteAccess->accessibleSiteIds(
                $user,
                MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
            );
            if ($approvedSiteIds === []) {
                return [];
            }

            $boardSiteIds = Client::query()
                ->whereIn('id', $clientIds)
                ->whereIn('site_id', $approvedSiteIds)
                ->pluck('site_id')
                ->map(fn ($siteId) => (int) $siteId)
                ->filter(fn (int $siteId) => $siteId > 0)
                ->unique()
                ->values()
                ->all();
            if ($boardSiteIds === []) {
                return [];
            }

            return $this->governanceScope
                ->controlledWitnessPicker($boardSiteIds, $user->id)
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** Coded "reason not given" options surfaced in the record wizard. */
    public function notGivenReasons(): array
    {
        return NotGivenReason::options();
    }

    /** The signing identity + competency flags shown in the record wizard. */
    public function boardUser(User $user): array
    {
        return [
            'first_name' => Str::before(trim((string) $user->name), ' ') ?: $user->name,
            'name' => $user->name,
            'role_label' => $user->role ? Str::headline($user->role) : null,
            'med_competent' => $this->isMedCompetent($user),
            'controlled_record' => $user->canDo('medications.controlled.record'),
            'cd_witness' => $user->canDo('medications.controlled.witness'),
            // NF-03: the organisation's restricted-competency rule, shown to
            // the worker before they sign (null when no rule applies).
            'competency_notice' => $this->competencyNoticeFor($user),
            // PIN-1: without a usable witness PIN this person can't be chosen
            // to witness or co-sign; the board prompts them to set one.
            'witness_pin' => app(WitnessPinService::class)->status($user),
        ];
    }

    /**
     * "Med-competent": the viewer may record doses and the competency policy
     * allows them to give medicines now — a passed, acknowledged, in-date
     * assessment, or an approved exemption at one of their Sites. The
     * permission alone is not competence (no assessment, or one awaiting
     * acknowledgement, is not). Worked out once per viewer per request.
     */
    public function isMedCompetent(User $user): bool
    {
        return $this->medCompetent[(int) $user->id] ??= $this->decideMedCompetent($user);
    }

    private function decideMedCompetent(User $user): bool
    {
        if (! $user->canDo('medications.administer.record')) {
            return false;
        }

        try {
            $policy = app(MedicationAdministratorCompetencyPolicy::class);
            $now = now();
            if ($policy->evaluate($user, null, $now)['allowed']) {
                return true;
            }

            // An exemption is Site-scoped: any of the viewer's Sites will do.
            $siteIds = $this->siteAccess->accessibleSiteIds($user, MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS);
            $exemptionSiteIds = $siteIds === [] ? collect() : MedicationCompetencyExemption::query()
                ->where('user_id', $user->id)
                ->where('scope', MedicationCompetencyExemption::SCOPE_ADMINISTRATION)
                ->whereNull('revoked_at')
                ->whereIn('site_id', $siteIds)
                ->distinct()
                ->pluck('site_id');
            foreach ($exemptionSiteIds as $siteId) {
                if ($policy->evaluate($user, (int) $siteId, $now)['allowed']) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            report($e);

            // Not shown as competent; the server still decides when a dose is signed.
            return false;
        }
    }

    /** @return array{requires_cosigner: bool, blocked: bool, message: string}|null */
    public function competencyNoticeFor(User $user): ?array
    {
        try {
            return app(MedicationCompetencyRestrictionRules::class)->noticeFor($user, null, now());
        } catch (\Throwable $e) {
            report($e);

            // The server still enforces the rule when the dose is signed.
            return null;
        }
    }

    public function rawUtcInstant(ClientMedicationAdministration $administration, string $column): Carbon
    {
        $raw = $administration->getRawOriginal($column);

        return $raw
            ? Carbon::parse((string) $raw, 'UTC')
            : Carbon::createFromTimestamp(0, 'UTC');
    }
}
