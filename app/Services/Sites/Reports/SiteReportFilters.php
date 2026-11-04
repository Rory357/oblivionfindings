<?php

namespace App\Services\Sites\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The one date range + region scope every Site report reads from the query
 * string, so a filter chosen on one report carries to the next.
 *
 *   ?period=30d|3m|6m|12m          a rolling window ending today (NZ day)
 *   ?period=custom&date_from&date_to  an explicit range (YYYY-MM-DD)
 *   ?region=Auckland               one region (the site's resolved region)
 *
 * Dates are NZ calendar days (app.worker_timezone); timestamp columns are
 * compared against the UTC instants of those days' start and end.
 */
final class SiteReportFilters
{
    public const PERIODS = [
        '30d' => 'Last 30 days',
        '3m' => 'Last 3 months',
        '6m' => 'Last 6 months',
        '12m' => 'Last 12 months',
        'custom' => 'Custom range',
    ];

    public const DEFAULT_PERIOD = '6m';

    /** The longest custom range a report will scan. */
    private const MAX_RANGE_YEARS = 3;

    public function __construct(
        public readonly string $period,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?string $region,
        public readonly CarbonImmutable $today,
    ) {}

    public static function timezone(): string
    {
        return (string) config('app.worker_timezone', 'Pacific/Auckland');
    }

    public static function fromRequest(Request $request): self
    {
        $tz = self::timezone();
        $today = CarbonImmutable::now($tz)->startOfDay();

        $period = is_string($request->query('period')) ? $request->query('period') : '';
        $rawFrom = self::parseDate($request->query('date_from'), $tz);
        $rawTo = self::parseDate($request->query('date_to'), $tz);

        // Older links sent only date_from/date_to: read them as a custom range.
        if ($period === '' && ($rawFrom || $rawTo)) {
            $period = 'custom';
        }
        if (! array_key_exists($period, self::PERIODS)) {
            $period = self::DEFAULT_PERIOD;
        }

        if ($period === 'custom') {
            $to = $rawTo ?? $today;
            $from = $rawFrom ?? $to->subMonthsNoOverflow(6)->addDay();
            if ($to->greaterThan($today)) {
                $to = $today;
            }
            if ($from->greaterThan($to)) {
                [$from, $to] = [$to, $from];
            }
            $floor = $to->subYears(self::MAX_RANGE_YEARS);
            if ($from->lessThan($floor)) {
                $from = $floor;
            }
        } else {
            $to = $today;
            $from = match ($period) {
                '30d' => $today->subDays(29),
                '3m' => $today->subMonthsNoOverflow(3)->addDay(),
                '12m' => $today->subMonthsNoOverflow(12)->addDay(),
                default => $today->subMonthsNoOverflow(6)->addDay(),
            };
        }

        $region = is_string($request->query('region')) ? trim($request->query('region')) : '';

        return new self($period, $from, $to, $region !== '' ? $region : null, $today);
    }

    private static function parseDate(mixed $value, string $tz): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $tz);
        if (! $date || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date->startOfDay();
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    public function todayDate(): string
    {
        return $this->today->toDateString();
    }

    /** Last day a check can count as due in this period: the range end, never after today. */
    public function dueUntil(): string
    {
        return $this->to->greaterThan($this->today) ? $this->todayDate() : $this->toDate();
    }

    /** UTC instant of the period's first NZ day. */
    public function fromUtc(): CarbonImmutable
    {
        return $this->from->startOfDay()->utc();
    }

    /** UTC instant of the period's last NZ day's end. */
    public function toUtc(): CarbonImmutable
    {
        return $this->to->endOfDay()->utc();
    }

    /** The NZ calendar day of a stored UTC timestamp. */
    public static function localDate(?string $timestamp): ?string
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        return CarbonImmutable::parse($timestamp, 'UTC')->setTimezone(self::timezone())->toDateString();
    }

    /** Trend buckets: weeks for short ranges, months otherwise. */
    public function bucketUnit(): string
    {
        return $this->from->diffInDays($this->to) <= 62 ? 'week' : 'month';
    }

    /**
     * Every bucket the period touches, oldest first.
     *
     * @return array<int, array{key: string, start: string, end: string}>
     */
    public function buckets(): array
    {
        $buckets = [];
        $unit = $this->bucketUnit();
        $cursor = $unit === 'week' ? $this->from->startOfWeek() : $this->from->startOfMonth();

        while ($cursor->lessThanOrEqualTo($this->to)) {
            $end = $unit === 'week' ? $cursor->endOfWeek() : $cursor->endOfMonth();
            $start = $cursor->lessThan($this->from) ? $this->from : $cursor;
            $buckets[] = [
                'key' => $this->bucketKey($cursor->toDateString()),
                'start' => $start->toDateString(),
                'end' => ($end->greaterThan($this->to) ? $this->to : $end)->toDateString(),
            ];
            $cursor = $unit === 'week' ? $cursor->addWeek() : $cursor->addMonthNoOverflow()->startOfMonth();
        }

        return $buckets;
    }

    /** The bucket key a YYYY-MM-DD date falls in. */
    public function bucketKey(string $date): string
    {
        if ($this->bucketUnit() === 'month') {
            return substr($date, 0, 7);
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10), self::timezone())
            ->startOfWeek()
            ->toDateString();
    }

    public function inPeriod(?string $date): bool
    {
        if ($date === null) {
            return false;
        }
        $day = substr($date, 0, 10);

        return $day >= $this->fromDate() && $day <= $this->toDate();
    }

    /** @return array{period: string, from: string, to: string, region: string|null, today: string, unit: string} */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'from' => $this->fromDate(),
            'to' => $this->toDate(),
            'region' => $this->region,
            'today' => $this->todayDate(),
            'unit' => $this->bucketUnit(),
        ];
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function periodOptions(): array
    {
        return collect(self::PERIODS)
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }
}
