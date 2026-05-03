<?php

namespace Tests\Feature\Auth\OAuth;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class PortalOAuthCallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
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
        $user = User::factory()->create([
            'email' => 'existing.family@example.test',
            'approved_at' => now(),
            'role' => 'next_of_kin',
        ]);
        $user->roles()->attach(Role::where('name', 'next_of_kin')->firstOrFail());
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
        User::factory()->create([
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
     * @param array{id: string, name: string, email: string|null} $attributes
     */
    private function fakeSocialiteUser(string $provider, array $attributes): void
    {
        $user = (new SocialiteUser())->map($attributes);
        $user->setRaw([
            ...$attributes,
            'mail' => $attributes['email'],
            'userPrincipalName' => $attributes['email'],
        ]);
        $user->setToken($provider.'-access-token');
        $user->setRefreshToken($provider.'-refresh-token');
        $user->setExpiresIn(3600);

        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn($user);

        Socialite::shouldReceive('driver')
            ->with($provider)
            ->andReturn($driver);
    }
}
