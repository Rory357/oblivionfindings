<?php

namespace App\Services;

use App\Http\Controllers\Concerns\SanitizesCsvOutput;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientIncident;
use App\Models\ClientInrRecord;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationError;
use App\Models\MedicationOrderVersion;
use App\Models\MedicationReview;
use App\Models\MedicationSyringeDriver;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Support\Medication\MedicationStockQuantity;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MedicationReportingService
{
    use SanitizesCsvOutput;

    public function __construct(
        private MedicationGovernanceScopeService $governanceScope,
    ) {}

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<int, int>|null  $siteIds
     * @return Builder<TModel>
     */
    private function canonicalMedicationRows(
        Builder $query,
        ?array $siteIds,
        bool $allowNullMedication = false,
    ): Builder {
        return $this->governanceScope->scopeCanonicalClientMedicationRows(
            $query,
            $this->failClosedSiteIds($siteIds),
            $allowNullMedication,
        );
    }

    /** @return array<int, int> */
    private function failClosedSiteIds(?array $siteIds): array
    {
        return $siteIds ?? [];
    }

    /** @return Builder<ClientMedicationAdministration> */
    private function effectiveAdministrationRows(?array $siteIds, bool $includeControlled = false): Builder
    {
        $query = $this->canonicalMedicationRows(
            ClientMedicationAdministration::query()->effectiveClinicalEvidence(),
            $siteIds,
        );

        if (! $includeControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($query);
        }

        return $query;
    }

    /**
     * Generate MAR export for date range
     */
    public function exportMar(
        ?int $clientId = null,
        ?Carbon $dateFrom = null,
        ?Carbon $dateTo = null,
        ?int $serviceContextId = null,
        ?string $status = null,
        ?string $careLevel = null,
        ?array $siteIds = null,
        bool $includeControlled = false,
    ): array {
        $dateFrom = $dateFrom ?? now()->subDays(30);
        $dateTo = $dateTo ?? now();

        $query = $this->effectiveAdministrationRows($siteIds, $includeControlled)
            ->with([
                'client:id,first_name,last_name,care_level',
                'medication:id,name,dosage,controlled_drug,is_prn,route,form,pharmac_therapeutic_group,pharmac_subgroup,deleted_at',
                'administeredBy:id,name',
                'witnessedBy:id,name',
                'serviceContext:id,name',
                'shift:id,starts_at,ends_at',
            ])
            ->whereBetween('administered_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]);

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        if ($serviceContextId) {
            $query->where('service_context_id', $serviceContextId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($careLevel) {
            $query->whereHas('client', fn ($q) => $q->where('care_level', $careLevel));
        }

        $administrations = $query->orderBy('administered_at')->get();

        return [
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'total_records' => $administrations->count(),
            ],
            'records' => $administrations->map(fn ($a) => [
                'id' => $a->id,
                'date' => $a->administered_at?->toDateString(),
                'time' => $a->administered_at?->format('H:i'),
                'client' => $a->client ? trim("{$a->client->first_name} {$a->client->last_name}") : 'Unknown',
                'client_id' => $a->client_id,
                'care_level' => $a->client?->care_level,
                'medication' => $a->medication?->historicalDisplayName() ?? 'Unknown',
                'dosage' => $a->medication?->dosage ?? 'N/A',
                'route' => $a->medication?->route ?? 'N/A',
                'form' => $a->medication?->form ?? 'N/A',
                'pharmac_therapeutic_group' => $a->medication?->pharmac_therapeutic_group,
                'pharmac_subgroup' => $a->medication?->pharmac_subgroup,
                'is_prn' => $a->medication?->is_prn ?? false,
                'controlled_drug' => $a->medication?->controlled_drug ?? false,
                'status' => $a->status,
                'dose_given' => $a->dose_given,
                'reason_code' => $a->reason_code,
                'reason' => $a->reason,
                'notes' => $a->notes,
                'administered_by' => $a->administeredBy?->name ?? 'Unknown',
                'witnessed_by' => $a->witnessedBy?->name,
                'blood_glucose_level' => $a->blood_glucose_level,
                'pulse_bpm' => $a->pulse_bpm,
                'blood_pressure' => $a->blood_pressure_systolic && $a->blood_pressure_diastolic
                    ? "{$a->blood_pressure_systolic}/{$a->blood_pressure_diastolic}"
                    : null,
                'scheduled_for' => $a->scheduled_for?->toDateTimeString(),
                'shift_date' => $a->shift?->starts_at?->toDateString(),
                'service_context' => $a->serviceContext?->name ?? 'N/A',
                'late_minutes' => $a->late_minutes,
                'early_minutes' => $a->early_minutes,
                'is_correction' => $a->is_correction,
                'correction_reason' => $a->correction_reason,
            ])->toArray(),
        ];
    }

    /**
     * Generate PRN usage report
     */
    public function reportPrnUsage(
        ?int $clientId = null,
        ?Carbon $dateFrom = null,
        ?Carbon $dateTo = null,
        ?string $careLevel = null,
        ?array $siteIds = null,
        bool $includeControlled = false,
    ): array {
        $dateFrom = $dateFrom ?? now()->subDays(30);
        $dateTo = $dateTo ?? now();

        $query = $this->effectiveAdministrationRows($siteIds, $includeControlled)
            ->whereHas('medication', fn ($q) => $q->where('is_prn', true))
            ->where('status', 'given')
            ->whereBetween('administered_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()])
            ->with(['medication:id,name,max_per_day,client_id,pharmac_therapeutic_group,deleted_at', 'client:id,first_name,last_name,care_level']);

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        if ($careLevel) {
            $query->whereHas('client', fn ($q) => $q->where('care_level', $careLevel));
        }

        $administrations = $query->orderBy('administered_at')->get();

        // Group by medication and client
        $grouped = $administrations->groupBy(fn ($a) => "{$a->client_id}-{$a->client_medication_id}");

        $daysInRange = max(
            1,
            $dateFrom->copy()->startOfDay()->diffInDays($dateTo->copy()->startOfDay()) + 1
        );

        $summaries = $grouped->map(function ($group) use ($daysInRange) {
            $first = $group->first();
            $count = $group->count();
            $maxPerDay = (int) filter_var($first->medication?->max_per_day, FILTER_SANITIZE_NUMBER_INT);

            return [
                'client_id' => $first->client_id,
                'client_name' => $first->client ? trim("{$first->client->first_name} {$first->client->last_name}") : 'Unknown',
                'care_level' => $first->client?->care_level,
                'medication_id' => $first->client_medication_id,
                'medication_name' => $first->medication?->historicalDisplayName() ?? 'Unknown',
                'pharmac_therapeutic_group' => $first->medication?->pharmac_therapeutic_group,
                'max_per_day' => $maxPerDay ?: null,
                'total_administrations' => $count,
                'average_per_day' => round($count / $daysInRange, 2),
                'first_administration' => $group->first()->administered_at?->toDateString(),
                'last_administration' => $group->last()->administered_at?->toDateString(),
                'limit_exceeded_count' => $maxPerDay > 0 ? $group->filter(function ($a) use ($maxPerDay) {
                    // Count administrations where this was over the daily limit
                    $dayCount = $group->whereBetween('administered_at', [
                        $a->administered_at->copy()->startOfDay(),
                        $a->administered_at->copy()->endOfDay(),
                    ])->count();

                    return $dayCount > $maxPerDay;
                })->count() : 0,
            ];
        })->values();

        return [
            'meta' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'total_prn_administrations' => $administrations->count(),
            ],
            'summaries' => $summaries->toArray(),
            'daily_breakdown' => $this->getPrnDailyBreakdown($administrations),
        ];
    }

    public function reportRegularUsage(?int $clientId = null, ?Carbon $dateFrom = null, ?Carbon $dateTo = null, ?string $careLevel = null, ?array $siteIds = null, bool $includeControlled = false): array
    {
        return $this->reportMedicationUsageByType('regular', $clientId, $dateFrom, $dateTo, $careLevel, $siteIds, $includeControlled);
    }

    public function reportShortCourseUsage(?int $clientId = null, ?Carbon $dateFrom = null, ?Carbon $dateTo = null, ?string $careLevel = null, ?array $siteIds = null, bool $includeControlled = false): array
    {
        return $this->reportMedicationUsageByType('short_course', $clientId, $dateFrom, $dateTo, $careLevel, $siteIds, $includeControlled);
    }

    private function reportMedicationUsageByType(string $type, ?int $clientId, ?Carbon $dateFrom, ?Carbon $dateTo, ?string $careLevel, ?array $siteIds = null, bool $includeControlled = false): array
    {
        $dateFrom = $dateFrom ?? now()->subDays(30);
        $dateTo = $dateTo ?? now();

        $query = $this->effectiveAdministrationRows($siteIds, $includeControlled)
            ->with(['client:id,first_name,last_name,care_level', 'medication:id,name,dosage,is_prn,end_date,pharmac_therapeutic_group,pharmac_subgroup,deleted_at', 'administeredBy:id,name'])
            ->where('status', 'given')
            ->whereBetween('administered_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()])
            ->whereHas('medication', function ($q) use ($type) {
                $q->where('is_prn', false);
                if ($type === 'short_course') {
                    $q->whereNotNull('end_date');
                } else {
                    $q->whereNull('end_date');
                }
            })
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->when($careLevel, fn ($q) => $q->whereHas('client', fn ($clientQuery) => $clientQuery->where('care_level', $careLevel)));

        $records = $query->orderBy('administered_at')->get();

        return [
            'meta' => [
                'type' => $type,
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'total_records' => $records->count(),
            ],
            'records' => $records->map(fn ($a) => [
                'date' => $a->administered_at?->toDateTimeString(),
                'client' => $a->client ? trim("{$a->client->first_name} {$a->client->last_name}") : 'Unknown',
                'care_level' => $a->client?->care_level,
                'medication' => $a->medication?->historicalDisplayName() ?? 'Unknown',
                'dosage' => $a->medication?->dosage,
                'pharmac_therapeutic_group' => $a->medication?->pharmac_therapeutic_group,
                'pharmac_subgroup' => $a->medication?->pharmac_subgroup,
                'dose_given' => $a->dose_given,
                'administered_by' => $a->administeredBy?->name,
            ])->toArray(),
        ];
    }

    public function reportObservationUsage(?int $clientId = null, ?Carbon $dateFrom = null, ?Carbon $dateTo = null, ?string $careLevel = null, ?array $siteIds = null, bool $includeControlled = false): array
    {
        $dateFrom = $dateFrom ?? now()->subDays(30);
        $dateTo = $dateTo ?? now();

        $observations = $this->effectiveAdministrationRows($siteIds, $includeControlled)
            ->with(['client:id,first_name,last_name,care_level', 'medication:id,name,pharmac_therapeutic_group,deleted_at'])
            ->whereBetween('administered_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()])
            ->where(function ($query) {
                $query->whereNotNull('blood_glucose_level')
                    ->orWhereNotNull('pulse_bpm')
                    ->orWhereNotNull('blood_pressure_systolic')
                    ->orWhereNotNull('blood_pressure_diastolic');
            })
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->when($careLevel, fn ($q) => $q->whereHas('client', fn ($clientQuery) => $clientQuery->where('care_level', $careLevel)))
            ->orderBy('administered_at')
            ->get()
            ->map(fn ($a) => [
                'date' => $a->administered_at?->toDateTimeString(),
                'client' => $a->client ? trim("{$a->client->first_name} {$a->client->last_name}") : 'Unknown',
                'care_level' => $a->client?->care_level,
                'medication' => $a->medication?->historicalDisplayName(),
                'observation_type' => 'administration_observation',
                'value' => collect([
                    'bsl' => $a->blood_glucose_level,
                    'pulse' => $a->pulse_bpm,
                    'blood_pressure' => $a->blood_pressure_systolic && $a->blood_pressure_diastolic
                        ? "{$a->blood_pressure_systolic}/{$a->blood_pressure_diastolic}"
                        : null,
                ])->filter()->toJson(),
                'pharmac_therapeutic_group' => $a->medication?->pharmac_therapeutic_group,
            ]);

        $inrQuery = $this->canonicalMedicationRows(
            ClientInrRecord::query(),
            $siteIds,
        );
        if (! $includeControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($inrQuery);
        }
        $inrs = $inrQuery
            ->with(['client:id,first_name,last_name,care_level', 'medication:id,name,pharmac_therapeutic_group'])
            ->whereBetween('tested_on', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->when($careLevel, fn ($q) => $q->whereHas('client', fn ($clientQuery) => $clientQuery->where('care_level', $careLevel)))
            ->orderBy('tested_on')
            ->get()
            ->map(fn ($record) => [
                'date' => $record->tested_on?->toDateString(),
                'client' => $record->client ? trim("{$record->client->first_name} {$record->client->last_name}") : 'Unknown',
                'care_level' => $record->client?->care_level,
                'medication' => $record->medication?->name ?? 'Warfarin',
                'observation_type' => 'inr',
                'value' => $record->inr_value,
                'pharmac_therapeutic_group' => $record->medication?->pharmac_therapeutic_group,
            ]);

        $records = $observations->merge($inrs)->sortBy('date')->values();

        return [
            'meta' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'total_records' => $records->count(),
            ],
            'records' => $records->toArray(),
        ];
    }

    public function reportSyringeDriverUsage(
        ?int $clientId = null,
        ?Carbon $dateFrom = null,
        ?Carbon $dateTo = null,
        ?string $careLevel = null,
        ?array $siteIds = null,
        bool $includeControlled = false,
    ): array {
        $dateFrom = $dateFrom ?? now()->subDays(30);
        $dateTo = $dateTo ?? now();
        $siteIds = $this->failClosedSiteIds($siteIds);

        $drivers = MedicationSyringeDriver::query()
            ->whereHas('client', fn ($client) => $client->whereIn('site_id', $siteIds))
            ->with(['client:id,first_name,last_name,care_level', 'commencedBy:id,name', 'completedBy:id,name'])
            ->whereBetween('commenced_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()])
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->when($careLevel, fn ($q) => $q->whereHas('client', fn ($clientQuery) => $clientQuery->where('care_level', $careLevel)))
            ->orderBy('commenced_at')
            ->get()
            ->map(function (MedicationSyringeDriver $driver) use ($includeControlled): ?MedicationSyringeDriver {
                $contents = $this->governanceScope->visibleSyringeDriverContents(
                    $driver->client,
                    $driver->contents ?? [],
                    $includeControlled,
                );
                if ($contents === null) {
                    return null;
                }

                $driver->setAttribute('contents', $contents);

                return $driver;
            })
            ->filter()
            ->values();

        return [
            'meta' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'total_records' => $drivers->count(),
            ],
            'records' => $drivers->map(fn ($driver) => [
                'commenced_at' => $driver->commenced_at?->toDateTimeString(),
                'client' => $driver->client ? trim("{$driver->client->first_name} {$driver->client->last_name}") : 'Unknown',
                'care_level' => $driver->client?->care_level,
                'status' => $driver->status,
                'rate' => $driver->rate,
                'rate_unit' => $driver->rate_unit,
                'duration_hours' => $driver->duration_hours,
                'contents' => $driver->contents,
                'site_of_insertion' => $driver->site_of_insertion,
                'commenced_by' => $driver->commencedBy?->name,
                'completed_at' => $driver->completed_at?->toDateTimeString(),
                'completed_by' => $driver->completedBy?->name,
            ])->toArray(),
        ];
    }

    public function reportChartReviews(?int $clientId = null, ?Carbon $dateFrom = null, ?Carbon $dateTo = null, ?string $careLevel = null, ?array $siteIds = null): array
    {
        $dateFrom = $dateFrom ?? now()->subDays(30);
        $dateTo = $dateTo ?? now();
        $siteIds = $this->failClosedSiteIds($siteIds);

        $reviews = MedicationReview::query()
            ->whereHas('client', fn ($client) => $client->whereIn('site_id', $siteIds))
            ->with('client:id,first_name,last_name,care_level,next_chart_review_date')
            ->where(function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('scheduled_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
                    ->orWhereBetween('completed_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
                    ->orWhereBetween('next_review_date', [$dateFrom->toDateString(), $dateTo->toDateString()]);
            })
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->when($careLevel, fn ($q) => $q->whereHas('client', fn ($clientQuery) => $clientQuery->where('care_level', $careLevel)))
            ->orderBy('scheduled_date')
            ->get();

        return [
            'meta' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'total_records' => $reviews->count(),
            ],
            'records' => $reviews->map(fn ($review) => [
                'client' => $review->client ? trim("{$review->client->first_name} {$review->client->last_name}") : 'Unknown',
                'care_level' => $review->client?->care_level,
                'review_type' => $review->review_type,
                'status' => $review->status,
                'scheduled_date' => $review->scheduled_date?->toDateString(),
                'completed_date' => $review->completed_date?->toDateString(),
                'next_review_date' => $review->next_review_date?->toDateString(),
                'next_chart_review_date' => $review->client?->next_chart_review_date?->toDateString(),
            ])->toArray(),
        ];
    }

    /**
     * Get PRN daily breakdown
     */
    private function getPrnDailyBreakdown(Collection $administrations): array
    {
        return $administrations
            ->groupBy(fn ($a) => $a->administered_at?->toDateString() ?? 'unknown')
            ->map(fn ($group, $date) => [
                'date' => $date,
                'count' => $group->count(),
                'medications' => $group
                    ->map(fn ($administration) => $administration->medication?->historicalDisplayName())
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray(),
            ])
            ->values()
            ->toArray();
    }

    /**
     * Generate missed dose report
     */
    public function reportMissedDoses(
        ?int $clientId = null,
        ?Carbon $dateFrom = null,
        ?Carbon $dateTo = null,
        ?array $siteIds = null,
        bool $includeControlled = false,
    ): array {
        $dateFrom = $dateFrom ?? now()->subDays(30);
        $dateTo = $dateTo ?? now();

        $query = $this->effectiveAdministrationRows($siteIds, $includeControlled)
            ->where('status', 'missed')
            ->whereBetween('scheduled_for', [$dateFrom->startOfDay(), $dateTo->endOfDay()])
            ->with(['medication:id,name,dosage,controlled_drug,high_risk,deleted_at', 'client:id,first_name,last_name']);

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        $missed = $query->orderByDesc('scheduled_for')->get();

        return [
            'meta' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'total_missed' => $missed->count(),
            ],
            'records' => $missed->map(fn ($m) => [
                'id' => $m->id,
                'date' => $m->scheduled_for?->toDateString(),
                'time' => $m->scheduled_for?->format('H:i'),
                'client' => $m->client ? trim("{$m->client->first_name} {$m->client->last_name}") : 'Unknown',
                'client_id' => $m->client_id,
                'medication' => $m->medication?->historicalDisplayName() ?? 'Unknown',
                'dosage' => $m->medication?->dosage ?? 'N/A',
                'controlled_drug' => $m->medication?->controlled_drug ?? false,
                'high_risk' => $m->medication?->high_risk ?? false,
                'reason' => $m->reason,
                'notes' => $m->notes,
                'severity' => $m->medication?->controlled_drug ? 'critical' :
                    ($m->medication?->high_risk ? 'high' : 'medium'),
            ])->toArray(),
            'summary_by_client' => $missed->groupBy('client_id')
                ->map(fn ($group) => [
                    'client_name' => $group->first()->client ?
                        trim("{$group->first()->client->first_name} {$group->first()->client->last_name}") : 'Unknown',
                    'count' => $group->count(),
                    'controlled_missed' => $group->where('medication.controlled_drug', true)->count(),
                ])
                ->values()
                ->toArray(),
        ];
    }

    /**
     * Generate late dose report
     */
    public function reportLateDoses(
        ?int $clientId = null,
        ?Carbon $dateFrom = null,
        ?Carbon $dateTo = null,
        int $lateThresholdMinutes = 30,
        ?array $siteIds = null,
        bool $includeControlled = false,
    ): array {
        $dateFrom = $dateFrom ?? now()->subDays(30);
        $dateTo = $dateTo ?? now();

        $query = $this->effectiveAdministrationRows($siteIds, $includeControlled)
            ->where('status', 'given')
            ->whereNotNull('scheduled_for')
            ->whereNotNull('administered_at')
            ->whereBetween('administered_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()])
            ->whereRaw("TIMESTAMPDIFF(MINUTE, scheduled_for, administered_at) > {$lateThresholdMinutes}")
            ->with(['medication:id,name,dosage,deleted_at', 'client:id,first_name,last_name']);

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        $late = $query->orderByDesc('administered_at')->get();

        return [
            'meta' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'late_threshold_minutes' => $lateThresholdMinutes,
                'total_late' => $late->count(),
            ],
            'records' => $late->map(fn ($l) => [
                'id' => $l->id,
                'date' => $l->administered_at?->toDateString(),
                'client' => $l->client ? trim("{$l->client->first_name} {$l->client->last_name}") : 'Unknown',
                'client_id' => $l->client_id,
                'medication' => $l->medication?->historicalDisplayName() ?? 'Unknown',
                'scheduled_time' => $l->scheduled_for?->format('H:i'),
                'administered_time' => $l->administered_at?->format('H:i'),
                'late_minutes' => $l->scheduled_for->diffInMinutes($l->administered_at),
                'reason' => $l->reason,
            ])->toArray(),
        ];
    }

    /**
     * Generate controlled drug balance report
     */
    public function reportControlledDrugBalance(
        ?int $clientId = null,
        ?int $medicationId = null,
        ?array $siteIds = null,
    ): array {
        $siteIds = $this->failClosedSiteIds($siteIds);

        $query = ClientMedication::where('controlled_drug', true)
            ->active()
            ->whereHas('client', fn ($client) => $client->whereIn('site_id', $siteIds))
            ->with(['stock', 'client:id,first_name,last_name']);

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        if ($medicationId) {
            $query->where('id', $medicationId);
        }

        $medications = $query->get();

        $balances = $medications->map(fn ($m) => [
            'client_id' => $m->client_id,
            'client_name' => $m->client ? trim("{$m->client->first_name} {$m->client->last_name}") : 'Unknown',
            'medication_id' => $m->id,
            'medication_name' => $m->name,
            'form' => $m->form,
            'strength' => $m->dosage,
            'current_balance' => $m->stock?->on_hand ?? 0,
            'unit' => $m->stock?->unit ?? 'units',
            'reorder_level' => $m->stock?->reorder_level,
            'last_counted_at' => $m->stock?->last_counted_at?->toIso8601String(),
            'status' => $this->getStockStatus($m->stock),
        ])->toArray();

        return [
            'generated_at' => now()->toIso8601String(),
            'total_controlled_medications' => $medications->count(),
            'balances' => $balances,
        ];
    }

    /**
     * Get stock status
     */
    private function getStockStatus(?ClientMedicationStock $stock): string
    {
        if (! $stock || $stock->on_hand === null) {
            return 'unknown';
        }
        if (MedicationStockQuantity::equals($stock->on_hand, 0)) {
            return 'out_of_stock';
        }
        if ($stock->isLowStock()) {
            return 'low_stock';
        }

        return 'ok';
    }

    /**
     * Generate controlled discrepancy report
     */
    public function reportControlledDiscrepancies(
        ?int $clientId = null,
        ?string $status = null,
        ?Carbon $dateFrom = null,
        ?Carbon $dateTo = null,
        ?array $siteIds = null,
    ): array {
        $dateFrom = $dateFrom ?? now()->subDays(90);
        $dateTo = $dateTo ?? now();

        $query = $this->canonicalMedicationRows(
            ClientControlledDrugDiscrepancy::query(),
            $siteIds,
        )
            ->with([
                'client:id,first_name,last_name',
                'medication:id,name',
                'reportedBy:id,name',
                'resolvedBy:id,name',
            ])
            ->whereBetween('reported_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]);

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        $discrepancies = $query->orderByDesc('reported_at')->get();

        return [
            'meta' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'total_discrepancies' => $discrepancies->count(),
                'open_count' => $discrepancies->where('status', 'open')->count(),
                'under_review_count' => $discrepancies->where('status', 'under_review')->count(),
                'closed_count' => $discrepancies->where('status', 'closed')->count(),
            ],
            'records' => $discrepancies->map(fn ($d) => [
                'id' => $d->id,
                'reported_at' => $d->reported_at?->toIso8601String(),
                'client' => $d->client ? trim("{$d->client->first_name} {$d->client->last_name}") : 'Unknown',
                'medication' => $d->medication?->name ?? 'Unknown',
                'expected' => $d->on_hand_before,
                'actual' => $d->on_hand_after,
                'difference' => $d->difference,
                'status' => $d->status,
                'reason' => $d->reason,
                'reported_by' => $d->reportedBy?->name,
                'resolved_at' => $d->resolved_at?->toIso8601String(),
                'resolved_by' => $d->resolvedBy?->name,
                'resolution_notes' => $d->resolution_notes,
            ])->toArray(),
        ];
    }

    /**
     * Generate medication change history report
     */
    public function reportMedicationChanges(
        ?int $clientId = null,
        ?int $medicationId = null,
        ?Carbon $dateFrom = null,
        ?Carbon $dateTo = null,
        ?array $siteIds = null,
        bool $includeControlled = false,
    ): array {
        $dateFrom = $dateFrom ?? now()->subDays(90);
        $dateTo = $dateTo ?? now();

        $query = $this->canonicalMedicationRows(
            MedicationOrderVersion::query(),
            $siteIds,
        );
        if (! $includeControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($query);
            $query->where('medication_order_versions.controlled_drug', false);
        }
        $query
            ->with([
                'client:id,first_name,last_name',
                'medication:id,name',
                'changedBy:id,name',
            ])
            ->whereBetween('changed_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]);

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        if ($medicationId) {
            $query->where('client_medication_id', $medicationId);
        }

        $versions = $query->orderByDesc('changed_at')->get();

        return [
            'meta' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'total_changes' => $versions->count(),
            ],
            'records' => $versions->map(fn ($v) => [
                'id' => $v->id,
                'changed_at' => $v->changed_at?->toIso8601String(),
                'client' => $v->client ? trim("{$v->client->first_name} {$v->client->last_name}") : 'Unknown',
                'medication' => $v->name,
                'version_number' => $v->version_number,
                'dosage' => $v->dosage,
                'state' => $v->state,
                'change_reason' => $v->change_reason,
                'changed_by' => $v->changedBy?->name ?? 'Unknown',
            ])->toArray(),
        ];
    }

    /**
     * Generate medication incidents report
     */
    public function reportMedicationIncidents(
        ?int $clientId = null,
        ?Carbon $dateFrom = null,
        ?Carbon $dateTo = null,
        ?array $siteIds = null,
        bool $includeControlled = false,
    ): array {
        $dateFrom = $dateFrom ?? now()->subDays(90);
        $dateTo = $dateTo ?? now();
        $siteIds = $this->failClosedSiteIds($siteIds);

        $query = ClientIncident::query()
            ->whereHas('client', fn ($client) => $client->whereIn('site_id', $siteIds))
            ->whereBetween('occurred_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()])
            ->with(['client:id,first_name,last_name']);

        $hasMedicationMetadata = Schema::hasTable('client_incidents')
            && Schema::hasColumn('client_incidents', 'metadata');
        if ($hasMedicationMetadata) {
            $query->where(function (Builder $incidentQuery): void {
                $incidentQuery
                    ->whereNotNull('metadata->medication_id')
                    ->orWhereExists(function ($errors): void {
                        $errors
                            ->selectRaw('1')
                            ->from('medication_errors')
                            ->whereColumn('medication_errors.client_incident_id', 'client_incidents.id')
                            ->whereColumn('medication_errors.client_id', 'client_incidents.client_id')
                            ->whereNotNull('medication_errors.client_medication_id')
                            ->whereNull('medication_errors.deleted_at');
                    });
            });
        } else {
            $query->whereIn('type', $includeControlled
                ? ['medication', 'controlled_drug']
                : ['medication']);
        }

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        $incidentRows = $query->orderByDesc('occurred_at')->get();
        if ($hasMedicationMetadata) {
            $linkedErrors = MedicationError::query()
                ->whereIn('client_incident_id', $incidentRows->modelKeys())
                ->orderBy('id')
                ->get(['id', 'client_incident_id', 'client_id', 'client_medication_id'])
                ->groupBy('client_incident_id');
            $metadataMedicationIds = $incidentRows
                ->map(fn (ClientIncident $incident) => data_get($incident->metadata, 'medication_id'))
                ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
                ->map(fn ($id) => (int) $id);
            $linkedMedicationIds = $linkedErrors
                ->flatten(1)
                ->pluck('client_medication_id')
                ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
                ->map(fn ($id) => (int) $id);
            $medicationIds = $metadataMedicationIds
                ->merge($linkedMedicationIds)
                ->unique()
                ->values();
            $medications = ClientMedication::withTrashed()
                ->whereIn('id', $medicationIds)
                ->get(['id', 'client_id', 'name', 'controlled_drug', 'deleted_at'])
                ->keyBy(fn (ClientMedication $medication) => $medication->client_id.':'.$medication->id);

            $incidents = $incidentRows
                ->map(function (ClientIncident $incident) use ($includeControlled, $linkedErrors, $medications): ?array {
                    $medicationId = data_get($incident->metadata, 'medication_id');
                    if ($medicationId === null) {
                        $errorRows = $linkedErrors->get($incident->id, collect());
                        if ($errorRows->count() !== 1) {
                            return null;
                        }

                        /** @var MedicationError $linkedError */
                        $linkedError = $errorRows->first();
                        if ((int) $linkedError->client_id !== (int) $incident->client_id
                            || ! is_numeric($linkedError->client_medication_id)
                            || (int) $linkedError->client_medication_id <= 0) {
                            return null;
                        }
                        $medicationId = (int) $linkedError->client_medication_id;
                    } elseif (! is_numeric($medicationId) || (int) $medicationId <= 0) {
                        return null;
                    }

                    $medication = $medications->get($incident->client_id.':'.(int) $medicationId);
                    if ($medication === null
                        || (! $includeControlled && ($medication->controlled_drug || $incident->type === 'controlled_drug'))) {
                        return null;
                    }

                    return [
                        'incident' => $incident,
                        'medication' => $medication,
                        'medication_id' => (int) $medicationId,
                    ];
                })
                ->filter()
                ->values();
        } else {
            $incidents = $incidentRows
                ->map(fn (ClientIncident $incident) => [
                    'incident' => $incident,
                    'medication' => null,
                    'medication_id' => null,
                ]);
        }

        return [
            'meta' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'total_incidents' => $incidents->count(),
            ],
            'summary_by_category' => $incidents->groupBy(fn (array $row) => $row['incident']->category)
                ->map(fn ($group) => [
                    'count' => $group->count(),
                    'by_severity' => $group->groupBy(fn (array $row) => $row['incident']->severity)->map->count(),
                ])
                ->toArray(),
            'records' => $incidents->map(function (array $row): array {
                /** @var ClientIncident $incident */
                $incident = $row['incident'];
                /** @var ClientMedication|null $medication */
                $medication = $row['medication'];

                return [
                    'id' => $incident->id,
                    'occurred_at' => $incident->occurred_at?->toIso8601String(),
                    'client' => $incident->client
                        ? trim("{$incident->client->first_name} {$incident->client->last_name}")
                        : 'Unknown',
                    'category' => $incident->category,
                    'severity' => $incident->severity,
                    'title' => $incident->title,
                    'medication_id' => $row['medication_id'],
                    'medication_name' => $medication?->historicalDisplayName() ?? $incident->title,
                    'controlled_drug' => (bool) $medication?->controlled_drug,
                    'high_risk' => $incident->metadata['high_risk'] ?? false,
                    'status' => $incident->status,
                ];
            })->toArray(),
        ];
    }

    /**
     * Generate comprehensive medication audit report
     */
    public function generateAuditReport(
        ?int $clientId = null,
        ?Carbon $dateFrom = null,
        ?Carbon $dateTo = null,
        ?array $siteIds = null,
        bool $includeControlled = false,
    ): array {
        $dateFrom = $dateFrom ?? now()->subDays(30);
        $dateTo = $dateTo ?? now();
        $siteIds = $this->failClosedSiteIds($siteIds);

        return [
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'client_id' => $clientId,
            ],
            'mar_summary' => $this->getMarSummary($clientId, $dateFrom, $dateTo, $siteIds, $includeControlled),
            'prn_summary' => $this->getPrnSummary($clientId, $dateFrom, $dateTo, $siteIds, $includeControlled),
            ...($includeControlled ? [
                'controlled_summary' => $this->getControlledSummary($clientId, $dateFrom, $dateTo, $siteIds),
            ] : []),
            'safety_alerts' => $this->getSafetyAlerts($clientId, $dateFrom, $dateTo, $siteIds, $includeControlled),
            'compliance_metrics' => $this->getComplianceMetrics($clientId, $dateFrom, $dateTo, $siteIds, $includeControlled),
        ];
    }

    /**
     * Get MAR summary for audit
     */
    private function getMarSummary(?int $clientId, Carbon $dateFrom, Carbon $dateTo, ?array $siteIds = null, bool $includeControlled = false): array
    {
        $query = $this->effectiveAdministrationRows($siteIds, $includeControlled)->whereBetween('administered_at', [
            $dateFrom->startOfDay(),
            $dateTo->endOfDay(),
        ]);

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        $rawCorrections = $this->canonicalMedicationRows(
            ClientMedicationAdministration::query(),
            $siteIds,
        );
        if (! $includeControlled) {
            $this->governanceScope->scopeWithoutControlledMedicationRows($rawCorrections);
        }
        $rawCorrections
            ->where('is_correction', true)
            ->whereBetween('administered_at', [
                $dateFrom->startOfDay(),
                $dateTo->endOfDay(),
            ])
            ->when($clientId, fn ($corrections) => $corrections->where('client_id', $clientId));
        $correctionsCount = $rawCorrections->count();
        $stats = (clone $query)->selectRaw('
            status,
            COUNT(*) as count,
            AVG(late_minutes) as avg_late_minutes
        ')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return [
            'total_administrations' => $stats->sum('count'),
            'by_status' => $stats->map(fn ($s) => [
                'count' => $s->count,
                'percentage' => 0, // Calculated below
                'avg_late_minutes' => round($s->avg_late_minutes ?? 0, 1),
            ])->toArray(),
            'corrections_count' => $correctionsCount,
        ];
    }

    /**
     * Get PRN summary for audit
     */
    private function getPrnSummary(?int $clientId, Carbon $dateFrom, Carbon $dateTo, ?array $siteIds = null, bool $includeControlled = false): array
    {
        $query = $this->effectiveAdministrationRows($siteIds, $includeControlled)
            ->whereHas('medication', fn ($q) => $q->where('is_prn', true))
            ->where('status', 'given')
            ->whereBetween('administered_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()]);

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        $totalPrn = $query->count();
        $limitExceedances = 0; // Would need complex query to calculate

        return [
            'total_prn_administrations' => $totalPrn,
            'limit_exceedances' => $limitExceedances,
            'avg_per_day' => round($totalPrn / max(1, $dateFrom->diffInDays($dateTo) + 1), 2),
        ];
    }

    /**
     * Get controlled drug summary for audit
     */
    private function getControlledSummary(?int $clientId, Carbon $dateFrom, Carbon $dateTo, ?array $siteIds = null): array
    {
        $query = $this->canonicalMedicationRows(
            ClientControlledDrugEntry::query(),
            $siteIds,
        )->whereBetween('recorded_at', [
            $dateFrom->startOfDay(),
            $dateTo->endOfDay(),
        ]);

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        return [
            'total_entries' => $query->count(),
            'by_type' => $query->clone()->selectRaw('entry_type, COUNT(*) as count')
                ->groupBy('entry_type')
                ->pluck('count', 'entry_type')
                ->toArray(),
            'discrepancies' => $this->canonicalMedicationRows(
                ClientControlledDrugDiscrepancy::query(),
                $siteIds,
            )->whereBetween('reported_at', [
                $dateFrom->startOfDay(),
                $dateTo->endOfDay(),
            ])
                ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
                ->count(),
        ];
    }

    /**
     * Get safety alerts for audit
     */
    private function getSafetyAlerts(?int $clientId, Carbon $dateFrom, Carbon $dateTo, ?array $siteIds = null, bool $includeControlled = false): array
    {
        $query = $this->canonicalMedicationRows(
            MedicationDashboardAlert::query(),
            $siteIds,
            true,
        )->whereBetween('created_at', [
            $dateFrom->startOfDay(),
            $dateTo->endOfDay(),
        ]);
        if (! $includeControlled) {
            $query->whereNotIn('alert_type', [
                'controlled_discrepancy',
                'controlled_overdue_check',
                'controlled_loss',
            ]);
            $this->governanceScope->scopeWithoutControlledMedicationRows($query);
        }

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        return [
            'total_alerts' => $query->count(),
            'by_type' => $query->clone()->selectRaw('alert_type, COUNT(*) as count')
                ->groupBy('alert_type')
                ->pluck('count', 'alert_type')
                ->toArray(),
            'by_severity' => $query->clone()->selectRaw('severity, COUNT(*) as count')
                ->groupBy('severity')
                ->pluck('count', 'severity')
                ->toArray(),
            'active_count' => $query->clone()->where('status', 'active')->count(),
        ];
    }

    /**
     * Get compliance metrics
     */
    private function getComplianceMetrics(?int $clientId, Carbon $dateFrom, Carbon $dateTo, ?array $siteIds = null, bool $includeControlled = false): array
    {
        $adminQuery = $this->effectiveAdministrationRows($siteIds, $includeControlled)->whereBetween('administered_at', [
            $dateFrom->startOfDay(),
            $dateTo->endOfDay(),
        ]);

        if ($clientId) {
            $adminQuery->where('client_id', $clientId);
        }

        $total = $adminQuery->count();
        $onTime = $adminQuery->clone()->whereNull('late_minutes')->count();
        $withWitness = $adminQuery->clone()->whereHas('medication', fn ($q) => $q->where('controlled_drug', true))
            ->whereNotNull('witnessed_by')
            ->count();
        $controlledTotal = $adminQuery->clone()->whereHas('medication', fn ($q) => $q->where('controlled_drug', true))
            ->count();

        return [
            'on_time_percentage' => $total > 0 ? round(($onTime / $total) * 100, 1) : 0,
            'witness_compliance_percentage' => $controlledTotal > 0 ? round(($withWitness / $controlledTotal) * 100, 1) : 100,
            'documentation_completeness' => $this->calculateDocumentationCompleteness($clientId, $dateFrom, $dateTo, $siteIds, $includeControlled),
        ];
    }

    /**
     * Calculate documentation completeness
     */
    private function calculateDocumentationCompleteness(?int $clientId, Carbon $dateFrom, Carbon $dateTo, ?array $siteIds = null, bool $includeControlled = false): float
    {
        $query = $this->effectiveAdministrationRows($siteIds, $includeControlled)->whereBetween('administered_at', [
            $dateFrom->startOfDay(),
            $dateTo->endOfDay(),
        ]);

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        $total = $query->count();
        if ($total === 0) {
            return 100.0;
        }

        $withNotes = $query->clone()->whereNotNull('notes')->count();
        $withReason = $query->clone()
            ->where(function ($builder) {
                $builder->where('status', 'given')->orWhereNotNull('reason');
            })
            ->count();

        return round((($withNotes + $withReason) / ($total * 2)) * 100, 1);
    }

    /**
     * Export to CSV format
     */
    public function exportToCsv(array $data, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');

            if (empty($data['records'] ?? [])) {
                $this->putCsv($out, ['No data available']);
                fclose($out);

                return;
            }

            // Headers from first record
            $headers = array_keys($data['records'][0]);
            $this->putCsv($out, $headers);

            // Data rows
            foreach ($data['records'] as $record) {
                $this->putCsv($out, array_map(function ($value) {
                    if (is_array($value)) {
                        return json_encode($value);
                    }

                    return $value;
                }, $record));
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
