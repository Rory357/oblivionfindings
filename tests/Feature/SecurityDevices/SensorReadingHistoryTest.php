<?php

use App\Domain\SecurityDevices\Iot\Readings\SensorReadingHistory;
use App\Domain\SecurityDevices\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// S&D Phase E: sensor readings over time, for the sensor page graphs and the battery forecast.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-02T00:00:00Z');
    $this->device = Device::factory()->create(['name' => 'Room 3 sensor']);
    $this->history = app(SensorReadingHistory::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('forecasts the battery from its drop since the last change', function (): void {
    $start = CarbonImmutable::now('UTC')->subDays(6);
    foreach ([[0, 80], [3, 74], [6, 68]] as [$day, $percent]) {
        $this->history->record($this->device->id, ['battery' => $percent], $start->addDays($day));
    }
    // 2% a day from 68%: about 34 days left.
    expect($this->history->batteryDaysLeft($this->device->id))->toBe(34);

    // A new battery starts the forecast again; one reading isn't enough to say.
    $this->history->record($this->device->id, ['battery' => 100], CarbonImmutable::now('UTC'));
    expect($this->history->batteryDaysLeft($this->device->id))->toBeNull();
});

it('gives the graph a thinned series and keeps 13 months', function (): void {
    $now = CarbonImmutable::now('UTC');
    for ($i = 0; $i < 48; $i++) {
        $this->history->record($this->device->id, ['temperature' => 20 + ($i % 2), 'humidity' => 50], $now->subMinutes(30 * $i));
    }
    $series = $this->history->series($this->device->id, 'temperature', $now->subDay(), $now, 12);
    expect(count($series))->toBeLessThanOrEqual(13)
        ->and($series[0]['value'])->toBeGreaterThanOrEqual(20.0)->toBeLessThanOrEqual(21.0);

    $this->history->record($this->device->id, ['temperature' => 18], $now->subMonths(14));
    expect($this->history->prune())->toBe(1)
        ->and(DB::table('security_device_sensor_readings')->where('device_id', $this->device->id)->count())->toBe(96);
});
