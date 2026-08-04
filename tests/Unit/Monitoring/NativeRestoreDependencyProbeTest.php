<?php

use App\Domain\Monitoring\Contracts\SnapshotStore;
use App\Domain\Monitoring\Contracts\TimeSeriesStore;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerRestoreProbe;
use App\Infrastructure\Monitoring\NativeRestoreDependencyProbe;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

uses(TestCase::class);

it('probes Redis InfluxDB and the private snapshot store without writing data', function (): void {
    $redis = Mockery::mock(Connection::class);
    $redis->shouldReceive('command')->once()->with('ping')->andReturn('PONG');
    Redis::shouldReceive('connection')->once()->andReturn($redis);

    $timeseries = Mockery::mock(TimeSeriesStore::class);
    $timeseries->shouldReceive('healthy')->once()->andReturnTrue();
    $snapshots = Mockery::mock(SnapshotStore::class);
    $snapshots->shouldReceive('exists')
        ->once()
        ->with('monitoring/configuration-snapshots/.restore-health-check')
        ->andReturnFalse();
    $snapshots->shouldNotReceive('put');
    $snapshots->shouldNotReceive('read');
    $snapshots->shouldNotReceive('delete');
    $secretManager = Mockery::mock(SecretManagerRestoreProbe::class);
    $secretManager->shouldReceive('healthy')->once()->andReturnTrue();

    expect((new NativeRestoreDependencyProbe($timeseries, $snapshots, $secretManager))->health())->toBe([
        'redis' => true,
        'timeseries' => true,
        'snapshots' => true,
        'secret_manager' => true,
    ]);
});

it('fails each dependency closed without propagating connection details', function (): void {
    Redis::shouldReceive('connection')->once()->andThrow(new RuntimeException('redis endpoint detail'));

    $timeseries = Mockery::mock(TimeSeriesStore::class);
    $timeseries->shouldReceive('healthy')->once()->andThrow(new RuntimeException('influx endpoint detail'));
    $snapshots = Mockery::mock(SnapshotStore::class);
    $snapshots->shouldReceive('exists')->once()->andThrow(new RuntimeException('disk detail'));
    $secretManager = Mockery::mock(SecretManagerRestoreProbe::class);
    $secretManager->shouldReceive('healthy')->once()->andThrow(new RuntimeException('vault endpoint detail'));

    expect((new NativeRestoreDependencyProbe($timeseries, $snapshots, $secretManager))->health())->toBe([
        'redis' => false,
        'timeseries' => false,
        'snapshots' => false,
        'secret_manager' => false,
    ]);
});
