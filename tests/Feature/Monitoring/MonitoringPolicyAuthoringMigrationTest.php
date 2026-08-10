<?php

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Models\MonitoringCoverageExpectation;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Models\MonitoringRetentionPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('provides versioned actor-attributed monitoring policy lifecycle columns', function (): void {
    expect(Schema::hasColumns('monitoring_profiles', [
        'legacy_data_retention_policy_id', 'version', 'created_by_user_id', 'updated_by_user_id',
        'deactivated_at', 'deactivated_by_user_id', 'deactivation_reason',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('monitoring_coverage_expectations', [
            'identity_key', 'version', 'created_by_user_id', 'updated_by_user_id',
            'deactivated_at', 'deactivated_by_user_id', 'deactivation_reason',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('monitor_dependencies', [
            'version', 'created_by_user_id', 'updated_by_user_id',
            'deactivated_at', 'deactivated_by_user_id', 'deactivation_reason',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('monitoring_maintenance_windows', [
            'timezone', 'version', 'created_by_user_id', 'updated_by_user_id',
            'cancelled_at', 'cancelled_by_user_id', 'cancellation_reason',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('monitoring_retention_policies', [
            'identity_key', 'version', 'change_reason', 'updated_by_user_id', 'deactivated_at',
            'deactivated_by_user_id', 'deactivation_reason',
        ]))->toBeTrue();
});

it('binds profile retention to the native monitoring policy table', function (): void {
    $foreignKey = collect(Schema::getForeignKeys('monitoring_profiles'))->first(
        fn (array $key): bool => in_array('retention_policy_id', $key['columns'] ?? [], true),
    );

    expect($foreignKey)->not->toBeNull()
        ->and(str_ends_with(
            strtolower((string) ($foreignKey['foreign_table'] ?? '')),
            'monitoring_retention_policies',
        ))->toBeTrue()
        ->and((new MonitoringProfile)->retentionPolicy()->getRelated())->toBeInstanceOf(MonitoringRetentionPolicy::class);
});

it('enforces normalized application coverage identity across inactive lifecycle history', function (): void {
    $attributes = [
        'site_id' => null,
        'device_domain' => 'it_infrastructure',
        'device_category' => null,
        'capability' => 'reachability',
        'monitor_kind' => MonitorKind::Icmp,
        'minimum_count' => 1,
        'support_status' => 'supported',
        'support_evidence' => ['source' => 'migration-test'],
        'is_active' => false,
    ];
    MonitoringCoverageExpectation::query()->create($attributes);

    expect(fn () => MonitoringCoverageExpectation::query()->create([
        ...$attributes,
        'device_domain' => 'IT_INFRASTRUCTURE',
        'capability' => 'REACHABILITY',
    ]))->toThrow(QueryException::class);
});

it('enforces normalized retention identity across inactive lifecycle history', function (): void {
    $attributes = [
        'name' => 'Historical application retention',
        'scope_kind' => 'application',
        'site_id' => null,
        'device_id' => null,
        'data_class' => null,
        'privacy_class' => null,
        'raw_days' => 14,
        'hourly_days' => 180,
        'daily_days' => 1825,
        'legal_hold' => false,
        'is_active' => false,
        'version' => 2,
    ];
    MonitoringRetentionPolicy::query()->create($attributes);

    expect(fn () => MonitoringRetentionPolicy::query()->create([
        ...$attributes,
        'name' => 'Duplicate inactive application retention',
    ]))->toThrow(QueryException::class);
});
