<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\ApprovedProbeScopeProvider;
use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\WebPages\Models\WebRequest;
use App\Domain\SecurityDevices\WebPages\Models\WebSession;
use App\Domain\SecurityDevices\WebPages\WebPages;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Str;

/*
 * S&D Phase G (pop-up 61): a device's own web page through the Site's hub, for IT admins with
 * webPage.open. The page is served at an unguessable link inside a sandbox; the hub fetches it and
 * gets each request's session details signed.
 */

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    $this->configPair = sodium_crypto_sign_seed_keypair(str_repeat("\x57", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    config()->set('monitoring.collector.signing_secret_key', base64_encode(sodium_crypto_sign_secretkey($this->configPair)));
    config()->set('security_devices.web_page_wait_seconds', 0);

    $this->site = Site::factory()->create(['name' => 'Miro House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    DiscoveryScope::factory()->create([
        'site_id' => $this->site->id, 'collector_id' => null, 'cidrs' => ['10.66.1.0/24'], 'protocols' => ['tcp'],
        'port_bounds' => ['tcp' => [80]], 'exclusions' => [], 'status' => 'active',
    ]);
    $this->printer = Device::factory()->create([
        'name' => 'Miro House printer', 'domain' => 'it_infrastructure', 'category' => 'printer',
        'manufacturer' => 'Brother', 'model' => 'HL-L2350DW', 'ip_address' => '10.66.1.40', 'last_seen_at' => now(),
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $this->printer->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id,
        'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(),
    ]);
    $hubPair = sodium_crypto_sign_seed_keypair(str_repeat("\x58", SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $hubPublic = sodium_crypto_sign_publickey($hubPair);
    $this->hub = MonitoringCollector::factory()->create([
        'site_id' => $this->site->id, 'name' => 'Miro House', 'collector_uuid' => (string) Str::uuid(),
        'public_key' => base64_encode($hubPublic), 'public_key_fingerprint' => hash('sha256', $hubPublic),
        'client_certificate_fingerprint' => hash('sha256', 'web-hub-certificate'),
        'status' => 'online', 'last_seen_at' => now(), 'approved_at' => now()->subDay(), 'revoked_at' => null,
        'runtime_status' => ['version' => '2026.11.1', 'command_adapters' => 'terminal_v1,web_page_v1'],
    ]);
    $this->app->instance(DnsResolver::class, new class implements DnsResolver
    {
        public function resolve(string $host): array
        {
            return ['10.66.1.40'];
        }
    });
    foreach ([ApprovedProbeScopeProvider::class, EgressPolicy::class, WebPages::class] as $service) {
        $this->app->forgetInstance($service);
    }
    $this->person = function (array $keys): User {
        $user = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user->fresh();
    };
    $this->admin = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.webPage.open']);
});

it('opens only with webPage.open, through a hub that runs it, and logs it', function (): void {
    $manager = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view']);
    $this->actingAs($manager)->postJson("/security-devices/devices/{$this->printer->id}/web")->assertForbidden();

    $this->hub->forceFill(['runtime_status' => ['command_adapters' => 'terminal_v1']])->save();
    $this->actingAs($this->admin)->postJson("/security-devices/devices/{$this->printer->id}/web")
        ->assertUnprocessable()->assertJsonPath('errors.web.0', 'Miro House hub needs its software update before it can open device pages.');

    $this->hub->forceFill(['runtime_status' => ['command_adapters' => 'web_page_v1']])->save();
    $url = $this->actingAs($this->admin)->postJson("/security-devices/devices/{$this->printer->id}/web")->assertCreated()->json('url');
    expect($url)->toMatch('#^/security-devices/web/[A-Za-z0-9_-]{43}/$#')
        ->and(WebSession::query()->sole()->token_hash)->not->toContain(substr($url, 22, 43));
    $this->assertDatabaseHas('audit_logs', ['action' => 'security_devices.web_page.opened']);
});

it('serves nothing for an unknown link, and says plainly when the hub didn’t answer', function (): void {
    $this->get('/security-devices/web/'.str_repeat('A', 43).'/')->assertNotFound()->assertHeader('Content-Security-Policy', "sandbox; frame-ancestors 'self'");

    $url = $this->actingAs($this->admin)->postJson("/security-devices/devices/{$this->printer->id}/web")->json('url');
    // No sign-in needed for the frame's own requests: the link is the key.
    auth()->logout();
    $this->get($url.'status.html')->assertStatus(504)->assertSee('The device didn’t answer through the hub in time.', false);
});

it('gives the hub each request with its session details signed, and keeps the answer only until served', function (): void {
    $url = $this->actingAs($this->admin)->postJson("/security-devices/devices/{$this->printer->id}/web")->json('url');
    $token = substr($url, strlen('/security-devices/web/'), 43);
    $session = WebSession::query()->sole();
    $request = WebRequest::query()->create([
        'uuid' => (string) Str::uuid(), 'session_id' => $session->id, 'method' => 'GET', 'path' => '/status.html',
        'headers' => ['accept' => 'text/html'], 'state' => 'queued',
    ]);

    $work = app(WebPages::class)->hubWork($this->hub->fresh(), []);
    $sent = $work['requests'][0];
    expect($sent['uuid'])->toBe($request->uuid)
        ->and($sent['details']['address'])->toBe('10.66.1.40')
        ->and($sent['details']['scheme'])->toBe('http')
        ->and(sodium_crypto_sign_verify_detached(base64_decode($sent['details']['signature']), WebPages::canonical($sent['details']), sodium_crypto_sign_publickey($this->configPair)))->toBeTrue()
        ->and($request->fresh()->state)->toBe('sent');

    app(WebPages::class)->hubWork($this->hub->fresh(), ['responses' => [[
        'uuid' => $request->uuid, 'status' => 200,
        'headers' => ['content-type' => 'text/html', 'set-cookie' => 'SID=1', 'location' => 'http://10.66.1.40/next'],
        'body_base64' => base64_encode('<a href="/settings">Settings</a><img src="http://10.66.1.40/logo.png">'),
    ]]]);
    $done = $request->fresh();
    expect($done->state)->toBe('done')
        ->and($done->response_headers)->toBe(['content-type' => 'text/html', 'location' => 'http://10.66.1.40/next']);

    $answer = app(WebPages::class)->answer($session, $token, $done);
    expect($answer['headers']['Content-Security-Policy'])->toContain('sandbox allow-scripts allow-forms')
        ->and($answer['headers']['location'])->toBe('/security-devices/web/'.$token.'/next')
        ->and($answer['body'])->toContain('href="/security-devices/web/'.$token.'/settings"')
        ->and($answer['body'])->toContain('src="/security-devices/web/'.$token.'/logo.png"')
        ->and($request->fresh()->response_body_base64)->toBeNull();
});

it('closes after 15 minutes without use', function (): void {
    $url = $this->actingAs($this->admin)->postJson("/security-devices/devices/{$this->printer->id}/web")->json('url');
    WebSession::query()->update(['last_used_at' => now()->subMinutes(16)]);

    $this->get($url)->assertStatus(410);
    expect(WebSession::query()->sole()->close_reason)->toBe('idle');
});
