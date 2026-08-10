<?php

use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Models\MetricSeries;
use App\Domain\Monitoring\Models\MonitoringRetentionTombstone;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-06T12:00:00Z');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('keeps snapshot evidence immutable while auditing exact storage reconciliation and retention transitions', function (): void {
    [$site, $device] = monitoringEvidenceScope();
    $snapshot = monitoringEvidenceSnapshot($site, $device);

    $snapshot->forceFill(['content_hash' => str_repeat('f', 64)]);
    expect(fn () => $snapshot->save())
        ->toThrow(UnexpectedValueException::class, 'snapshot evidence is immutable');

    expect(fn () => DB::table('monitoring_configuration_snapshots')
        ->where('id', $snapshot->id)
        ->update(['content_hash' => str_repeat('e', 64)]))
        ->toThrow(QueryException::class, 'snapshot evidence is immutable');

    $snapshot->refresh()->forceFill(['storage_state' => 'missing'])->save();
    DB::table('monitoring_configuration_snapshots')
        ->where('id', $snapshot->id)
        ->update(['storage_state' => 'available']);
    $snapshot->refresh()->forceFill([
        'storage_state' => 'deleted',
        'payload_deleted_at' => now(),
    ])->save();

    expect(DB::table('monitoring_configuration_snapshot_storage_events')
        ->where('snapshot_id', $snapshot->id)
        ->orderBy('id')
        ->pluck('transition_kind')
        ->all())->toBe([
            'storage_reconciled',
            'storage_reconciled',
            'retention_deleted',
        ]);
    $storageEventId = DB::table('monitoring_configuration_snapshot_storage_events')
        ->where('snapshot_id', $snapshot->id)
        ->min('id');

    expect(fn () => DB::table('monitoring_configuration_snapshots')
        ->where('id', $snapshot->id)
        ->update([
            'storage_state' => 'available',
            'payload_deleted_at' => null,
        ]))->toThrow(QueryException::class, 'snapshot history is immutable')
        ->and(fn () => DB::table('monitoring_configuration_snapshots')
            ->where('id', $snapshot->id)
            ->delete())->toThrow(QueryException::class, 'cannot be deleted')
        ->and(fn () => $snapshot->delete())
        ->toThrow(UnexpectedValueException::class, 'cannot be deleted')
        ->and(fn () => DB::table('monitoring_configuration_snapshot_storage_events')
            ->where('id', $storageEventId)
            ->update(['transition_kind' => 'rewritten']))
        ->toThrow(QueryException::class, 'transition evidence is immutable')
        ->and(fn () => DB::table('monitoring_configuration_snapshot_storage_events')
            ->where('id', $storageEventId)
            ->delete())->toThrow(QueryException::class, 'cannot be deleted');

    $migration = require database_path(
        'migrations/2026_08_06_000047_enforce_monitoring_evidence_lifecycle.php',
    );
    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'retained storage or pointer transition evidence exists');
});

it('retains append-only tombstones with database-enforced source lineage', function (): void {
    [$site, $device] = monitoringEvidenceScope();
    $series = monitoringEvidenceSeries($site, $device);
    $tombstone = MonitoringRetentionTombstone::query()->create([
        'tombstone_uuid' => (string) Str::uuid(),
        'series_id' => $series->id,
        'site_id' => $site->id,
        'device_id' => $device->id,
        'monitor_id' => null,
        'data_class' => $series->data_class,
        'retention_tier' => $series->retention_tier,
        'period_start' => now()->subHours(4),
        'period_end' => now()->subHours(3),
        'policy_id' => null,
        'deleted_by_user_id' => null,
        'job_reference' => 'retention:test:immutable',
        'deleted_at' => now(),
    ]);

    $tombstone->forceFill(['job_reference' => 'retention:test:rewritten']);
    expect(fn () => $tombstone->save())
        ->toThrow(UnexpectedValueException::class, 'tombstone evidence is immutable');

    expect(fn () => DB::table('monitoring_retention_tombstones')
        ->where('id', $tombstone->id)
        ->update(['job_reference' => 'retention:test:bypass']))
        ->toThrow(QueryException::class, 'tombstone evidence is immutable')
        ->and(fn () => DB::table('monitoring_retention_tombstones')
            ->where('id', $tombstone->id)
            ->delete())->toThrow(QueryException::class, 'cannot be deleted')
        ->and(fn () => $tombstone->delete())
        ->toThrow(UnexpectedValueException::class, 'cannot be deleted');

    $otherSite = Site::factory()->create();
    expect(fn () => DB::table('monitoring_retention_tombstones')->insert([
        'tombstone_uuid' => (string) Str::uuid(),
        'series_id' => $series->id,
        'snapshot_id' => null,
        'site_id' => $otherSite->id,
        'device_id' => $device->id,
        'monitor_id' => null,
        'data_class' => $series->data_class,
        'retention_tier' => $series->retention_tier,
        'period_start' => now()->subHours(4),
        'period_end' => now()->subHours(3),
        'policy_id' => null,
        'deleted_by_user_id' => null,
        'job_reference' => 'retention:test:wrong-site',
        'deleted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'tombstone lineage is invalid');
});

it('keeps metric identity immutable and audits every valid pointer maintenance path', function (): void {
    [$site, $device] = monitoringEvidenceScope();
    $series = monitoringEvidenceSeries($site, $device);
    $first = CarbonImmutable::parse('2026-08-06T10:00:00Z');
    $last = CarbonImmutable::parse('2026-08-06T11:00:00Z');

    $series->forceFill([
        'first_point_at' => $first,
        'last_point_at' => $last,
    ])->save();

    $series->refresh()->forceFill(['unit' => 'milliseconds']);
    expect(fn () => $series->save())
        ->toThrow(UnexpectedValueException::class, 'identity evidence is immutable');

    expect(fn () => DB::table('monitoring_metric_series')
        ->where('id', $series->id)
        ->update(['external_key' => str_repeat('f', 64)]))
        ->toThrow(QueryException::class, 'identity evidence is immutable');

    DB::table('monitoring_metric_series')
        ->where('id', $series->id)
        ->update(['first_point_at' => $first->subHour()]);
    $series->refresh()->forceFill(['first_point_at' => $first->subMinutes(30)])->save();
    DB::table('monitoring_metric_series')
        ->where('id', $series->id)
        ->update([
            'first_point_at' => $first->subMinutes(15),
            'last_point_at' => $last->addMinutes(15),
        ]);

    expect(DB::table('monitoring_metric_series_pointer_events')
        ->where('series_id', $series->id)
        ->orderBy('id')
        ->pluck('transition_kind')
        ->all())->toBe([
            'initialized',
            'range_extended',
            'retention_trimmed',
            'range_reconciled',
        ]);
    $pointerEventId = DB::table('monitoring_metric_series_pointer_events')
        ->where('series_id', $series->id)
        ->min('id');

    expect(fn () => DB::table('monitoring_metric_series')
        ->where('id', $series->id)
        ->update(['first_point_at' => null]))
        ->toThrow(QueryException::class, 'pointer range is invalid')
        ->and(fn () => DB::table('monitoring_metric_series')
            ->where('id', $series->id)
            ->delete())->toThrow(QueryException::class, 'cannot be deleted')
        ->and(fn () => DB::table('monitoring_metric_series_pointer_events')
            ->where('id', $pointerEventId)
            ->update(['transition_kind' => 'rewritten']))
        ->toThrow(QueryException::class, 'transition evidence is immutable')
        ->and(fn () => DB::table('monitoring_metric_series_pointer_events')
            ->where('id', $pointerEventId)
            ->delete())->toThrow(QueryException::class, 'cannot be deleted');

    $series->refresh();
    expect(fn () => $series->delete())
        ->toThrow(UnexpectedValueException::class, 'cannot be deleted');
});

/** @return array{Site, Device} */
function monitoringEvidenceScope(): array
{
    return [Site::factory()->create(), Device::factory()->create()];
}

function monitoringEvidenceSeries(Site $site, Device $device): MetricSeries
{
    return MetricSeries::query()->create([
        'site_id' => $site->id,
        'device_id' => $device->id,
        'monitor_id' => null,
        'metric' => 'wan.utilisation',
        'dimensions' => ['interface' => 'wan'],
        'dimensions_hash' => hash('sha256', '{"interface":"wan"}'),
        'unit' => 'percent',
        'source' => 'native_snmp',
        'data_class' => 'operational',
        'privacy_class' => 'standard',
        'retention_tier' => 'raw',
        'external_key' => hash('sha256', (string) Str::uuid()),
    ]);
}

function monitoringEvidenceSnapshot(Site $site, Device $device): ConfigurationSnapshot
{
    $uuid = (string) Str::uuid();

    return ConfigurationSnapshot::query()->create([
        'snapshot_uuid' => $uuid,
        'site_id' => $site->id,
        'device_id' => $device->id,
        'source_kind' => 'ssh',
        'source' => 'native_read_only_inventory',
        'storage_disk' => 'private',
        'storage_path' => "monitoring/configuration-snapshots/{$uuid}.json.enc",
        'storage_path_hash' => hash('sha256', $uuid),
        'storage_state' => 'available',
        'content_hash' => str_repeat('a', 64),
        'configuration_hash' => str_repeat('b', 64),
        'content_size' => 128,
        'mime_type' => 'application/json',
        'firmware_version' => '1.0.0',
        'captured_at' => now()->subDay(),
        'retention_policy_id' => null,
        'previous_snapshot_id' => null,
        'diff_summary' => [
            'added' => [],
            'removed' => [],
            'changed' => [],
            'truncated' => false,
        ],
        'created_by_user_id' => null,
    ]);
}
