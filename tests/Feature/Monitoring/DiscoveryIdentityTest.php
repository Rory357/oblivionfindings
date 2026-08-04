<?php

use App\Domain\Monitoring\Discovery\Data\DiscoveredIdentity;
use App\Domain\Monitoring\Discovery\Models\DeviceIdentityEvidence;
use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Discovery\Services\DeviceIdentityMatcher;
use App\Domain\Monitoring\Discovery\Services\DiscoveryCandidateService;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Schema;

/** @return array{site: Site, scope: DiscoveryScope} */
function discoveryIdentityScope(array $attributes = []): array
{
    $site = Site::factory()->create([
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $scope = DiscoveryScope::factory()->create([
        'site_id' => $site->id,
        'cidrs' => ['10.44.0.0/16'],
        'exclusions' => [],
        'status' => 'active',
        ...$attributes,
    ]);

    return compact('site', 'scope');
}

function discoveryAssignedDevice(Site $site, array $attributes = []): Device
{
    $device = Device::factory()->itInfrastructure()->create($attributes);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => AssignmentType::Permanent,
        'assigned_at' => now()->subMinute(),
        'released_at' => null,
    ]);

    return $device;
}

function discoveryIdentity(array $overrides = []): DiscoveredIdentity
{
    return new DiscoveredIdentity(
        provider: $overrides['provider'] ?? 'snmp',
        providerId: $overrides['providerId'] ?? null,
        serialNumber: $overrides['serialNumber'] ?? null,
        hardwareId: $overrides['hardwareId'] ?? null,
        macAddresses: $overrides['macAddresses'] ?? [],
        certificateFingerprint: $overrides['certificateFingerprint'] ?? null,
        hostname: $overrides['hostname'] ?? null,
        addresses: $overrides['addresses'] ?? ['10.44.0.10'],
        fingerprint: $overrides['fingerprint'] ?? null,
    );
}

function discoveryReviewer(): User
{
    $reviewer = User::factory()->create(['approved_at' => now()]);
    $role = Role::query()->create([
        'name' => 'discovery_reviewer_'.str()->uuid(),
        'label' => 'Discovery reviewer',
        'level' => 50,
        'type' => 'custom',
    ]);
    $keys = [
        'securityDevices.devices.create',
        'securityDevices.devices.update',
        'securityDevices.devices.delete',
        'securityDevices.devices.viewAllSites',
        'securityDevices.integrations.manage',
    ];
    foreach ($keys as $key) {
        Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'security_devices', 'module' => 'Security & Devices'],
        );
    }
    $role->permissions()->sync(Permission::query()->whereIn('key', $keys)->pluck('id'));
    $reviewer->roles()->attach($role);

    return $reviewer;
}

it('creates Site-scoped discovery persistence without a second device registry', function () {
    expect(Schema::hasColumns('monitoring_discovery_scopes', [
        'site_id', 'collector_id', 'cidrs', 'seed_hosts', 'protocols', 'exclusions',
        'port_bounds', 'max_targets_per_run', 'packets_per_second', 'schedule_cron', 'status',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('monitoring_discovery_runs', [
            'discovery_scope_id', 'run_uuid', 'status', 'trigger', 'scope_snapshot',
            'found_count', 'matched_count', 'proposed_count', 'changed_count',
            'excluded_count', 'failed_count', 'unresolved_count', 'started_at', 'completed_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('monitoring_discovery_candidates', [
            'discovery_run_id', 'candidate_uuid', 'canonical_device_id', 'decision',
            'confidence', 'reasons', 'evidence_snapshot', 'evidence_hash', 'reviewed_by_user_id',
            'reviewed_at', 'review_action', 'superseded_by_candidate_id',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('monitoring_device_identity_evidence', [
            'canonical_device_id', 'evidence_type', 'value_hash', 'source',
            'first_seen_at', 'last_seen_at', 'confidence', 'is_active', 'superseded_at',
        ]))->toBeTrue()
        ->and(Schema::hasTable('monitoring_discovered_devices'))->toBeFalse();
});

it('auto-matches only one canonical Device on immutable high-confidence evidence', function () {
    $record = discoveryIdentityScope();
    $device = discoveryAssignedDevice($record['site'], ['serial_number' => 'SER-100']);

    $matched = app(DeviceIdentityMatcher::class)->match($record['scope'], discoveryIdentity([
        'serialNumber' => ' ser-100 ',
        'macAddresses' => ['00:11:22:33:44:55'],
    ]));
    $mutable = app(DeviceIdentityMatcher::class)->match($record['scope'], discoveryIdentity([
        'hostname' => $device->name,
    ]));

    expect($matched->decision)->toBe('matched')
        ->and($matched->deviceId)->toBe($device->id)
        ->and($matched->confidence)->toBe(95)
        ->and($matched->reasons)->toContain('serial_number_exact')
        ->and($mutable->decision)->toBe('review')
        ->and($mutable->deviceId)->toBe($device->id)
        ->and($mutable->reasons)->toContain('hostname_is_mutable');
});

it('matches provider and certificate evidence but queues conflicting immutable identities', function () {
    $record = discoveryIdentityScope();
    $providerDevice = discoveryAssignedDevice($record['site']);
    $certificateDevice = discoveryAssignedDevice($record['site']);
    DeviceIdentityEvidence::record($providerDevice, 'provider_id', 'unifi:ap-100', 'unifi', 100);
    DeviceIdentityEvidence::record($certificateDevice, 'certificate_fingerprint', 'sha256:aa11', 'tls', 95);

    $providerMatch = app(DeviceIdentityMatcher::class)->match($record['scope'], discoveryIdentity([
        'provider' => 'unifi',
        'providerId' => 'AP-100',
    ]));
    $conflict = app(DeviceIdentityMatcher::class)->match($record['scope'], discoveryIdentity([
        'provider' => 'unifi',
        'providerId' => 'AP-100',
        'certificateFingerprint' => 'SHA256:AA11',
    ]));

    expect($providerMatch->decision)->toBe('matched')
        ->and($providerMatch->deviceId)->toBe($providerDevice->id)
        ->and($providerMatch->confidence)->toBe(100)
        ->and($conflict->decision)->toBe('review')
        ->and($conflict->deviceId)->toBeNull()
        ->and($conflict->reasons)->toContain('conflicting_immutable_evidence');
});

it('normalises MAC evidence and keeps address history and mutable fingerprints in review', function () {
    $record = discoveryIdentityScope();
    $device = discoveryAssignedDevice($record['site'], [
        'mac_address' => '00:11:22:33:44:55',
        'ip_address' => '10.44.0.10',
        'manufacturer' => 'Cisco',
        'model' => 'C9300',
    ]);

    $mac = app(DeviceIdentityMatcher::class)->match($record['scope'], discoveryIdentity([
        'macAddresses' => ['0011.2233.4455'],
    ]));
    $address = app(DeviceIdentityMatcher::class)->match($record['scope'], discoveryIdentity([
        'addresses' => ['10.44.0.10'],
    ]));
    $fingerprint = app(DeviceIdentityMatcher::class)->match($record['scope'], discoveryIdentity([
        'fingerprint' => 'cisco:c9300',
    ]));

    expect($mac->decision)->toBe('review')
        ->and($mac->deviceId)->toBe($device->id)
        ->and($mac->confidence)->toBe(80)
        ->and($mac->reasons)->toContain('mac_address_requires_review')
        ->and($address->decision)->toBe('review')
        ->and($address->reasons)->toContain('address_history_only')
        ->and($fingerprint->decision)->toBe('review')
        ->and($fingerprint->confidence)->toBe(55);
});

it('rejects cross-Site identity ownership and addresses outside the approved scope', function () {
    $record = discoveryIdentityScope();
    $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    discoveryAssignedDevice($otherSite, ['serial_number' => 'CROSS-SITE-1']);

    $wrongSite = app(DeviceIdentityMatcher::class)->match($record['scope'], discoveryIdentity([
        'serialNumber' => 'CROSS-SITE-1',
    ]));
    $wrongNetwork = app(DeviceIdentityMatcher::class)->match($record['scope'], discoveryIdentity([
        'addresses' => ['192.0.2.44'],
    ]));

    expect($wrongSite->decision)->toBe('rejected')
        ->and($wrongSite->deviceId)->toBeNull()
        ->and($wrongSite->reasons)->toContain('canonical_site_mismatch')
        ->and($wrongNetwork->decision)->toBe('rejected')
        ->and($wrongNetwork->reasons)->toContain('address_outside_approved_network');
});

it('applies exclusions before identity matching and proposes an unmatched candidate', function () {
    $record = discoveryIdentityScope(['exclusions' => ['10.44.20.0/24', 'blocked-switch']]);
    discoveryAssignedDevice($record['site'], ['serial_number' => 'EXCLUDED-1']);

    $excluded = app(DeviceIdentityMatcher::class)->match($record['scope'], discoveryIdentity([
        'serialNumber' => 'EXCLUDED-1',
        'addresses' => ['10.44.20.8'],
    ]));
    $proposed = app(DeviceIdentityMatcher::class)->match($record['scope'], discoveryIdentity([
        'serialNumber' => 'NEW-DEVICE-99',
        'addresses' => ['10.44.30.8'],
    ]));

    expect($excluded->decision)->toBe('excluded')
        ->and($excluded->deviceId)->toBeNull()
        ->and($excluded->reasons)->toContain('scope_exclusion')
        ->and($proposed->decision)->toBe('proposed')
        ->and($proposed->reasons)->toContain('no_existing_identity_match');
});

it('stores one explainable candidate per run and locks completed run summaries', function () {
    $record = discoveryIdentityScope();
    $run = DiscoveryRun::factory()->create([
        'discovery_scope_id' => $record['scope']->id,
        'status' => 'running',
        'scope_snapshot' => $record['scope']->snapshot(),
    ]);
    $identity = discoveryIdentity(['serialNumber' => 'NEW-DEVICE-100']);
    $match = app(DeviceIdentityMatcher::class)->match($record['scope'], $identity);

    $first = app(DiscoveryCandidateService::class)->record($run, $identity, $match);
    $second = app(DiscoveryCandidateService::class)->record($run, $identity, $match);
    $run->forceFill([
        'status' => 'completed',
        'found_count' => 1,
        'proposed_count' => 1,
        'completed_at' => now(),
    ])->save();

    expect($second->id)->toBe($first->id)
        ->and($first->decision)->toBe('proposed')
        ->and($first->evidence_hash)->toHaveLength(64)
        ->and(DiscoveryCandidate::query()->count())->toBe(1)
        ->and(fn () => $run->fresh()->update(['proposed_count' => 2]))
        ->toThrow(LogicException::class, 'summary is immutable')
        ->and(fn () => DiscoveryRun::query()->whereKey($run->id)->update(['failed_count' => 1]))
        ->toThrow(LogicException::class, 'summary is immutable');
});

it('adopts a reviewed proposal through the canonical Device registry with hashed identity evidence', function () {
    $record = discoveryIdentityScope();
    $actor = discoveryReviewer();
    $run = DiscoveryRun::factory()->create(['discovery_scope_id' => $record['scope']->id]);
    $identity = discoveryIdentity([
        'serialNumber' => 'ADOPT-100',
        'macAddresses' => ['00:11:22:33:44:66'],
        'hostname' => 'edge-switch-100',
    ]);
    $candidate = app(DiscoveryCandidateService::class)->record(
        $run,
        $identity,
        app(DeviceIdentityMatcher::class)->match($record['scope'], $identity),
    );

    $device = app(DiscoveryCandidateService::class)->adopt($candidate, $actor->id, [
        'name' => 'Edge switch 100',
        'domain' => 'it_infrastructure',
        'category' => 'network',
    ]);

    expect($device)->toBeInstanceOf(Device::class)
        ->and($device->assignments()->active()->where('assignable_type', 'site')->value('assignable_id'))->toBe($record['site']->id)
        ->and($device->serial_number)->toBe('ADOPT-100')
        ->and(DeviceIdentityEvidence::query()->where('canonical_device_id', $device->id)->count())->toBeGreaterThanOrEqual(2)
        ->and(DeviceIdentityEvidence::query()->where('canonical_device_id', $device->id)->where('value_hash', 'ADOPT-100')->exists())->toBeFalse()
        ->and($candidate->fresh()->canonical_device_id)->toBe($device->id)
        ->and($candidate->fresh()->review_action)->toBe('adopted')
        ->and(AuditLog::query()->where('action', 'monitoring.discovery.candidate.adopted')->exists())->toBeTrue();
});

it('denies candidate adoption without explicit discovery and Device permissions', function () {
    $record = discoveryIdentityScope();
    $actor = User::factory()->create(['approved_at' => now()]);
    $run = DiscoveryRun::factory()->create(['discovery_scope_id' => $record['scope']->id]);
    $identity = discoveryIdentity(['serialNumber' => 'DENIED-ADOPT']);
    $candidate = app(DiscoveryCandidateService::class)->record(
        $run,
        $identity,
        app(DeviceIdentityMatcher::class)->match($record['scope'], $identity),
    );

    expect(fn () => app(DiscoveryCandidateService::class)->adopt($candidate, $actor->id, [
        'name' => 'Denied device',
        'domain' => 'it_infrastructure',
        'category' => 'network',
    ]))->toThrow(AuthorizationException::class)
        ->and($candidate->fresh()->reviewed_at)->toBeNull()
        ->and(Device::query()->where('serial_number', 'DENIED-ADOPT')->exists())->toBeFalse();
});

it('merges same-Site duplicates idempotently while preserving one canonical Device', function () {
    $record = discoveryIdentityScope();
    $actor = discoveryReviewer();
    $winner = discoveryAssignedDevice($record['site'], ['serial_number' => 'MERGE-WINNER']);
    $loser = discoveryAssignedDevice($record['site'], ['serial_number' => 'MERGE-LOSER']);
    $profile = MonitoringProfile::factory()->create();
    $monitor = Monitor::factory()->create([
        'device_id' => $loser->id,
        'profile_id' => $profile->id,
        'kind' => MonitorKind::Icmp,
    ]);
    $evidence = DeviceIdentityEvidence::record($loser, 'serial_number', 'merge-loser', 'discovery', 95);
    $candidate = DiscoveryCandidate::factory()->create([
        'discovery_run_id' => DiscoveryRun::factory()->create(['discovery_scope_id' => $record['scope']->id])->id,
        'canonical_device_id' => $winner->id,
        'decision' => 'review',
    ]);

    $first = app(DiscoveryCandidateService::class)->merge($candidate, $winner, $loser, $actor->id);
    $second = app(DiscoveryCandidateService::class)->merge($candidate->fresh(), $winner->fresh(), $loser, $actor->id);

    expect($first->id)->toBe($winner->id)
        ->and($second->id)->toBe($winner->id)
        ->and(Device::withTrashed()->findOrFail($loser->id)->trashed())->toBeTrue()
        ->and($monitor->fresh()->device_id)->toBe($winner->id)
        ->and($evidence->fresh()->canonical_device_id)->toBe($winner->id)
        ->and($winner->assignments()->active()->count())->toBe(1)
        ->and($candidate->fresh()->review_action)->toBe('merged')
        ->and(AuditLog::query()->where('action', 'monitoring.discovery.candidate.merged')->count())->toBe(1);
});

it('splits selected evidence and monitors into a new canonical Device without rewriting history', function () {
    $record = discoveryIdentityScope();
    $actor = discoveryReviewer();
    $source = discoveryAssignedDevice($record['site'], ['serial_number' => 'SPLIT-SOURCE']);
    $selected = DeviceIdentityEvidence::record($source, 'hardware_id', 'board-200', 'snmp', 90);
    $retained = DeviceIdentityEvidence::record($source, 'serial_number', 'split-source', 'snmp', 95);
    $profile = MonitoringProfile::factory()->create();
    $movedMonitor = Monitor::factory()->create(['device_id' => $source->id, 'profile_id' => $profile->id]);
    $retainedMonitor = Monitor::factory()->create(['device_id' => $source->id, 'profile_id' => $profile->id]);
    $candidate = DiscoveryCandidate::factory()->create([
        'discovery_run_id' => DiscoveryRun::factory()->create(['discovery_scope_id' => $record['scope']->id])->id,
        'canonical_device_id' => $source->id,
        'decision' => 'review',
    ]);

    $created = app(DiscoveryCandidateService::class)->split(
        $candidate,
        $source,
        [$selected->id],
        [$movedMonitor->id],
        $actor->id,
        ['name' => 'Split network device', 'domain' => 'it_infrastructure', 'category' => 'network'],
    );

    expect($created->id)->not->toBe($source->id)
        ->and($created->assignments()->active()->value('assignable_id'))->toBe($record['site']->id)
        ->and($selected->fresh()->canonical_device_id)->toBe($created->id)
        ->and($retained->fresh()->canonical_device_id)->toBe($source->id)
        ->and($movedMonitor->fresh()->device_id)->toBe($created->id)
        ->and($retainedMonitor->fresh()->device_id)->toBe($source->id)
        ->and($candidate->fresh()->review_action)->toBe('split')
        ->and(AuditLog::query()->where('action', 'monitoring.discovery.candidate.split')->exists())->toBeTrue();
});
