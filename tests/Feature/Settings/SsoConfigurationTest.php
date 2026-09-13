<?php

namespace Tests\Feature\Settings;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\SsoConfigurationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class SsoConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->firstOrFail());
        config([
            'inertia.ssr.enabled' => false,
            'app.url' => 'https://application.example.test',
            'sso.staff_domain' => 'example.test',
            'services.microsoft' => ['client_id' => null, 'client_secret' => null, 'redirect' => null, 'tenant' => null],
            'services.google' => ['client_id' => null, 'client_secret' => null, 'redirect' => null],
        ]);
        Http::preventStrayRequests();
    }

    public function test_configuration_requires_current_access_permission(): void
    {
        $this->get('/settings/sso')->assertRedirect('/login');
        $this->putJson('/settings/sso/providers/google', $this->payload())->assertUnauthorized();
        $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $worker->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
        $this->actingAs($worker)->get('/settings/sso')->assertForbidden();
        $this->actingAs($worker)->putJson('/settings/sso/providers/google', $this->payload())->assertForbidden();
        $this->actingAs($worker)->getJson('/settings/sso/configuration/google')->assertForbidden();
        $this->actingAs($worker)->postJson('/settings/sso/providers/google/check')->assertForbidden();
        $this->assertDatabaseMissing('app_settings', ['key' => 'settings.sso.google']);
    }

    public function test_login_pages_receive_only_current_audience_availability(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->where('ssoProviders', ['microsoft' => false, 'google' => false]));
        $settings = app(SsoConfigurationService::class);
        $settings->saveProvider($this->admin, 'google', [...$this->payload(), 'portal_enabled' => false]);
        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page->where('ssoProviders', ['microsoft' => false, 'google' => true])->missing('providers'));
        $this->get('/portal/login')->assertOk()->assertInertia(fn ($page) => $page->where('ssoProviders', ['microsoft' => false, 'google' => false])->missing('providers'));
        Http::assertNothingSent();
    }

    public function test_stale_actor_approval_cannot_publish_a_provider_configuration(): void
    {
        User::whereKey($this->admin->id)->update(['approved_at' => null]);
        try {
            app(SsoConfigurationService::class)->saveProvider($this->admin, 'google', $this->payload());
            $this->fail('A revoked account cannot publish SSO configuration.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseMissing('app_settings', ['key' => 'settings.sso.google']);
    }

    public function test_save_persists_encrypted_secret_and_masked_refresh_without_provider_calls(): void
    {
        $this->actingAs($this->admin)->putJson('/settings/sso/providers/google', $this->payload())
            ->assertOk()->assertJsonPath('status', 'saved')->assertJsonPath('configuration.version', 1)
            ->assertJsonPath('configuration.secret_present', true)
            ->assertJsonPath('configuration.consent_status', 'unverified')
            ->assertJsonMissingPath('configuration.client_secret')->assertJsonMissingPath('configuration.secret_ciphertext');
        $stored = AppSetting::where('key', 'settings.sso.google')->firstOrFail();
        $this->assertStringNotContainsString('synthetic-client-secret', $stored->getRawOriginal('value'));
        $this->assertSame('synthetic-client-secret', Crypt::decryptString($stored->value['secret_ciphertext']));
        $this->actingAs($this->admin)->get('/settings/sso')->assertOk()->assertInertia(fn ($page) => $page
            ->component('settings/sso-config')->where('providers.google.client_id', 'synthetic.apps.googleusercontent.com')
            ->where('providers.google.secret_present', true)->missing('providers.google.client_secret')->missing('providers.google.secret_ciphertext'));
        $audit = AuditLog::where('action', 'settings.sso.updated')->firstOrFail();
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame('replace', $audit->meta['secret_action']);
        $this->assertStringNotContainsString('synthetic-client-secret', json_encode($audit->toArray()));
        Http::assertNothingSent();
    }

    public function test_blank_secret_keeps_existing_and_explicit_replacement_is_audited(): void
    {
        $this->actingAs($this->admin)->putJson('/settings/sso/providers/google', $this->payload())->assertOk();
        $this->putJson('/settings/sso/providers/google', $this->payload([
            'expected_version' => 1, 'client_secret' => '', 'secret_action' => 'keep', 'domain' => 'changed.example.test',
        ]))->assertOk()->assertJsonPath('configuration.version', 2);
        $this->assertSame('synthetic-client-secret', app(SsoConfigurationService::class)->provider('google')['client_secret']);
        $this->putJson('/settings/sso/providers/google', $this->payload([
            'expected_version' => 2, 'client_secret' => 'replacement-synthetic-secret',
        ]))->assertOk();
        $this->assertSame('replacement-synthetic-secret', app(SsoConfigurationService::class)->provider('google')['client_secret']);
        $this->assertSame(['replace', 'keep', 'replace'], AuditLog::where('action', 'settings.sso.updated')->orderBy('id')->get()->pluck('meta.secret_action')->all());
    }

    public function test_removal_requires_confirmation_and_disabled_provider_and_never_falls_back_to_deployment_secret(): void
    {
        config(['services.google.client_secret' => 'deployment-synthetic-secret']);
        $this->actingAs($this->admin)->putJson('/settings/sso/providers/google', $this->payload())->assertOk();
        $remove = $this->payload(['expected_version' => 1, 'secret_action' => 'remove', 'client_secret' => '']);
        $this->putJson('/settings/sso/providers/google', $remove)->assertUnprocessable()->assertJsonValidationErrors('confirm_secret_removal');
        $remove['confirm_secret_removal'] = true;
        $this->putJson('/settings/sso/providers/google', $remove)->assertUnprocessable()->assertJsonValidationErrors('secret_action');
        $remove['staff_enabled'] = false;
        $remove['portal_enabled'] = false;
        $this->putJson('/settings/sso/providers/google', $remove)->assertOk()->assertJsonPath('configuration.secret_present', false);
        $this->assertSame('', app(SsoConfigurationService::class)->provider('google')['client_secret']);
        $this->assertArrayNotHasKey('secret_ciphertext', AppSetting::where('key', 'settings.sso.google')->value('value'));
        $this->assertSame('remove', AuditLog::where('action', 'settings.sso.updated')->latest('id')->firstOrFail()->meta['secret_action']);
    }

    public function test_stale_editor_cannot_overwrite_and_can_read_current_masked_configuration(): void
    {
        $this->actingAs($this->admin)->putJson('/settings/sso/providers/google', $this->payload())->assertOk();
        $this->putJson('/settings/sso/providers/google', $this->payload(['domain' => 'stale.example.test']))->assertConflict();
        $this->getJson('/settings/sso/configuration/google')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('configuration.version', 1)
            ->assertJsonPath('configuration.domain', 'example.test')->assertJsonMissingPath('configuration.client_secret');
        $this->assertSame(1, AuditLog::where('action', 'settings.sso.updated')->count());
    }

    public function test_invalid_callback_domain_directory_and_client_pair_are_rejected_without_save(): void
    {
        $this->actingAs($this->admin);
        foreach ([['domain' => '*.example.test'], ['domain' => 'example.test/path'], ['redirect_uri' => 'https://external.example/callback']] as $invalid) {
            $this->putJson('/settings/sso/providers/google', $this->payload($invalid))->assertUnprocessable();
        }
        $this->putJson('/settings/sso/providers/microsoft', $this->payload(['client_id' => '0c99ac6e-f2ba-45eb-b5b2-955cdf300011', 'directory_id' => 'common']))
            ->assertUnprocessable()->assertJsonValidationErrors('directory_id');
        config(['app.url' => 'https://user:password@application.example.test?redirect=external']);
        $this->putJson('/settings/sso/providers/google', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('callback_urls');
        $this->assertDatabaseMissing('app_settings', ['key' => 'settings.sso.google']);
        config(['app.url' => 'https://application.example.test']);
        $this->putJson('/settings/sso/providers/google', $this->payload())->assertOk();
        $this->putJson('/settings/sso/providers/google', $this->payload([
            'expected_version' => 1, 'client_id' => 'different-client', 'secret_action' => 'keep', 'client_secret' => '',
        ]))->assertUnprocessable()->assertJsonValidationErrors('client_secret');
    }

    public function test_validation_never_flashes_client_secret_into_session(): void
    {
        $this->actingAs($this->admin)->from('/settings/sso')->put('/settings/sso/providers/google', $this->payload(['domain' => '*']))
            ->assertRedirect('/settings/sso')->assertSessionHasErrors('domain')->assertSessionMissing('_old_input.client_secret');
    }

    public function test_deployed_configuration_is_identified_without_saving_or_exposing_the_secret(): void
    {
        config(['services.google.client_id' => 'deployment-client', 'services.google.client_secret' => 'deployment-secret']);
        $this->actingAs($this->admin)->getJson('/settings/sso/configuration/google')->assertOk()
            ->assertJsonPath('configuration.source', 'deployment')->assertJsonPath('configuration.version', 0)
            ->assertJsonPath('configuration.client_id', 'deployment-client')->assertJsonPath('configuration.secret_source', 'deployment')
            ->assertJsonPath('configuration.secret_present', true)->assertJsonMissingPath('configuration.client_secret');
        $this->assertDatabaseMissing('app_settings', ['key' => 'settings.sso.google']);
    }

    public function test_local_check_is_configuration_only_and_cannot_claim_consent(): void
    {
        $this->actingAs($this->admin)->postJson('/settings/sso/providers/google/check')->assertOk()
            ->assertJsonPath('configuration_valid', false)->assertJsonPath('consent_status', 'unverified');
        $this->putJson('/settings/sso/providers/google', $this->payload())->assertOk();
        $this->postJson('/settings/sso/providers/google/check')->assertOk()->assertJsonPath('configuration_valid', true)
            ->assertJsonPath('version', 1)->assertJsonPath('sign_in_status', 'unverified');
        Http::assertNothingSent();
    }

    public function test_provisioning_persists_only_supported_controls_and_retains_governed_approval_and_roles(): void
    {
        $payload = ['expected_version' => 0, 'auto_create_staff' => false, 'auto_link_existing' => false, 'portal_auto_create' => false];
        $this->actingAs($this->admin)->putJson('/settings/sso/provisioning', $payload)->assertOk()
            ->assertJsonPath('configuration.auto_create_staff', false)->assertJsonPath('configuration.require_admin_approval', true)
            ->assertJsonPath('configuration.default_role_name', 'support_worker')->assertJsonPath('configuration.portal_role_name', 'next_of_kin');
        $this->getJson('/settings/sso/configuration/provisioning')->assertJsonPath('configuration.version', 1)
            ->assertJsonPath('configuration.portal_auto_create', false);
        $this->putJson('/settings/sso/provisioning', [...$payload, 'expected_version' => 1, 'require_admin_approval' => false, 'default_role_id' => Role::where('name', 'admin')->value('id')])
            ->assertUnprocessable()->assertJsonValidationErrors(['require_admin_approval', 'default_role_id']);
    }

    public function test_audit_failure_rolls_back_configuration_and_secret_atomically(): void
    {
        $fail = true;
        AuditLog::creating(function (AuditLog $audit) use (&$fail): void {
            if ($fail && $audit->action === 'settings.sso.updated') {
                throw new \RuntimeException('Synthetic audit persistence failure.');
            }
        });
        try {
            $this->actingAs($this->admin)->putJson('/settings/sso/providers/google', $this->payload())->assertStatus(500);
        } finally {
            $fail = false;
        }
        $this->assertDatabaseMissing('app_settings', ['key' => 'settings.sso.google']);
        $this->assertSame(0, AuditLog::where('action', 'settings.sso.updated')->count());
    }

    public function test_corrupt_secret_is_not_silently_preserved_or_exposed(): void
    {
        AppSetting::create(['key' => 'settings.sso.google', 'value' => ['version' => 1, 'secret_source' => 'saved', 'secret_ciphertext' => 'broken-ciphertext']]);
        $this->actingAs($this->admin)->getJson('/settings/sso/configuration/google')->assertOk()
            ->assertJsonPath('configuration.secret_readable', false)->assertJsonMissingPath('configuration.secret_ciphertext');
        $this->putJson('/settings/sso/providers/google', $this->payload(['expected_version' => 1, 'secret_action' => 'keep', 'client_secret' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors('client_secret');
        $this->putJson('/settings/sso/providers/google', $this->payload(['expected_version' => 1]))->assertOk();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'expected_version' => 0,
            'client_id' => 'synthetic.apps.googleusercontent.com',
            'domain' => 'example.test',
            'staff_enabled' => true,
            'portal_enabled' => false,
            'secret_action' => 'replace',
            'client_secret' => 'synthetic-client-secret',
        ], $overrides);
    }
}
