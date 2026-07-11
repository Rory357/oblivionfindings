<?php

namespace App\Services;

use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Enums\Medication\NotGivenReason;
use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationCompetencyAssessment;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class EnhancedMarService
{
    protected MarScheduleService $scheduleService;

    protected MedicationSafetyService $safetyService;

    protected MedicationScanVerificationService $scanVerificationService;

    protected MedicationRuleService $ruleService;

    public function __construct(
        MarScheduleService $scheduleService,
        MedicationSafetyService $safetyService,
        MedicationScanVerificationService $scanVerificationService,
        MedicationRuleService $ruleService
    ) {
        $this->scheduleService = $scheduleService;
        $this->safetyService = $safetyService;
        $this->scanVerificationService = $scanVerificationService;
        $this->ruleService = $ruleService;
    }

    /**
     * Build the enhanced MAR view for a client
     */
    public function build(Client $client, Carbon $date, ?Carbon $now = null, ?int $activeShiftId = null): array
    {
        $date = $date->copy()->timezone($this->scheduleService->workerTimezone())->startOfDay();
        $now = ($now ?? now())->copy()->timezone($this->scheduleService->workerTimezone());
        $isToday = $date->isSameDay($now);

        // Get client's allergies for safety display
        $allergies = $client->medicationAllergies()
            ->whereNull('deleted_at')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'allergen' => $a->allergen,
                'reaction' => $a->reaction,
                'severity' => $a->severity,
                'is_severe' => $a->isSevere(),
            ])
            ->toArray();

        // Get active medications
        $medications = $client->medications()
            ->active()
            ->where(function ($q) use ($date) {
                $q->whereNull('start_date')->orWhere('start_date', '<=', $date->toDateString());
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $date->toDateString());
            })
            ->with(['stock'])
            ->get();

        $awaitingVerification = $client->medications()
            ->awaitingVerification()
            ->where(function ($q) use ($date) {
                $q->whereNull('start_date')->orWhere('start_date', '<=', $date->toDateString());
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $date->toDateString());
            })
            ->with(['stock'])
            ->get()
            ->map(fn (ClientMedication $medication) => [
                'client_medication_id' => $medication->id,
                'medication' => $this->formatMedication($medication, $client),
                'approval_status' => $medication->approval_status,
                'can_record' => false,
                'reason' => 'Medication order is awaiting verification.',
            ])
            ->values()
            ->all();

        // Build scheduled rows
        $scheduledRows = [];
        $prnRows = [];

        foreach ($medications as $medication) {
            // Build scheduled doses for non-PRN medications
            if (! $medication->is_prn) {
                $scheduledTimes = $this->scheduleService->scheduledTimesForDate($medication, $date);

                foreach ($scheduledTimes as $scheduledFor) {
                    $row = $this->buildScheduledRow($medication, $scheduledFor, $now, $date, $client, $activeShiftId);
                    $scheduledRows[] = $row;
                }
            } else {
                // Build PRN row
                $prnRows[] = $this->buildPrnRow($medication, $now, $client, $activeShiftId);
            }
        }

        // Sort scheduled rows by time
        usort($scheduledRows, fn ($a, $b) => strcmp($a['scheduled_time'], $b['scheduled_time']));

        // Get recent administration history
        $history = $this->getHistory($client, $date);

        // Get upcoming doses (for today view)
        $upcoming = $isToday ? $this->getUpcomingDoses($scheduledRows, $now) : [];

        // Calculate summary stats
        $stats = $this->calculateStats($scheduledRows, $prnRows, $history);

        return [
            'date' => $date->toDateString(),
            'is_today' => $isToday,
            'scheduled' => $scheduledRows,
            'prn' => $prnRows,
            'history' => $history,
            'awaiting_verification' => $awaitingVerification,
            'attention_alerts' => $this->getAttentionAlerts($client),
            'inr_records' => $this->getInrRecords($client),
            'syringe_drivers' => $this->getRunningSyringeDrivers($client),
            'upcoming' => $upcoming,
            'stats' => $stats,
            'allergies' => $allergies,
            'settings' => [
                'window_before_minutes' => $this->scheduleService->windowBeforeMinutes(),
                'window_after_minutes' => $this->scheduleService->windowAfterMinutes(),
                'due_soon_minutes' => $this->scheduleService->dueSoonMinutes(),
                'suppress_med_admin_alerts' => (bool) $client->suppress_med_admin_alerts,
                'med_alerts_suppressed_reason' => $client->med_alerts_suppressed_reason,
                'chart_review_interval_months' => $client->chart_review_interval_months,
                'next_chart_review_date' => $client->next_chart_review_date?->toDateString(),
                'care_level' => $client->care_level,
            ],
            'active_shift_id' => $activeShiftId,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getAttentionAlerts(Client $client): array
    {
        return $client->medicationAlerts()
            ->enabled()
            ->unresolved()
            ->latest()
            ->get()
            ->map(fn ($alert) => [
                'id' => $alert->id,
                'type' => $alert->type,
                'title' => $alert->title,
                'detail' => $alert->detail,
                'prompt_on_open' => (bool) $alert->prompt_on_open,
                'created_at' => $alert->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getInrRecords(Client $client): array
    {
        return $client->inrRecords()
            ->with('medication:id,name')
            ->latest('tested_on')
            ->limit(20)
            ->get()
            ->map(fn ($record) => [
                'id' => $record->id,
                'client_medication_id' => $record->client_medication_id,
                'medication_name' => $record->medication?->name,
                'inr_value' => $record->inr_value,
                'target_range_low' => $record->target_range_low,
                'target_range_high' => $record->target_range_high,
                'dose_mg' => $record->dose_mg,
                'tested_on' => $record->tested_on?->toDateString(),
                'next_test_date' => $record->next_test_date?->toDateString(),
                'disabled_at' => $record->disabled_at?->toIso8601String(),
                'notes' => $record->notes,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getRunningSyringeDrivers(Client $client): array
    {
        return $client->syringeDrivers()
            ->running()
            ->with(['checks' => fn ($query) => $query->latest('checked_at')->limit(5)])
            ->latest('commenced_at')
            ->get()
            ->map(fn ($driver) => [
                'id' => $driver->id,
                'status' => $driver->status,
                'commenced_at' => $driver->commenced_at?->toIso8601String(),
                'rate' => $driver->rate,
                'rate_unit' => $driver->rate_unit,
                'duration_hours' => $driver->duration_hours,
                'contents' => $driver->contents ?? [],
                'site_of_insertion' => $driver->site_of_insertion,
                'notes' => $driver->notes,
                'checks' => $driver->checks
                    ->map(fn ($check) => [
                        'id' => $check->id,
                        'checked_at' => $check->checked_at?->toIso8601String(),
                        'infusion_running' => (bool) $check->infusion_running,
                        'site_condition' => $check->site_condition,
                        'volume_remaining' => $check->volume_remaining,
                        'notes' => $check->notes,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Build a scheduled medication row
     */
    private function buildScheduledRow(
        ClientMedication $medication,
        Carbon $scheduledFor,
        Carbon $now,
        Carbon $date,
        Client $client,
        ?int $activeShiftId
    ): array {
        // Get existing administration for this slot
        [$slotStartUtc, $slotEndUtc] = $this->scheduleService->utcSlotWindow($scheduledFor);

        $existing = ClientMedicationAdministration::where('client_medication_id', $medication->id)
            ->whereBetween('scheduled_for', [$slotStartUtc, $slotEndUtc])
            ->with(['administeredBy:id,name', 'witnessedBy:id,name'])
            ->first();

        $scheduleState = $this->getScheduleState($scheduledFor, $now, $date->isToday(), $existing);
        [$windowStart, $windowEnd] = $this->scheduleService->windowForScheduled($scheduledFor);

        // Perform safety check
        $safetyCheck = $existing ? null : $this->safetyService->performSafetyCheck($client, $medication, $now);
        $adminRules = $this->ruleService->requirementsFor($medication);

        return [
            'id' => $existing?->id,
            'client_medication_id' => $medication->id,
            'medication' => $this->formatMedication($medication, $client),
            'scheduled_for' => $scheduledFor->toIso8601String(),
            'scheduled_time' => $scheduledFor->format('H:i'),
            'schedule_state' => $scheduleState,
            'schedule_state_label' => $this->getScheduleStateLabel($scheduleState),
            'window_start' => $windowStart->toIso8601String(),
            'window_end' => $windowEnd->toIso8601String(),
            'can_record' => $scheduleState !== 'completed' && $scheduleState !== 'future' && $medication->isAdministrable(),
            'is_overdue' => $scheduleState === 'missed_auto' || $scheduleState === 'late',
            'requires_witness' => $medication->requiresWitness() || $adminRules['requires_countersign'],
            'administration' => $existing ? $this->formatAdministration($existing) : null,
            'safety_check' => $safetyCheck,
            'is_correction' => $existing?->is_correction ?? false,
        ];
    }

    /**
     * Build a PRN medication row
     */
    private function buildPrnRow(
        ClientMedication $medication,
        Carbon $now,
        Client $client,
        ?int $activeShiftId
    ): array {
        // Get PRN history
        $prnHistory = $this->safetyService->getPrnHistory($medication, 24);

        // Perform safety check
        $safetyCheck = $this->safetyService->performSafetyCheck($client, $medication, $now);
        $adminRules = $this->ruleService->requirementsFor($medication);

        return [
            'client_medication_id' => $medication->id,
            'medication' => $this->formatMedication($medication, $client),
            'is_prn' => true,
            'prn_reason' => $medication->prn_reason,
            'max_per_day' => $medication->max_per_day,
            'count_24h' => $prnHistory['count'],
            'remaining_today' => $prnHistory['remaining_today'],
            'prn_history' => $prnHistory['history'],
            'is_near_limit' => $medication->isPrnNearLimit(),
            'is_over_limit' => $medication->isPrnOverLimit(),
            'is_blocked' => $safetyCheck['blocked'],
            'can_record' => ! $safetyCheck['blocked'] && $medication->isAdministrable(),
            'requires_witness' => $medication->requiresWitness() || $adminRules['requires_countersign'],
            'safety_check' => $safetyCheck,
        ];
    }

    /**
     * Format medication for display
     */
    private function formatMedication(ClientMedication $medication, ?Client $client = null): array
    {
        return [
            'id' => $medication->id,
            'name' => $medication->name,
            'dosage' => $medication->formatted_dose,
            'route' => $medication->route,
            'form' => $medication->form,
            'instructions' => $medication->instructions,
            'indication' => $medication->indication,
            'is_prn' => $medication->is_prn,
            'prn_reason' => $medication->prn_reason,
            'controlled_drug' => $medication->controlled_drug,
            'high_risk' => $medication->high_risk,
            'witness_required' => $medication->witness_required,
            'prescriber' => $medication->prescriber,
            'pharmacy' => $medication->pharmacy,
            'pharmac_therapeutic_group' => $medication->pharmac_therapeutic_group,
            'pharmac_subgroup' => $medication->pharmac_subgroup,
            'state' => $medication->state,
            'approval_status' => $medication->approval_status ?? 'verified',
            'is_administrable' => $medication->isAdministrable(),
            'admin_rules' => $this->ruleService->requirementsFor($medication),
            'stock' => $medication->stock ? [
                'on_hand' => $medication->stock->on_hand,
                'unit' => $medication->stock->unit,
                'reorder_level' => $medication->stock->reorder_level,
            ] : null,
            'scan_verification' => $client
                ? $this->scanVerificationService->payload($client, $medication)
                : null,
        ];
    }

    /**
     * Format administration for display
     */
    private function formatAdministration(ClientMedicationAdministration $admin): array
    {
        $scheduledFor = $this->administrationDateUtc($admin, 'scheduled_for');
        $administeredAt = $this->administrationDateUtc($admin, 'administered_at');

        return [
            'id' => $admin->id,
            'status' => $admin->status,
            'status_label' => $this->getStatusLabel($admin->status),
            'dose_given' => $admin->dose_given,
            'reason' => $admin->reason,
            'reason_code' => $admin->reason_code,
            'reason_label' => $admin->reason_code ? NotGivenReason::tryFrom($admin->reason_code)?->label() : null,
            'notes' => $admin->notes,
            'scheduled_for' => $scheduledFor?->toIso8601String(),
            'administered_at' => $administeredAt?->toIso8601String(),
            'administered_by' => $admin->administeredBy?->name,
            'witnessed_by' => $admin->witnessedBy?->name,
            'witnessed_at' => $admin->witnessed_at?->toIso8601String(),
            'witness_method' => $admin->witness_method,
            'blood_glucose_level' => $admin->blood_glucose_level,
            'pulse_bpm' => $admin->pulse_bpm,
            'blood_pressure_systolic' => $admin->blood_pressure_systolic,
            'blood_pressure_diastolic' => $admin->blood_pressure_diastolic,
            'late_minutes' => $admin->late_minutes,
            'early_minutes' => $admin->early_minutes,
            'is_correction' => $admin->is_correction,
            'correction_reason' => $admin->correction_reason,
            'outcome' => $admin->outcome,
        ];
    }

    private function administrationDateUtc(ClientMedicationAdministration $administration, string $column): ?Carbon
    {
        $raw = $administration->getRawOriginal($column);

        return $raw
            ? Carbon::parse((string) $raw, 'UTC')
            : null;
    }

    /**
     * Get schedule state
     */
    private function getScheduleState(Carbon $scheduledFor, Carbon $now, bool $isToday, ?ClientMedicationAdministration $existing): string
    {
        if ($existing) {
            return 'completed';
        }

        if (! $isToday) {
            return $scheduledFor->isPast() ? 'missed_auto' : 'future';
        }

        $dueSoonMinutes = 60;
        $lateAfterMinutes = 30;
        $missAfterMinutes = 180;

        $diff = $scheduledFor->diffInMinutes($now, false);

        if ($diff < -$dueSoonMinutes) {
            return 'upcoming';
        }
        if ($diff >= -$dueSoonMinutes && $diff < 0) {
            return 'due_soon';
        }
        if ($diff >= 0 && $diff <= $lateAfterMinutes) {
            return 'due';
        }
        if ($diff > $lateAfterMinutes && $diff <= $missAfterMinutes) {
            return 'late';
        }

        return 'missed_auto';
    }

    /**
     * Get schedule state label
     */
    private function getScheduleStateLabel(string $state): array
    {
        return match ($state) {
            'upcoming' => ['label' => 'Upcoming', 'color' => 'slate', 'icon' => 'clock'],
            'due_soon' => ['label' => 'Due Soon', 'color' => 'yellow', 'icon' => 'alert-circle'],
            'due' => ['label' => 'Due', 'color' => 'blue', 'icon' => 'check-circle'],
            'late' => ['label' => 'Late', 'color' => 'orange', 'icon' => 'alert-triangle'],
            'missed_auto' => ['label' => 'Missed', 'color' => 'red', 'icon' => 'x-circle'],
            'completed' => ['label' => 'Given', 'color' => 'green', 'icon' => 'check'],
            'future' => ['label' => 'Future', 'color' => 'slate', 'icon' => 'calendar'],
            default => ['label' => $state, 'color' => 'gray', 'icon' => 'help-circle'],
        };
    }

    /**
     * Get status label
     */
    private function getStatusLabel(string $status): array
    {
        return match ($status) {
            'given' => ['label' => 'Given', 'color' => 'green'],
            'refused' => ['label' => 'Refused', 'color' => 'orange'],
            'withheld' => ['label' => 'Withheld', 'color' => 'yellow'],
            'missed' => ['label' => 'Missed', 'color' => 'red'],
            default => ['label' => $status, 'color' => 'gray'],
        };
    }

    /**
     * Get administration history for the date
     */
    private function getHistory(Client $client, Carbon $date): array
    {
        [$dayStartUtc, $dayEndUtc] = $this->scheduleService->utcDayWindow($date);

        return ClientMedicationAdministration::where('client_id', $client->id)
            ->where(function ($q) use ($dayStartUtc, $dayEndUtc) {
                $q->whereBetween('administered_at', [$dayStartUtc, $dayEndUtc])
                    ->orWhereBetween('scheduled_for', [$dayStartUtc, $dayEndUtc]);
            })
            ->with(['medication:id,name,dosage,controlled_drug', 'administeredBy:id,name', 'witnessedBy:id,name'])
            ->orderByDesc('administered_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(function (ClientMedicationAdministration $administration) {
                $scheduledFor = $this->administrationDateUtc($administration, 'scheduled_for');
                $administeredAt = $this->administrationDateUtc($administration, 'administered_at');

                return [
                    'id' => $administration->id,
                    'medication_name' => $administration->medication?->name ?? 'Unknown',
                    'status' => $administration->status,
                    'status_label' => $this->getStatusLabel($administration->status),
                    'dose_given' => $administration->dose_given,
                    'reason' => $administration->reason,
                    'notes' => $administration->notes,
                    'administered_at' => $administeredAt?->toIso8601String(),
                    'scheduled_for' => $scheduledFor?->toIso8601String(),
                    'administered_by' => $administration->administeredBy?->name,
                    'witnessed_by' => $administration->witnessedBy?->name,
                    'is_correction' => $administration->is_correction,
                    'correction_reason' => $administration->correction_reason,
                    'controlled_drug' => $administration->medication?->controlled_drug ?? false,
                ];
            })
            ->toArray();
    }

    /**
     * Get upcoming doses for today
     */
    private function getUpcomingDoses(array $scheduledRows, Carbon $now): array
    {
        return collect($scheduledRows)
            ->filter(fn ($r) => in_array($r['schedule_state'], ['due', 'due_soon', 'late'], true))
            ->sortBy('scheduled_for')
            ->values()
            ->toArray();
    }

    /**
     * Calculate MAR statistics
     */
    private function calculateStats(array $scheduledRows, array $prnRows, array $history): array
    {
        $scheduledStats = [
            'total' => count($scheduledRows),
            'completed' => count(array_filter($scheduledRows, fn ($r) => $r['schedule_state'] === 'completed')),
            'due' => count(array_filter($scheduledRows, fn ($r) => $r['schedule_state'] === 'due')),
            'late' => count(array_filter($scheduledRows, fn ($r) => $r['schedule_state'] === 'late')),
            'missed' => count(array_filter($scheduledRows, fn ($r) => $r['schedule_state'] === 'missed_auto')),
            'upcoming' => count(array_filter($scheduledRows, fn ($r) => in_array($r['schedule_state'], ['upcoming', 'due_soon']))),
        ];

        $prnStats = [
            'total_medications' => count($prnRows),
            'near_limit' => count(array_filter($prnRows, fn ($r) => $r['is_near_limit'])),
            'over_limit' => count(array_filter($prnRows, fn ($r) => $r['is_over_limit'])),
        ];

        $controlledCount = count(array_filter($scheduledRows, fn ($r) => $r['medication']['controlled_drug']))
            + count(array_filter($prnRows, fn ($r) => $r['medication']['controlled_drug']));

        return [
            'scheduled' => $scheduledStats,
            'prn' => $prnStats,
            'controlled_count' => $controlledCount,
            'history_count' => count($history),
            'completion_percentage' => $scheduledStats['total'] > 0
                ? round(($scheduledStats['completed'] / $scheduledStats['total']) * 100, 1)
                : 0,
        ];
    }

    /**
     * Record medication administration
     */
    public function recordAdministration(
        Client $client,
        ClientMedication $medication,
        array $data,
        int $userId,
        ?int $shiftId = null
    ): array {
        if ($shiftId !== null && ! Shift::query()
            ->whereKey($shiftId)
            ->where('client_id', $client->id)
            ->exists()) {
            return [
                'success' => false,
                'error' => 'The selected shift does not belong to this client.',
                'error_field' => 'shift_id',
            ];
        }

        if (! $medication->isAdministrable()) {
            return [
                'success' => false,
                'error' => 'Medication order is awaiting verification before it can be administered.',
                'error_field' => 'approval_status',
            ];
        }

        $notGivenValidation = $this->validateNotGivenReason($data);
        if ($notGivenValidation !== null) {
            return $notGivenValidation;
        }

        $adminRules = $this->ruleService->requirementsFor($medication);
        $observationValidation = $this->validateRequiredObservations($adminRules['required_observations'], $data);
        if ($observationValidation !== null) {
            return $observationValidation;
        }

        $witnessValidation = $this->validateWitness($medication, $adminRules, $data, $userId);
        if (! ($witnessValidation['success'] ?? false)) {
            return $witnessValidation;
        }

        $competencyValidation = $this->validateAdministratorCompetency($data, $userId);
        if ($competencyValidation !== null) {
            return $competencyValidation;
        }

        $covertValidation = $this->validateCovertAuthorisation($medication, $data);
        if ($covertValidation !== null) {
            return $covertValidation;
        }

        // Validate safety check (including dose validation)
        $safetyCheck = $this->safetyService->performSafetyCheck(
            $client,
            $medication,
            null,
            $data['dose_given'] ?? null
        );

        if ($safetyCheck['blocked'] && ! ($data['override_safety'] ?? false)) {
            // A blocked PRN over its 24h limit is an incident-worthy event no
            // matter which surface attempted it (MAR wizard, My Day, guided
            // round). Fire it here — the shared choke point — deduped so a
            // worker re-tapping doesn't raise duplicates.
            if ($medication->is_prn && $medication->fresh()->isPrnBlocked()) {
                $limitIncidentKey = 'emar:prn-over-limit:'.$client->id.':'.$medication->id.':'.now()->format('YmdHi');
                if (Cache::add($limitIncidentKey, true, now()->addMinutes(15))) {
                    app(MedicationIncidentIntegrationService::class)
                        ->handlePrnOverLimit($client, $medication->fresh(), $userId);
                }
            }

            return [
                'success' => false,
                'error' => $safetyCheck['block_reason'],
                'error_field' => 'client_medication_id',
                'safety_check' => $safetyCheck,
            ];
        }

        // A blocked safety check that proceeds via override_safety must leave a
        // durable trace on the MAR record — a silent override is an audit gap.
        if ($safetyCheck['blocked'] && ($data['override_safety'] ?? false)) {
            $overrideNote = '⚠ Safety check overridden by recorder: '.($safetyCheck['block_reason'] ?? 'blocked');
            $data['notes'] = trim(($data['notes'] ?? '') === '' ? $overrideNote : $data['notes']."\n".$overrideNote);
        }

        // Validate time window for scheduled doses
        $scheduledFor = isset($data['scheduled_for'])
            ? $this->scheduleService->parseWorkerDateTime((string) $data['scheduled_for'])
            : null;
        $adminAt = isset($data['administered_at'])
            ? $this->scheduleService->parseWorkerDateTime((string) $data['administered_at'])
            : now($this->scheduleService->workerTimezone());
        $windowCheck = null;

        if ($scheduledFor && ! $medication->is_prn) {
            $windowCheck = $this->safetyService->validateTimeWindow(
                $scheduledFor,
                $adminAt,
                $this->scheduleService->windowBeforeMinutes(),
                $this->scheduleService->windowAfterMinutes()
            );

            if (! $windowCheck['valid'] && empty($data['reason']) && ! ($data['override_window'] ?? false)) {
                return [
                    'success' => false,
                    'error' => 'Outside time window: '.$windowCheck['message'].'. Please provide a reason.',
                    'error_field' => 'reason',
                    'time_window' => $windowCheck,
                ];
            }
        }

        $result = DB::transaction(function () use ($client, $medication, $data, $userId, $shiftId, $safetyCheck, $scheduledFor, $adminAt, $windowCheck, $witnessValidation) {
            $shift = null;
            if ($shiftId !== null) {
                $shift = Shift::query()
                    ->whereKey($shiftId)
                    ->where('client_id', $client->id)
                    ->lockForUpdate()
                    ->first();

                if (! $shift) {
                    return [
                        'success' => false,
                        'error' => 'The selected shift does not belong to this client.',
                        'error_field' => 'shift_id',
                    ];
                }
            }

            // Re-fetch medication with lock to prevent race conditions
            $medication = ClientMedication::lockForUpdate()->findOrFail($medication->id);

            if (! $medication->isAdministrable()) {
                return [
                    'success' => false,
                    'error' => 'Medication order is awaiting verification before it can be administered.',
                    'error_field' => 'approval_status',
                ];
            }

            if ($scheduledFor && ! $medication->is_prn) {
                [$slotStartUtc, $slotEndUtc] = $this->scheduleService->utcSlotWindow($scheduledFor);

                $existing = ClientMedicationAdministration::query()
                    ->where('client_id', $client->id)
                    ->where('client_medication_id', $medication->id)
                    ->whereBetween('scheduled_for', [$slotStartUtc, $slotEndUtc])
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if ($existing) {
                    return [
                        'success' => true,
                        'administration' => $existing,
                        'safety_check' => $safetyCheck,
                        'duplicate' => true,
                    ];
                }
            }

            // Lock stock record if it exists
            if ($medication->controlled_drug) {
                $medication->load(['stock' => function ($q) {
                    $q->lockForUpdate();
                }]);
            }

            // Create administration record
            $admin = new ClientMedicationAdministration;
            $admin->client_id = $client->id;
            $admin->client_medication_id = $medication->id;
            $admin->shift_id = $shiftId;
            $admin->administered_by = $userId;
            $admin->witnessed_by = $witnessValidation['witnessed_by'] ?? null;
            $admin->witnessed_at = $witnessValidation['witnessed_at'] ?? null;
            $admin->witness_method = $witnessValidation['witness_method'] ?? null;
            $admin->scheduled_for = $scheduledFor?->copy()->utc();
            $admin->administered_at = $adminAt->copy()->utc();
            $admin->status = $data['status'];
            $admin->reason = $data['reason'] ?? null;
            $admin->reason_code = $data['reason_code'] ?? null;
            $admin->dose_given = $data['dose_given'] ?? null;
            $admin->notes = $data['notes'] ?? null;
            $admin->blood_glucose_level = $data['blood_glucose_level'] ?? null;
            $admin->pulse_bpm = $data['pulse_bpm'] ?? null;
            $admin->blood_pressure_systolic = $data['blood_pressure_systolic'] ?? null;
            $admin->blood_pressure_diastolic = $data['blood_pressure_diastolic'] ?? null;
            $admin->late_minutes = $windowCheck['late_minutes'] ?? null;
            $admin->early_minutes = $windowCheck['early_minutes'] ?? null;
            $admin->outcome = $data['outcome'] ?? null;
            $admin->site = $data['site'] ?? null;

            if ($shift) {
                $admin->service_context_id = $shift->service_context_id;
            }

            if (! $admin->service_context_id) {
                $admin->service_context_id = $client->service_context_id ?: ServiceContext::defaultId();
            }

            $admin->save();

            if ($admin->status === 'given') {
                $this->mirrorClinicalObservations($admin, $medication, $data, $userId);
            }

            // Handle controlled drug register entry
            if ($medication->controlled_drug && $admin->status === 'given') {
                $this->recordControlledDrugEntry(
                    $medication,
                    $admin,
                    $userId,
                    $admin->witnessed_by,
                    (float) ($data['quantity_administered'] ?? 1)
                );
            }

            return [
                'success' => true,
                'administration' => $admin,
                'safety_check' => $safetyCheck,
            ];
        });

        // Incident integration fires after the transaction commits so a rolled-back
        // dose never raises an incident. Living here (not per-controller) means the
        // MAR wizard, My Day, guided rounds and the client-medical form all raise
        // the same missed/refused/late incidents.
        if (($result['success'] ?? false) && empty($result['duplicate']) && isset($result['administration'])) {
            $this->fireIncidentHooks($result['administration'], $medication, $userId);
        }

        return $result;
    }

    private function fireIncidentHooks(
        ClientMedicationAdministration $admin,
        ClientMedication $medication,
        int $userId
    ): void {
        $incidents = app(MedicationIncidentIntegrationService::class);

        if ($admin->status === 'missed') {
            $incidents->handleMissedDose($admin, $userId);
        } elseif ($admin->status === 'refused' && ($medication->high_risk || $medication->controlled_drug)) {
            $incidents->handleRefusedDose($admin);
        }

        if ($admin->late_minutes && $admin->late_minutes > 120) {
            $incidents->handleLateDose($admin, $admin->late_minutes);
        }
    }

    private function validateNotGivenReason(array $data): ?array
    {
        if (($data['status'] ?? null) === 'given') {
            return null;
        }

        $reasonCode = $data['reason_code'] ?? null;
        if (! $reasonCode) {
            return [
                'success' => false,
                'error' => 'Select the reason this medication was not given.',
                'error_field' => 'reason_code',
            ];
        }

        $reason = NotGivenReason::tryFrom($reasonCode);
        if (! $reason) {
            return [
                'success' => false,
                'error' => 'Select a valid reason this medication was not given.',
                'error_field' => 'reason_code',
            ];
        }

        if ($reason->requiresDetail() && blank($data['reason'] ?? null)) {
            return [
                'success' => false,
                'error' => 'Add a short note when using Other as the reason.',
                'error_field' => 'reason',
            ];
        }

        return null;
    }

    private function validateRequiredObservations(array $requiredObservations, array $data): ?array
    {
        if (($data['status'] ?? null) !== 'given') {
            return null;
        }

        foreach ($requiredObservations as $observation) {
            $missingField = match ($observation) {
                'blood_glucose' => $this->missingValue($data, 'blood_glucose_level') ? 'blood_glucose_level' : null,
                'pulse' => $this->missingValue($data, 'pulse_bpm') ? 'pulse_bpm' : null,
                'blood_pressure' => $this->missingValue($data, 'blood_pressure_systolic') || $this->missingValue($data, 'blood_pressure_diastolic')
                    ? 'blood_pressure_systolic'
                    : null,
                default => null,
            };

            if ($missingField) {
                return [
                    'success' => false,
                    'error' => 'Record the required observation before signing this medication.',
                    'error_field' => $missingField,
                ];
            }
        }

        return null;
    }

    private function missingValue(array $data, string $field): bool
    {
        return ! array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '';
    }

    /**
     * Block a "given" administration when the administering user's LATEST
     * medication competency assessment is failed or expired. Staff with no
     * assessment on file stay permission-gated only (canDo), so admins and
     * clinical managers who are not on the competency register are unaffected,
     * and recording a refusal/missed dose (documentation) is never blocked.
     */
    private function validateAdministratorCompetency(array $data, int $userId): ?array
    {
        if (($data['status'] ?? null) !== 'given') {
            return null;
        }

        $latest = MedicationCompetencyAssessment::query()
            ->where('user_id', $userId)
            ->orderByDesc('assessment_date')
            ->orderByDesc('id')
            ->first();

        if (! $latest || $latest->isPassed()) {
            return null;
        }

        $why = $latest->status !== 'passed'
            ? 'your latest medication competency assessment is recorded as "'.str_replace('_', ' ', (string) $latest->status).'"'
            : 'your medication competency expired on '.$latest->expiry_date?->format('d/m/Y');

        return [
            'success' => false,
            'error' => 'You cannot sign this dose as given — '.$why.'. Ask a competency assessor to reassess you before administering medications.',
            'error_field' => 'status',
        ];
    }

    /**
     * A covert medication (hidden in food/drink) may only be administered under
     * a current covert authorisation — a restrictive practice under NZ law. Once
     * the authorisation's review date has passed the legal basis has lapsed, so
     * block administration until it is renewed rather than silently continuing
     * (the review-overdue flag was previously advisory-only in the UI).
     */
    private function validateCovertAuthorisation(ClientMedication $medication, array $data): ?array
    {
        if (($data['status'] ?? null) !== 'given') {
            return null;
        }

        $covert = $medication->relationLoaded('covertAuthorisation')
            ? $medication->covertAuthorisation
            : $medication->covertAuthorisation()->first();

        if (! $covert || ! $covert->isExpired()) {
            return null;
        }

        return [
            'success' => false,
            'error' => 'Covert administration is not authorised — the covert authorisation review was due on '
                .$covert->review_date?->format('d/m/Y')
                .'. Renew the covert authorisation review before administering this medication.',
            'error_field' => 'client_medication_id',
        ];
    }

    private function validateWitness(ClientMedication $medication, array $adminRules, array $data, int $userId): array
    {
        $requiresWitness = $medication->requiresWitness() || ($adminRules['requires_countersign'] ?? false);

        if (($data['status'] ?? null) !== 'given' || ! $requiresWitness) {
            return ['success' => true];
        }

        if (empty($data['witnessed_by'])) {
            return [
                'success' => false,
                'error' => 'Witness is required for this medication.',
                'error_field' => 'witnessed_by',
            ];
        }

        if ((int) $data['witnessed_by'] === (int) $userId) {
            return [
                'success' => false,
                'error' => 'Witness must be a different user.',
                'error_field' => 'witnessed_by',
            ];
        }

        if (blank($data['witness_credential'] ?? null)) {
            return [
                'success' => false,
                'error' => 'Witness password is required before this medication can be signed.',
                'error_field' => 'witness_credential',
            ];
        }

        $witness = User::query()->find($data['witnessed_by']);
        if (! $witness || ! $witness->canDo('medications.controlled.witness')) {
            return [
                'success' => false,
                'error' => 'Selected witness is not authorised to witness medication administrations.',
                'error_field' => 'witnessed_by',
            ];
        }

        if (! Hash::check((string) $data['witness_credential'], (string) $witness->password)) {
            return [
                'success' => false,
                'error' => 'Witness password did not match.',
                'error_field' => 'witness_credential',
            ];
        }

        return [
            'success' => true,
            'witnessed_by' => $witness->id,
            'witnessed_at' => now(),
            'witness_method' => 'password',
        ];
    }

    private function mirrorClinicalObservations(
        ClientMedicationAdministration $admin,
        ClientMedication $medication,
        array $data,
        int $userId
    ): void {
        $observationData = array_filter([
            'blood_glucose' => $data['blood_glucose_level'] ?? null,
            'pulse' => $data['pulse_bpm'] ?? null,
            'systolic' => $data['blood_pressure_systolic'] ?? null,
            'diastolic' => $data['blood_pressure_diastolic'] ?? null,
            'source' => 'emar_administration',
            'client_medication_administration_id' => $admin->id,
            'client_medication_id' => $medication->id,
        ], fn ($value) => $value !== null && $value !== '');

        $hasClinicalObservation = collect(['blood_glucose', 'pulse', 'systolic', 'diastolic'])
            ->contains(fn (string $key) => array_key_exists($key, $observationData));

        if (! $hasClinicalObservation) {
            return;
        }

        ClinicalObservation::query()->create([
            'client_id' => $admin->client_id,
            'shift_id' => $admin->shift_id,
            'site_id' => $medication->client?->site_id,
            'recorded_by' => $userId,
            'observation_type' => ObservationType::Vitals,
            'recorded_at' => $admin->administered_at ?? now(),
            'data' => $observationData,
            'notes' => 'Captured at medication sign-off for '.$medication->name,
        ]);
    }

    /**
     * Record controlled drug entry
     */
    private function recordControlledDrugEntry(
        ClientMedication $medication,
        ClientMedicationAdministration $admin,
        int $recordedBy,
        ?int $witnessedBy,
        float $quantity = 1.0
    ): void {
        $quantity = $quantity > 0 ? $quantity : 1.0;
        $stock = $medication->stock;
        $before = $stock?->on_hand;

        // Update stock if applicable
        if ($stock && $before !== null) {
            $stock->on_hand = max(0, $before - $quantity);
            $stock->last_counted_at = now();
            $stock->save();
        }

        // Create controlled drug register entry
        ClientControlledDrugEntry::create([
            'client_id' => $admin->client_id,
            'client_medication_id' => $medication->id,
            'shift_id' => $admin->shift_id,
            'service_context_id' => $admin->service_context_id,
            'entry_type' => 'administered',
            'quantity' => $quantity,
            'unit' => $stock?->unit,
            'on_hand_before' => $before,
            'on_hand_after' => $stock?->on_hand,
            'reason' => $admin->reason,
            'notes' => $admin->notes,
            'recorded_at' => $admin->administered_at,
            'recorded_by' => $recordedBy,
            'witnessed_by' => $witnessedBy,
        ]);
    }

    /**
     * Get shift medication summary
     */
    public function getShiftSummary(int $shiftId): array
    {
        $shift = Shift::with('client')->findOrFail($shiftId);
        $client = $shift->client;

        if (! $client) {
            return ['error' => 'No client associated with this shift'];
        }

        $administrations = ClientMedicationAdministration::where('shift_id', $shiftId)
            ->with('medication:id,name,controlled_drug,is_prn')
            ->orderByDesc('administered_at')
            ->get();

        $scheduledCount = $administrations->where('medication.is_prn', false)->count();
        $prnCount = $administrations->where('medication.is_prn', true)->count();
        $controlledCount = $administrations->where('medication.controlled_drug', true)->count();

        $byStatus = $administrations->groupBy('status')
            ->map(fn ($group) => $group->count())
            ->toArray();

        return [
            'shift_id' => $shiftId,
            'client_name' => $client->full_name,
            'date' => $shift->starts_at?->toDateString(),
            'total_administrations' => $administrations->count(),
            'scheduled_count' => $scheduledCount,
            'prn_count' => $prnCount,
            'controlled_count' => $controlledCount,
            'by_status' => $byStatus,
            'administrations' => $administrations->map(fn ($a) => [
                'id' => $a->id,
                'medication_name' => $a->medication?->name ?? 'Unknown',
                'status' => $a->status,
                'administered_at' => $a->administered_at?->toIso8601String(),
                'is_controlled' => $a->medication?->controlled_drug ?? false,
                'is_prn' => $a->medication?->is_prn ?? false,
            ])->toArray(),
        ];
    }
}
