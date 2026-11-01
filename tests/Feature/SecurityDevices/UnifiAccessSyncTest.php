<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Access\Models\AccessCard;
use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Access\Models\AccessDoor;
use App\Domain\SecurityDevices\Access\Models\AccessEvent;
use App\Domain\SecurityDevices\Access\Models\AccessPerson;
use App\Domain\SecurityDevices\Access\Models\AccessPolicy;
use App\Domain\SecurityDevices\Access\Models\AccessVisitor;
use App\Domain\SecurityDevices\Access\UnifiAccessSync;
use App\Domain\SecurityDevices\Cctv\Models\CameraEndpointTrust;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 * S&D Phase H (H0): UniFi Access kept in step through each Site's hub. The server sends a hub
 * that runs it a read-only console list with a token sealed to that hub; the hub sends back what
 * the console holds. A staff match by employee number is only a suggestion.
 */

const ACCESS_SYNC_TOKEN = 'TEST-ONLY-ACCESS-SYNC-TOKEN';
const ACCESS_SYNC_PIN = 'sha256//GGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGGG=';

beforeEach(function (): void {
    $this->site = Site::factory()->create(['name' => 'Kowhai House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->console = AccessConsole::query()->create(['site_id' => $this->site->id, 'address' => '10.78.0.40', 'port' => 12445, 'state' => 'waiting']);
    CredentialReference::query()->create([
        'reference_key' => 'database:unifi_access/'.$this->site->id.'/sync', 'site_id' => $this->site->id, 'provider' => 'unifi_access',
        'purpose' => 'device_management', 'capabilities' => ['read:access.sync'],
        'secret_manager_reference' => 'secret/data/sites/'.$this->site->id.'/unifi-access',
        'secret_manager_reference_hash' => hash('sha256', 'unifi-access-'.$this->site->id),
        'status' => CredentialReferenceStatus::Active, 'rotation_status' => CredentialRotationStatus::Current,
        'test_status' => CredentialTestStatus::Passed, 'version' => 1, 'last_tested_at' => now(),
    ]);
    $hubPair = sodium_crypto_sign_seed_keypair(str_repeat("\x51", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $hubPublic = sodium_crypto_sign_publickey($hubPair);
    $this->hub = MonitoringCollector::factory()->create([
        'site_id' => $this->site->id, 'name' => 'Kowhai House', 'collector_uuid' => (string) Str::uuid(),
        'public_key' => base64_encode($hubPublic), 'public_key_fingerprint' => hash('sha256', $hubPublic),
        'client_certificate_fingerprint' => hash('sha256', 'access-hub-certificate'),
        'status' => 'online', 'last_seen_at' => now(), 'approved_at' => now()->subDay(), 'revoked_at' => null,
        'runtime_status' => ['version' => '2026.11.2', 'command_adapters' => 'ssh_config_backup_v1,unifi_access_v1'],
    ]);
    $this->app->instance(CredentialLeaseProvider::class, new class implements CredentialLeaseProvider
    {
        public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease
        {
            return new CredentialLease('lease-'.Str::uuid(), now()->toImmutable()->addMinutes(10), ['api_token' => ACCESS_SYNC_TOKEN]);
        }
    });
    $this->app->forgetInstance(UnifiAccessSync::class);
    $this->snapshot = fn (array $overrides = []): array => array_replace([
        'item_type' => 'unifi_access_snapshot',
        'console_id' => (string) $this->console->id,
        'site_id' => $this->site->id,
        'synced_at' => '2026-11-02T01:00:00+00:00',
        'state' => 'ok',
        'complete' => true,
        'doors' => [
            ['id' => 'door-front', 'name' => 'Front door', 'lock' => 'locked', 'position' => 'closed', 'hub_bound' => true],
            ['id' => 'door-office', 'name' => 'Office', 'lock' => 'unlocked', 'position' => 'open', 'hub_bound' => false],
        ],
        'people' => [
            ['id' => 'user-ana', 'first_name' => 'Ana', 'last_name' => 'Tane', 'employee_number' => 'E104', 'status' => 'active', 'policy_ids' => ['policy-staff'],
                'cards' => [['token_hash' => hash('sha256', 'card-1'), 'display_id' => '100001']]],
            ['id' => 'user-unknown', 'first_name' => 'No', 'last_name' => 'Match', 'employee_number' => 'E999', 'status' => 'deactivated', 'policy_ids' => [], 'cards' => []],
        ],
        'visitors' => [['id' => 'visitor-1', 'name' => 'Plumber', 'status' => 'upcoming', 'starts_at' => '2026-11-02T02:00:00+00:00', 'ends_at' => '2026-11-02T04:00:00+00:00']],
        'policies' => [['id' => 'policy-staff', 'name' => 'Staff', 'door_ids' => ['door-front'], 'schedule' => ['id' => 'sched-1']]],
        'events' => [
            ['id' => 'evt-1', 'occurred_at' => '2026-11-02T00:10:00+00:00', 'door_id' => 'door-front', 'person_id' => 'user-ana', 'kind' => 'opened', 'result' => 'access', 'method' => 'card', 'actor_name' => 'Ana Tane'],
            ['id' => 'evt-2', 'occurred_at' => '2026-11-02T00:20:00+00:00', 'door_id' => 'door-front', 'person_id' => null, 'kind' => 'denied', 'result' => 'blocked', 'method' => 'magic', 'actor_name' => null],
        ],
        'events_until' => '2026-11-02T01:00:00+00:00',
    ], $overrides);
});

it('sends a hub that runs it a read-only console list, with the token sealed to that hub', function (): void {
    $section = app(UnifiAccessSync::class)->hubSection($this->hub->fresh());

    expect($section['consoles'])->toHaveCount(1);
    $entry = $section['consoles'][0];
    expect(array_keys($entry))->toBe(['console_id', 'site_id', 'address', 'port', 'trusted_pin', 'interval_seconds', 'events_since', 'credential_lease'])
        ->and($entry['console_id'])->toBe((string) $this->console->id)
        ->and($entry['address'])->toBe('10.78.0.40')
        ->and($entry['port'])->toBe(12445)
        ->and($entry['trusted_pin'])->toBeNull()
        ->and($entry['credential_lease']['protocol'])->toBe('access.unifi')
        ->and($entry['credential_lease']['target'])->toBe('10.78.0.40')
        ->and($entry['credential_lease']['device_id'])->toBe('access-console-'.$this->console->id)
        ->and($entry['credential_lease']['site_id'])->toBe($this->site->id)
        ->and(json_encode($section))->not->toContain(ACCESS_SYNC_TOKEN);

    // Once the console's key is trusted in Setup, the hub gets it.
    CameraEndpointTrust::query()->create(['site_id' => $this->site->id, 'host' => '10.78.0.40', 'port' => 12445, 'trusted_pin' => ACCESS_SYNC_PIN, 'trusted_fingerprint' => str_repeat('AB:', 31).'AB', 'trusted_at' => now()]);
    expect(app(UnifiAccessSync::class)->hubSection($this->hub->fresh())['consoles'][0]['trusted_pin'])->toBe(ACCESS_SYNC_PIN);
});

it('sends nothing to an older hub, or without a saved UniFi Access login', function (): void {
    $this->hub->forceFill(['runtime_status' => ['version' => '2026.10.1', 'command_adapters' => 'ssh_config_backup_v1']])->save();
    expect(app(UnifiAccessSync::class)->hubSection($this->hub->fresh()))->toBeNull();

    $this->hub->forceFill(['runtime_status' => ['version' => '2026.11.2', 'command_adapters' => 'unifi_access_v1']])->save();
    CredentialReference::query()->where('provider', 'unifi_access')->update(['status' => CredentialReferenceStatus::Revoked->value]);
    expect(app(UnifiAccessSync::class)->hubSection($this->hub->fresh()))->toBeNull();
});

it('never sends another Site’s console', function (): void {
    $other = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    AccessConsole::query()->create(['site_id' => $other->id, 'address' => '10.79.0.40', 'port' => 12445, 'state' => 'waiting']);

    $addresses = array_column(app(UnifiAccessSync::class)->hubSection($this->hub->fresh())['consoles'], 'address');
    expect($addresses)->toBe(['10.78.0.40']);
});

it('stores what the console holds, and suggests a staff match by employee number', function (): void {
    $staff = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::factory()->create(['user_id' => $staff->id, 'employee_number' => 'E104', 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true]);

    app(UnifiAccessSync::class)->ingest($this->hub->fresh(), ($this->snapshot)());

    $console = $this->console->fresh();
    expect($console->state)->toBe('ok')
        ->and($console->events_until->toAtomString())->toBe('2026-11-02T01:00:00+00:00')
        ->and(AccessDoor::query()->where('console_id', $console->id)->pluck('lock_state', 'external_id')->all())->toBe(['door-front' => 'locked', 'door-office' => 'unlocked'])
        ->and(AccessVisitor::query()->count())->toBe(1)
        ->and(AccessPolicy::query()->first()->door_ids)->toBe(['door-front'])
        ->and(AccessCard::query()->first()->token_hash)->toBe(hash('sha256', 'card-1'));

    $ana = AccessPerson::query()->where('external_id', 'user-ana')->first();
    expect($ana->user_id)->toBe($staff->id)
        ->and($ana->match_state)->toBe('suggested')
        ->and(AccessPerson::query()->where('external_id', 'user-unknown')->first()->match_state)->toBe('unmatched');

    $events = AccessEvent::query()->orderBy('occurred_at')->get();
    expect($events)->toHaveCount(2)
        ->and($events[0]->person_id)->toBe($ana->id)
        ->and($events[1]->kind)->toBe('denied')
        ->and($events[1]->method)->toBe('other');

    // The same events again are not stored twice.
    app(UnifiAccessSync::class)->ingest($this->hub->fresh(), ($this->snapshot)(['synced_at' => '2026-11-02T01:05:00+00:00']));
    expect(AccessEvent::query()->count())->toBe(2);
});

it('removes what UniFi no longer has only when the snapshot is complete', function (): void {
    app(UnifiAccessSync::class)->ingest($this->hub->fresh(), ($this->snapshot)());
    $oneDoor = [['id' => 'door-front', 'name' => 'Front door', 'lock' => 'locked', 'position' => 'closed', 'hub_bound' => true]];

    app(UnifiAccessSync::class)->ingest($this->hub->fresh(), ($this->snapshot)(['synced_at' => '2026-11-02T01:05:00+00:00', 'complete' => false, 'doors' => $oneDoor]));
    expect(AccessDoor::query()->count())->toBe(2);

    app(UnifiAccessSync::class)->ingest($this->hub->fresh(), ($this->snapshot)(['synced_at' => '2026-11-02T01:10:00+00:00', 'complete' => true, 'doors' => $oneDoor]));
    expect(AccessDoor::query()->pluck('external_id')->all())->toBe(['door-front'])
        ->and(AccessEvent::query()->count())->toBe(2);
});

it('records an untrusted certificate for Setup, and says when the hub couldn’t read', function (): void {
    app(UnifiAccessSync::class)->ingest($this->hub->fresh(), [
        'item_type' => 'unifi_access_snapshot', 'console_id' => (string) $this->console->id, 'state' => 'untrusted',
        'reason' => 'The console’s certificate has changed.',
        'certificate' => ['pin' => ACCESS_SYNC_PIN, 'fingerprint' => str_repeat('CD:', 31).'CD', 'subject' => 'UniFi Access', 'valid_to' => 1_900_000_000],
    ]);
    expect($this->console->fresh()->state)->toBe('untrusted')
        ->and($this->console->fresh()->last_error)->toBe('The console’s certificate has changed.')
        ->and(CameraEndpointTrust::query()->where('host', '10.78.0.40')->value('offered_pin'))->toBe(ACCESS_SYNC_PIN);

    // A certificate missing its fields is ignored, never an error.
    app(UnifiAccessSync::class)->ingest($this->hub->fresh(), ['console_id' => (string) $this->console->id, 'state' => 'untrusted', 'certificate' => ['pin' => 'x']]);

    app(UnifiAccessSync::class)->ingest($this->hub->fresh(), ['console_id' => (string) $this->console->id, 'state' => 'failed', 'reason' => 'UniFi Access refused the token.']);
    expect($this->console->fresh()->state)->toBe('failed')
        ->and($this->console->fresh()->last_error)->toBe('UniFi Access refused the token.');
});

it('ignores a snapshot for a console outside the hub’s Sites, and tolerates junk rows', function (): void {
    $other = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $far = AccessConsole::query()->create(['site_id' => $other->id, 'address' => '10.79.0.40', 'port' => 12445, 'state' => 'waiting']);

    app(UnifiAccessSync::class)->ingest($this->hub->fresh(), ($this->snapshot)(['console_id' => (string) $far->id]));
    expect($far->fresh()->state)->toBe('waiting')
        ->and(AccessDoor::query()->count())->toBe(0);

    app(UnifiAccessSync::class)->ingest($this->hub->fresh(), ($this->snapshot)(['doors' => 'x', 'people' => [['id' => ['nested']], 7], 'events' => [['id' => 'evt-9']]]));
    expect($this->console->fresh()->state)->toBe('ok')
        ->and(AccessDoor::query()->count())->toBe(0);
});

it('keeps each door’s readers and the console’s emergency state, and keeps them when an older console doesn’t say', function (): void {
    app(UnifiAccessSync::class)->ingest($this->hub->fresh(), ($this->snapshot)([
        'readers' => [['id' => 'reader-front', 'name' => 'Front reader', 'type' => 'UA-G2-PRO', 'door_id' => 'door-front'], ['id' => 'reader-x', 'name' => 'X', 'type' => 'UA', 'door_id' => 'door-missing']],
        'emergency' => 'lockdown',
    ]));
    expect(AccessDoor::query()->where('external_id', 'door-front')->first()->readers)->toBe([['id' => 'reader-front', 'name' => 'Front reader', 'type' => 'UA-G2-PRO']])
        ->and(AccessDoor::query()->where('external_id', 'door-office')->first()->readers)->toBe([])
        ->and($this->console->fresh()->emergency_mode)->toBe('lockdown');

    app(UnifiAccessSync::class)->ingest($this->hub->fresh(), ($this->snapshot)(['synced_at' => '2026-11-02T01:05:00+00:00']));
    expect(AccessDoor::query()->where('external_id', 'door-front')->first()->readers)->toHaveCount(1)
        ->and($this->console->fresh()->emergency_mode)->toBe('lockdown');
});

it('clears names on door events after 90 days', function (): void {
    app(UnifiAccessSync::class)->ingest($this->hub->fresh(), ($this->snapshot)());
    AccessEvent::query()->where('event_key', hash('sha256', 'evt-1'))->update(['occurred_at' => now()->subDays(91)]);

    expect(app(UnifiAccessSync::class)->forgetOldNames())->toBe(1)
        ->and(AccessEvent::query()->where('event_key', hash('sha256', 'evt-1'))->value('actor_name'))->toBeNull();
});
