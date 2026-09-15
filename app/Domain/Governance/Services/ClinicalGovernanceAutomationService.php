<?php

namespace App\Domain\Governance\Services;

use App\Domain\Clinical\Enums\ClinicalEventType;
use App\Domain\Clinical\Models\ClinicalEvent;
use App\Domain\Governance\Models\ClinicalGovernanceIndicator;
use App\Domain\Governance\Models\ClinicalGovernanceSnapshot;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationError;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Care quality: monthly counts of medication errors, falls, skin injuries and
 * signs of infection, taken from eMAR and Health & clinical records.
 *
 * Periods follow the NZ calendar. The current month is compared with the same
 * days of last month, so 1–14 September is never compared with all of August.
 */
class ClinicalGovernanceAutomationService
{
    public const PERIOD_TYPE_MONTHLY = 'monthly';

    /** Older automatic records carried a generated summary that repeated the numbers. */
    private const AUTOMATIC_NARRATIVE_PREFIX = 'Automated monthly clinical governance snapshot.';

    public function syncCurrentSnapshot(?CarbonInterface $asOf = null): ClinicalGovernanceSnapshot
    {
        [$periodStart, $periodEnd] = $this->dateRange($asOf);

        $this->finalisePreviousMonth($periodStart);

        return $this->syncPeriod($periodStart, $periodEnd);
    }

    public function supportedIndicators(): Collection
    {
        return ClinicalGovernanceIndicator::query()
            ->whereIn('indicator_code', array_keys($this->definitionMeta()))
            ->where('is_active', true)
            ->orderBy('indicator_code')
            ->get();
    }

    public function recentSnapshots(int $limit = 12): Collection
    {
        return ClinicalGovernanceSnapshot::query()
            ->where('period_type', self::PERIOD_TYPE_MONTHLY)
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function definitionMeta(): array
    {
        return [
            'HCG-001' => ['target_direction' => 'below'],
            'HCG-002' => ['target_direction' => 'below'],
            'HCG-003' => ['target_direction' => 'below'],
            'HCG-004' => ['target_direction' => 'below'],
        ];
    }

    public function sourceHint(): string
    {
        return 'Counted automatically from medication errors in eMAR and from falls, skin injuries and signs of infection recorded in Health & clinical.';
    }

    /**
     * Whether each source has ever been used. A count of 0 from a source
     * nobody records in yet is "No data yet", not "On target".
     *
     * @return array<string, bool>
     */
    public function sourcesInUse(): array
    {
        $emar = MedicationError::query()->exists() || ClientMedicationAdministration::query()->exists();
        $clinical = ClinicalEvent::query()->exists();

        return [
            'HCG-001' => $emar,
            'HCG-002' => $clinical,
            'HCG-003' => $clinical,
            'HCG-004' => $clinical,
        ];
    }

    public static function timezone(): string
    {
        $timezone = config('app.worker_timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Pacific/Auckland';
    }

    /** "September so far (1–14 Sep)" for a month in progress, "August 2026" once complete. */
    public static function periodLabel(?CarbonInterface $start, ?CarbonInterface $end): string
    {
        if ($start === null || $end === null) {
            return 'This month';
        }

        if (self::coversWholeMonth($start, $end)) {
            return $start->format('F Y');
        }

        return sprintf('%s so far (%s)', $start->format('F'), self::dayRange($start, $end));
    }

    /** "Sep 2026" or "Sep 2026 (so far)" — for table columns. */
    public static function shortPeriodLabel(?CarbonInterface $start, ?CarbonInterface $end): string
    {
        if ($start === null) {
            return 'This month';
        }

        return $start->format('M Y').($end !== null && ! self::coversWholeMonth($start, $end) ? ' (so far)' : '');
    }

    /** "1–14 Aug", or "1 Aug" for a single day. */
    public static function dayRange(CarbonInterface $start, CarbonInterface $end): string
    {
        return $start->day === $end->day
            ? $start->format('j M')
            : $start->format('j').'–'.$end->format('j M');
    }

    public static function coversWholeMonth(CarbonInterface $start, CarbonInterface $end): bool
    {
        return $start->day === 1 && $end->day === $end->daysInMonth;
    }

    /**
     * The same days of the previous month: 1–14 September compares with
     * 1–14 August; a whole month compares with the whole previous month.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function comparisonRange(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $start = $periodStart->subMonthNoOverflow()->startOfMonth();

        if (self::coversWholeMonth($periodStart, $periodEnd)) {
            return [$start, $start->endOfMonth()];
        }

        $days = min($periodEnd->day, $start->daysInMonth);

        return [$start, $start->addDays($days - 1)->endOfDay()];
    }

    protected function syncPeriod(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): ClinicalGovernanceSnapshot
    {
        $definitions = collect($this->definitions($periodStart, $periodEnd));
        $indicators = $this->syncIndicators($definitions);
        [$compareStart, $compareEnd] = $this->comparisonRange($periodStart, $periodEnd);

        $indicatorValues = $definitions->map(function (array $definition) use ($indicators, $periodStart, $periodEnd, $compareStart, $compareEnd) {
            /** @var ClinicalGovernanceIndicator $indicator */
            $indicator = $indicators->get($definition['indicator_code']);
            $value = (float) $definition['resolver']($periodStart, $periodEnd);
            $previousValue = (float) $definition['resolver']($compareStart, $compareEnd);

            return [
                'indicator_id' => $indicator->id,
                'indicator_code' => $indicator->indicator_code,
                'value' => $value,
                'status' => $indicator->getStatus($value),
                'trend' => $this->trend($value, $previousValue),
                'previous_value' => $previousValue,
                'source_href' => $definition['source_href'],
                'source_label' => $definition['source_label'],
            ];
        })->values()->all();

        $attributes = [
            'period_start' => $periodStart->toDateString(),
            'period_type' => self::PERIOD_TYPE_MONTHLY,
        ];

        $values = [
            'period_end' => $periodEnd->toDateString(),
            'indicator_values' => $indicatorValues,
            'summary' => [
                'automated' => true,
                'source_hint' => $this->sourceHint(),
                'compared_with' => [
                    'start' => $compareStart->toDateString(),
                    'end' => $compareEnd->toDateString(),
                ],
                'categories' => collect($indicatorValues)->mapWithKeys(fn (array $value) => [
                    $value['indicator_code'] => $value['value'],
                ])->all(),
            ],
            'captured_by' => null,
        ];

        // No generated narrative: it only repeated the numbers. Clear the old one.
        $existing = ClinicalGovernanceSnapshot::query()->where($attributes)->first();
        if ($existing === null || str_starts_with((string) $existing->narrative, self::AUTOMATIC_NARRATIVE_PREFIX)) {
            $values['narrative'] = null;
        }

        return ClinicalGovernanceSnapshot::updateOrCreate($attributes, $values);
    }

    /** Recount last month in full when it was last counted part-way through. */
    protected function finalisePreviousMonth(CarbonImmutable $periodStart): void
    {
        $previousStart = $periodStart->subMonthNoOverflow()->startOfMonth();
        $previousEnd = $previousStart->endOfMonth();

        $snapshot = ClinicalGovernanceSnapshot::query()
            ->where('period_type', self::PERIOD_TYPE_MONTHLY)
            ->whereDate('period_start', $previousStart->toDateString())
            ->first();

        if ($snapshot !== null && ($snapshot->period_end?->toDateString() ?? '') < $previousEnd->toDateString()) {
            $this->syncPeriod($previousStart, $previousEnd);
        }
    }

    protected function definitions(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $dateFrom = $periodStart->toDateString();
        $dateTo = $periodEnd->toDateString();

        return [
            [
                'indicator_code' => 'HCG-001',
                'category' => 'medication_errors',
                'name' => 'Medication errors',
                'definition' => 'Medication errors reported in eMAR.',
                'data_source' => 'eMAR',
                'unit' => 'count',
                'target_value' => 0,
                'warning_threshold' => 1,
                'critical_threshold' => 3,
                'frequency' => self::PERIOD_TYPE_MONTHLY,
                'source_href' => "/emar/errors?date_from={$dateFrom}&date_to={$dateTo}",
                'source_label' => 'Open medication errors',
                'resolver' => fn (CarbonImmutable $start, CarbonImmutable $end): int => MedicationError::query()
                    ->whereBetween('reported_at', $this->utcBounds($start, $end))
                    ->count(),
            ],
            [
                'indicator_code' => 'HCG-002',
                'category' => 'falls',
                'name' => 'Falls',
                'definition' => 'Falls recorded in Health & clinical.',
                'data_source' => 'Health & clinical',
                'unit' => 'count',
                'target_value' => 0,
                'warning_threshold' => 1,
                'critical_threshold' => 3,
                'frequency' => self::PERIOD_TYPE_MONTHLY,
                'source_href' => "/health-clinical/events?event_type=fall&date_from={$dateFrom}&date_to={$dateTo}",
                'source_label' => 'Open falls',
                'resolver' => fn (CarbonImmutable $start, CarbonImmutable $end): int => $this->countClinicalEvents(
                    ClinicalEventType::Fall,
                    $start,
                    $end
                ),
            ],
            [
                'indicator_code' => 'HCG-003',
                'category' => 'pressure_injuries',
                'name' => 'Skin injuries',
                'definition' => 'Skin injuries, such as pressure injuries, recorded in Health & clinical.',
                'data_source' => 'Health & clinical',
                'unit' => 'count',
                'target_value' => 0,
                'warning_threshold' => 1,
                'critical_threshold' => 3,
                'frequency' => self::PERIOD_TYPE_MONTHLY,
                'source_href' => "/health-clinical/events?event_type=skin_integrity&date_from={$dateFrom}&date_to={$dateTo}",
                'source_label' => 'Open skin injuries',
                'resolver' => fn (CarbonImmutable $start, CarbonImmutable $end): int => $this->countClinicalEvents(
                    ClinicalEventType::SkinIntegrity,
                    $start,
                    $end
                ),
            ],
            [
                'indicator_code' => 'HCG-004',
                'category' => 'infections',
                'name' => 'Signs of infection',
                'definition' => 'Signs of infection recorded in Health & clinical.',
                'data_source' => 'Health & clinical',
                'unit' => 'count',
                'target_value' => 0,
                'warning_threshold' => 1,
                'critical_threshold' => 3,
                'frequency' => self::PERIOD_TYPE_MONTHLY,
                'source_href' => "/health-clinical/events?event_type=infection_sign&date_from={$dateFrom}&date_to={$dateTo}",
                'source_label' => 'Open signs of infection',
                'resolver' => fn (CarbonImmutable $start, CarbonImmutable $end): int => $this->countClinicalEvents(
                    ClinicalEventType::InfectionSign,
                    $start,
                    $end
                ),
            ],
        ];
    }

    protected function syncIndicators(Collection $definitions): Collection
    {
        return $definitions->mapWithKeys(function (array $definition) {
            $indicator = ClinicalGovernanceIndicator::query()->updateOrCreate(
                ['indicator_code' => $definition['indicator_code']],
                [
                    'category' => $definition['category'],
                    'name' => $definition['name'],
                    'definition' => $definition['definition'],
                    'data_source' => $definition['data_source'],
                    'unit' => $definition['unit'],
                    'target_value' => $definition['target_value'],
                    'warning_threshold' => $definition['warning_threshold'],
                    'critical_threshold' => $definition['critical_threshold'],
                    'frequency' => $definition['frequency'],
                    'is_automated' => true,
                    'is_active' => true,
                ]
            );

            return [$indicator->indicator_code => $indicator];
        });
    }

    protected function countClinicalEvents(ClinicalEventType $type, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return ClinicalEvent::query()
            ->where('event_type', $type->value)
            ->whereBetween('occurred_at', $this->utcBounds($start, $end))
            ->count();
    }

    /**
     * NZ calendar days as stored (UTC) times.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function utcBounds(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return [$start->startOfDay()->utc(), $end->endOfDay()->utc()];
    }

    /**
     * From the 1st of the current NZ month to the end of today (NZ).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function dateRange(?CarbonInterface $asOf = null): array
    {
        $local = CarbonImmutable::instance($asOf ?? now())->setTimezone(self::timezone());

        return [$local->startOfMonth(), $local->endOfDay()];
    }

    protected function trend(float $value, ?float $previousValue): string
    {
        if ($previousValue === null) {
            return 'stable';
        }

        if ($value > $previousValue) {
            return 'up';
        }

        if ($value < $previousValue) {
            return 'down';
        }

        return 'stable';
    }
}
