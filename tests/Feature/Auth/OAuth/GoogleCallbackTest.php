<?php

namespace Tests\Feature\Auth\OAuth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleCallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_callback_creates_pending_user_and_identity_without_logging_in(): void
    {
        $this->fakeSocialiteUser('google', [
            'id' => 'google-123',
            'name' => 'Google User',
            'email' => 'New.Google@example.test',
        ]);

        $this->get('/auth/google/callback')
            ->assertRedirect(route('login', absolute: false))
            ->assertSessionHas('success', 'Thanks for signing up! Your account is awaiting approval.');

        $user = User::where('email', 'new.google@example.test')->firstOrFail();
        $this->assertNull($user->approved_at);
        $this->assertDatabaseHas('identities', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-123',
            'email' => 'new.google@example.test',
        ]);
        $this->assertGuest();
    }

    public function test_google_callback_links_identity_to_authenticated_user(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $this->fakeSocialiteUser('google', [
            'id' => 'google-linked',
            'name' => 'Linked User',
            'email' => 'linked@example.test',
        ]);

        $this->actingAs($user)
            ->withSession(['oauth_link_user' => $user->id])
            ->get('/auth/google/callback')
            ->assertRedirect('/settings/profile')
            ->assertSessionHas('success', 'Google account linked.');

        $this->assertDatabaseHas('identities', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-linked',
            'email' => 'linked@example.test',
        ]);
    }

    public function test_google_callback_rejects_missing_email(): void
    {
        $this->fakeSocialiteUser('google', [
            'id' => 'google-no-email',
            'name' => 'No Email',
            'email' => null,
        ]);

        $this->get('/auth/google/callback')
            ->assertUnauthorized();
    }

    /**
     * @param array{id: string, name: string, email: string|null} $attributes
     */
    private function fakeSocialiteUser(string $provider, array $attributes): void
    {
        $user = (new SocialiteUser())->map($attributes);
        $user->setRaw($attributes);
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
