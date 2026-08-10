<?php

namespace App\Infrastructure\Monitoring;

use App\Domain\Monitoring\Contracts\TimeSeriesStore;
use App\Domain\Monitoring\Data\TimeSeriesPoint;
use App\Domain\Monitoring\Exceptions\TimeSeriesUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

final class InfluxDbTimeSeriesStore implements TimeSeriesStore
{
    private const string MEASUREMENT = 'oblivion_metric';

    /** @param list<TimeSeriesPoint> $points */
    public function writePoints(array $points): void
    {
        if ($points === []) {
            return;
        }

        $maximum = (int) config('monitoring.storage.timeseries.maximum_batch_points', 500);
        if ($maximum < 1 || count($points) > $maximum) {
            throw new TimeSeriesUnavailable('Time-series write batch is invalid.');
        }

        foreach ($points as $point) {
            if (! $point instanceof TimeSeriesPoint) {
                throw new TimeSeriesUnavailable('Time-series write batch is invalid.');
            }
        }

        try {
            $response = $this->request()
                ->withQueryParameters([
                    'org' => $this->setting('organisation'),
                    'bucket' => $this->setting('bucket'),
                    'precision' => 'ns',
                ])
                ->withHeaders(['Content-Type' => 'text/plain; charset=utf-8'])
                ->withBody(implode("\n", array_map($this->line(...), $points)), 'text/plain')
                ->post('/api/v2/write');
        } catch (Throwable) {
            throw new TimeSeriesUnavailable('Time-series storage is unavailable.');
        }

        if (! $response->successful()) {
            throw new TimeSeriesUnavailable('Time-series storage is unavailable.');
        }
    }

    public function range(
        string $externalKey,
        string $tier,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $this->assertReference($externalKey, $tier);
        if ($to->lessThanOrEqualTo($from)) {
            throw new TimeSeriesUnavailable('Time-series query range is invalid.');
        }

        $query = sprintf(
            'from(bucket: %s) |> range(start: time(v: %s), stop: time(v: %s))'
            .' |> filter(fn: (r) => r._measurement == %s and r.external_key == %s and r.tier == %s)'
            .' |> pivot(rowKey:["_time"], columnKey:["_field"], valueColumn:"_value")'
            .' |> sort(columns:["_time"])',
            $this->fluxString($this->setting('bucket')),
            $this->fluxString($this->time($from)),
            $this->fluxString($this->time($to)),
            $this->fluxString(self::MEASUREMENT),
            $this->fluxString($externalKey),
            $this->fluxString($tier),
        );

        try {
            $response = $this->request()
                ->withQueryParameters(['org' => $this->setting('organisation')])
                ->accept('application/csv')
                ->post('/api/v2/query', [
                    'query' => $query,
                    'type' => 'flux',
                ]);
        } catch (Throwable) {
            throw new TimeSeriesUnavailable('Time-series storage is unavailable.');
        }

        if (! $response->successful()) {
            throw new TimeSeriesUnavailable('Time-series storage is unavailable.');
        }

        return $this->pointsFromCsv($response->body());
    }

    public function deleteRange(
        string $externalKey,
        string $tier,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): void {
        $this->assertReference($externalKey, $tier);
        if ($to->lessThanOrEqualTo($from)) {
            throw new TimeSeriesUnavailable('Time-series deletion range is invalid.');
        }

        $predicate = sprintf(
            '_measurement=%s AND external_key=%s AND tier=%s',
            $this->predicateString(self::MEASUREMENT),
            $this->predicateString($externalKey),
            $this->predicateString($tier),
        );

        try {
            $response = $this->request()
                ->withQueryParameters([
                    'org' => $this->setting('organisation'),
                    'bucket' => $this->setting('bucket'),
                ])
                ->post('/api/v2/delete', [
                    'start' => $this->time($from),
                    'stop' => $this->time($to),
                    'predicate' => $predicate,
                ]);
        } catch (Throwable) {
            throw new TimeSeriesUnavailable('Time-series storage is unavailable.');
        }

        if (! $response->successful()) {
            throw new TimeSeriesUnavailable('Time-series storage is unavailable.');
        }
    }

    public function exists(
        string $externalKey,
        string $tier,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
    ): bool {
        $this->assertReference($externalKey, $tier);
        $now = CarbonImmutable::now('UTC');
        $from = ($from ?? $now->subYears(20))->utc();
        $to = ($to ?? $now->addSecond())->utc();
        if ($to->lessThanOrEqualTo($from)) {
            throw new TimeSeriesUnavailable('Time-series query range is invalid.');
        }

        $query = sprintf(
            'from(bucket: %s) |> range(start: time(v: %s), stop: time(v: %s))'
            .' |> filter(fn: (r) => r._measurement == %s and r.external_key == %s and r.tier == %s and r._field == "value")'
            .' |> keep(columns:["_time"]) |> group() |> limit(n: 1)',
            $this->fluxString($this->setting('bucket')),
            $this->fluxString($this->time($from)),
            $this->fluxString($this->time($to)),
            $this->fluxString(self::MEASUREMENT),
            $this->fluxString($externalKey),
            $this->fluxString($tier),
        );

        try {
            $response = $this->request()
                ->withQueryParameters(['org' => $this->setting('organisation')])
                ->accept('application/csv')
                ->post('/api/v2/query', [
                    'query' => $query,
                    'type' => 'flux',
                ]);
        } catch (Throwable) {
            throw new TimeSeriesUnavailable('Time-series storage is unavailable.');
        }

        if (! $response->successful()) {
            throw new TimeSeriesUnavailable('Time-series storage is unavailable.');
        }

        return $this->csvContainsTime($response->body());
    }

    public function healthy(): bool
    {
        try {
            $response = $this->request(authenticate: false)->get('/health');

            return $response->successful()
                && in_array(strtolower((string) $response->json('status')), ['pass', 'ready'], true);
        } catch (Throwable) {
            return false;
        }
    }

    private function request(bool $authenticate = true): PendingRequest
    {
        $endpoint = rtrim((string) config('monitoring.storage.timeseries.url'), '/');
        $parts = parse_url($endpoint);
        $localHttp = app()->environment(['local', 'testing'])
            && ($parts['scheme'] ?? null) === 'http'
            && in_array(strtolower((string) ($parts['host'] ?? '')), ['127.0.0.1', 'localhost', '::1'], true);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])
            || (($parts['scheme'] ?? null) !== 'https' && ! $localHttp)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new TimeSeriesUnavailable('Time-series storage is not configured.');
        }

        $request = Http::baseUrl($endpoint)
            ->connectTimeout((int) config('monitoring.storage.timeseries.connect_timeout_seconds', 3))
            ->timeout((int) config('monitoring.storage.timeseries.response_timeout_seconds', 15))
            ->retry(2, 100, throw: false)
            ->acceptJson();

        if (! $authenticate) {
            return $request;
        }

        $token = (string) config('monitoring.storage.timeseries.token');
        if ($token === '') {
            throw new TimeSeriesUnavailable('Time-series storage is not configured.');
        }

        return $request->withToken($token);
    }

    private function setting(string $key): string
    {
        $value = (string) config("monitoring.storage.timeseries.{$key}");
        if ($value === '' || strlen($value) > 255 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new TimeSeriesUnavailable('Time-series storage is not configured.');
        }

        return $value;
    }

    private function line(TimeSeriesPoint $point): string
    {
        $tags = [
            'external_key' => $point->externalKey,
            'tier' => $point->tier,
            'metric' => $point->metric,
            'unit' => $point->unit,
            'site_id' => (string) $point->siteId,
            'device_id' => (string) $point->deviceId,
            'monitor_id' => $point->monitorId === null ? 'none' : (string) $point->monitorId,
        ];
        $tagBytes = collect($tags)
            ->map(fn (string $value, string $key): string => $this->tag($key).'='.$this->tag($value))
            ->implode(',');
        $fields = [
            'value' => $this->number($point->value),
            'series_id' => $point->seriesId.'i',
            'idempotency_key' => $this->fieldString($point->idempotencyKey),
            'dimensions' => $this->fieldString($this->json($point->dimensions)),
        ];
        foreach ($point->statistics as $name => $statistic) {
            $fields[$name] = $name === 'count'
                ? ((int) $statistic).'i'
                : $this->number((float) $statistic);
        }
        $fieldBytes = collect($fields)
            ->map(fn (string $value, string $key): string => $key.'='.$value)
            ->implode(',');
        $nanoseconds = ($point->observedAt->getTimestamp() * 1_000_000_000)
            + ($point->observedAt->micro * 1_000);

        return self::MEASUREMENT.','.$tagBytes.' '.$fieldBytes.' '.$nanoseconds;
    }

    /** @return list<TimeSeriesPoint> */
    private function pointsFromCsv(string $csv): array
    {
        $header = null;
        $points = [];

        foreach (preg_split('/\r\n|\n|\r/', $csv) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $columns = str_getcsv($line, ',', '"', '\\');
            if (in_array('_time', $columns, true)) {
                $header = $columns;

                continue;
            }
            if ($header === null || count($columns) !== count($header)) {
                continue;
            }
            $row = array_combine($header, $columns);
            if (! is_array($row) || ! is_numeric($row['value'] ?? null)
                || ! is_numeric($row['series_id'] ?? null)) {
                continue;
            }

            try {
                $dimensions = json_decode((string) ($row['dimensions'] ?? '{}'), true, 16, JSON_THROW_ON_ERROR);
                $observedAt = CarbonImmutable::parse($row['_time'])->utc();
            } catch (Throwable) {
                continue;
            }
            $statistics = [];
            foreach (['p50', 'p95', 'min', 'max', 'count'] as $statistic) {
                if (isset($row[$statistic]) && is_numeric($row[$statistic])) {
                    $statistics[$statistic] = $statistic === 'count'
                        ? (int) $row[$statistic]
                        : (float) $row[$statistic];
                }
            }
            $points[] = new TimeSeriesPoint(
                externalKey: (string) $row['external_key'],
                seriesId: (int) $row['series_id'],
                siteId: (int) $row['site_id'],
                deviceId: (int) $row['device_id'],
                monitorId: ($row['monitor_id'] ?? 'none') === 'none' ? null : (int) $row['monitor_id'],
                metric: (string) $row['metric'],
                value: (float) $row['value'],
                unit: (string) $row['unit'],
                dimensions: is_array($dimensions) ? $dimensions : [],
                tier: (string) $row['tier'],
                observedAt: $observedAt,
                idempotencyKey: (string) ($row['idempotency_key'] ?? ''),
                statistics: $statistics,
            );
        }

        return $points;
    }

    private function csvContainsTime(string $csv): bool
    {
        $header = null;

        foreach (preg_split('/\r\n|\n|\r/', $csv) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $columns = str_getcsv($line, ',', '"', '\\');
            if (in_array('_time', $columns, true)) {
                $header = $columns;

                continue;
            }
            if ($header === null || count($columns) !== count($header)) {
                continue;
            }
            $row = array_combine($header, $columns);
            if (is_array($row) && is_string($row['_time'] ?? null) && $row['_time'] !== '') {
                return true;
            }
        }

        return false;
    }

    private function assertReference(string $externalKey, string $tier): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $externalKey) !== 1
            || ! in_array($tier, ['raw', 'hourly', 'daily'], true)) {
            throw new TimeSeriesUnavailable('Time-series reference is invalid.');
        }
    }

    private function tag(string $value): string
    {
        return str_replace(['\\', ' ', ',', '='], ['\\\\', '\\ ', '\\,', '\\='], $value);
    }

    private function fieldString(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    private function fluxString(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    private function predicateString(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    private function number(float $value): string
    {
        if (! is_finite($value)) {
            throw new TimeSeriesUnavailable('Time-series point is invalid.');
        }

        return sprintf('%.12F', $value);
    }

    private function time(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new TimeSeriesUnavailable('Time-series point is invalid.', previous: $exception);
        }
    }
}
