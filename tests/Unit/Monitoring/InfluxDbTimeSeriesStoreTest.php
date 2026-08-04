<?php

use App\Infrastructure\Monitoring\InfluxDbTimeSeriesStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Http;

uses(TestCase::class);

it('checks a retained pointer with stored bounds and one grouped result', function (): void {
    config()->set('monitoring.storage.timeseries', [
        'driver' => 'influxdb',
        'url' => 'https://influx.example.test',
        'token' => 'RAW-INFLUX-TOKEN-SENTINEL',
        'organisation' => 'oblivion-findings',
        'bucket' => 'native-monitoring',
        'maximum_batch_points' => 500,
        'connect_timeout_seconds' => 1,
        'response_timeout_seconds' => 2,
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://influx.example.test/api/v2/query*' => Http::response(implode("\n", [
            '#datatype,string,string,long,dateTime:RFC3339',
            '#group,false,false,false,false',
            '#default,_result,,,',
            ',result,table,_time',
            ',,0,2026-08-02T11:00:00.654321Z',
        ]), 200, ['Content-Type' => 'application/csv']),
    ]);
    $from = CarbonImmutable::parse('2026-08-01T10:00:00.123456Z');
    $to = CarbonImmutable::parse('2026-08-02T11:00:00.654322Z');
    $externalKey = str_repeat('c', 64);

    expect((new InfluxDbTimeSeriesStore)->exists($externalKey, 'raw', $from, $to))->toBeTrue();

    Http::assertSent(function ($request) use ($externalKey, $from, $to): bool {
        $query = (string) data_get($request->data(), 'query');

        return str_contains($request->url(), '/api/v2/query')
            && str_contains($query, 'external_key == "'.$externalKey.'"')
            && str_contains($query, 'tier == "raw"')
            && str_contains($query, 'r._field == "value"')
            && str_contains($query, 'time(v: "'.$from->format('Y-m-d\TH:i:s.u\Z').'")')
            && str_contains($query, 'time(v: "'.$to->format('Y-m-d\TH:i:s.u\Z').'")')
            && str_contains($query, 'keep(columns:["_time"])')
            && str_contains($query, 'group()')
            && str_contains($query, 'limit(n: 1)')
            && ! str_contains($query, 'pivot(');
    });
});
