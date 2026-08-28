<?php

namespace App\Services\Emar;

use App\Enums\Medication\NotGivenReason;
use App\Http\Controllers\Emar\EmarController;
use App\Http\Controllers\Emar\WorkerMedsController;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\User;
use App\Services\MarScheduleService;
use App\Services\Medication\MedicationGovernanceScopeService;
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
    /** Statuses that mean a dose slot has been actioned and needs no chasing. */
    private const RECORDED_STATUSES = ['given', 'refused', 'withheld', 'missed'];

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
     * @param  array<int, int>  $clientIds
     * @param  Collection<string, ClientMedicationAdministration>  $bySlot
     * @return array<int, array<string, mixed>>
     */
    public function scheduleForDate(array $clientIds, Carbon $date, Carbon $now, Collection $bySlot, bool $includeControlled = false): array
    {
        if (empty($clientIds)) {
            return [];
        }

        try {
            $timezone = $this->scheduleService->workerTimezone();

            $medications = ClientMedication::whereIn('client_id', $clientIds)
                ->active()
                ->where('is_prn', false)
                ->when(! $includeControlled, fn ($query) => $query->where('controlled_drug', false))
                ->where(function ($query) {
                    $query->whereNotNull('dose_times')
                        ->orWhereNotNull('frequency');
                })
                ->with('client:id,first_name,last_name,site_id')
                ->get();

            $rows = [];

            foreach ($medications as $med) {
                foreach ($this->scheduleService->scheduledTimesForDate($med, $date) as $scheduled) {
                    $administration = $bySlot->get(
                        $this->scheduleService->slotKey((int) $med->client_id, (int) $med->id, $scheduled),
                    );

                    if ($administration && ! in_array($administration->status, self::RECORDED_STATUSES, true)) {
                        $administration = null;
                    }

                    if ($administration) {
                        $status = $administration->status;
                    } elseif ($scheduled->lt($now)) {
                        $status = 'overdue';
                    } elseif ($scheduled->lte($now->copy()->addHour())) {
                        $status = 'due';
                    } else {
                        $status = 'upcoming';
                    }

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

        try {
            $timezone = $this->scheduleService->workerTimezone();

            return Client::query()
                ->whereIn('id', $clientIds)
                ->with(['site:id,name', 'medicationAllergies' => fn ($q) => $q->whereNull('deleted_at')])
                ->orderBy('first_name')
                ->get()
                ->map(function (Client $client) use ($timezone) {
                    $name = trim($client->first_name.' '.$client->last_name);
                    $dob = $client->date_of_birth;

                    return [
                        'id' => $client->id,
                        'name' => $name,
                        'preferred' => $client->preferred_name ?: $client->first_name,
                        'nhi' => $client->nhi_number,
                        'dob' => $dob?->format('j M Y'),
                        'age' => $dob ? (int) $dob->copy()->timezone($timezone)->diffInYears(now($timezone)) : null,
                        'site_id' => $client->site_id,
                        'site_name' => $client->site?->name,
                        'allergies' => $client->medicationAllergies
                            ->map(fn ($a) => trim((string) $a->allergen))
                            ->filter()
                            ->values()
                            ->all(),
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
                        [$now->copy()->subHours(24)],
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
            'med_competent' => $user->canDo('medications.administer.record'),
            'controlled_record' => $user->canDo('medications.controlled.record'),
            'cd_witness' => $user->canDo('medications.controlled.witness'),
        ];
    }

    public function rawUtcInstant(ClientMedicationAdministration $administration, string $column): Carbon
    {
        $raw = $administration->getRawOriginal($column);

        return $raw
            ? Carbon::parse((string) $raw, 'UTC')
            : Carbon::createFromTimestamp(0, 'UTC');
    }
}
