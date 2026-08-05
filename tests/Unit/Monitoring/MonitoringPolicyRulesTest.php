<?php

use App\Domain\Monitoring\Models\MonitoringCoverageExpectation;
use App\Domain\Monitoring\Models\MonitoringRetentionPolicy;
use App\Domain\Monitoring\Services\MonitoringPolicyRules;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('normalizes a coherent monitoring profile contract', function (): void {
    $values = app(MonitoringPolicyRules::class)->profile([
        'name' => ' Core availability ',
        'description' => ' Network reachability ',
        'interval_seconds' => 60,
        'failure_confirmations' => 3,
        'failure_duration_seconds' => 30,
        'recovery_confirmations' => 2,
        'recovery_duration_seconds' => 30,
        'stale_after_seconds' => 300,
        'rising_threshold' => 90,
        'falling_threshold' => 80,
        'baseline_window_seconds' => 3600,
        'baseline_minimum_samples' => 10,
        'baseline_deviation_multiplier' => 3,
        'maintenance_policy' => 'suppress_notifications_and_ticketing',
        'rollup_policy' => 'worst_applicable',
        'retention_policy_id' => null,
    ]);

    expect($values['name'])->toBe('Core availability')
        ->and($values['description'])->toBe('Network reachability')
        ->and($values['falling_threshold'])->toBe(80);
});

it('rejects unsafe profile threshold and stale combinations', function (array $changes): void {
    $attributes = [
        'name' => 'Core availability',
        'description' => null,
        'interval_seconds' => 60,
        'failure_confirmations' => 3,
        'failure_duration_seconds' => 0,
        'recovery_confirmations' => 2,
        'recovery_duration_seconds' => 0,
        'stale_after_seconds' => 300,
        'rising_threshold' => null,
        'falling_threshold' => null,
        'baseline_window_seconds' => 3600,
        'baseline_minimum_samples' => 10,
        'baseline_deviation_multiplier' => null,
        'maintenance_policy' => 'suppress_notifications_and_ticketing',
        'rollup_policy' => 'worst_applicable',
        'retention_policy_id' => null,
        ...$changes,
    ];

    expect(fn () => app(MonitoringPolicyRules::class)->profile($attributes))
        ->toThrow(ValidationException::class);
})->with([
    'stale before interval' => [['interval_seconds' => 300, 'stale_after_seconds' => 60]],
    'falling without rising' => [['falling_threshold' => 50]],
    'inverted hysteresis' => [['rising_threshold' => 50, 'falling_threshold' => 60]],
]);

it('requires exact retention scope shape ordering and explicit confirmation', function (): void {
    $rules = app(MonitoringPolicyRules::class);
    $valid = [
        'name' => 'Application default',
        'scope_kind' => 'application',
        'site_id' => null,
        'device_id' => null,
        'data_class' => null,
        'privacy_class' => null,
        'raw_days' => 14,
        'hourly_days' => 180,
        'daily_days' => 1825,
        'legal_hold' => false,
    ];

    expect($rules->retention($valid)['scope_kind'])->toBe('application');
    expect(fn () => $rules->retention([...$valid, 'data_class' => 'operational']))
        ->toThrow(ValidationException::class);
    expect(fn () => $rules->retention([...$valid, 'raw_days' => 200]))
        ->toThrow(ValidationException::class);
    expect(fn () => $rules->requireRetentionConfirmation('confirm', 'Approved retention reduction.'))
        ->toThrow(ValidationException::class);
    expect($rules->requireRetentionConfirmation(
        MonitoringPolicyRules::RETENTION_CONFIRMATION,
        'Approved retention reduction.',
    ))->toBe('Approved retention reduction.');
});

it('keeps governed coverage and retention identities stable across lifecycle state', function (): void {
    expect(MonitoringCoverageExpectation::identityFor(
        null,
        ' IT_INFRASTRUCTURE ',
        ' NETWORK ',
        ' REACHABILITY ',
    ))->toBe(MonitoringCoverageExpectation::identityFor(
        null,
        'it_infrastructure',
        'network',
        'reachability',
    ));

    expect(MonitoringRetentionPolicy::identityFor(
        ' APPLICATION ',
        null,
        null,
        null,
        null,
    ))->toBe(MonitoringRetentionPolicy::identityFor(
        'application',
        null,
        null,
        null,
        null,
    ));

    $rules = app(MonitoringPolicyRules::class);
    expect($rules->operationalReason(' Approved governed reactivation. '))
        ->toBe('Approved governed reactivation.');
    expect(fn () => $rules->operationalReason('Too short'))
        ->toThrow(ValidationException::class);
});
