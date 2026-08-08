<?php

use App\Domain\Monitoring\Contracts\SnapshotStore;
use App\Domain\Monitoring\Contracts\TimeSeriesStore;
use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Services\ConfigurationHistoryEvidenceService;
use App\Domain\Monitoring\Support\ConfigurationHistoryEvidenceContract;
use App\Infrastructure\Monitoring\InfluxDbTimeSeriesStore;
use App\Infrastructure\Monitoring\LaravelSnapshotStore;
use Carbon\CarbonImmutable;

/** @return list<string> */
function protectConfigurationHistoryWindowsDirectory(string $path): array
{
    if (DIRECTORY_SEPARATOR !== '\\') {
        return [];
    }

    $script = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
Import-Module (Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\Modules\Microsoft.PowerShell.Security\Microsoft.PowerShell.Security.psd1') -Force -ErrorAction Stop
$path = [Environment]::GetEnvironmentVariable('OBLIVION_A10_TEST_ACL_PATH', 'Process')
$acl = Get-Acl -LiteralPath $path
$acl.SetAccessRuleProtection($true, $true)
Set-Acl -LiteralPath $path -AclObject $acl
$verifiedAcl = Get-Acl -LiteralPath $path
$identities = @($verifiedAcl.Access | Where-Object {
    $_.AccessControlType.ToString() -eq 'Allow'
} | ForEach-Object { $_.IdentityReference.Value } | Select-Object -Unique)
if (-not $verifiedAcl.AreAccessRulesProtected -or $identities.Count -eq 0) {
    throw 'The exact test ACL was not applied.'
}
[ordered]@{
    protected = $verifiedAcl.AreAccessRulesProtected
    identities = $identities
} | ConvertTo-Json -Compress -Depth 3
POWERSHELL;
    $encodedScript = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
    $previousAclPath = getenv('OBLIVION_A10_TEST_ACL_PATH');
    putenv('OBLIVION_A10_TEST_ACL_PATH='.$path);
    $process = proc_open(
        ['powershell.exe', '-NoLogo', '-NoProfile', '-NonInteractive', '-EncodedCommand', $encodedScript],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to prepare the exact test ACL.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $previousAclPath === false
        ? putenv('OBLIVION_A10_TEST_ACL_PATH')
        : putenv('OBLIVION_A10_TEST_ACL_PATH='.$previousAclPath);
    if ($exit !== 0 || ! is_string($stdout) || trim($stdout) === '' || trim((string) $stderr) !== '') {
        throw new RuntimeException('Unable to prepare the exact test ACL: '.trim((string) $stderr));
    }
    $result = json_decode($stdout, true, 8, JSON_THROW_ON_ERROR);
    if (! is_array($result)
        || ($result['protected'] ?? null) !== true
        || ! is_array($result['identities'] ?? null)
        || $result['identities'] === []
        || array_filter($result['identities'], fn (mixed $identity): bool => ! is_string($identity) || $identity === '') !== []) {
        throw new RuntimeException('Unable to verify the exact test ACL.');
    }

    return array_values(array_unique($result['identities']));
}

it('rejects a PHPUnit fake-store runtime before any monitoring store method is called', function (): void {
    $snapshots = new ConfigurationHistoryGuardSnapshotStore;
    $timeSeries = new ConfigurationHistoryGuardTimeSeriesStore;
    app()->instance(SnapshotStore::class, $snapshots);
    app()->instance(TimeSeriesStore::class, $timeSeries);

    $this->artisan('monitoring:configuration-history-evidence', ['--json' => true])
        ->expectsOutputToContain('No monitoring store was read.')
        ->assertFailed();

    expect($snapshots->reads)->toBe(0)
        ->and($timeSeries->reads)->toBe(0);

    $report = (new ConfigurationHistoryEvidenceService(
        $snapshots,
        $timeSeries,
        app(ConfigurationHistoryEvidenceContract::class),
    ))->report([], [], ['document' => [], 'sha256' => '']);

    expect($report['all_verified'])->toBeFalse()
        ->and($report['checks'])->each->toBe('not_verified')
        ->and($snapshots->reads)->toBe(0)
        ->and($timeSeries->reads)->toBe(0);
});

it('requires exact recomputed bounded diff paths rather than a self asserted non-empty list', function (): void {
    $previous = getenv('MONITORING_A10_EVIDENCE_HMAC_KEY');
    putenv('MONITORING_A10_EVIDENCE_HMAC_KEY='.base64_encode(str_repeat('h', 32)));

    try {
        $contract = new ConfigurationHistoryEvidenceContract;
        $service = new ConfigurationHistoryEvidenceService(
            new ConfigurationHistoryGuardSnapshotStore,
            new ConfigurationHistoryGuardTimeSeriesStore,
            $contract,
        );
        $baseline = [
            'inventory_status' => 'ok',
            'completed_operations' => 1,
            'failed_operations' => 0,
            'configuration' => ['hostname' => 'baseline'],
            'firmware_version' => '1.0',
        ];
        $changed = [
            'inventory_status' => 'ok',
            'completed_operations' => 1,
            'failed_operations' => 0,
            'configuration' => ['hostname' => 'changed'],
            'firmware_version' => '1.0',
        ];
        $exact = [
            'added' => [],
            'removed' => [],
            'changed' => ['configuration.hostname'],
            'truncated' => false,
        ];
        $snapshot = new ConfigurationSnapshot;
        $snapshot->forceFill(['diff_summary' => $exact]);
        $manifest = [
            'commitments' => [
                'diff_summary_hmac_sha256' => $contract->commitment(json_encode(
                    $exact,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                )),
            ],
        ];
        $method = new ReflectionMethod($service, 'boundedDiffMatches');

        expect($method->invoke($service, $snapshot, $manifest, $baseline, $changed))->toBeTrue();

        $snapshot->forceFill(['diff_summary' => [
            'added' => [],
            'removed' => [],
            'changed' => ['configuration.unrelated'],
            'truncated' => false,
        ]]);
        expect($method->invoke($service, $snapshot, $manifest, $baseline, $changed))->toBeFalse();
    } finally {
        $previous === false
            ? putenv('MONITORING_A10_EVIDENCE_HMAC_KEY')
            : putenv('MONITORING_A10_EVIDENCE_HMAC_KEY='.$previous);
    }
});

it('admits only exact process-scoped restore endpoints roots and concrete stores', function (): void {
    $contract = new ConfigurationHistoryEvidenceContract;
    $objectRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'a10-object-'.bin2hex(random_bytes(6));
    $evidenceRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'a10-evidence-'.bin2hex(random_bytes(6));
    mkdir($objectRoot, 0700);
    mkdir($evidenceRoot, 0700);
    if (DIRECTORY_SEPARATOR !== '\\') {
        chmod($objectRoot, 0700);
        chmod($evidenceRoot, 0700);
    }
    $windowsAclIdentities = array_values(array_unique([
        ...protectConfigurationHistoryWindowsDirectory($objectRoot),
        ...protectConfigurationHistoryWindowsDirectory($evidenceRoot),
    ]));
    $revision = $contract->currentReleaseRevision(base_path()) ?? str_repeat('a', 40);
    $environment = [
        'MONITORING_RESTORE_MYSQL_DSN' => 'mysql://restore.invalid/a10',
        'DB_URL' => 'mysql://restore.invalid/a10',
        'MONITORING_RESTORE_INFLUX_URL' => 'https://influx.restore.invalid',
        'MONITORING_TIMESERIES_URL' => 'https://influx.restore.invalid',
        'MONITORING_RESTORE_INFLUX_TOKEN' => 'restore-token',
        'MONITORING_RESTORE_INFLUX_ORG' => 'restore-org',
        'MONITORING_RESTORE_INFLUX_BUCKET' => 'restore-bucket',
        'MONITORING_RESTORE_FILESYSTEM_DRIVER' => 'local',
        'MONITORING_RESTORE_FILESYSTEM_ROOT' => $objectRoot,
        'MONITORING_A10_EVIDENCE_DIRECTORY' => $evidenceRoot,
        'MONITORING_A10_EVIDENCE_ACL_REFERENCE' => 'ACL-'.str_repeat('1', 32),
        'MONITORING_A10_PRODUCTION_ATTESTATION_PUBLIC_KEY' => base64_encode(str_repeat('p', 32)),
        'MONITORING_A10_BROWSER_ATTESTATION_PUBLIC_KEY' => base64_encode(str_repeat('b', 32)),
        'MONITORING_A10_EVIDENCE_HMAC_KEY' => base64_encode(str_repeat('h', 32)),
        'MONITORING_A10_WINDOWS_ACL_ALLOWED_IDENTITIES' => implode('|', $windowsAclIdentities),
        'OBLIVION_RELEASE_REVISION' => $revision,
    ];
    $previousEnvironment = [];
    foreach ($environment as $key => $value) {
        $previousEnvironment[$key] = getenv($key);
        putenv($key.'='.$value);
    }
    $oldAppEnvironment = app()->environment();
    app()->instance('env', 'restore-verification');
    $configuration = [
        'app.debug' => false,
        'database.default' => 'mysql',
        'database.connections.mysql.url' => $environment['DB_URL'],
        'monitoring.storage.timeseries.driver' => 'influxdb',
        'monitoring.storage.timeseries.url' => $environment['MONITORING_TIMESERIES_URL'],
        'monitoring.storage.timeseries.token' => $environment['MONITORING_RESTORE_INFLUX_TOKEN'],
        'monitoring.storage.timeseries.organisation' => $environment['MONITORING_RESTORE_INFLUX_ORG'],
        'monitoring.storage.timeseries.bucket' => $environment['MONITORING_RESTORE_INFLUX_BUCKET'],
        'monitoring.storage.snapshots.disk' => 'monitoring-restore',
        'filesystems.disks.monitoring-restore.driver' => 'local',
        'filesystems.disks.monitoring-restore.root' => $objectRoot,
        'filesystems.disks.monitoring-restore.visibility' => 'private',
        'filesystems.disks.monitoring-restore.serve' => false,
        'filesystems.disks.monitoring-restore.throw' => true,
    ];
    $previousConfiguration = [];
    foreach ($configuration as $key => $value) {
        $previousConfiguration[$key] = config($key);
        config()->set($key, $value);
    }

    try {
        expect($contract->runtimeErrors(
            LaravelSnapshotStore::class,
            InfluxDbTimeSeriesStore::class,
            base_path(),
            false,
        ))->toBe([]);

        config()->set('monitoring.storage.timeseries.url', 'https://wrong.invalid');
        expect($contract->runtimeErrors(
            LaravelSnapshotStore::class,
            InfluxDbTimeSeriesStore::class,
            base_path(),
            false,
        ))->toContain('restore_influx_scope_mismatch');

        config()->set('monitoring.storage.timeseries.url', $environment['MONITORING_TIMESERIES_URL']);
        expect($contract->runtimeErrors(
            ConfigurationHistoryGuardSnapshotStore::class,
            ConfigurationHistoryGuardTimeSeriesStore::class,
            base_path(),
            true,
        ))->toContain(
            'test_runtime_ineligible',
            'restore_influx_scope_mismatch',
            'restore_object_scope_mismatch',
        );
    } finally {
        foreach ($previousConfiguration as $key => $value) {
            config()->set($key, $value);
        }
        app()->instance('env', $oldAppEnvironment);
        foreach ($previousEnvironment as $key => $value) {
            $value === false ? putenv($key) : putenv($key.'='.$value);
        }
        rmdir($objectRoot);
        rmdir($evidenceRoot);
    }
});

final class ConfigurationHistoryGuardSnapshotStore implements SnapshotStore
{
    public int $reads = 0;

    public function put(string $path, string $contents): void
    {
        throw new RuntimeException('A guarded evidence test must not write.');
    }

    public function read(string $path): string
    {
        $this->reads++;

        return self::RESTORE_HEALTH_CONTENT;
    }

    public function delete(string $path): void
    {
        throw new RuntimeException('A guarded evidence test must not delete.');
    }

    public function exists(string $path): bool
    {
        $this->reads++;

        return true;
    }
}

final class ConfigurationHistoryGuardTimeSeriesStore implements TimeSeriesStore
{
    public int $reads = 0;

    public function writePoints(array $points): void
    {
        throw new RuntimeException('A guarded evidence test must not write.');
    }

    public function range(
        string $externalKey,
        string $tier,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $this->reads++;

        return [];
    }

    public function deleteRange(
        string $externalKey,
        string $tier,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): void {
        throw new RuntimeException('A guarded evidence test must not delete.');
    }

    public function exists(
        string $externalKey,
        string $tier,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
    ): bool {
        $this->reads++;

        return false;
    }

    public function healthy(): bool
    {
        $this->reads++;

        return false;
    }
}
