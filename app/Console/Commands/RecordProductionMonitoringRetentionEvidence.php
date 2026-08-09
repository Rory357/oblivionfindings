<?php

namespace App\Console\Commands;

use App\Domain\Monitoring\Contracts\TimeSeriesStore;
use App\Domain\Monitoring\Jobs\DownsampleMetrics;
use App\Domain\Monitoring\Services\MetricIngestService;
use App\Domain\Monitoring\Services\ProductionRetentionEndpointAttestation;
use App\Domain\Monitoring\Services\ProductionRetentionEndpointGuard;
use App\Domain\Monitoring\Services\ProductionRetentionEndpointProbe;
use App\Domain\Monitoring\Services\ProductionRetentionEvidenceArtifactWriter;
use App\Domain\Monitoring\Services\ProductionRetentionEvidenceVerifier;
use App\Domain\Monitoring\Services\ProductionRetentionReleaseAuthority;
use App\Domain\Monitoring\Services\RetentionEnforcer;
use App\Support\Monitoring\LoadSoakReleaseCheckoutVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RecordProductionMonitoringRetentionEvidence extends Command
{
    protected $signature = 'monitoring:record-production-retention-evidence
        {--output-directory= : Existing private absolute directory outside the release checkout}
        {--endpoint-attestation= : Independently signed absolute endpoint approval outside the release checkout}
        {--json : Emit one value-free JSON result}';

    protected $description = 'Execute and verify production time-series retention against MySQL and InfluxDB';

    public function handle(
        TimeSeriesStore $store,
        MetricIngestService $ingest,
        RetentionEnforcer $retentionEnforcer,
        ProductionRetentionEndpointGuard $endpointGuard,
        ProductionRetentionEndpointProbe $endpointProbe,
        ProductionRetentionEndpointAttestation $endpointAttestation,
        ProductionRetentionEvidenceVerifier $verifier,
        ProductionRetentionEvidenceArtifactWriter $writer,
        ProductionRetentionReleaseAuthority $releaseAuthority,
        LoadSoakReleaseCheckoutVerifier $releaseCheckout,
    ): int {
        $outputDirectory = trim((string) $this->option('output-directory'));
        $attestationPath = trim((string) $this->option('endpoint-attestation'));
        $settings = (array) config('monitoring.storage.timeseries', []);

        try {
            $databaseDriver = DB::connection()->getDriverName();
            $databaseSettings = (array) DB::connection()->getConfig();
        } catch (Throwable) {
            return $this->finish(['mysql_endpoint_unavailable']);
        }

        $errors = $endpointGuard->errors(
            app()->environment(),
            app()->runningUnitTests()
                || defined('PHPUNIT_COMPOSER_INSTALL')
                || defined('__PHPUNIT_PHAR__')
                || class_exists('PHPUnit\\Framework\\TestCase', false),
            $databaseDriver,
            $store::class,
            $settings,
            $databaseSettings,
        );
        if ($outputDirectory === '') {
            $errors[] = 'output_directory_required';
        } else {
            try {
                $writer->validateDirectory($outputDirectory);
            } catch (Throwable) {
                $errors[] = 'output_directory_ineligible';
            }
        }
        if ($attestationPath === '') {
            $errors[] = 'endpoint_attestation_required';
        }
        if ($errors !== []) {
            return $this->finish(array_values(array_unique($errors)));
        }

        try {
            $authority = $releaseAuthority->loadInstalled();
        } catch (Throwable) {
            return $this->finish(['release_authority_invalid']);
        }
        if (! $releaseCheckout->verify(base_path(), $authority['release_revision'])) {
            return $this->finish(['release_checkout_invalid']);
        }

        try {
            $fingerprints = $endpointProbe->fingerprints($settings);
            $approvedEndpoints = $endpointAttestation->load(
                $attestationPath,
                $fingerprints,
                $authority['release_revision'],
                null,
                $authority['public_key'],
            );
        } catch (Throwable) {
            return $this->finish(['endpoint_attestation_invalid']);
        }

        try {
            if (! $store->healthy()) {
                return $this->finish(['influxdb_health_check_failed']);
            }
        } catch (Throwable) {
            return $this->finish(['influxdb_health_check_failed']);
        }

        $artifactId = (string) $approvedEndpoints['run_id'];
        $jobReference = $artifactId;
        $started = CarbonImmutable::now('UTC');

        try {
            $before = $verifier->captureBefore($started);
            (new DownsampleMetrics)->handle($store, $ingest);
            $retentionAt = CarbonImmutable::now('UTC');
            $retention = $retentionEnforcer->enforce(
                now: $retentionAt,
                actorId: null,
                jobReference: $jobReference,
                includeSnapshots: false,
            );
            $mutationCompleted = CarbonImmutable::now('UTC');
            $verification = $verifier->verify(
                $jobReference,
                $started,
                $mutationCompleted,
                $before,
                $retention,
            );
            $completed = CarbonImmutable::now('UTC');
            $report = $this->report(
                $artifactId,
                $started,
                $completed,
                $verification['execution'],
                $verification['integrity'],
                $verification['errors'],
                $approvedEndpoints,
            );
        } catch (Throwable) {
            $completed = CarbonImmutable::now('UTC');
            $report = $this->report(
                $artifactId,
                $started,
                $completed,
                $this->emptyExecution(),
                $this->emptyIntegrity(),
                ['execution_or_verification_failed'],
                $approvedEndpoints,
            );
        }

        if (! $releaseCheckout->verify(base_path(), $authority['release_revision'])) {
            $report['errors'] = array_values(array_unique([
                ...$report['errors'],
                'release_checkout_changed',
            ]));
            $report['status'] = 'failed';
            $report['a05_release_evidence'] = false;
        }

        try {
            $currentAuthority = $releaseAuthority->loadInstalled();
            $authorityIsCurrent = hash_equals(
                $authority['release_revision'],
                $currentAuthority['release_revision'],
            ) && hash_equals(
                $authority['key_reference'],
                $currentAuthority['key_reference'],
            ) && hash_equals(
                $authority['public_key'],
                $currentAuthority['public_key'],
            );
        } catch (Throwable) {
            $authorityIsCurrent = false;
        }
        if (! $authorityIsCurrent) {
            $report['errors'] = array_values(array_unique([
                ...$report['errors'],
                'release_authority_changed_or_expired',
            ]));
            $report['status'] = 'failed';
            $report['a05_release_evidence'] = false;
        }

        try {
            $artifact = $writer->write($outputDirectory, $report, $authority['public_key']);
        } catch (Throwable) {
            return $this->finish(['evidence_artifact_write_failed']);
        }

        return $this->finish(
            $report['errors'],
            $artifact['filename'],
            $artifact['sha256_filename'],
            $artifact['sha256'],
        );
    }

    /**
     * @param  array<string, int>  $execution
     * @param  array<string, int>  $integrity
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $endpointAttestation
     * @return array<string, mixed>
     */
    private function report(
        string $artifactId,
        CarbonImmutable $started,
        CarbonImmutable $completed,
        array $execution,
        array $integrity,
        array $errors,
        array $endpointAttestation,
    ): array {
        $errors = array_values(array_unique($errors));
        sort($errors);

        return [
            'schema' => 'monitoring-production-retention-v1',
            'artifact_id' => $artifactId,
            'classification' => 'production_real_endpoints',
            'a05_release_evidence' => $errors === [],
            'status' => $errors === [] ? 'verified' : 'failed',
            'started_at_utc' => $started->toIso8601ZuluString(),
            'completed_at_utc' => $completed->toIso8601ZuluString(),
            'endpoints' => [
                'business_store' => 'mysql',
                'time_series_store' => 'influxdb',
                'health' => 'verified',
            ],
            'endpoint_attestation' => $endpointAttestation,
            'execution' => $execution,
            'integrity' => $integrity,
            'errors' => $errors,
        ];
    }

    /** @return array<string, int> */
    private function emptyExecution(): array
    {
        return [
            'raw_to_hourly_chain_count' => 0,
            'hourly_to_daily_chain_count' => 0,
            'tombstone_count' => 0,
            'raw_tombstone_count' => 0,
            'hourly_tombstone_count' => 0,
            'privacy_tombstone_count' => 0,
            'held_record_count' => 0,
            'coverage_verified_count' => 0,
            'occupied_buckets_verified_count' => 0,
            'coverage_blocked_count' => 0,
            'reconciled_deletion_intent_count' => 0,
            'unresolved_deletion_intent_count' => 0,
        ];
    }

    /** @return array<string, int> */
    private function emptyIntegrity(): array
    {
        return [
            'tombstone_lineage_gap_count' => 0,
            'deleted_range_gap_count' => 0,
            'legal_hold_gap_count' => 0,
            'business_reference_gap_count' => 0,
            'pointer_gap_count' => 0,
            'timeseries_reference_gap_count' => 0,
        ];
    }

    /** @param list<string> $errors */
    private function finish(
        array $errors,
        ?string $filename = null,
        ?string $checksumFilename = null,
        ?string $checksum = null,
    ): int {
        $errors = array_values(array_unique($errors));
        sort($errors);
        $result = [
            'status' => $errors === [] ? 'verified' : 'failed',
            'a05_release_evidence' => $errors === [],
            'artifact_filename' => $filename,
            'checksum_filename' => $checksumFilename,
            'sha256' => $checksum,
            'errors' => $errors,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        } elseif ($errors === []) {
            $this->info('Production monitoring retention evidence verified: '.$filename);
        } else {
            $this->error('Production monitoring retention evidence failed: '.implode(', ', $errors));
        }

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}
