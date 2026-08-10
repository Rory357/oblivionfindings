<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerLeaseIssuer;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerSecretStore;
use App\Domain\SecurityDevices\Credentials\Data\SecretLeaseRequest;
use App\Domain\SecurityDevices\Credentials\Services\HashicorpVaultLeaseIssuer;
use App\Domain\SecurityDevices\Presenters\IntegrationSiteCredentialsPresenter;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSecretReference;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Integration\IntegrationSecretManager;
use App\Services\Integration\IntegrationSecretMaterialService;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\FakeIntegrationSecretBackend;
use Tests\TestCase;

class IntegrationProviderSecretCutoverTest extends TestCase
{
    use RefreshDatabase;

    private FakeIntegrationSecretBackend $backend;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $this->backend = new FakeIntegrationSecretBackend;
        $this->app->instance(SecretManagerSecretStore::class, $this->backend);
        $this->app->instance(SecretManagerLeaseIssuer::class, $this->backend);
    }

    public function test_legacy_read_backfill_versioned_use_rollback_restore_and_finalize_are_safe(): void
    {
        $legacy = 'LEGACY-UNIFI-PROVIDER-SECRET';
        $connection = IntegrationProviderConnection::query()->create([
            'provider' => 'unifi',
            'secret_encrypted' => Crypt::encryptString($legacy),
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        ]);
        $material = app(IntegrationSecretMaterialService::class);
        $manager = app(IntegrationSecretManager::class);

        $this->assertSame($legacy, $material->application(
            $connection,
            IntegrationSecretManager::PURPOSE_PRIMARY,
            'api_key',
        ));
        $this->assertSame(1, $manager->backfillApplication($connection));
        $reference = $connection->secretReferences()->sole();
        $this->assertSame(IntegrationSecretReference::STATUS_ACTIVE, $reference->status);
        $this->assertNotNull($connection->fresh()->secret_encrypted);
        $this->assertSame($legacy, $material->application(
            $connection->fresh(),
            IntegrationSecretManager::PURPOSE_PRIMARY,
            'api_key',
        ));
        $this->assertSame($this->backend->issues, $this->backend->revocations);

        $this->backend->available = false;
        try {
            $material->application(
                $connection->fresh(),
                IntegrationSecretManager::PURPOSE_PRIMARY,
                'api_key',
            );
            $this->fail('An active cutover must not fall back to the legacy secret.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }
        $this->backend->available = true;
        $manager->rollbackApplication($connection->fresh(), IntegrationSecretManager::PURPOSE_PRIMARY);
        $this->assertSame($legacy, $material->application(
            $connection->fresh(),
            IntegrationSecretManager::PURPOSE_PRIMARY,
            'api_key',
        ));
        $manager->restoreApplication($connection->fresh(), IntegrationSecretManager::PURPOSE_PRIMARY);
        $manager->finalizeApplication($connection->fresh());
        $this->assertNull($connection->fresh()->secret_encrypted);
        $this->assertSame($legacy, $material->application(
            $connection->fresh(),
            IntegrationSecretManager::PURPOSE_PRIMARY,
            'api_key',
        ));
        $this->assertSame($this->backend->issues, $this->backend->revocations);
    }

    public function test_migration_rollback_refuses_to_discard_finalized_provider_and_site_references(): void
    {
        $connection = IntegrationProviderConnection::query()->create([
            'provider' => 'unifi',
            'secret_encrypted' => Crypt::encryptString('LEGACY-APPLICATION-SECRET'),
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        ]);
        $site = Site::factory()->create();
        $siteSecret = IntegrationSiteSecret::query()->create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'capability' => 'access_api',
            'base_url' => 'https://access.example.test',
            'secret_encrypted' => Crypt::encryptString('LEGACY-SITE-SECRET'),
            'is_enabled' => true,
        ]);
        $manager = app(IntegrationSecretManager::class);
        $manager->storeApplication(
            $connection,
            IntegrationSecretManager::PURPOSE_PRIMARY,
            ['api_key' => 'VAULT-APPLICATION-SECRET'],
        );
        $manager->storeSite($siteSecret, ['api_key' => 'VAULT-SITE-SECRET']);
        $manager->finalizeApplication($connection->fresh());
        $manager->finalizeSite($siteSecret->fresh());
        $referenceIds = IntegrationSecretReference::query()->orderBy('id')->pluck('id')->all();

        $migration = require database_path('migrations/2026_08_06_000035_create_integration_secret_references.php');

        try {
            $migration->down();
            $this->fail('Rollback must refuse to discard finalized provider secret references.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Provider secret references cannot be rolled back after legacy credentials have been finalized. Restore every legacy credential before retrying.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue(Schema::hasTable('integration_secret_references'));
        $this->assertNull($connection->fresh()->secret_encrypted);
        $this->assertNull($siteSecret->fresh()->secret_encrypted);
        $this->assertSame(
            $referenceIds,
            IntegrationSecretReference::query()->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame('VAULT-APPLICATION-SECRET', app(IntegrationSecretMaterialService::class)->application(
            $connection->fresh(),
            IntegrationSecretManager::PURPOSE_PRIMARY,
            'api_key',
        ));
        $this->assertSame(
            'VAULT-SITE-SECRET',
            app(IntegrationSecretMaterialService::class)->site($siteSecret->fresh(), 'api_key'),
        );
    }

    public function test_local_rollback_remains_authoritative_while_vault_is_unavailable(): void
    {
        $legacy = 'ROLLBACK-LEGACY-SECRET';
        $connection = IntegrationProviderConnection::query()->create([
            'provider' => 'unifi',
            'secret_encrypted' => Crypt::encryptString($legacy),
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        ]);
        $manager = app(IntegrationSecretManager::class);
        $manager->backfillApplication($connection);

        $this->backend->available = false;
        $reference = $manager->rollbackApplication(
            $connection->fresh(),
            IntegrationSecretManager::PURPOSE_PRIMARY,
        );

        $this->assertSame(IntegrationSecretReference::STATUS_ROLLED_BACK, $reference->status);
        $this->assertSame($legacy, app(IntegrationSecretMaterialService::class)->application(
            $connection->fresh(),
            IntegrationSecretManager::PURPOSE_PRIMARY,
            'api_key',
        ));
        try {
            $manager->restoreApplication($connection->fresh(), IntegrationSecretManager::PURPOSE_PRIMARY);
            $this->fail('An unverified Vault restore must not reactivate the cutover.');
        } catch (RuntimeException) {
            $this->assertSame(
                IntegrationSecretReference::STATUS_ROLLED_BACK,
                $reference->fresh()->status,
            );
        }
    }

    public function test_application_secret_leases_pin_the_verified_vault_version_and_are_one_use(): void
    {
        config()->set('monitoring.credentials.vault.url', 'https://vault.provider.test');
        config()->set('monitoring.credentials.vault.token', 'vault-provider-bootstrap-token');
        config()->set('monitoring.credentials.vault.namespace', 'oblivion');
        Http::fake([
            'https://vault.provider.test/v1/secret/data/oblivion/provider-integrations/unifi/api-key?version=7' => Http::response([
                'data' => ['data' => ['api_key' => 'VERSION-PINNED-SECRET']],
            ]),
        ]);
        $request = new SecretLeaseRequest(
            referenceUuid: '019fd298-cc81-7227-b906-c146de5a2ac3',
            siteId: null,
            provider: 'unifi',
            purpose: IntegrationSecretManager::PURPOSE_PRIMARY,
            capabilities: ['provider:primary'],
            externalReference: 'secret/data/oblivion/provider-integrations/unifi/api-key',
            expiresAt: CarbonImmutable::now('UTC')->addMinute(),
            secretVersion: 7,
        );

        $lease = (new HashicorpVaultLeaseIssuer)->issue($request);

        $this->assertSame('VERSION-PINNED-SECRET', $lease->material()['api_key']);
        try {
            $lease->material();
            $this->fail('Provider secret lease material must be one use.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }
        $serializedRequest = json_encode($request, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('"secret_version":7', $serializedRequest);
        $this->assertStringNotContainsString('secret/data/oblivion', $serializedRequest);
        Http::assertSent(fn (HttpRequest $sent): bool => $sent->url()
            === 'https://vault.provider.test/v1/secret/data/oblivion/provider-integrations/unifi/api-key?version=7');
    }

    public function test_failed_activation_leaves_no_local_reference_and_a_retry_supersedes_the_orphaned_version(): void
    {
        $connection = IntegrationProviderConnection::query()->create([
            'provider' => 'unifi',
            'status' => IntegrationProviderConnection::STATUS_DISCONNECTED,
        ]);
        $manager = app(IntegrationSecretManager::class);
        $this->backend->issueFailuresRemaining = 1;
        $this->backend->softDeleteFailuresRemaining = 1;

        try {
            $manager->storeApplication(
                $connection,
                IntegrationSecretManager::PURPOSE_PRIMARY,
                ['api_key' => 'FAILED-ACTIVATION-SECRET'],
            );
            $this->fail('An unverified secret version must not be activated.');
        } catch (RuntimeException) {
            $this->assertSame(0, $connection->secretReferences()->count());
        }

        $reference = $manager->storeApplication(
            $connection,
            IntegrationSecretManager::PURPOSE_PRIMARY,
            ['api_key' => 'RECOVERED-ACTIVATION-SECRET'],
        );

        $this->assertSame(2, $reference->secret_manager_version);
        $this->assertSame('RECOVERED-ACTIVATION-SECRET', app(IntegrationSecretMaterialService::class)->application(
            $connection->fresh(),
            IntegrationSecretManager::PURPOSE_PRIMARY,
            'api_key',
        ));
    }

    public function test_controllers_store_only_secret_references_and_value_free_metadata(): void
    {
        $manager = $this->manager();
        $milesightSecret = 'MILESIGHT-CUTOVER-SECRET-9876';
        $webhookSecret = 'MILESIGHT-WEBHOOK-CUTOVER-9876';
        $unifiSecret = 'UNIFI-CUTOVER-SECRET-4321';

        $this->actingAs($manager)->post('/security-devices/integrations/milesight/key', [
            'client_id' => 'client-cutover',
            'client_secret' => $milesightSecret,
            'base_url' => 'https://milesight.example.test',
        ])->assertRedirect()->assertSessionHas('success');
        $this->actingAs($manager)->post('/security-devices/integrations/milesight/webhook', [
            'webhook_secret' => $webhookSecret,
        ])->assertRedirect()->assertSessionHas('success');
        $this->actingAs($manager)->post('/security-devices/integrations/unifi/key', [
            'api_key' => $unifiSecret,
        ])->assertRedirect()->assertSessionHas('success');

        $milesight = IntegrationProviderConnection::query()->where('provider', 'milesight')->sole();
        $unifi = IntegrationProviderConnection::query()->where('provider', 'unifi')->sole();
        $this->assertNull($milesight->secret_encrypted);
        $this->assertNull($unifi->secret_encrypted);
        $this->assertArrayNotHasKey('webhook_secret_encrypted', $milesight->config);
        $this->assertSame('9876', $milesight->secret_last4);
        $this->assertSame('4321', $unifi->secret_last4);
        $this->assertSame(3, IntegrationSecretReference::query()->active()->count());

        $database = json_encode([
            DB::table((new IntegrationProviderConnection)->getTable())->get()->all(),
            DB::table('integration_secret_references')->get()->all(),
        ], JSON_THROW_ON_ERROR);
        $projection = json_encode([
            $milesight->fresh()->load('secretReferences')->toArray(),
            $unifi->fresh()->load('secretReferences')->toArray(),
        ], JSON_THROW_ON_ERROR);
        foreach ([$milesightSecret, $webhookSecret, $unifiSecret, 'secret/data/oblivion/provider-integrations'] as $value) {
            $this->assertStringNotContainsString($value, $database);
            $this->assertStringNotContainsString($value, $projection);
        }
    }

    public function test_site_projection_is_value_free_and_vault_unavailability_never_falls_back_after_cutover(): void
    {
        $site = Site::factory()->create();
        $siteSecret = IntegrationSiteSecret::query()->create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'capability' => 'access_api',
            'base_url' => 'https://access.example.test',
            'secret_encrypted' => Crypt::encryptString('LEGACY-SITE-SECRET'),
            'is_enabled' => true,
        ]);
        app(IntegrationSecretManager::class)->storeSite($siteSecret, ['api_key' => 'VAULT-SITE-SECRET']);

        $projection = app(IntegrationSiteCredentialsPresenter::class)->project($siteSecret->fresh());
        $encoded = json_encode($projection, JSON_THROW_ON_ERROR);
        $this->assertTrue($projection['configured']);
        $this->assertStringNotContainsString('LEGACY-SITE-SECRET', $encoded);
        $this->assertStringNotContainsString('VAULT-SITE-SECRET', $encoded);
        $this->assertArrayNotHasKey('secret_manager_reference', $projection);

        $this->backend->available = false;
        $this->expectException(RuntimeException::class);
        app(IntegrationSecretMaterialService::class)->site($siteSecret->fresh(), 'api_key');
    }

    public function test_controller_fails_closed_when_the_secret_manager_is_unavailable(): void
    {
        $sentinel = 'MILESIGHT-UNAVAILABLE-SECRET';
        $this->backend->available = false;

        $this->actingAs($this->manager())
            ->post('/security-devices/integrations/milesight/key', [
                'client_id' => 'unavailable-client',
                'client_secret' => $sentinel,
            ])
            ->assertRedirect()
            ->assertSessionHas('error')
            ->assertSessionMissing('_old_input.client_secret');

        $this->assertDatabaseMissing((new IntegrationProviderConnection)->getTable(), ['provider' => 'milesight']);
        $this->assertDatabaseCount('integration_secret_references', 0);
        $this->assertStringNotContainsString($sentinel, json_encode(session()->all(), JSON_THROW_ON_ERROR));
    }

    public function test_failed_vault_writes_preserve_existing_application_and_site_connection_state(): void
    {
        $manager = $this->manager();
        $site = Site::factory()->create();
        $unifi = IntegrationProviderConnection::query()->create([
            'provider' => 'unifi',
            'secret_encrypted' => Crypt::encryptString('EXISTING-UNIFI-SECRET'),
            'secret_last4' => 'fifi',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
            'last_error' => 'preserved unifi diagnostic',
            'config' => ['preserved' => 'unifi'],
            'rotated_at' => now()->subDays(3),
            'created_by' => $manager->id,
        ]);
        $milesight = IntegrationProviderConnection::query()->create([
            'provider' => 'milesight',
            'secret_encrypted' => Crypt::encryptString('EXISTING-MILESIGHT-SECRET'),
            'secret_last4' => 'ight',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
            'last_error' => 'preserved milesight diagnostic',
            'config' => [
                'client_id' => 'preserved-client',
                'base_url' => 'https://preserved.milesight.test',
                'preserved' => 'milesight',
            ],
            'rotated_at' => now()->subDays(2),
            'created_by' => $manager->id,
        ]);
        $siteSecret = IntegrationSiteSecret::query()->create([
            'site_id' => $site->id,
            'provider' => 'unifi',
            'capability' => 'access_api',
            'base_url' => 'https://preserved-site.test',
            'secret_encrypted' => Crypt::encryptString('EXISTING-SITE-SECRET'),
            'is_enabled' => true,
            'last_tested_at' => now()->subHour(),
            'last_error' => 'preserved Site diagnostic',
        ]);
        $unifiState = $unifi->only([
            'secret_encrypted',
            'secret_last4',
            'status',
            'last_error',
            'config',
            'rotated_at',
            'created_by',
        ]);
        $milesightState = $milesight->only([
            'secret_encrypted',
            'secret_last4',
            'status',
            'last_error',
            'config',
            'rotated_at',
            'created_by',
        ]);
        $siteState = $siteSecret->only([
            'base_url',
            'secret_encrypted',
            'is_enabled',
            'last_tested_at',
            'last_error',
        ]);
        $this->backend->available = false;

        $this->actingAs($manager)->post('/security-devices/integrations/unifi/key', [
            'api_key' => 'REJECTED-UNIFI-SAVE',
        ])->assertRedirect()->assertSessionHas('error');
        $this->actingAs($manager)->post('/security-devices/integrations/unifi/rotate', [
            'api_key' => 'REJECTED-UNIFI-ROTATION',
        ])->assertRedirect()->assertSessionHas('error');
        $this->actingAs($manager)->post('/security-devices/integrations/milesight/key', [
            'client_id' => 'rejected-client',
            'client_secret' => 'REJECTED-MILESIGHT-SAVE',
            'base_url' => 'https://rejected.milesight.test',
        ])->assertRedirect()->assertSessionHas('error');
        $this->actingAs($manager)->post('/security-devices/integrations/milesight/rotate', [
            'client_secret' => 'REJECTED-MILESIGHT-ROTATION',
        ])->assertRedirect()->assertSessionHas('error');
        $this->actingAs($manager)->put(route('sites.integrations.updateSecret', [
            'site' => $site,
            'provider' => 'unifi',
            'capability' => 'access_api',
        ]), [
            'base_url' => 'https://rejected-site.test',
            'secret' => 'REJECTED-SITE-SECRET',
            'is_enabled' => false,
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertEquals($unifiState, $unifi->fresh()->only(array_keys($unifiState)));
        $this->assertEquals($milesightState, $milesight->fresh()->only(array_keys($milesightState)));
        $this->assertEquals($siteState, $siteSecret->fresh()->only(array_keys($siteState)));
    }

    public function test_failed_external_cleanup_survives_owner_deletion_and_is_value_free_and_retryable(): void
    {
        $manager = $this->manager();
        $connection = IntegrationProviderConnection::query()->create([
            'provider' => 'milesight',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
            'config' => ['client_id' => 'cleanup-client'],
        ]);
        $secret = 'CLEANUP-POINTER-MUST-NOT-CONTAIN-THIS-SECRET';
        app(IntegrationSecretManager::class)->storeApplication(
            $connection,
            IntegrationSecretManager::PURPOSE_PRIMARY,
            ['client_secret' => $secret],
        );
        $this->backend->softDeleteFailuresRemaining = 1;

        $this->actingAs($manager)
            ->delete('/security-devices/integrations/milesight/key')
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing($connection->getTable(), ['id' => $connection->id]);
        $pointer = IntegrationSecretReference::query()->sole();
        $this->assertNull($pointer->provider_connection_id);
        $this->assertNull($pointer->site_secret_id);
        $this->assertSame(IntegrationSecretReference::STATUS_REVOKED, $pointer->status);
        $this->assertNotNull($pointer->cleanup_pending_at);
        $this->assertNotNull($pointer->cleanup_last_attempt_at);
        $this->assertSame(1, $pointer->cleanup_attempts);
        $rawPointer = json_encode(
            DB::table('integration_secret_references')->where('id', $pointer->id)->first(),
            JSON_THROW_ON_ERROR,
        );
        $projection = json_encode($pointer->toArray(), JSON_THROW_ON_ERROR);
        foreach ([$secret, 'secret/data/oblivion/provider-integrations'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $rawPointer);
            $this->assertStringNotContainsString($sensitive, $projection);
        }

        $migration = require database_path('migrations/2026_08_06_000035_create_integration_secret_references.php');
        try {
            $migration->down();
            $this->fail('Rollback must refuse to discard an external cleanup retry pointer.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Provider secret references cannot be rolled back while external cleanup is pending. Complete the governed cleanup retry before retrying.',
                $exception->getMessage(),
            );
        }
        $this->assertDatabaseHas('integration_secret_references', ['id' => $pointer->id]);

        $result = app(IntegrationSecretManager::class)->retryPendingCleanup();
        $this->assertSame(['processed' => 1, 'cleaned' => 1, 'remaining' => 0], $result);
        $this->assertDatabaseMissing('integration_secret_references', ['id' => $pointer->id]);
    }

    private function manager(): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        return $user;
    }
}
