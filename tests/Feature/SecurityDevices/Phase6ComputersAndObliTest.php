<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Drivers\Endpoints\MicrosoftIntuneDriver;
use App\Domain\SecurityDevices\Drivers\Endpoints\WindowsEndpointObliDriver;
use App\Domain\SecurityDevices\Drivers\Services\DeviceDriverRegistry;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Endpoints\Models\IntuneDeviceLink;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointAgentIdentity;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointCommandSigner;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointRemoteSupportSettings;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptPolicy;
use App\Domain\SecurityDevices\Endpoints\Services\StunRelayProbe;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSecretReference;
use App\Models\Integration\IntegrationSyncLog;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class Phase6ComputersAndObliTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Notification::fake();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        config()->set('endpoint_management.enabled', false);
        config()->set('endpoint_management.obli.enabled', false);
        config()->set('endpoint_management.obli.technician_enabled', false);
        config()->set('endpoint_management.remote_support.enabled', false);
        config()->set('endpoint_management.remote_support.relay_urls', []);
        config()->set('endpoint_management.remote_support.relay_secret', null);
        config()->set('endpoint_management.command_signing_secret', null);
        config()->set('endpoint_management.server_url', 'https://localhost');
        config()->set('endpoint_management.intune.enabled', false);

        URL::forceScheme('https');
        $this->withServerVariables(['HTTPS' => 'on']);

        $this->site = Site::factory()->create([
            'name' => 'Kauri Care House',
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);

        $this->admin = User::factory()->create([
            'approved_at' => now(),
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        ensureCanonicalHrStaffProfile($this->admin, $this->site);
    }

    public function test_admin_can_enable_remote_support_and_verify_turn_relay_and_issue_computer_bootstrap_and_session_relay_without_env_override(): void
    {
        $settings = app(EndpointRemoteSupportSettings::class);
        $this->assertFalse($settings->endpointEnabled());
        $this->assertFalse($settings->sessionBrokerEnabled());
        $this->assertFalse($settings->isAvailableFor($this->admin));

        // Phase A walk step 8: with no relay set up, "Test relay" says so and never "Verified".
        $this->actingAs($this->admin)
            ->postJson('/settings/obli/remote-support/verify-relay')
            ->assertOk()
            ->assertJsonPath('verification.ok', false)
            ->assertJsonPath('verification.status', 'not_configured')
            ->assertJsonPath('verification.message', 'No relay set up. Save a relay address and its shared secret, then test again.');

        $saveResponse = $this->actingAs($this->admin)
            ->putJson('/settings/obli/remote-support', [
                'expected_actor_id' => $this->admin->id,
                'expected_version' => 0,
                'endpoint_management_enabled' => true,
                'obli_helper_enabled' => true,
                'obli_technician_enabled' => true,
                'remote_support_enabled' => true,
                'relay_urls' => ['turns:turn.oblivionfindings.test:5349?transport=tcp'],
                'relay_secret' => null,
                'generate_relay_secret' => true,
                'clear_relay_secret' => false,
            ])
            ->assertOk()
            ->assertJsonPath('remote_support.version', 1)
            ->assertJsonPath('remote_support.effective_endpoint_enabled', true)
            ->assertJsonPath('remote_support.effective_obli_helper_enabled', true)
            ->assertJsonPath('remote_support.effective_remote_support_enabled', true)
            ->assertJsonPath('remote_support.relay_ready', true)
            ->assertJsonPath('remote_support.command_signing_ready', true)
            ->assertJsonPath('remote_support.script_execution_enabled', false);

        $generatedSecret = (string) $saveResponse->json('generated_relay_secret');
        $this->assertSame(64, strlen($generatedSecret));

        // Phase A, item A2: "Test relay" contacts each relay (here a stand-in that answers) and never
        // claims more than "answered". tests/Unit/SecurityDevices/StunRelayProbeTest.php covers the probe.
        $this->app->instance(StunRelayProbe::class, new class extends StunRelayProbe
        {
            public function probe(string $relayUrl, float $timeoutSeconds = 3.0): array
            {
                return ['answered' => true, 'milliseconds' => 12, 'problem' => null];
            }
        });

        $this->actingAs($this->admin)
            ->postJson('/settings/obli/remote-support/verify-relay')
            ->assertOk()
            ->assertJsonPath('verification.ok', true)
            ->assertJsonPath('verification.status', 'relay_answered')
            ->assertJsonPath('verification.relays_answered', 1)
            ->assertJsonPath('verification.turn_rest_hmac_ready', true)
            ->assertJsonPath('verification.command_signing_ready', true)
            ->assertJsonPath('verification.https_origin_ready', true)
            ->assertJsonPath('verification.message', fn (string $message): bool => str_contains($message, 'The relay answered: turns:turn.oblivionfindings.test:5349?transport=tcp (12 ms)')
                && ! str_contains($message, 'Verified'));

        $this->app->instance(StunRelayProbe::class, new class extends StunRelayProbe
        {
            public function probe(string $relayUrl, float $timeoutSeconds = 3.0): array
            {
                return ['answered' => false, 'milliseconds' => null, 'problem' => 'no answer'];
            }
        });

        $this->actingAs($this->admin)
            ->postJson('/settings/obli/remote-support/verify-relay')
            ->assertOk()
            ->assertJsonPath('verification.ok', false)
            ->assertJsonPath('verification.status', 'unreachable')
            ->assertJsonPath('verification.message', 'No answer from turns:turn.oblivionfindings.test:5349?transport=tcp (no answer).');

        $computer = Device::factory()->create([
            'name' => 'KAURI-RECEPTION-PC',
            'domain' => 'it_infrastructure',
            'category' => 'endpoint',
            'provider' => 'oblivion_native',
            'status' => 'active',
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $computer->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $this->site->id,
            'custody_site_id' => $this->site->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now()->subMinute(),
        ]);

        // Verify 6.2 bootstrap profile endpoint works purely from saved DB settings.
        $options = $this->actingAs($this->admin)
            ->getJson("/security-devices/devices/{$computer->id}/endpoint/bootstrap-options")
            ->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('server_url', 'https://localhost')
            ->json();

        $bootstrap = $this->actingAs($this->admin)
            ->postJson("/security-devices/devices/{$computer->id}/endpoint/bootstrap", [
                'expected_context' => $options['context_hash'],
            ])
            ->assertCreated()
            ->assertJsonPath('platform', 'windows')
            ->assertJsonPath('device_label', 'KAURI-RECEPTION-PC')
            ->json();

        $credential = bin2hex(random_bytes(32));
        app(EndpointAgentIdentity::class)->enrol($bootstrap['enrolment_token'], $credential, 'windows');
        $agent = EndpointAgent::query()->where('device_id', $computer->id)->firstOrFail();
        $agent->forceFill(['last_seen_at' => now(), 'observed_at' => now()])->save();

        // Enable attended remote support policy for this computer and start a session.
        $this->actingAs($this->admin)
            ->putJson("/it/remote-support/devices/{$computer->id}/policy", [
                'expected_actor_id' => $this->admin->id,
                'expected_version' => 0,
                'enabled' => true,
                'consent_mode' => 'attended',
                'allow_control' => true,
                'max_minutes' => 30,
                'reason' => 'Help reception staff with printer setup',
            ])
            ->assertOk();

        $session = $this->actingAs($this->admin)
            ->postJson("/it/remote-support/devices/{$computer->id}/sessions", [
                'request_uuid' => (string) Str::uuid(),
                'expected_actor_id' => $this->admin->id,
                'expected_policy_version' => 1,
                'access_mode' => 'view',
                'purpose' => 'Check printer queue on reception computer',
            ])
            ->assertCreated()
            ->json();

        $sessionUuid = $session['uuid'];
        $desktopSession = (string) Str::uuid();

        // Windows PC Obli tray fetches signed offer and clicks Allow.
        $offerEnvelope = $this->withToken($credential)
            ->getJson('/api/endpoints/agent/remote/offer')
            ->assertOk()
            ->json('offer');
        $offerBytes = base64_decode($offerEnvelope['payload'], true);
        $this->assertTrue(sodium_crypto_sign_verify_detached(
            base64_decode($offerEnvelope['signature'], true),
            $offerBytes,
            base64_decode(app(EndpointCommandSigner::class)->publicKey(), true),
        ));
        $offerPayload = json_decode($offerBytes, true, 512, JSON_THROW_ON_ERROR);

        $this->withToken($credential)
            ->postJson("/api/endpoints/agent/remote/{$sessionUuid}/decision", [
                'decision' => 'allow',
                'challenge' => $offerPayload['challenge'],
                'desktop_session' => $desktopSession,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'allowed');

        // Technician fetches wrapped relay grant `{ status: 'allowed', relay: { ... } }`.
        $viewerRelayResponse = $this->withoutToken()
            ->actingAs($this->admin)
            ->getJson("/it/remote-support/sessions/{$sessionUuid}/relay")
            ->assertOk()
            ->assertJsonPath('status', 'allowed')
            ->assertJsonPath('relay.role', 'technician')
            ->assertJsonPath('relay.urls.0', 'turns:turn.oblivionfindings.test:5349?transport=tcp');

        $this->assertStringNotContainsString($generatedSecret, $viewerRelayResponse->getContent());

        // Windows PC Obli tray clicks Stop and immediately revokes relay grants.
        $this->withToken($credential)
            ->postJson("/api/endpoints/agent/remote/{$sessionUuid}/stop")
            ->assertOk()
            ->assertJsonPath('status', 'stopped');

        $this->withoutToken()
            ->actingAs($this->admin)
            ->getJson("/it/remote-support/sessions/{$sessionUuid}/relay")
            ->assertOk()
            ->assertJsonPath('relay', null);
    }

    public function test_intune_setup_discovers_windows_computers_imports_idempotently_to_site_and_syncs_posture(): void
    {
        $directoryId = '11111111-1111-4111-8111-111111111111';
        $clientId = '22222222-2222-4222-8222-222222222222';
        $clientSecret = 'intune-super-secret-value-9942';
        $pcUuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $serverUuid = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $iosUuid = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

        Http::fake(function (HttpClientRequest $request) use ($clientId, $clientSecret, $directoryId, $iosUuid, $pcUuid, $serverUuid) {
            if ($request->url() === "https://login.microsoftonline.com/{$directoryId}/oauth2/v2.0/token") {
                $this->assertSame($clientId, $request['client_id']);
                $this->assertSame($clientSecret, $request['client_secret']);

                return Http::response(['access_token' => 'graph-read-only-token'], 200);
            }

            if (str_starts_with($request->url(), 'https://graph.microsoft.com/v1.0/deviceManagement/managedDevices')) {
                return Http::response([
                    'value' => [
                        [
                            'id' => strtoupper($pcUuid),
                            'deviceName' => 'KAURI-OFFICE-01',
                            'managedDeviceOwnerType' => 'company',
                            'enrolledDateTime' => '2026-09-01T00:00:00Z',
                            'lastSyncDateTime' => '2026-10-29T01:00:00Z',
                            'operatingSystem' => 'Windows',
                            'osVersion' => '10.0.22631.4317',
                            'complianceState' => 'compliant',
                            'managementAgent' => 'mdm',
                            'manufacturer' => 'Dell Inc.',
                            'model' => 'OptiPlex 7010',
                            'serialNumber' => 'DL7010NZ',
                            'isEncrypted' => true,
                        ],
                        [
                            'id' => $serverUuid,
                            'deviceName' => 'KAURI-FILE-SRV',
                            'managedDeviceOwnerType' => 'company',
                            'enrolledDateTime' => '2026-08-15T00:00:00Z',
                            'lastSyncDateTime' => '2026-10-29T01:05:00Z',
                            'operatingSystem' => 'Windows Server',
                            'osVersion' => '10.0.20348.2762',
                            'complianceState' => 'noncompliant',
                            'managementAgent' => 'mdm',
                            'manufacturer' => 'Hewlett Packard Enterprise',
                            'model' => 'ProLiant DL360',
                            'serialNumber' => 'HPEDL360NZ',
                            'isEncrypted' => false,
                        ],
                        [
                            'id' => $iosUuid,
                            'deviceName' => 'On-call iPhone',
                            'managedDeviceOwnerType' => 'company',
                            'enrolledDateTime' => '2026-09-10T00:00:00Z',
                            'lastSyncDateTime' => '2026-10-29T01:05:00Z',
                            'operatingSystem' => 'iOS',
                            'osVersion' => '18.1',
                            'complianceState' => 'compliant',
                            'managementAgent' => 'mdm',
                            'manufacturer' => 'Apple',
                            'model' => 'iPhone 15',
                            'serialNumber' => 'APL15NZ',
                            'isEncrypted' => true,
                        ],
                    ],
                ], 200);
            }

            return Http::response([], 404);
        });

        // 1. Save Microsoft Entra read-only credentials via secret manager boundary.
        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/intune/key', [
                'directory_id' => strtoupper($directoryId),
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ])
            ->assertRedirect();

        $connection = IntegrationProviderConnection::query()->forProvider('intune')->sole();
        $this->assertNull($connection->secret_encrypted);
        $this->assertSame('9942', $connection->secret_last4);
        $this->assertTrue(
            IntegrationSecretReference::query()
                ->where('provider_connection_id', $connection->id)
                ->exists(),
        );

        // 2. Test connection against Microsoft Entra + Graph.
        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/intune/test')
            ->assertRedirect();
        $this->assertSame(IntegrationProviderConnection::STATUS_CONNECTED, $connection->fresh()->status);

        // 3. Discover Windows computers (filtering out non-Windows iOS phone).
        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/intune/discover')
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->get('/security-devices/integrations/intune')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('security-devices/integrations/intune')
                ->where('providerConnection.status', 'connected')
                ->has('discoveredDevices', 2)
                ->where('discoveredDevices.0.device_name', 'KAURI-FILE-SRV')
                ->where('discoveredDevices.1.device_name', 'KAURI-OFFICE-01')
                ->where('linkedDevicesCount', 0));

        // 4. Bulk import both Windows computers into Kauri Care House.
        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/intune/import', [
                'site_id' => $this->site->id,
                'managed_device_ids' => [$pcUuid, $serverUuid],
            ])
            ->assertRedirect();

        $this->assertSame(2, Device::query()->where('provider', 'intune')->count());
        $importedPc = Device::query()->where('name', 'KAURI-OFFICE-01')->sole();
        $importedServer = Device::query()->where('name', 'KAURI-FILE-SRV')->sole();

        $this->assertSame('endpoint', $importedPc->category);
        $this->assertSame('healthy', $importedPc->health_status->value);
        $this->assertSame('server', $importedServer->category);
        $this->assertSame('warning', $importedServer->health_status->value);

        $this->assertTrue(
            DeviceAssignment::query()
                ->where('device_id', $importedPc->id)
                ->where('assignable_type', DeviceAssignment::TARGET_SITE)
                ->where('assignable_id', $this->site->id)
                ->whereNull('released_at')
                ->exists(),
        );

        $this->assertSame(2, IntuneDeviceLink::query()->count());

        // 5. Re-running import for the same UUIDs is idempotent (0 duplicates created, 2 updated).
        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/intune/import', [
                'site_id' => $this->site->id,
                'managed_device_ids' => [$pcUuid, $serverUuid],
            ])
            ->assertRedirect();

        $this->assertSame(2, Device::query()->where('provider', 'intune')->count());
        $latestImportLog = IntegrationSyncLog::query()
            ->where('provider', 'intune')
            ->where('action', 'import_devices')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(0, $latestImportLog->items_created);
        $this->assertSame(2, $latestImportLog->items_updated);

        // 6. Refresh linked computers via IntuneVisibilitySync using DB credentials.
        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/intune/sync')
            ->assertRedirect();

        $this->assertSame(
            'current',
            IntuneDeviceLink::query()->where('device_id', $importedPc->id)->value('sync_status'),
        );
    }

    public function test_supported_devices_includes_computer_drivers_and_arbitrary_script_execution_remains_off(): void
    {
        $registry = app(DeviceDriverRegistry::class);
        $obliDriver = $registry->find(WindowsEndpointObliDriver::KEY);
        $intuneDriver = $registry->find(MicrosoftIntuneDriver::KEY);

        $this->assertInstanceOf(WindowsEndpointObliDriver::class, $obliDriver);
        $this->assertInstanceOf(MicrosoftIntuneDriver::class, $intuneDriver);

        $nativePc = new Device([
            'domain' => 'it_infrastructure',
            'category' => 'endpoint',
            'provider' => 'oblivion_native',
        ]);
        $intunePc = new Device([
            'domain' => 'it_infrastructure',
            'category' => 'endpoint',
            'provider' => 'intune',
        ]);

        $this->assertTrue($obliDriver->matchesDevice($nativePc));
        $this->assertFalse($obliDriver->matchesDevice($intunePc));
        $this->assertTrue($intuneDriver->matchesDevice($intunePc));
        $this->assertFalse($intuneDriver->matchesDevice($nativePc));

        $this->actingAs($this->admin)
            ->get('/security-devices/supported-devices')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('security-devices/supported-devices')
                ->where('classes', fn ($classes): bool => collect($classes)->contains(
                    static fn (array $item): bool => $item['key'] === 'computer'
                        && $item['label'] === 'Computers & endpoints'
                        && $item['count'] === 2,
                )));

        // Phase 6.4 invariant: arbitrary script execution stays OFF.
        $this->assertFalse((bool) config('endpoint_management.scripts.release_enabled'));
        $this->assertFalse(app(EndpointScriptPolicy::class)->available());
    }
}
