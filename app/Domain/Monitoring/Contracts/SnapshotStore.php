<?php

namespace App\Domain\Monitoring\Contracts;

interface SnapshotStore
{
    public const string RESTORE_HEALTH_PATH = 'monitoring/configuration-snapshots/.restore-health-check';

    public const string RESTORE_HEALTH_CONTENT = 'oblivion-monitoring-snapshot-store-v1';

    public function put(string $path, string $contents): void;

    public function read(string $path): string;

    public function delete(string $path): void;

    public function exists(string $path): bool;
}
