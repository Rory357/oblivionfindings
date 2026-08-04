<?php

namespace App\Infrastructure\Monitoring;

use App\Domain\Monitoring\Contracts\RestoreDependencyProbe;
use App\Domain\Monitoring\Contracts\SnapshotStore;
use App\Domain\Monitoring\Contracts\TimeSeriesStore;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerRestoreProbe;
use Illuminate\Support\Facades\Redis;
use Throwable;

final readonly class NativeRestoreDependencyProbe implements RestoreDependencyProbe
{
    private const string SNAPSHOT_HEALTH_PATH = 'monitoring/configuration-snapshots/.restore-health-check';

    public function __construct(
        private TimeSeriesStore $timeseries,
        private SnapshotStore $snapshots,
        private SecretManagerRestoreProbe $secretManager,
    ) {}

    public function health(): array
    {
        return [
            'redis' => $this->redisHealthy(),
            'timeseries' => $this->timeSeriesHealthy(),
            'snapshots' => $this->snapshotStoreHealthy(),
            'secret_manager' => $this->secretManagerHealthy(),
        ];
    }

    private function redisHealthy(): bool
    {
        try {
            $response = Redis::connection()->command('ping');

            return $response === true
                || $response === 1
                || strtoupper((string) $response) === 'PONG';
        } catch (Throwable) {
            return false;
        }
    }

    private function timeSeriesHealthy(): bool
    {
        try {
            return $this->timeseries->healthy();
        } catch (Throwable) {
            return false;
        }
    }

    private function snapshotStoreHealthy(): bool
    {
        try {
            $this->snapshots->exists(self::SNAPSHOT_HEALTH_PATH);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function secretManagerHealthy(): bool
    {
        try {
            return $this->secretManager->healthy();
        } catch (Throwable) {
            return false;
        }
    }
}
