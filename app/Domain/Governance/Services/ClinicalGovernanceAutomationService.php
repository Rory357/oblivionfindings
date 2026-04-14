<?php

namespace App\Domain\Governance\Services;

use App\Domain\Clinical\Enums\ClinicalEventType;
use App\Domain\Clinical\Models\ClinicalEvent;
use App\Domain\Governance\Models\ClinicalGovernanceIndicator;
use App\Domain\Governance\Models\ClinicalGovernanceSnapshot;
use App\Models\MedicationError;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ClinicalGovernanceAutomationService
{
    public const PERIOD_TYPE_MONTHLY = 'monthly';

    public function syncCurrentSnapshot(?Carbon $asOf = null): ClinicalGovernanceSnapshot
    {
        [$periodStart, $periodEnd] = $this->dateRange($asOf);
        $definitions = collect($this->definitions($periodStart, $periodEnd));
        $indicators = $this->syncIndicators($definitions);
        $previousValues = $this->previousSnapshotValues($periodStart);

        $indicatorValues = $definitions->map(function (array $definition) use ($indicators, $previousValues, $periodStart, $periodEnd) {
            /** @var ClinicalGovernanceIndicator $indicator */
            $indicator = $indicators->get($definition['indicator_code']);
            $value = (float) $definition['resolver']($periodStart, $periodEnd);
            $previousValue = $previousValues->get($definition['indicator_code']);

            return [
                'indicator_id' => $indicator->id,
                'indicator_code' => $indicator->indicator_code,
                'value' => $value,
                'status' => $indicator->getStatus($value),
                'trend' => $this->trend($value, $previousValue),
                'source_href' => $definition['source_href'],
                'source_label' => $definition['source_label'],
            ];
        })->values()->all();

        return ClinicalGovernanceSnapshot::updateOrCreate(
            [
                'period_start' => $periodStart->toDateString(),
                'period_type' => self::PERIOD_TYPE_MONTHLY,
            ],
            [
                'period_end' => $periodEnd->toDateString(),
                'indicator_values' => $indicatorValues,
                'summary' => [
                    'automated' => true,
                    'source_hint' => $this->sourceHint(),
                    'categories' => collect($indicatorValues)->mapWithKeys(fn (array $value) => [
                        $value['indicator_code'] => $value['value'],
                    ])->all(),
                ],
                'narrative' => $this->narrative($indicatorValues),
                'captured_by' => null,
            ]
        );
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
            'HCG-001' => [
                'target_direction' => 'below',
            ],
            'HCG-002' => [
                'target_direction' => 'below',
            ],
            'HCG-003' => [
                'target_direction' => 'below',
            ],
            'HCG-004' => [
                'target_direction' => 'below',
            ],
        ];
    }

    public function sourceHint(): string
    {
        return 'Auto-fed from Health & Clinical clinical events and eMAR medication errors.';
    }

    protected function definitions(Carbon $periodStart, Carbon $periodEnd): array
    {
        $dateFrom = $periodStart->toDateString();
        $dateTo = $periodEnd->toDateString();

        return [
            [
                'indicator_code' => 'HCG-001',
                'category' => 'medication_errors',
                'name' => 'Medication Errors',
                'definition' => 'Medication errors reported in eMAR during the current month.',
                'data_source' => 'eMAR medication errors',
                'unit' => 'count',
                'target_value' => 0,
                'warning_threshold' => 1,
                'critical_threshold' => 3,
                'frequency' => self::PERIOD_TYPE_MONTHLY,
                'source_href' => "/emar/errors?date_from={$dateFrom}&date_to={$dateTo}",
                'source_label' => 'View medication errors',
                'resolver' => fn (Carbon $start, Carbon $end): int => MedicationError::query()
                    ->whereBetween('reported_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
                    ->count(),
            ],
            [
                'indicator_code' => 'HCG-002',
                'category' => 'falls',
                'name' => 'Clinical Falls',
                'definition' => 'Clinical events recorded as falls during the current month.',
                'data_source' => 'Health & Clinical clinical events',
                'unit' => 'count',
                'target_value' => 0,
                'warning_threshold' => 1,
                'critical_threshold' => 3,
                'frequency' => self::PERIOD_TYPE_MONTHLY,
                'source_href' => "/health-clinical/events?event_type=fall&date_from={$dateFrom}&date_to={$dateTo}",
                'source_label' => 'View falls',
                'resolver' => fn (Carbon $start, Carbon $end): int => $this->countClinicalEvents(
                    ClinicalEventType::Fall,
                    $start,
                    $end
                ),
            ],
            [
                'indicator_code' => 'HCG-003',
                'category' => 'pressure_injuries',
                'name' => 'Skin Integrity Events',
                'definition' => 'Clinical events recorded as skin integrity issues during the current month.',
                'data_source' => 'Health & Clinical clinical events',
                'unit' => 'count',
                'target_value' => 0,
                'warning_threshold' => 1,
                'critical_threshold' => 3,
                'frequency' => self::PERIOD_TYPE_MONTHLY,
                'source_href' => "/health-clinical/events?event_type=skin_integrity&date_from={$dateFrom}&date_to={$dateTo}",
                'source_label' => 'View skin integrity events',
                'resolver' => fn (Carbon $start, Carbon $end): int => $this->countClinicalEvents(
                    ClinicalEventType::SkinIntegrity,
                    $start,
                    $end
                ),
            ],
            [
                'indicator_code' => 'HCG-004',
                'category' => 'infections',
                'name' => 'Infection Sign Events',
                'definition' => 'Clinical events recorded as infection signs during the current month.',
                'data_source' => 'Health & Clinical clinical events',
                'unit' => 'count',
                'target_value' => 0,
                'warning_threshold' => 1,
                'critical_threshold' => 3,
                'frequency' => self::PERIOD_TYPE_MONTHLY,
                'source_href' => "/health-clinical/events?event_type=infection_sign&date_from={$dateFrom}&date_to={$dateTo}",
                'source_label' => 'View infection events',
                'resolver' => fn (Carbon $start, Carbon $end): int => $this->countClinicalEvents(
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

    protected function previousSnapshotValues(Carbon $periodStart): Collection
    {
        $snapshot = ClinicalGovernanceSnapshot::query()
            ->where('period_type', self::PERIOD_TYPE_MONTHLY)
            ->whereDate('period_start', '<', $periodStart->toDateString())
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->first();

        return collect($snapshot?->indicator_values ?? [])
            ->mapWithKeys(fn (array $value) => [
                ($value['indicator_code'] ?? (string) $value['indicator_id']) => isset($value['value'])
                    ? (float) $value['value']
                    : null,
            ]);
    }

    protected function countClinicalEvents(ClinicalEventType $type, Carbon $start, Carbon $end): int
    {
        return ClinicalEvent::query()
            ->where('event_type', $type->value)
            ->whereBetween('occurred_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->count();
    }

    protected function dateRange(?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy();

        return [
            $asOf->copy()->startOfMonth(),
            $asOf->copy()->endOfDay(),
        ];
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

    protected function narrative(array $indicatorValues): string
    {
        $values = collect($indicatorValues)->mapWithKeys(fn (array $value) => [
            $value['indicator_code'] => (int) $value['value'],
        ]);

        return sprintf(
            'Automated monthly clinical governance snapshot. Medication errors: %d. Falls: %d. Skin integrity events: %d. Infection sign events: %d.',
            $values->get('HCG-001', 0),
            $values->get('HCG-002', 0),
            $values->get('HCG-003', 0),
            $values->get('HCG-004', 0),
        );
    }
}
