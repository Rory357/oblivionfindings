<?php

namespace App\Console\Commands;

use App\Domain\Monitoring\Services\MonitoringRestoreReconciliationService;
use Illuminate\Console\Command;

final class MonitoringRestoreReport extends Command
{
    protected $signature = 'monitoring:reconcile-restore
        {--json : Emit one value-free JSON report}
        {--assert-process-config : Fail unless restored-runtime config exactly matches the process-scoped endpoints}
        {--config-only : Check process-scoped restored-runtime config without reading any store}';

    protected $description = 'Read-only reconciliation of a restored native monitoring runtime';

    public function handle(MonitoringRestoreReconciliationService $reconciliation): int
    {
        if ($this->option('config-only') && ! $this->option('assert-process-config')) {
            $this->error('The config-only restore check requires --assert-process-config.');

            return self::FAILURE;
        }

        if ($this->option('assert-process-config') && ! $this->processConfigurationMatches()) {
            $this->error('Restore verification process configuration was not applied. No restored store was read.');

            return self::FAILURE;
        }

        if ($this->option('config-only')) {
            $this->info('Restore verification process configuration matched. No restored store was read.');

            return self::SUCCESS;
        }

        $report = $reconciliation->report();
        $failures = collect($report)
            ->except('checked_at')
            ->sum(fn (mixed $value): int => (int) $value);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));
        } else {
            $this->table(
                ['Check', 'Count'],
                collect($report)->except('checked_at')->map(fn (mixed $value, string $key): array => [$key, $value])->values()->all(),
            );
            $this->info('Checked at '.$report['checked_at']);
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function processConfigurationMatches(): bool
    {
        return app()->environment('restore-verification')
            && config('app.debug') === false
            && config('database.default') === 'mysql'
            && $this->configuredValueMatches('DB_URL', config('database.connections.mysql.url'))
            && $this->configuredValueMatches('REDIS_URL', config('database.redis.default.url'))
            && $this->configuredValueMatches('MONITORING_TIMESERIES_URL', config('monitoring.storage.timeseries.url'))
            && $this->configuredValueMatches('MONITORING_SNAPSHOT_DISK', config('monitoring.storage.snapshots.disk'))
            && config('monitoring.storage.snapshots.disk') === 'monitoring-restore'
            && config('filesystems.disks.monitoring-restore.visibility') === 'private'
            && config('filesystems.disks.monitoring-restore.serve') === false
            && config('filesystems.disks.monitoring-restore.throw') === true
            && $this->snapshotConfigurationMatches()
            && $this->configuredValueMatches('MONITORING_CREDENTIAL_DRIVER', config('monitoring.credentials.driver'))
            && config('monitoring.credentials.driver') === 'vault'
            && $this->configuredValueMatches('MONITORING_VAULT_URL', config('monitoring.credentials.vault.url'));
    }

    private function snapshotConfigurationMatches(): bool
    {
        $driver = getenv('MONITORING_RESTORE_FILESYSTEM_DRIVER');
        if (! is_string($driver)
            || ! in_array($driver, ['local', 's3'], true)
            || ! hash_equals($driver, (string) config('filesystems.disks.monitoring-restore.driver'))) {
            return false;
        }

        if ($driver === 'local') {
            return $this->configuredValueMatches(
                'MONITORING_RESTORE_FILESYSTEM_ROOT',
                config('filesystems.disks.monitoring-restore.root'),
            );
        }

        return $this->configuredValueMatches(
            'MONITORING_RESTORE_OBJECT_ACCESS_KEY_ID',
            config('filesystems.disks.monitoring-restore.key'),
        )
            && $this->configuredValueMatches(
                'MONITORING_RESTORE_OBJECT_SECRET_ACCESS_KEY',
                config('filesystems.disks.monitoring-restore.secret'),
            )
            && $this->configuredValueMatches(
                'MONITORING_RESTORE_OBJECT_REGION',
                config('filesystems.disks.monitoring-restore.region'),
            )
            && $this->configuredValueMatches(
                'MONITORING_RESTORE_OBJECT_BUCKET',
                config('filesystems.disks.monitoring-restore.bucket'),
            )
            && $this->configuredValueMatches(
                'MONITORING_RESTORE_OBJECT_ENDPOINT',
                config('filesystems.disks.monitoring-restore.endpoint'),
            )
            && $this->configuredBooleanMatches(
                'MONITORING_RESTORE_OBJECT_PATH_STYLE',
                config('filesystems.disks.monitoring-restore.use_path_style_endpoint'),
            );
    }

    private function configuredValueMatches(string $environmentKey, mixed $configured): bool
    {
        $expected = getenv($environmentKey);

        return is_string($expected)
            && $expected !== ''
            && is_string($configured)
            && hash_equals($expected, $configured);
    }

    private function configuredBooleanMatches(string $environmentKey, mixed $configured): bool
    {
        $expected = getenv($environmentKey);
        $expectedBoolean = is_string($expected)
            ? filter_var($expected, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        return is_bool($expectedBoolean)
            && is_bool($configured)
            && $expectedBoolean === $configured;
    }
}
