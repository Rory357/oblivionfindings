<?php

use App\Domain\It\Data\ItObliDiagnosticBinding;
use App\Domain\It\Exceptions\ItObliSourceUnavailable;
use App\Domain\It\Services\ItObliDiagnosticSource;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Management\Services\CommandAssignmentFingerprint;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([RbacSeeder::class, SecurityDevicesPermissionsSeeder::class]);
    config()->set('endpoint_management.enabled', true);
    config()->set('endpoint_management.obli.technician_enabled', true);
    $this->sourceActor = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);
    $this->sourceActor->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
    foreach (['it.view', 'it.manage', 'securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.endpoints.diagnostics'] as $key) {
        $permission = Permission::query()->firstOrCreate(['key' => $key], ['name' => $key, 'module' => 'securityDevices']);
        $this->sourceActor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }
    $this->sourceSite = ensureCanonicalHrStaffProfile($this->sourceActor);
    $this->sourceDevice = Device::factory()->create(['domain' => 'it_infrastructure', 'category' => 'endpoint', 'provider' => 'oblivion_native', 'status' => 'active']);
    DeviceAssignment::query()->create(['device_id' => $this->sourceDevice->id, 'assignable_type' => 'site', 'assignable_id' => $this->sourceSite->id, 'assignment_type' => 'permanent', 'assigned_at' => now()->subMinute()]);
    $this->sourceAgent = EndpointAgent::query()->create(['created_by_user_id' => $this->sourceActor->id, 'uuid' => '11111111-1111-4111-8111-111111111111', 'device_id' => $this->sourceDevice->id, 'site_id' => $this->sourceSite->id, 'platform' => 'windows', 'enrolled_at' => now(), 'credential_hash' => str_repeat('a', 64), 'assignment_fingerprint' => app(CommandAssignmentFingerprint::class)->forDevice($this->sourceDevice), 'sequence' => 1, 'heartbeat_hash' => str_repeat('b', 64), 'observed_at' => now()->subSeconds(20), 'last_seen_at' => now()->subSeconds(10), 'inventory' => ['hostname' => 'private-hostname', 'os_version' => 'Windows 11 10.0.26100.1', 'architecture' => 'amd64', 'memory_total_bytes' => 8192, 'memory_available_bytes' => 4096, 'uptime_seconds' => 120, 'cpu_percent' => null, 'disks' => [], 'software' => [['name' => 'Microsoft Edge', 'version' => '130.0.1', 'publisher' => 'private-publisher']], 'software_complete' => true, 'services' => [['name' => 'Spooler', 'state' => 'running']], 'services_complete' => false, 'probe_errors' => ['cpu']]]);
    $this->source = app(ItObliDiagnosticSource::class);
});

it('provides selected server diagnostics with factual freshness and sealed immutable receipt data', function () {
    $preview = $this->source->preview($this->sourceActor, $this->sourceDevice->id, null);
    expect(array_keys($preview))->toBe(['actor_id', 'device_id', 'ticket_id', 'snapshot_version', 'observed_at', 'received_at', 'fresh_until', 'groups', 'snapshot', 'completeness'])
        ->and($preview['actor_id'])->toBe($this->sourceActor->id)->and($preview['ticket_id'])->toBeNull()
        ->and($preview['completeness']['system']['missing_fields'])->toBe(['cpu_percent'])
        ->and(json_encode($preview))->not->toContain('private-hostname')->not->toContain('private-publisher')->not->toContain('agent_uuid')->not->toContain('credential');
    $binding = $this->source->capture($this->sourceActor, $this->sourceDevice->id, null, ['software'], $preview['snapshot_version']);
    expect($binding->groups())->toBe(['software'])->and(array_keys($binding->toStorage()['snapshot']))->toBe(['schema', 'observed_at', 'software'])
        ->and(strlen(json_encode($binding->toStorage())))->toBeLessThanOrEqual(6144)
        ->and($binding->providerText())->toContain('microsoft_edge')->toContain('Completeness: {"software":{"complete":true}}')->not->toContain('print_spooler')->not->toContain('memory_total_bytes')
        ->and((string) $binding)->not->toContain('microsoft_edge');
    $restored = ItObliDiagnosticBinding::fromStorage(json_decode(json_encode($binding->toStorage()), true));
    expect($restored->providerText())->toBe($binding->providerText());
    $tampered = $binding->toStorage();
    $tampered['snapshot']['software'][0]['version'] = '999.0';
    expect(fn () => ItObliDiagnosticBinding::fromStorage($tampered))->toThrow(ItObliSourceUnavailable::class);
    $tampered = $binding->toStorage();
    $tampered['actor_id']++;
    expect(fn () => ItObliDiagnosticBinding::fromStorage($tampered))->toThrow(ItObliSourceUnavailable::class);
});

it('requires exact fresh review for capture but permits newer or older current-incarnation history', function () {
    $preview = $this->source->preview($this->sourceActor, $this->sourceDevice->id, null);
    $binding = $this->source->capture($this->sourceActor, $this->sourceDevice->id, null, ['system'], $preview['snapshot_version']);
    expect($binding->providerText())->toContain('Completeness: {"system":{"complete":false,"missing_fields":["cpu_percent"]}}');
    $this->sourceAgent->forceFill(['sequence' => 2, 'heartbeat_hash' => str_repeat('c', 64)])->save();
    try {
        $this->source->capture($this->sourceActor, $this->sourceDevice->id, null, ['system'], $preview['snapshot_version']);
        $this->fail('Changed snapshot was sent.');
    } catch (ItObliSourceUnavailable $error) {
        expect($error->codeName)->toBe('source_changed');
    }
    $this->sourceAgent->forceFill(['observed_at' => now()->subDay(), 'last_seen_at' => now()->subDay()])->save();
    $this->source->assertCurrent($this->sourceActor, $binding);
    expect(fn () => $this->source->preview($this->sourceActor, $this->sourceDevice->id, null))->toThrow(ItObliSourceUnavailable::class);
    $this->sourceAgent->forceFill(['uuid' => '22222222-2222-4222-8222-222222222222'])->save();
    expect(fn () => $this->source->assertCurrent($this->sourceActor, $binding))->toThrow(ItObliSourceUnavailable::class);
});

it('separates common authority from diagnostic permission and denies revocation or deployment withdrawal', function () {
    $preview = $this->source->preview($this->sourceActor, $this->sourceDevice->id, null);
    $binding = $this->source->capture($this->sourceActor, $this->sourceDevice->id, null, ['system'], $preview['snapshot_version']);
    $permission = Permission::query()->where('key', 'securityDevices.endpoints.diagnostics')->firstOrFail();
    $this->sourceActor->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
    $common = $this->source->withAuthorized($this->sourceActor, $this->sourceDevice->id, null, fn ($actor, $authority) => $authority);
    expect(array_keys($common))->toBe(['actor_id', 'device_id', 'ticket_id', 'agent_uuid', 'assignment_fingerprint']);
    expect(fn () => $this->source->preview($this->sourceActor, $this->sourceDevice->id, null))->toThrow(HttpException::class)
        ->and(fn () => $this->source->assertCurrent($this->sourceActor, $binding))->toThrow(HttpException::class);
    $this->sourceAgent->forceFill(['revoked_at' => now(), 'credential_hash' => null])->save();
    expect(fn () => $this->source->withAuthorized($this->sourceActor, $this->sourceDevice->id, null, fn () => true))->toThrow(ItObliSourceUnavailable::class);
    config()->set('endpoint_management.obli.technician_enabled', false);
    expect(fn () => $this->source->preview($this->sourceActor, $this->sourceDevice->id, null))->toThrow(ItObliSourceUnavailable::class);
});

it('conceals unrelated Sites and requires the exact optional current linked ticket context', function () {
    $other = Device::factory()->create(['domain' => 'it_infrastructure', 'category' => 'endpoint', 'status' => 'active']);
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    DeviceAssignment::query()->create(['device_id' => $other->id, 'assignable_type' => 'site', 'assignable_id' => $site->id, 'assigned_at' => now()->subMinute(), 'assignment_type' => 'permanent']);
    expect(fn () => $this->source->preview($this->sourceActor, $other->id, null))->toThrow(HttpException::class);
    $ticket = ItTicket::factory()->create(['site_id' => $this->sourceSite->id, 'is_sensitive' => false, 'status' => 'open', 'work_type' => 'incident', 'merged_into_ticket_id' => null]);
    expect(fn () => $this->source->preview($this->sourceActor, $this->sourceDevice->id, $ticket->id))->toThrow(HttpException::class);
    $ticket->links()->create(['relationship' => 'affected_device', 'linkable_type' => $this->sourceDevice->getMorphClass(), 'linkable_id' => $this->sourceDevice->id]);
    $preview = $this->source->preview($this->sourceActor, $this->sourceDevice->id, $ticket->id);
    $binding = $this->source->capture($this->sourceActor, $this->sourceDevice->id, $ticket->id, ['services'], $preview['snapshot_version']);
    $ticket->links()->delete();
    expect(fn () => $this->source->assertCurrent($this->sourceActor, $binding))->toThrow(HttpException::class);
});
