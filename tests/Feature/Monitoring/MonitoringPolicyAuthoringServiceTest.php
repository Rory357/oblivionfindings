<?php

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Exceptions\MonitoringPolicyVersionConflict;
use App\Domain\Monitoring\Models\MetricSeries;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCoverageExpectation;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Models\MonitoringRetentionPolicy;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\MaintenanceEvaluator;
use App\Domain\Monitoring\Services\MonitoringPolicyAuditWriter;
use App\Domain\Monitoring\Services\MonitoringPolicyAuthoringService;
use App\Domain\Monitoring\Services\MonitoringPolicyRules;
use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\AuditLog;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

it('versions profiles, rolls audit failure back, and requires an atomic replacement before deactivation', function (): void {
    $actor = User::factory()->create();
    $service = app(MonitoringPolicyAuthoringService::class);
    $profile = $service->createProfile($actor, ['name' => 'Core reachability']);

    $updated = $service->updateProfile($actor, $profile, 1, ['failure_confirmations' => 4]);
    expect($updated->version)->toBe(2)
        ->and($updated->failure_confirmations)->toBe(4)
        ->and(AuditLog::query()->where('action', 'monitoring.profile.updated')->exists())->toBeTrue();
    expect(fn () => $service->updateProfile($actor, $updated, 1, ['failure_confirmations' => 5]))
        ->toThrow(MonitoringPolicyVersionConflict::class);

    $failingAudit = Mockery::mock(MonitoringPolicyAuditWriter::class);
    $failingAudit->shouldReceive('write')->once()->andThrow(new RuntimeException('audit unavailable'));
    $rollbackService = new MonitoringPolicyAuthoringService(
        app(MonitoringPolicyRules::class),
        app(CanonicalDeviceSiteResolver::class),
        $failingAudit,
    );
    expect(fn () => $rollbackService->createProfile($actor, ['name' => 'Must roll back']))
        ->toThrow(RuntimeException::class, 'audit unavailable');
    expect(MonitoringProfile::query()->where('name', 'Must roll back')->exists())->toBeFalse();

    [$site, $device] = authoringPolicyDevice();
    $monitor = Monitor::factory()->create(['device_id' => $device->id, 'profile_id' => $updated->id]);
    $replacement = $service->createProfile($actor, ['name' => 'Replacement reachability']);
    expect(fn () => $service->deactivateProfile($actor, $updated, 2, 'Superseded monitoring standard.'))
        ->toThrow(ValidationException::class);

    $deactivated = $service->deactivateProfile(
        $actor,
        $updated,
        2,
        'Superseded monitoring standard.',
        $replacement->id,
    );
    expect($deactivated->is_active)->toBeFalse()
        ->and($deactivated->version)->toBe(3)
        ->and($monitor->fresh()->profile_id)->toBe($replacement->id)
        ->and($site->exists)->toBeTrue();
});

it('normalizes coverage identities and serializes an acyclic manual dependency graph', function (): void {
    $actor = User::factory()->create();
    $service = app(MonitoringPolicyAuthoringService::class);
    [$site, $firstDevice] = authoringPolicyDevice();
    [, $secondDevice] = authoringPolicyDevice($site);
    [, $thirdDevice] = authoringPolicyDevice($site);

    $coverage = $service->createCoverageExpectation($actor, [
        'site_id' => null,
        'device_domain' => 'it_infrastructure',
        'device_category' => 'network',
        'capability' => 'reachability',
        'rationale' => 'Every managed network device requires reachability monitoring.',
    ]);
    expect($coverage->identity_key)->toHaveLength(64)
        ->and($coverage->monitor_kind)->toBe(MonitorKind::Icmp);
    expect(fn () => MonitoringCoverageExpectation::query()->create([
        'site_id' => null,
        'device_domain' => 'IT_INFRASTRUCTURE',
        'device_category' => 'NETWORK',
        'capability' => 'REACHABILITY',
        'monitor_kind' => MonitorKind::Icmp,
        'minimum_count' => 1,
        'support_status' => 'supported',
        'support_evidence' => ['source' => 'test'],
        'is_active' => true,
    ]))->toThrow(QueryException::class);

    $first = Monitor::factory()->create(['device_id' => $firstDevice->id]);
    $second = Monitor::factory()->create(['device_id' => $secondDevice->id]);
    $third = Monitor::factory()->create(['device_id' => $thirdDevice->id]);
    $service->createManualDependency($actor, [
        'site_id' => $site->id,
        'upstream_monitor_id' => $first->id,
        'downstream_monitor_id' => $second->id,
        'confidence' => 1,
    ]);
    $service->createManualDependency($actor, [
        'site_id' => $site->id,
        'upstream_monitor_id' => $second->id,
        'downstream_monitor_id' => $third->id,
        'confidence' => 1,
    ]);

    expect(fn () => $service->createManualDependency($actor, [
        'site_id' => $site->id,
        'upstream_monitor_id' => $third->id,
        'downstream_monitor_id' => $first->id,
        'confidence' => 1,
    ]))->toThrow(LogicException::class, 'cycle');
});

it('keeps recurring maintenance at its local wall time across daylight saving and governs cancellation', function (): void {
    $actor = User::factory()->create();
    [$site, $device] = authoringPolicyDevice();
    $monitor = Monitor::factory()->create(['device_id' => $device->id]);
    $service = app(MonitoringPolicyAuthoringService::class);

    $window = $service->createMaintenanceWindow($actor, [
        'site_id' => $site->id,
        'monitor_id' => $monitor->id,
        'name' => 'Daily network maintenance',
        'starts_at' => '2026-09-26T09:00:00+12:00',
        'ends_at' => '2026-09-26T10:00:00+12:00',
        'recurrence' => 'daily',
        'recurrence_until' => '2026-10-26T10:00:00+13:00',
        'timezone' => 'Pacific/Auckland',
        'reason' => 'Approved daily network maintenance window.',
    ]);

    expect(app(MaintenanceEvaluator::class)->activeWindow(
        $monitor,
        CarbonImmutable::parse('2026-09-27T09:30:00+13:00'),
    ))->not->toBeNull();

    $cancelled = $service->cancelMaintenanceWindow(
        $actor,
        $window,
        1,
        'The approved maintenance activity was withdrawn.',
    );
    expect($cancelled->status)->toBe('cancelled')
        ->and($cancelled->version)->toBe(2)
        ->and($cancelled->cancelled_by_user_id)->toBe($actor->id);
});

it('previews destructive retention changes and protects confirmation legal hold and the application default', function (): void {
    $actor = User::factory()->create();
    [$site, $device] = authoringPolicyDevice();
    MetricSeries::query()->create([
        'site_id' => $site->id,
        'device_id' => $device->id,
        'metric' => 'network.latency',
        'dimensions' => [],
        'dimensions_hash' => hash('sha256', '{}'),
        'unit' => 'ms',
        'source' => 'native_monitoring',
        'data_class' => 'operational',
        'privacy_class' => 'standard',
        'retention_tier' => 'raw',
        'external_key' => fake()->uuid(),
        'first_point_at' => now()->subDays(120),
        'last_point_at' => now()->subDays(100),
    ]);
    $service = app(MonitoringPolicyAuthoringService::class);
    $attributes = [
        'name' => 'Site operational history',
        'scope_kind' => 'site',
        'site_id' => $site->id,
        'device_id' => null,
        'data_class' => null,
        'privacy_class' => null,
        'raw_days' => 7,
        'hourly_days' => 10,
        'daily_days' => 12,
        'legal_hold' => false,
    ];
    $preview = $service->previewRetentionPolicy($attributes);
    expect($preview['metric_series_candidates'])->toBe(1)
        ->and($preview['requires_confirmation'])->toBeTrue();
    expect(fn () => $service->createRetentionPolicy($actor, $attributes))
        ->toThrow(ValidationException::class);

    $policy = $service->createRetentionPolicy(
        $actor,
        $attributes,
        MonitoringPolicyRules::RETENTION_CONFIRMATION,
        'Approved site retention policy activation.',
    );
    expect($policy->identity_key)->toHaveLength(64)
        ->and($policy->change_reason)->toBe('Approved site retention policy activation.');

    expect(fn () => $service->updateRetentionPolicy(
        $actor,
        $policy,
        1,
        ['raw_days' => 3, 'hourly_days' => 5, 'daily_days' => 7],
    ))->toThrow(ValidationException::class);

    $held = $service->updateRetentionPolicy($actor, $policy, 1, ['legal_hold' => true]);
    expect(fn () => $service->updateRetentionPolicy($actor, $held, 2, ['legal_hold' => false]))
        ->toThrow(ValidationException::class);
    $reduced = $service->updateRetentionPolicy(
        $actor,
        $held,
        2,
        ['legal_hold' => false, 'raw_days' => 3, 'hourly_days' => 5, 'daily_days' => 7],
        MonitoringPolicyRules::RETENTION_CONFIRMATION,
        'Approved legal hold removal and retention reduction.',
    );
    expect($reduced->version)->toBe(3)
        ->and($reduced->raw_days)->toBe(3)
        ->and($reduced->change_reason)->toBe('Approved legal hold removal and retention reduction.');

    $applicationDefault = MonitoringRetentionPolicy::query()->create([
        'name' => 'Application monitoring default',
        'scope_kind' => 'application',
        'raw_days' => 14,
        'hourly_days' => 180,
        'daily_days' => 1825,
        'legal_hold' => false,
        'is_active' => true,
        'version' => 1,
        'created_by_user_id' => $actor->id,
    ]);
    expect(fn () => $service->deactivateRetentionPolicy(
        $actor,
        $applicationDefault,
        $applicationDefault->version,
        'Application default is being retired.',
    ))->toThrow(ValidationException::class, 'last active application');
});

it('serializes profile retention attachment against retention deactivation', function (): void {
    $actor = User::factory()->create();
    [$site] = authoringPolicyDevice();
    $service = app(MonitoringPolicyAuthoringService::class);
    $attributes = [
        'name' => 'Site retention attachment',
        'scope_kind' => 'site',
        'site_id' => $site->id,
        'device_id' => null,
        'data_class' => null,
        'privacy_class' => null,
        'raw_days' => 14,
        'hourly_days' => 180,
        'daily_days' => 1825,
        'legal_hold' => false,
    ];
    $retention = $service->createRetentionPolicy(
        $actor,
        $attributes,
        MonitoringPolicyRules::RETENTION_CONFIRMATION,
        'Approved Site retention attachment policy.',
    );

    $lockQueries = [];
    DB::listen(function (QueryExecuted $query) use (&$lockQueries): void {
        if (str_contains(strtolower($query->sql), 'for update')) {
            $lockQueries[] = strtolower($query->sql);
        }
    });

    $profile = $service->createProfile($actor, [
        'name' => 'Retention-attached profile',
        'retention_policy_id' => $retention->id,
    ]);
    expect($profile->retention_policy_id)->toBe($retention->id)
        ->and($lockQueries)->not->toBeEmpty()
        ->and($lockQueries[0])->toContain('monitoring_retention_policies');

    $lockQueries = [];
    expect(fn () => $service->deactivateRetentionPolicy(
        $actor,
        $retention,
        1,
        'The attached policy cannot be retired yet.',
    ))->toThrow(ValidationException::class, 'active monitoring profile');
    expect($lockQueries)->toHaveCount(2)
        ->and($lockQueries[0])->toContain('monitoring_retention_policies')
        ->and($lockQueries[1])->toContain('monitoring_profiles');

    $service->updateProfile($actor, $profile, 1, ['retention_policy_id' => null]);
    $inactive = $service->deactivateRetentionPolicy(
        $actor,
        $retention->fresh(),
        1,
        'The Site retention attachment is no longer required.',
    );
    expect($inactive->is_active)->toBeFalse();

    expect(fn () => $service->createProfile($actor, [
        'name' => 'Invalid inactive attachment',
        'retention_policy_id' => $inactive->id,
    ]))->toThrow(ValidationException::class, 'unavailable');
    expect(fn () => $service->updateProfile(
        $actor,
        $profile->fresh(),
        2,
        ['retention_policy_id' => $inactive->id],
    ))->toThrow(ValidationException::class, 'unavailable');
});

it('reactivates coverage and retention identities without replacing their history', function (): void {
    $actor = User::factory()->create();
    [$site] = authoringPolicyDevice();
    $service = app(MonitoringPolicyAuthoringService::class);
    $coverageAttributes = [
        'site_id' => $site->id,
        'device_domain' => 'it_infrastructure',
        'device_category' => 'network',
        'capability' => 'reachability',
        'minimum_count' => 1,
        'support_status' => 'supported',
        'rationale' => 'Every managed network device requires reachability monitoring.',
    ];
    $coverage = $service->createCoverageExpectation($actor, $coverageAttributes);
    $inactiveCoverage = $service->deactivateCoverageExpectation(
        $actor,
        $coverage,
        1,
        'The Site coverage standard was temporarily withdrawn.',
    );
    expect(fn () => $service->createCoverageExpectation($actor, $coverageAttributes))
        ->toThrow(ValidationException::class, 'Reactivate that governed record');

    $reactivatedCoverage = $service->reactivateCoverageExpectation(
        $actor,
        $inactiveCoverage,
        2,
        'The approved Site coverage standard is active again.',
        ['minimum_count' => 2],
    );
    $coverageAudit = AuditLog::query()
        ->where('action', 'monitoring.coverage.reactivated')
        ->where('auditable_id', $coverage->id)
        ->sole();
    expect($reactivatedCoverage->id)->toBe($coverage->id)
        ->and($reactivatedCoverage->version)->toBe(3)
        ->and($reactivatedCoverage->minimum_count)->toBe(2)
        ->and($reactivatedCoverage->is_active)->toBeTrue()
        ->and($coverageAudit->meta['previous_deactivation_reason'])
        ->toBe('The Site coverage standard was temporarily withdrawn.')
        ->and($coverageAudit->meta['reactivation_reason'])
        ->toBe('The approved Site coverage standard is active again.');

    $retentionAttributes = [
        'name' => 'Reusable Site retention',
        'scope_kind' => 'site',
        'site_id' => $site->id,
        'device_id' => null,
        'data_class' => null,
        'privacy_class' => null,
        'raw_days' => 14,
        'hourly_days' => 180,
        'daily_days' => 1825,
        'legal_hold' => false,
    ];
    $retention = $service->createRetentionPolicy(
        $actor,
        $retentionAttributes,
        MonitoringPolicyRules::RETENTION_CONFIRMATION,
        'Approved reusable Site retention policy.',
    );
    $inactiveRetention = $service->deactivateRetentionPolicy(
        $actor,
        $retention,
        1,
        'The reusable Site retention policy was temporarily withdrawn.',
    );
    expect(fn () => $service->createRetentionPolicy(
        $actor,
        $retentionAttributes,
        MonitoringPolicyRules::RETENTION_CONFIRMATION,
        'Attempting a duplicate retention identity.',
    ))->toThrow(ValidationException::class, 'Reactivate that governed record');
    expect(fn () => $service->reactivateRetentionPolicy(
        $actor,
        $inactiveRetention,
        2,
        'The approved Site retention policy is active again.',
    ))->toThrow(ValidationException::class, 'confirmation');

    $reactivatedRetention = $service->reactivateRetentionPolicy(
        $actor,
        $inactiveRetention,
        2,
        'The approved Site retention policy is active again.',
        ['daily_days' => 2000],
        MonitoringPolicyRules::RETENTION_CONFIRMATION,
    );
    $retentionAudit = AuditLog::query()
        ->where('action', 'monitoring.retention.reactivated')
        ->where('auditable_id', $retention->id)
        ->sole();
    expect($reactivatedRetention->id)->toBe($retention->id)
        ->and($reactivatedRetention->version)->toBe(3)
        ->and($reactivatedRetention->daily_days)->toBe(2000)
        ->and($reactivatedRetention->is_active)->toBeTrue()
        ->and($retentionAudit->meta['previous_deactivation_reason'])
        ->toBe('The reusable Site retention policy was temporarily withdrawn.')
        ->and($retentionAudit->meta['reactivation_reason'])
        ->toBe('The approved Site retention policy is active again.');
});

/** @return array{Site, Device} */
function authoringPolicyDevice(?Site $site = null): array
{
    $site ??= Site::factory()->create(['is_active' => true, 'archived' => false]);
    $device = Device::factory()->itInfrastructure()->create();
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => AssignmentType::Permanent,
        'assigned_at' => now()->subMinute(),
    ]);

    return [$site, $device];
}
