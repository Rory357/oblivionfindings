<?php

namespace Tests\Feature\Auth\OAuth;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesSsoProvider;
use Tests\TestCase;

class PortalOAuthCallbackTest extends TestCase
{
    use FakesSsoProvider;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->configureSsoFixture();
    }

    public function test_portal_google_callback_creates_pending_next_of_kin_user(): void
    {
        $this->fakeSocialiteUser('google', [
            'id' => 'portal-google-123',
            'name' => 'Portal Family',
            'email' => 'Family.Member@example.test',
        ]);

        $this->get('/portal/auth/google/callback')
            ->assertRedirect(route('portal.login', absolute: false))
            ->assertSessionHas('success', 'Your account has been created and is awaiting approval by staff.');

        $user = User::where('email', 'family.member@example.test')->firstOrFail();
        $portalRole = Role::where('name', 'next_of_kin')->firstOrFail();
        $this->assertNull($user->approved_at);
        $this->assertSame('next_of_kin', $user->role);
        $this->assertTrue($user->roles()->whereKey($portalRole->id)->exists());
        $this->assertGuest();
    }

    public function test_portal_microsoft_callback_logs_in_existing_user(): void
    {
        $user = User::factory()->withoutTwoFactor()->create([
            'email' => 'existing.family@example.test',
            'approved_at' => now(),
            'role' => 'next_of_kin',
        ]);
        $user->roles()->attach(Role::where('name', 'next_of_kin')->firstOrFail());
        $user->identities()->create(['provider' => 'microsoft', 'provider_user_id' => 'portal-ms-123', 'email' => $user->email]);
        $this->fakeSocialiteUser('microsoft', [
            'id' => 'portal-ms-123',
            'name' => 'Existing Family',
            'email' => 'existing.family@example.test',
        ]);

        $this->get('/portal/auth/microsoft/callback')
            ->assertRedirect('/portal');

        $this->assertAuthenticatedAs($user);
    }

    public function test_portal_callback_does_not_log_in_existing_pending_user(): void
    {
        User::factory()->withoutTwoFactor()->create([
            'email' => 'pending.family@example.test',
            'approved_at' => null,
            'role' => 'next_of_kin',
        ])->roles()->attach(Role::where('name', 'next_of_kin')->firstOrFail());
        $this->fakeSocialiteUser('google', [
            'id' => 'portal-google-pending',
            'name' => 'Pending Family',
            'email' => 'pending.family@example.test',
        ]);

        $this->get('/portal/auth/google/callback')
            ->assertRedirect(route('portal.login', absolute: false))
            ->assertSessionHas('success', 'Your account is awaiting approval by staff.');

        $this->assertGuest();
    }

    public function test_portal_callback_rejects_missing_email(): void
    {
        $this->fakeSocialiteUser('google', [
            'id' => 'portal-no-email',
            'name' => 'No Email',
            'email' => null,
        ]);

        $this->get('/portal/auth/google/callback')
            ->assertUnauthorized();
    }

    /**
     * @param  array{id: string, name: string, email: string|null}  $attributes
     */
    private function fakeSocialiteUser(string $provider, array $attributes): void
    {
        $this->fakeSsoProvider($provider, $attributes);
        $this->beginSsoFixture($provider, 'portal');
    }
}
