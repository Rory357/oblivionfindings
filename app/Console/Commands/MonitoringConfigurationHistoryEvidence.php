<?php

namespace App\Console\Commands;

use App\Domain\Monitoring\Contracts\SnapshotStore;
use App\Domain\Monitoring\Contracts\TimeSeriesStore;
use App\Domain\Monitoring\Services\ConfigurationHistoryEvidenceService;
use App\Domain\Monitoring\Support\ConfigurationHistoryEvidenceContract;
use Illuminate\Console\Command;
use Throwable;

final class MonitoringConfigurationHistoryEvidence extends Command
{
    protected $signature = 'monitoring:configuration-history-evidence
        {--manifest= : Absolute path to the external reviewed production evidence manifest}
        {--restore-evidence= : Absolute path to the verified restore reconciliation artifact}
        {--browser-evidence= : Absolute path to the external restored-browser companion}
        {--json : Emit one value-free JSON report}';

    protected $description = 'Fail-closed restored-runtime proof of real-host configuration, firmware and capacity history';

    public function handle(
        ConfigurationHistoryEvidenceContract $contract,
        ConfigurationHistoryEvidenceService $evidence,
        SnapshotStore $snapshots,
        TimeSeriesStore $timeSeries,
    ): int {
        if (! $contract->restoredRuntimeIsIsolated(
            $snapshots::class,
            $timeSeries::class,
            base_path(),
        )) {
            $this->error('Configuration history evidence requires the isolated restore-verification runtime. No monitoring store was read.');

            return self::FAILURE;
        }

        $manifestPath = $this->option('manifest');
        $restorePath = $this->option('restore-evidence');
        $browserPath = $this->option('browser-evidence');
        if (! is_string($manifestPath) || $manifestPath === ''
            || ! is_string($restorePath) || $restorePath === ''
            || ! is_string($browserPath) || $browserPath === '') {
            $this->error('All three external evidence paths are required. No monitoring store was read.');

            return self::INVALID;
        }

        try {
            $manifest = $contract->loadProductionManifest($manifestPath, base_path());
            $restore = $contract->loadRestoreEvidence($restorePath, base_path());
            $browser = $contract->loadBrowserEvidence($browserPath, base_path());
            $contract->assertLinked($manifest, $browser, $restore);
            $report = $evidence->report($manifest, $browser, $restore);
            if (! $contract->restoredRuntimeIsIsolated(
                $snapshots::class,
                $timeSeries::class,
                base_path(),
            )) {
                throw new \RuntimeException('The protected release identity changed during verification.');
            }
        } catch (Throwable) {
            $this->error('Configuration history evidence verification refused. No target or configuration value was emitted.');

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));
        } else {
            $this->table(
                ['Evidence', 'State'],
                collect($report['checks'])
                    ->map(fn (string $state, string $name): array => [$name, $state])
                    ->values()
                    ->all(),
            );
            $this->line($report['all_verified']
                ? 'Every required A10 configuration and history evidence check is verified.'
                : 'One or more A10 configuration and history evidence checks are not verified.');
        }

        return $report['all_verified'] ? self::SUCCESS : self::FAILURE;
    }
}
