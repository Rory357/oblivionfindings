<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Access\AccessJobs;
use App\Domain\SecurityDevices\Access\AccessLeavers;
use App\Domain\SecurityDevices\Access\Models\AccessCard;
use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Access\Models\AccessDoor;
use App\Domain\SecurityDevices\Access\Models\AccessJob;
use App\Domain\SecurityDevices\Access\Models\AccessPerson;
use App\Domain\SecurityDevices\Access\Models\AccessPolicy;
use App\Domain\SecurityDevices\Access\Models\AccessVisitor;
use App\Domain\SecurityDevices\Cctv\Models\CameraEndpointTrust;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Str;

/*
 * S&D Phase H (H1, D20): people, cards and visitor passes changed in UniFi Access through the
 * Site's hub. Each change is signed for the hub, never repeated, audited, and shown once the hub
 * says it's done. Nothing here unlocks a door. Leavers are turned off after their last day.
 */

const ACCESS_JOB_TOKEN = 'TEST-ONLY-ACCESS-JOB-TOKEN';
const ACCESS_JOB_PIN = 'sha256//JJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJ=';

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    $this->configPair = sodium_crypto_sign_seed_keypair(str_repeat("\x53", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    config()->set('monitoring.collector.signing_secret_key', base64_encode(sodium_crypto_sign_secretkey($this->configPair)));

    $this->site = Site::factory()->create(['name' => 'Totara House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->console = AccessConsole::query()->create(['site_id' => $this->site->id, 'address' => '10.81.0.40', 'port' => 12445, 'state' => 'ok']);
    CameraEndpointTrust::query()->create(['site_id' => $this->site->id, 'host' => '10.81.0.40', 'port' => 12445, 'trusted_pin' => ACCESS_JOB_PIN, 'trusted_fingerprint' => str_repeat('AB:', 31).'AB', 'trusted_at' => now()]);
    CredentialReference::query()->create([
        'reference_key' => 'database:unifi_access/'.$this->site->id.'/admin', 'site_id' => $this->site->id, 'provider' => 'unifi_access',
        'purpose' => 'device_management', 'capabilities' => ['write:access.admin'],
        'secret_manager_reference' => 'secret/data/sites/'.$this->site->id.'/unifi-access',
        'secret_manager_reference_hash' => hash('sha256', 'unifi-access-admin-'.$this->site->id),
        'status' => CredentialReferenceStatus::Active, 'rotation_status' => CredentialRotationStatus::Current,
        'test_status' => CredentialTestStatus::Passed, 'version' => 1, 'last_tested_at' => now(),
    ]);
    $hubPair = sodium_crypto_sign_seed_keypair(str_repeat("\x54", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $hubPublic = sodium_crypto_sign_publickey($hubPair);
    $this->hub = MonitoringCollector::factory()->create([
        'site_id' => $this->site->id, 'name' => 'Totara House', 'collector_uuid' => (string) Str::uuid(),
        'public_key' => base64_encode($hubPublic), 'public_key_fingerprint' => hash('sha256', $hubPublic),
        'client_certificate_fingerprint' => hash('sha256', 'access-jobs-hub-certificate'),
        'status' => 'online', 'last_seen_at' => now(), 'approved_at' => now()->subDay(), 'revoked_at' => null,
        'runtime_status' => ['version' => '2026.11.2', 'command_adapters' => 'unifi_access_v1,unifi_access_admin_v1'],
    ]);
    $this->app->instance(CredentialLeaseProvider::class, new class implements CredentialLeaseProvider
    {
        public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease
        {
            return new CredentialLease('lease-'.Str::uuid(), now()->toImmutable()->addMinutes(10), ['api_token' => ACCESS_JOB_TOKEN]);
        }
    });
    $this->app->forgetInstance(AccessJobs::class);
    AccessPolicy::query()->create(['console_id' => $this->console->id, 'external_id' => 'policy-staff', 'name' => 'Staff', 'door_ids' => ['door-front'], 'seen_at' => now()]);
    AccessDoor::query()->create(['console_id' => $this->console->id, 'external_id' => 'door-front', 'name' => 'Front door', 'hub_bound' => true, 'readers' => [['id' => 'reader-front', 'name' => 'Front reader', 'type' => 'UA-G2-PRO']], 'seen_at' => now()]);

    $this->person = function (array $keys, ?Site $site = null): User {
        $user = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => ($site ?? $this->site)->id, 'secondary_site_ids' => [], 'is_active' => true]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user->fresh();
    };
    $this->manager = ($this->person)(['securityDevices.viewAny', 'securityDevices.accessControl.view', 'securityDevices.accessControl.manage']);
    $this->work = fn (array $report = []): array => app(AccessJobs::class)->hubWork($this->hub->fresh(), $report);
});

it('hands the hub each change signed, with the token sealed to it, and keeps server-only details back', function (): void {
    $job = app(AccessJobs::class)->queue($this->console, 'user_create', ['first_name' => 'Ana', 'last_name' => 'Tane', 'employee_number' => 'E104', 'policy_ids' => ['policy-staff'], '_kind' => 'staff', '_user_id' => 5], $this->manager);

    $jobs = ($this->work)()['jobs'];
    expect($jobs)->toHaveCount(1);
    $details = $jobs[0];
    expect(array_keys($details))->toBe(['job', 'kind', 'params', 'console_id', 'site_id', 'address', 'port', 'trusted_pin', 'expires_at', 'credential_lease', 'signature'])
        ->and($details['params'])->toBe(['first_name' => 'Ana', 'last_name' => 'Tane', 'employee_number' => 'E104', 'policy_ids' => ['policy-staff']])
        ->and($details['trusted_pin'])->toBe(ACCESS_JOB_PIN)
        ->and($details['credential_lease']['device_id'])->toBe('access-console-'.$this->console->id)
        ->and(sodium_crypto_sign_verify_detached(base64_decode($details['signature']), AccessJobs::canonical($details), sodium_crypto_sign_publickey($this->configPair)))->toBeTrue()
        ->and(json_encode($jobs))->not->toContain(ACCESS_JOB_TOKEN)
        ->and($job->fresh()->state)->toBe('sent');

    // Not offered again straight away; offered again (same job) if the hub never answers.
    expect(($this->work)()['jobs'])->toBe([]);
    $this->travel(2)->minutes();
    expect(($this->work)()['jobs'][0]['job'] ?? null)->toBe($job->uuid);
    $this->assertDatabaseHas('audit_logs', ['action' => 'security_devices.access.job_requested']);
});

it('refuses a change it can’t send safely', function (callable $break, string $message): void {
    $break($this);

    expect(fn () => app(AccessJobs::class)->queue($this->console->fresh(), 'user_status', ['user_id' => 'user-ana', 'status' => 'deactivated'], $this->manager))
        ->toThrow(DomainException::class, $message);
})->with([
    'an older hub' => [fn ($test) => $test->hub->forceFill(['runtime_status' => ['command_adapters' => 'unifi_access_v1']])->save(), 'needs an update'],
    'no hub' => [fn ($test) => $test->hub->forceFill(['revoked_at' => now()])->save(), 'No hub covers'],
    'no saved login' => [fn ($test) => CredentialReference::query()->update(['status' => CredentialReferenceStatus::Revoked->value]), 'Save the UniFi Access login'],
    'an untrusted console' => [fn ($test) => CameraEndpointTrust::query()->update(['trusted_pin' => null]), 'Trust the console'],
]);

it('refuses a kind or detail outside the list, including any door action', function (string $kind, array $params): void {
    expect(fn () => app(AccessJobs::class)->queue($this->console, $kind, $params, $this->manager))->toThrow(DomainException::class);
})->with([
    'unlock' => ['door_unlock', ['door_id' => 'door-front']],
    'emergency' => ['emergency', ['mode' => 'evacuation']],
    'extra detail' => ['user_status', ['user_id' => 'user-ana', 'status' => 'deactivated', 'force' => true]],
    'odd status' => ['user_status', ['user_id' => 'user-ana', 'status' => 'admin']],
    'a year-long pass' => ['visitor_create', ['first_name' => 'A', 'last_name' => '', 'starts_at' => 1_800_000_000, 'ends_at' => 1_800_000_000 + 365 * 86_400, 'door_ids' => ['door-front']]],
]);

it('shows what the hub did straight away, and audits it', function (): void {
    $staff = ($this->person)([]);
    $create = app(AccessJobs::class)->queue($this->console, 'user_create', ['first_name' => 'Ana', 'last_name' => 'Tane', 'employee_number' => 'E104', 'policy_ids' => ['policy-staff'], '_kind' => 'staff', '_user_id' => $staff->id], $this->manager);
    ($this->work)();
    ($this->work)(['results' => [['job' => $create->uuid, 'state' => 'succeeded', 'result' => ['user_id' => 'user-ana']]]]);

    $ana = AccessPerson::query()->where('external_id', 'user-ana')->first();
    expect($create->fresh()->state)->toBe('succeeded')
        ->and($ana->user_id)->toBe($staff->id)
        ->and($ana->match_state)->toBe('matched')
        ->and($ana->kind)->toBe('staff');

    $enrol = app(AccessJobs::class)->queue($this->console, 'card_enrol', ['user_id' => 'user-ana', 'reader_id' => 'reader-front'], $this->manager);
    ($this->work)();
    ($this->work)(['results' => [['job' => $enrol->uuid, 'state' => 'succeeded', 'result' => ['token_hash' => hash('sha256', 'new-card'), 'display_id' => '100245']]]]);
    expect(AccessCard::query()->where('person_id', $ana->id)->value('display_id'))->toBe('100245');

    $off = app(AccessJobs::class)->queue($this->console, 'user_status', ['user_id' => 'user-ana', 'status' => 'deactivated'], $this->manager, 'Lost card');
    ($this->work)();
    ($this->work)(['results' => [['job' => $off->uuid, 'state' => 'succeeded', 'result' => ['status' => 'deactivated']]]]);
    expect($ana->fresh()->status)->toBe('deactivated');
    $this->assertDatabaseHas('audit_logs', ['action' => 'security_devices.access.job_succeeded']);
});

it('says “uncertain” for an answer that doesn’t look right, and for a change the hub never answered', function (): void {
    $odd = app(AccessJobs::class)->queue($this->console, 'user_status', ['user_id' => 'user-ana', 'status' => 'deactivated'], $this->manager);
    ($this->work)();
    ($this->work)(['results' => [['job' => $odd->uuid, 'state' => 'succeeded', 'result' => ['status' => 'superuser']]]]);
    expect($odd->fresh()->state)->toBe('uncertain');

    $quiet = app(AccessJobs::class)->queue($this->console, 'user_status', ['user_id' => 'user-ben', 'status' => 'deactivated'], $this->manager, null, 120);
    ($this->work)();
    $never = app(AccessJobs::class)->queue($this->console, 'user_status', ['user_id' => 'user-cat', 'status' => 'deactivated'], $this->manager, null, 120);
    $this->hub->forceFill(['runtime_status' => ['command_adapters' => 'unifi_access_v1']])->save();
    ($this->work)();
    $this->travel(3)->minutes();
    app(AccessJobs::class)->expire();

    expect($quiet->fresh()->state)->toBe('uncertain')
        ->and($never->fresh()->state)->toBe('expired');
});

it('ignores answers from another hub', function (): void {
    $job = app(AccessJobs::class)->queue($this->console, 'user_status', ['user_id' => 'user-ana', 'status' => 'deactivated'], $this->manager);
    ($this->work)();
    $other = MonitoringCollector::factory()->create(['site_id' => Site::factory()->create()->id, 'approved_at' => now(), 'revoked_at' => null, 'status' => 'online',
        'runtime_status' => ['command_adapters' => 'unifi_access_admin_v1']]);

    app(AccessJobs::class)->hubWork($other, ['results' => [['job' => $job->uuid, 'state' => 'succeeded', 'result' => ['status' => 'deactivated']]]]);
    expect($job->fresh()->state)->toBe('sent')
        ->and(app(AccessJobs::class)->hubWork($other, [])['jobs'])->toBe([]);
});

it('lets only Access managers at that Site ask for changes, with staff names from HR', function (): void {
    $staff = User::factory()->create(['name' => 'Hemi Walker', 'approved_at' => now()]);
    HrEmployeeProfile::factory()->create(['user_id' => $staff->id, 'employee_number' => 'E220', 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true]);

    $viewer = ($this->person)(['securityDevices.viewAny', 'securityDevices.accessControl.view']);
    $this->actingAs($viewer)->postJson("/security-devices/access/consoles/{$this->console->id}/people", ['user_id' => $staff->id, 'policy_ids' => []])->assertForbidden();

    $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $farConsole = AccessConsole::query()->create(['site_id' => $otherSite->id, 'address' => '10.82.0.40', 'port' => 12445, 'state' => 'ok']);
    $this->actingAs($this->manager)->postJson("/security-devices/access/consoles/{$farConsole->id}/people", ['user_id' => $staff->id, 'policy_ids' => []])->assertNotFound();

    $response = $this->actingAs($this->manager)->postJson("/security-devices/access/consoles/{$this->console->id}/people", [
        'user_id' => $staff->id, 'first_name' => 'Someone Else', 'policy_ids' => ['policy-staff'],
    ])->assertStatus(202);
    $job = AccessJob::query()->where('uuid', $response->json('job'))->first();
    expect($job->params['first_name'])->toBe('Hemi')
        ->and($job->params['last_name'])->toBe('Walker')
        ->and($job->params['employee_number'])->toBe('E220');

    $this->actingAs($this->manager)->postJson("/security-devices/access/consoles/{$this->console->id}/people", ['first_name' => 'Plumber', 'policy_ids' => ['policy-elsewhere']])
        ->assertUnprocessable()->assertJsonValidationErrors('policy_ids');

    $this->actingAs($this->manager)->getJson("/security-devices/access/jobs/{$job->uuid}")->assertOk()->assertJsonPath('state', 'queued');
});

it('adds a card only at a reader at that Site, and asks why before turning a card off', function (): void {
    $ana = AccessPerson::query()->create(['console_id' => $this->console->id, 'external_id' => 'user-ana', 'first_name' => 'Ana', 'status' => 'active', 'seen_at' => now()]);
    $card = AccessCard::query()->create(['person_id' => $ana->id, 'token_hash' => hash('sha256', 'old-card'), 'display_id' => '4821', 'status' => 'active', 'seen_at' => now()]);

    $this->actingAs($this->manager)->postJson("/security-devices/access/people/{$ana->id}/cards", ['reader_id' => 'reader-elsewhere'])->assertUnprocessable();
    $this->actingAs($this->manager)->postJson("/security-devices/access/people/{$ana->id}/cards", ['reader_id' => 'reader-front'])->assertStatus(202);

    $this->actingAs($this->manager)->deleteJson("/security-devices/access/cards/{$card->id}", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->actingAs($this->manager)->deleteJson("/security-devices/access/cards/{$card->id}", ['reason' => 'Lost'])->assertStatus(202);
    $removal = AccessJob::query()->where('kind', 'card_remove')->first();
    expect($removal->params['token_hash'])->toBe(hash('sha256', 'old-card'))
        ->and($removal->reason)->toBe('Lost');
});

it('matches a UniFi person only to staff at that Site', function (): void {
    $ana = AccessPerson::query()->create(['console_id' => $this->console->id, 'external_id' => 'user-ana', 'first_name' => 'Ana', 'status' => 'active', 'seen_at' => now()]);
    $elsewhere = ($this->person)([], Site::factory()->create());
    $here = ($this->person)([]);

    $this->actingAs($this->manager)->putJson("/security-devices/access/people/{$ana->id}/match", ['user_id' => $elsewhere->id])->assertUnprocessable();
    $this->actingAs($this->manager)->putJson("/security-devices/access/people/{$ana->id}/match", ['user_id' => $here->id])->assertOk();
    expect($ana->fresh()->match_state)->toBe('matched')
        ->and($ana->fresh()->user_id)->toBe($here->id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'security_devices.access.person_matched']);
});

it('turns a leaver off after the end of their last day, once', function (): void {
    $leaver = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::factory()->create(['user_id' => $leaver->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true, 'end_date' => '2026-11-12']);
    AccessPerson::query()->create(['console_id' => $this->console->id, 'external_id' => 'user-leaver', 'first_name' => 'Losa', 'status' => 'active', 'kind' => 'staff', 'user_id' => $leaver->id, 'match_state' => 'matched', 'seen_at' => now()]);
    AccessPerson::query()->create(['console_id' => $this->console->id, 'external_id' => 'user-guess', 'first_name' => 'Maybe', 'status' => 'active', 'kind' => 'staff', 'user_id' => $leaver->id, 'match_state' => 'suggested', 'seen_at' => now()]);

    // 11:59 pm on her last day, New Zealand time: not yet.
    expect(app(AccessLeavers::class)->tick(CarbonImmutable::parse('2026-11-12 23:59', 'Pacific/Auckland')->utc()))->toBe(0);
    // Just after midnight: turned off; the unconfirmed match is left alone.
    expect(app(AccessLeavers::class)->tick(CarbonImmutable::parse('2026-11-13 00:01', 'Pacific/Auckland')->utc()))->toBe(1)
        ->and(app(AccessLeavers::class)->tick(CarbonImmutable::parse('2026-11-13 00:06', 'Pacific/Auckland')->utc()))->toBe(0);

    $job = AccessJob::query()->first();
    expect($job->params)->toBe(['user_id' => 'user-leaver', 'status' => 'deactivated'])
        ->and($job->requested_by_user_id)->toBeNull()
        ->and($job->reason)->toBe('Left: last day 12 Nov 2026');
});

it('cancels a visitor pass and shows it at once', function (): void {
    $pass = AccessVisitor::query()->create(['console_id' => $this->console->id, 'external_id' => 'visitor-1', 'name' => 'Plumber', 'status' => 'upcoming', 'seen_at' => now()]);
    $this->actingAs($this->manager)->deleteJson("/security-devices/access/visitors/{$pass->id}")->assertStatus(202);
    $job = AccessJob::query()->where('kind', 'visitor_delete')->first();
    ($this->work)();
    ($this->work)(['results' => [['job' => $job->uuid, 'state' => 'succeeded', 'result' => ['deleted' => true]]]]);

    expect(AccessVisitor::query()->count())->toBe(0);
});
