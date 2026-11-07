<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\ApprovedProbeScopeProvider;
use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\Obli\ObliAiSettings;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\NetworkChecks\Models\NetworkCheckSession;
use App\Domain\SecurityDevices\NetworkChecks\Models\NetworkCheckStep;
use App\Domain\SecurityDevices\NetworkChecks\NetworkCheckProvider;
use App\Domain\SecurityDevices\NetworkChecks\NetworkChecks;
use App\Models\AppSetting;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/*
 * S&D Phase I (pop-up 97): Obli checks a Site's network. Read-only checks from a fixed list, run
 * by the hub that watches the Site against devices Oblivion knows there; the AI stays on the
 * server; findings come from what really came back, and a check that can't be done says why.
 */

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    $this->configPair = sodium_crypto_sign_seed_keypair(str_repeat("\x61", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    config()->set('monitoring.collector.signing_secret_key', base64_encode(sodium_crypto_sign_secretkey($this->configPair)));

    $this->site = Site::factory()->create(['name' => 'Miro House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->otherSite = Site::factory()->create(['name' => 'Kauri House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    DiscoveryScope::factory()->create([
        'site_id' => $this->site->id, 'collector_id' => null, 'cidrs' => ['10.66.1.0/24'], 'protocols' => ['icmp', 'tcp', 'tls'],
        'port_bounds' => ['tcp' => [443]], 'exclusions' => [], 'status' => 'active',
    ]);
    $hubPair = sodium_crypto_sign_seed_keypair(str_repeat("\x62", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $hubPublic = sodium_crypto_sign_publickey($hubPair);
    $this->hub = MonitoringCollector::factory()->create([
        'site_id' => $this->site->id, 'name' => 'Miro House', 'collector_uuid' => (string) Str::uuid(),
        'public_key' => base64_encode($hubPublic), 'public_key_fingerprint' => hash('sha256', $hubPublic),
        'client_certificate_fingerprint' => hash('sha256', 'network-check-hub-certificate'),
        'status' => 'online', 'last_seen_at' => now(), 'approved_at' => now()->subDay(), 'revoked_at' => null,
        'runtime_status' => ['version' => '2026.11.3', 'command_adapters' => 'web_page_v1,network_checks_v1'],
    ]);
    $profile = MonitoringProfile::factory()->create(['stale_after_seconds' => 300, 'interval_seconds' => 60, 'is_active' => true]);
    $device = function (string $name, string $subcategory, string $ip, bool $watched = true) use ($profile): Device {
        $device = Device::factory()->create([
            'name' => $name, 'domain' => 'it_infrastructure', 'category' => 'network', 'subcategory' => $subcategory, 'manufacturer' => 'Ubiquiti',
            'model' => 'UniFi', 'ip_address' => $ip, 'last_seen_at' => now(),
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id,
            'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(),
        ]);
        if ($watched) {
            Monitor::factory()->create([
                'device_id' => $device->id, 'profile_id' => $profile->id, 'collector_id' => $this->hub->id, 'kind' => MonitorKind::Icmp,
                'target' => $ip, 'current_state' => MonitorState::Healthy, 'last_observation_at' => now(), 'is_enabled' => true,
            ]);
        }

        return $device;
    };
    $this->router = $device('Miro House gateway', 'router', '10.66.1.1');
    $this->wifi = $device('Lounge Wi-Fi', 'access_point', '10.66.1.21');
    $this->app->instance(DnsResolver::class, new class implements DnsResolver
    {
        public function resolve(string $host): array
        {
            return [$host];
        }
    });
    foreach ([ApprovedProbeScopeProvider::class, EgressPolicy::class, NetworkChecks::class] as $service) {
        $this->app->forgetInstance($service);
    }
    AppSetting::query()->updateOrCreate(['key' => ObliAiSettings::KEY], ['value' => [
        'version' => 3, 'enabled' => true, 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'max_output_tokens' => 2048,
        'daily_budget_usd' => '10.00', 'monthly_budget_usd' => '100.00', 'input_price_usd' => '4.00', 'output_price_usd' => '20.00',
        'chat_retention_days' => 7, 'diagnostic_categories' => [], 'knowledge_enabled' => false, 'technician_enabled' => false,
    ]]);
    AppSetting::query()->updateOrCreate(['key' => 'obli.ai.provider_secret'], ['value' => Crypt::encryptString('fake-test-key-not-a-real-key')]);
    $this->provider = new class implements NetworkCheckProvider
    {
        /** @var list<array<string, mixed>> */
        public array $answers = [];

        /** @var list<array{tool: string, message: string}> */
        public array $calls = [];

        public function call(array $configuration, string $system, string $message, array $tool): array
        {
            $this->calls[] = ['tool' => $tool['name'], 'message' => $message];

            return ['input' => array_shift($this->answers) ?? [], 'input_tokens' => 1200, 'output_tokens' => 300];
        }
    };
    $this->app->instance(NetworkCheckProvider::class, $this->provider);
    $this->person = function (array $keys, ?int $siteId = null): User {
        $user = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $siteId ?? $this->site->id, 'secondary_site_ids' => [], 'is_active' => true]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user->fresh();
    };
    $this->it = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.integrations.manage']);
});

it('lets only IT ask, and only about Sites they can see', function (): void {
    $viewer = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view']);
    $this->actingAs($viewer)->getJson("/security-devices/sites/{$this->site->id}/network-checks")->assertForbidden();

    $elsewhere = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.integrations.manage'], $this->otherSite->id);
    $this->actingAs($elsewhere)->getJson("/security-devices/sites/{$this->site->id}/network-checks")->assertNotFound();
    $this->actingAs($elsewhere)->postJson("/security-devices/sites/{$this->site->id}/network-checks", ['question' => 'Is the internet down at Miro House?'])->assertNotFound();

    $this->actingAs($this->it)->getJson("/security-devices/sites/{$this->site->id}/network-checks")
        ->assertOk()->assertJsonPath('ready.ok', true)->assertJsonPath('ready.hub', 'Miro House hub');
});

it('says honestly why it can’t check: AI off, or a hub without the update', function (): void {
    $ai = AppSetting::query()->where('key', ObliAiSettings::KEY)->first();
    $ai->update(['value' => [...(array) $ai->value, 'enabled' => false]]);
    $this->actingAs($this->it)->getJson("/security-devices/sites/{$this->site->id}/network-checks")
        ->assertOk()->assertJsonPath('ready.reason', 'Not available: Obli’s AI is turned off in Settings › Obli.');
    $ai->update(['value' => [...(array) $ai->value, 'enabled' => true]]);

    $this->hub->forceFill(['runtime_status' => ['command_adapters' => 'web_page_v1']])->save();
    $this->actingAs($this->it)->postJson("/security-devices/sites/{$this->site->id}/network-checks", ['question' => 'Is the internet down at Miro House?'])
        ->assertUnprocessable()->assertJsonPath('errors.question.0', 'Not available: Miro House hub needs its software update before Obli can run network checks through it.');
    expect(NetworkCheckSession::query()->count())->toBe(0);
});

it('plans from the allow-list only, sends the hub signed read-only checks, and writes findings from what came back', function (): void {
    $this->provider->answers[] = [
        'plan' => ['Test the delay to the gateway', 'Look at the Wi-Fi point'],
        'checks' => [
            ['kind' => 'delay', 'device_id' => $this->router->id],
            ['kind' => 'reboot_router', 'device_id' => $this->router->id], // not on the list: dropped
            ['kind' => 'delay', 'device_id' => 999_999], // not a device at the Site: dropped
            ['kind' => 'wifi', 'device_id' => $this->wifi->id], // no UniFi login saved: not available
            ['kind' => 'stored_outages', 'device_id' => null],
        ],
        'reply' => '',
    ];
    $this->provider->answers[] = ['answer' => '**The line is fine:** 14 ms to the gateway, none lost.', 'focus_device_id' => $this->wifi->id, 'suggest_unifi' => false];

    $uuid = $this->actingAs($this->it)->postJson("/security-devices/sites/{$this->site->id}/network-checks", ['question' => 'Video calls keep dropping in the lounge. Can you check?'])
        ->assertCreated()->json('session.uuid');
    $session = NetworkCheckSession::query()->where('uuid', $uuid)->sole();
    expect($session->state)->toBe('checking')
        ->and($session->open_site_id)->toBe($this->site->id)
        ->and($this->provider->calls[0]['tool'])->toBe('plan_checks');
    $steps = NetworkCheckStep::query()->orderBy('position')->get();
    expect($steps->pluck('kind')->all())->toBe(['delay', 'wifi', 'stored_outages'])
        ->and($steps[1]->state)->toBe('not_available')
        ->and($steps[1]->finding)->toBe('Not available: there’s no UniFi login saved for Miro House')
        ->and($steps[2]->state)->toBe('done');

    // One running session per Site.
    $this->actingAs($this->it)->postJson("/security-devices/sites/{$this->site->id}/network-checks", ['question' => 'And the printer, while you’re there?'])
        ->assertUnprocessable();

    $work = app(NetworkChecks::class)->hubWork($this->hub->fresh(), []);
    $job = $work['jobs'][0];
    expect($work['jobs'])->toHaveCount(1)
        ->and($job['kind'])->toBe('delay')
        ->and($job['params'])->toBe(['address' => '10.66.1.1'])
        ->and($job['device_id'])->toBe((string) $this->router->id)
        ->and($job['credential_lease'])->toBeNull()
        ->and(sodium_crypto_sign_verify_detached(base64_decode($job['signature']), NetworkChecks::canonical($job), sodium_crypto_sign_publickey($this->configPair)))->toBeTrue();

    app(NetworkChecks::class)->hubWork($this->hub->fresh(), ['results' => [[
        'job' => $job['job'], 'state' => 'done', 'result' => ['sent' => 10, 'received' => 10, 'min_ms' => 9, 'median_ms' => 14, 'max_ms' => 31, 'extra' => 'dropped'],
    ]]]);
    $delay = NetworkCheckStep::query()->where('uuid', $job['job'])->sole();
    expect($delay->state)->toBe('done')
        ->and($delay->finding)->toBe('14 ms typical (9–31 ms) · none lost')
        ->and($delay->result)->toEqual(['sent' => 10, 'received' => 10, 'min_ms' => 9.0, 'median_ms' => 14.0, 'max_ms' => 31.0]);

    // The queue is sync in tests, so the answer is already written.
    $session->refresh();
    expect($session->state)->toBe('answered')
        ->and($session->open_site_id)->toBeNull()
        ->and($session->answer)->toBe('**The line is fine:** 14 ms to the gateway, none lost.')
        ->and($session->unchecked_numbers)->toBeFalse()
        ->and($session->focus_device_id)->toBe($this->wifi->id)
        ->and($this->provider->calls[1]['tool'])->toBe('give_answer')
        ->and($this->provider->calls[1]['message'])->toContain('14 ms typical');
    $this->assertDatabaseHas('audit_logs', ['action' => 'security_devices.network_check.answered']);

    $this->actingAs($this->it)->getJson("/security-devices/network-checks/{$uuid}")
        ->assertOk()->assertJsonPath('session.state', 'answered')->assertJsonPath('session.focus.name', 'Lounge Wi-Fi')
        ->assertJsonPath('session.steps.0.finding', '14 ms typical (9–31 ms) · none lost');
});

it('marks an answer with a number the hub never reported', function (): void {
    $this->provider->answers[] = ['plan' => ['Check the drop-outs'], 'checks' => [['kind' => 'stored_outages', 'device_id' => null]], 'reply' => ''];
    $this->provider->answers[] = ['answer' => 'The line dropped 7 times this week.', 'focus_device_id' => null, 'suggest_unifi' => false];

    $uuid = $this->actingAs($this->it)->postJson("/security-devices/sites/{$this->site->id}/network-checks", ['question' => 'Has the internet been dropping out?'])
        ->assertCreated()->json('session.uuid');
    app(NetworkChecks::class)->sweep();

    expect(NetworkCheckSession::query()->where('uuid', $uuid)->sole()->unchecked_numbers)->toBeTrue();
});

it('never sends the hub a device it doesn’t watch, and says so', function (): void {
    $printer = Device::factory()->create(['name' => 'Office switch', 'domain' => 'it_infrastructure', 'category' => 'network', 'subcategory' => 'switch', 'ip_address' => '10.66.1.30']);
    DeviceAssignment::query()->create([
        'device_id' => $printer->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id,
        'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(),
    ]);
    $this->provider->answers[] = ['plan' => ['Test the switch'], 'checks' => [['kind' => 'delay', 'device_id' => $printer->id]], 'reply' => ''];
    $this->provider->answers[] = ['answer' => 'Nothing could be checked.', 'focus_device_id' => null, 'suggest_unifi' => false];

    $this->actingAs($this->it)->postJson("/security-devices/sites/{$this->site->id}/network-checks", ['question' => 'Is the office switch answering?'])->assertCreated();

    expect(NetworkCheckStep::query()->sole()->finding)->toBe('Not available: Miro House hub doesn’t watch Office switch yet')
        ->and(app(NetworkChecks::class)->hubWork($this->hub->fresh(), [])['jobs'])->toBe([]);
});

it('marks a check the hub didn’t answer in time as not available, and still answers', function (): void {
    $this->provider->answers[] = ['plan' => ['Test the gateway'], 'checks' => [['kind' => 'delay', 'device_id' => $this->router->id]], 'reply' => ''];
    $this->provider->answers[] = ['answer' => 'The hub couldn’t check the gateway in time.', 'focus_device_id' => null, 'suggest_unifi' => false];
    $this->actingAs($this->it)->postJson("/security-devices/sites/{$this->site->id}/network-checks", ['question' => 'Is the gateway answering?'])->assertCreated();

    NetworkCheckStep::query()->update(['expires_at' => now()->subSecond()]);
    app(NetworkChecks::class)->sweep();

    expect(NetworkCheckStep::query()->sole()->finding)->toBe('Not available: the hub didn’t pick it up in time')
        ->and(NetworkCheckSession::query()->sole()->state)->toBe('answered');
});

it('raises an IT job with the whole session on it', function (): void {
    $this->provider->answers[] = ['plan' => ['Check the drop-outs'], 'checks' => [['kind' => 'stored_outages', 'device_id' => null]], 'reply' => ''];
    $this->provider->answers[] = ['answer' => 'No drop-outs recorded.', 'focus_device_id' => null, 'suggest_unifi' => false];
    $uuid = $this->actingAs($this->it)->postJson("/security-devices/sites/{$this->site->id}/network-checks", ['question' => 'Has the internet been dropping out?'])->json('session.uuid');
    app(NetworkChecks::class)->sweep();
    // IT's own intake decides who may raise a job.
    $this->actingAs($this->it)->getJson("/security-devices/network-checks/{$uuid}")->assertJsonPath('session.can_ticket', false);
    $this->it->permissionOverrides()->attach(Permission::query()->where('key', 'it.request')->firstOrFail()->id, ['allowed' => true]);
    $this->it = $this->it->fresh();
    $this->actingAs($this->it)->getJson("/security-devices/network-checks/{$uuid}")->assertJsonPath('session.can_ticket', true);

    $ticket = $this->actingAs($this->it)->postJson("/security-devices/network-checks/{$uuid}/ticket")->assertOk()->json('ticket');
    $again = $this->actingAs($this->it)->postJson("/security-devices/network-checks/{$uuid}/ticket")->assertOk()->json('ticket');

    expect($again['id'])->toBe($ticket['id']);
    $description = \App\Models\ItTicket::query()->findOrFail($ticket['id'])->description;
    expect($description)->toContain('Has the internet been dropping out?')
        ->and($description)->toContain('Drop-outs on the Internet tab: No drop-outs recorded in the last 14 days')
        ->and($description)->toContain('Read-only: nothing on the network was changed.');
});
