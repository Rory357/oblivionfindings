<?php

namespace Tests\Feature\Auth\OAuth;

use App\Http\Controllers\Auth\IdentityDisconnectController;
use App\Models\AppSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\SsoAuthenticationService;
use App\Services\SsoConfigurationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\InvalidStateException;
use Tests\Support\FakesSsoProvider;
use Tests\TestCase;

class SsoAuthenticationBoundaryTest extends TestCase
{
    use FakesSsoProvider;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->configureSsoFixture();
        Http::preventStrayRequests();
    }

    public function test_google_returning_approved_subject_can_sign_in_without_adding_roles(): void
    {
        $user = $this->approved('returning@example.test', 'google', 'stable-google');
        $before = $user->roles()->pluck('roles.id')->all();
        $this->fakeSsoProvider('google', ['id' => 'stable-google', 'email' => $user->email, 'name' => 'Returning']);
        $this->beginSsoFixture('google');
        $this->get('/auth/google/callback')->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertSame($before, $user->fresh()->roles()->pluck('roles.id')->all());
    }

    public function test_matching_email_cannot_take_over_an_approved_account_with_a_new_subject(): void
    {
        $user = $this->approved('approved@example.test', 'google', 'original-subject');
        $this->fakeSsoProvider('google', ['id' => 'different-subject', 'email' => $user->email, 'name' => 'Different']);
        $this->beginSsoFixture('google');
        $this->get('/auth/google/callback')->assertForbidden();
        $this->assertGuest();
        $this->assertDatabaseMissing('identities', ['provider_user_id' => 'different-subject']);
    }

    public function test_reassigned_provider_subject_cannot_be_bound_to_a_different_local_account(): void
    {
        $this->approved('original@example.test', 'microsoft', 'stable-microsoft');
        $this->approved('other@example.test', 'google', 'other-google');
        $this->fakeSsoProvider('microsoft', ['id' => 'stable-microsoft', 'email' => 'other@example.test', 'name' => 'Other']);
        $this->beginSsoFixture('microsoft');
        $this->get('/auth/microsoft/callback')->assertForbidden();
        $this->assertGuest();
    }

    public function test_google_requires_verified_email_and_authoritative_workspace_domain(): void
    {
        $this->fakeSsoProvider('google', ['id' => 'unverified', 'email' => 'person@example.test', 'name' => 'Person'], ['email_verified' => false]);
        $this->beginSsoFixture('google');
        $this->get('/auth/google/callback')->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'person@example.test']);
    }

    public function test_email_suffix_alone_does_not_prove_google_workspace_membership(): void
    {
        $this->fakeSsoProvider('google', ['id' => 'wrong-hd', 'email' => 'person@example.test', 'name' => 'Person'], ['hd' => 'other.example.test']);
        $this->beginSsoFixture('google');
        $this->get('/auth/google/callback')->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'person@example.test']);
    }

    public function test_missing_flow_and_wrong_audience_cannot_invoke_publication(): void
    {
        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('google');
        $this->fakeSsoProvider('google', ['id' => 'portal-flow', 'email' => 'person@example.test', 'name' => 'Person']);
        $this->beginSsoFixture('google', 'portal');
        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('google');
        $this->assertDatabaseMissing('users', ['email' => 'person@example.test']);
    }

    public function test_provider_configuration_change_between_redirect_and_callback_invalidates_flow(): void
    {
        $this->fakeSsoProvider('google', ['id' => 'old-flow', 'email' => 'person@example.test', 'name' => 'Person']);
        $this->beginSsoFixture('google');
        config(['services.google.client_secret' => 'changed-synthetic-secret']);
        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('google');
        $this->assertDatabaseMissing('users', ['email' => 'person@example.test']);
    }

    public function test_flow_expiry_is_not_a_success_and_does_not_create_account(): void
    {
        $this->fakeSsoProvider('google', ['id' => 'expired-flow', 'email' => 'person@example.test', 'name' => 'Person']);
        $this->beginSsoFixture('google');
        $this->travel(11)->minutes();
        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('google');
        $this->assertDatabaseMissing('users', ['email' => 'person@example.test']);
    }

    public function test_saved_disable_is_consumed_by_redirect_without_provider_calls(): void
    {
        AppSetting::create(['key' => 'settings.sso.google', 'value' => ['version' => 1, 'staff_enabled' => false, 'portal_enabled' => false]]);
        $this->get('/auth/google/redirect')->assertForbidden();
        $this->get('/portal/auth/google/redirect')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_provisioning_disables_new_staff_account_creation(): void
    {
        AppSetting::create(['key' => 'settings.sso.provisioning', 'value' => ['version' => 1, 'auto_create_staff' => false]]);
        $this->fakeSsoProvider('google', ['id' => 'new-staff', 'email' => 'new@example.test', 'name' => 'New']);
        $this->beginSsoFixture('google');
        $this->get('/auth/google/callback')->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    }

    public function test_provisioning_disables_new_portal_account_creation(): void
    {
        AppSetting::create(['key' => 'settings.sso.provisioning', 'value' => ['version' => 1, 'portal_auto_create' => false]]);
        $this->fakeSsoProvider('google', ['id' => 'new-family', 'email' => 'new@family.test', 'name' => 'New']);
        $this->beginSsoFixture('google', 'portal');
        $this->get('/portal/auth/google/callback')->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'new@family.test']);
    }

    public function test_disabling_pending_email_linking_preserves_existing_account(): void
    {
        AppSetting::create(['key' => 'settings.sso.provisioning', 'value' => ['version' => 1, 'auto_link_existing' => false]]);
        User::factory()->withoutTwoFactor()->create(['email' => 'pending@example.test', 'approved_at' => null]);
        $this->fakeSsoProvider('google', ['id' => 'new-pending-subject', 'email' => 'pending@example.test', 'name' => 'Pending']);
        $this->beginSsoFixture('google');
        $this->get('/auth/google/callback')->assertForbidden();
        $this->assertDatabaseMissing('identities', ['provider_user_id' => 'new-pending-subject']);
    }

    public function test_staff_callback_cannot_convert_portal_account_into_staff(): void
    {
        $user = $this->approved('family@example.test', 'google', 'family-google', 'next_of_kin');
        $this->fakeSsoProvider('google', ['id' => 'family-google', 'email' => $user->email, 'name' => 'Family']);
        $this->beginSsoFixture('google');
        $this->get('/auth/google/callback')->assertForbidden();
        $this->assertGuest();
        $this->assertSame('next_of_kin', $user->fresh()->role);
    }

    public function test_portal_callback_cannot_admit_staff_identity(): void
    {
        $user = $this->approved('staff@example.test', 'google', 'staff-google');
        $this->fakeSsoProvider('google', ['id' => 'staff-google', 'email' => $user->email, 'name' => 'Staff']);
        $this->beginSsoFixture('google', 'portal');
        $this->get('/portal/auth/google/callback')->assertForbidden();
        $this->assertGuest();
    }

    public function test_account_link_requires_the_same_authenticated_actor_at_callback(): void
    {
        $first = $this->approved('first@example.test', 'microsoft', 'first-ms');
        $second = $this->approved('second@example.test', 'microsoft', 'second-ms');
        $this->fakeSsoProvider('google', ['id' => 'link-google', 'email' => 'first@example.test', 'name' => 'First']);
        $this->actingAs($first);
        $this->beginSsoFixture('google', 'staff', true);
        $this->actingAs($second)->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('google');
        $this->assertDatabaseMissing('identities', ['provider_user_id' => 'link-google']);
    }

    public function test_existing_two_factor_requirement_is_not_bypassed_by_provider_sign_in(): void
    {
        $user = $this->approved('mfa@example.test', 'google', 'mfa-google');
        $user->forceFill(['two_factor_secret' => encrypt('SYNTHETIC'), 'two_factor_confirmed_at' => now()])->save();
        $this->fakeSsoProvider('google', ['id' => 'mfa-google', 'email' => $user->email, 'name' => 'MFA']);
        $this->beginSsoFixture('google');
        $this->get('/auth/google/callback')->assertRedirect(route('two-factor.login'))->assertSessionHas('login.id', $user->id);
        $this->assertGuest();
    }

    public function test_fresh_driver_uses_saved_credentials_canonical_audience_callback_and_minimal_sign_in_scopes(): void
    {
        AppSetting::create(['key' => 'settings.sso.google', 'value' => ['version' => 1, 'client_id' => 'saved-client', 'secret_source' => 'saved', 'secret_ciphertext' => Crypt::encryptString('saved-secret'), 'staff_enabled' => true, 'portal_enabled' => true, 'domain' => 'example.test']]);
        $request = Request::create('/auth/google/callback');
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        $service = app(SsoAuthenticationService::class);
        $config = app(SsoConfigurationService::class)->provider('google');
        $driver = $service->driver('google', $config, 'portal');
        $this->assertSame(['openid', 'profile', 'email'], $driver->getScopes());
        $reflection = new \ReflectionObject($driver);
        foreach (['clientId' => 'saved-client', 'clientSecret' => 'saved-secret', 'redirectUrl' => 'https://application.example.test/portal/auth/google/callback'] as $property => $expected) {
            $this->assertSame($expected, $reflection->getProperty($property)->getValue($driver));
        }
        $this->assertSame('synthetic-google-secret', config('services.google.client_secret'));
        $this->expectException(InvalidStateException::class);
        $driver->user(); // Fails local state verification before any provider request.
    }

    private function approved(string $email, string $provider, string $subject, string $role = 'support_worker'): User
    {
        $user = User::factory()->withoutTwoFactor()->create(['email' => $email, 'role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->firstOrFail());
        $user->identities()->create(['provider' => $provider, 'provider_user_id' => $subject, 'email' => $email]);

        return $user;
    }

    public function test_pending_portal_identity_survives_approval_and_can_return(): void
    {
        $user = User::factory()->withoutTwoFactor()->create(['email' => 'pending@family.example', 'role' => 'next_of_kin', 'approved_at' => null]);
        $user->roles()->attach(Role::where('name', 'next_of_kin')->firstOrFail());
        $this->fakeSsoProvider('google', ['id' => 'pending-family', 'email' => $user->email, 'name' => 'Family']);
        $this->beginSsoFixture('google', 'portal');
        $this->get('/portal/auth/google/callback')->assertRedirect('/portal/login');
        $this->assertGuest();
        $this->assertDatabaseHas('identities', ['user_id' => $user->id, 'provider_user_id' => 'pending-family']);
        $user->forceFill(['approved_at' => now()])->save();
        $this->beginSsoFixture('google', 'portal');
        $this->get('/portal/auth/google/callback')->assertRedirect('/portal');
        $this->assertAuthenticatedAs($user);
    }

    public function test_authenticated_portal_actor_can_explicitly_link_from_canonical_profile(): void
    {
        $user = $this->approved('linked@family.example', 'microsoft', 'family-ms', 'next_of_kin');
        $this->fakeSsoProvider('google', ['id' => 'family-google', 'email' => $user->email, 'name' => 'Family']);
        $this->actingAs($user)->get('/settings/profile')->assertOk()->assertInertia(fn ($page) => $page->where('profile.microsoftLinked', true)->where('profile.googleLinked', false)->where('profile.ssoLinkPrefix', '/portal'));
        $this->beginSsoFixture('google', 'portal', true);
        $this->get('/portal/auth/google/callback')->assertRedirect('/settings/profile');
        $this->assertDatabaseHas('identities', ['user_id' => $user->id, 'provider_user_id' => 'family-google']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.sso.identity_linked', 'user_id' => $user->id]);
    }

    public function test_explicit_link_already_in_callback_cannot_recreate_a_disconnected_identity(): void
    {
        $user = $this->approved('unlink@example.test', 'google', 'unlink-google');
        $this->actingAs($user);
        $this->fakeSsoProvider('google', ['id' => 'unlink-google', 'email' => $user->email, 'name' => 'Unlink'], [], function () {
            app(IdentityDisconnectController::class)->destroy(request(), 'google');
        });
        $this->beginSsoFixture('google', 'staff', true);
        $this->get('/auth/google/callback')->assertConflict();
        $this->assertDatabaseMissing('identities', ['user_id' => $user->id, 'provider' => 'google']);
    }

    public function test_disconnecting_an_absent_provider_also_cancels_inflight_explicit_link(): void
    {
        $user = $this->approved('cancel@example.test', 'microsoft', 'cancel-ms');
        $this->actingAs($user);
        $this->fakeSsoProvider('google', ['id' => 'new-google', 'email' => $user->email, 'name' => 'Cancel'], [], function () {
            app(IdentityDisconnectController::class)->destroy(request(), 'google');
        });
        $this->beginSsoFixture('google', 'staff', true);
        $this->get('/auth/google/callback')->assertConflict();
        $this->assertDatabaseMissing('identities', ['user_id' => $user->id, 'provider' => 'google']);
    }
}
