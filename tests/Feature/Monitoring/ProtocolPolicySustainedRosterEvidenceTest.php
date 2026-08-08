<?php

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\Monitoring\Services\ProtocolPolicyEvidenceService;
use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Site;
use Carbon\CarbonImmutable;

it('requires every member of a pinned protocol roster to remain fresh and advance', function () {
    $now = CarbonImmutable::parse('2026-08-09T12:00:00Z');
    $this->travelTo($now);
    $profile = MonitoringProfile::factory()->create([
        'is_active' => true,
        'interval_seconds' => 60,
        'stale_after_seconds' => 300,
    ]);
    $site = Site::factory()->create([
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $device = Device::factory()->itInfrastructure()->create();
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => AssignmentType::Permanent,
        'assigned_at' => $now->subHour(),
    ]);
    $monitors = collect(range(1, 2))->map(fn (): Monitor => Monitor::factory()->create([
        'device_id' => $device->id,
        'profile_id' => $profile->id,
        'kind' => MonitorKind::Icmp,
        'current_state' => MonitorState::Healthy,
        'effective_state' => MonitorState::Healthy,
        'last_observation_at' => $now->subMinutes(2),
    ]));
    foreach ($monitors as $index => $monitor) {
        MonitorObservation::factory()->create([
            'monitor_id' => $monitor->id,
            'state' => MonitorState::Healthy,
            'observed_at' => $now->subMinutes(3 - $index),
        ]);
    }

    $initial = app(ProtocolPolicyEvidenceService::class)->report(60);

    expect($initial['protocols']['icmp'])
        ->toMatchArray(['state' => 'verified', 'configured' => 2, 'fresh' => 2])
        ->and($initial['continuous_execution']['icmp'])
        ->toMatchArray([
            'members' => 2,
            'missing' => 0,
            'oldest_evidence_at' => $now->subMinutes(3)->toISOString(),
            'newest_evidence_at' => $now->subMinutes(2)->toISOString(),
        ])
        ->and($initial['continuous_execution']['icmp']['roster_fingerprint'])
        ->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($initial['evidence_roster_fingerprint'])
        ->toMatch('/\A[a-f0-9]{64}\z/');

    MonitorObservation::factory()->create([
        'monitor_id' => $monitors->first()->id,
        'state' => MonitorState::Healthy,
        'observed_at' => $now->subMinute(),
    ]);
    $oneAdvanced = app(ProtocolPolicyEvidenceService::class)->report(60);

    expect($oneAdvanced['evidence_roster_fingerprint'])->toBe($initial['evidence_roster_fingerprint'])
        ->and($oneAdvanced['continuous_execution']['icmp']['roster_fingerprint'])
        ->toBe($initial['continuous_execution']['icmp']['roster_fingerprint'])
        ->and($oneAdvanced['continuous_execution']['icmp']['oldest_evidence_at'])
        ->toBe($initial['continuous_execution']['icmp']['newest_evidence_at']);

    MonitorObservation::factory()->create([
        'monitor_id' => $monitors->last()->id,
        'state' => MonitorState::Healthy,
        'observed_at' => $now,
    ]);
    $allAdvanced = app(ProtocolPolicyEvidenceService::class)->report(60);

    expect(CarbonImmutable::parse($allAdvanced['continuous_execution']['icmp']['oldest_evidence_at'])
        ->gt(CarbonImmutable::parse($initial['continuous_execution']['icmp']['newest_evidence_at'])))->toBeTrue();

    Monitor::factory()->create([
        'device_id' => $device->id,
        'profile_id' => $profile->id,
        'kind' => MonitorKind::Icmp,
        'current_state' => MonitorState::Unknown,
        'effective_state' => MonitorState::Unknown,
        'last_observation_at' => null,
    ]);
    $missingMember = app(ProtocolPolicyEvidenceService::class)->report(60);

    expect($missingMember['protocols']['icmp'])
        ->toMatchArray(['state' => 'not_verified', 'configured' => 3, 'fresh' => 2])
        ->and($missingMember['continuous_execution']['icmp'])
        ->toMatchArray(['members' => 3, 'missing' => 1])
        ->and($missingMember['evidence_roster_fingerprint'])
        ->not->toBe($initial['evidence_roster_fingerprint']);
});
