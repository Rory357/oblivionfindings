<?php

namespace Tests\Feature\Auth\OAuth;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesSsoProvider;
use Tests\TestCase;

class MicrosoftCallbackTest extends TestCase
{
    use FakesSsoProvider;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->configureSsoFixture();
    }

    public function test_microsoft_callback_gracefully_handles_missing_org_domain(): void
    {
        config(['sso.staff_domain' => 'example.test']);
        $this->fakeSocialiteUser([
            'id' => 'ms-123',
            'name' => 'Microsoft User',
            'email' => 'user@example.test',
        ]);

        config(['sso.staff_domain' => '']);
        $this->get('/auth/microsoft/callback')
            ->assertRedirect(route('login', absolute: false))
            ->assertSessionHasErrors(['microsoft']);

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'user@example.test']);
    }

    public function test_microsoft_callback_rejects_non_org_email(): void
    {
        config(['sso.staff_domain' => 'example.test']);
        $this->fakeSocialiteUser([
            'id' => 'ms-123',
            'name' => 'External User',
            'email' => 'user@external.test',
        ]);

        $this->get('/auth/microsoft/callback')
            ->assertForbidden();
    }

    public function test_microsoft_callback_creates_pending_user_and_identity_without_logging_in(): void
    {
        config(['sso.staff_domain' => 'example.test']);
        $this->fakeSocialiteUser([
            'id' => 'ms-123',
            'name' => 'Microsoft User',
            'email' => 'New.Microsoft@example.test',
        ]);

        $this->get('/auth/microsoft/callback')
            ->assertRedirect(route('login', absolute: false))
            ->assertSessionHas('success', 'Thanks for signing up! Your account is awaiting approval.');

        $user = User::where('email', 'new.microsoft@example.test')->firstOrFail();
        $supportRole = Role::where('name', 'support_worker')->firstOrFail();
        $this->assertNull($user->approved_at);
        $this->assertTrue($user->roles()->whereKey($supportRole->id)->exists());
        $this->assertDatabaseHas('identities', [
            'user_id' => $user->id,
            'provider' => 'microsoft',
            'provider_user_id' => 'ms-123',
            'email' => 'new.microsoft@example.test',
        ]);
        $this->assertGuest();
    }

    public function test_microsoft_callback_logs_in_existing_approved_user(): void
    {
        config(['sso.staff_domain' => 'example.test']);
        $user = User::factory()->withoutTwoFactor()->create([
            'email' => 'approved@example.test',
            'approved_at' => now(),
        ]);
        $user->identities()->create(['provider' => 'microsoft', 'provider_user_id' => 'ms-approved', 'email' => $user->email]);
        $this->fakeSocialiteUser([
            'id' => 'ms-approved',
            'name' => 'Approved User',
            'email' => 'approved@example.test',
        ]);

        $this->get('/auth/microsoft/callback')
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('identities', [
            'user_id' => $user->id,
            'provider' => 'microsoft',
            'provider_user_id' => 'ms-approved',
            'email' => 'approved@example.test',
        ]);
    }

    public function test_microsoft_callback_links_identity_to_authenticated_user(): void
    {
        config(['sso.staff_domain' => 'example.test']);
        $user = User::factory()->withoutTwoFactor()->create(['approved_at' => now()]);
        $this->actingAs($user);
        $this->fakeSocialiteUser([
            'id' => 'ms-linked',
            'name' => 'Linked User',
            'email' => 'linked@example.test',
        ]);

        $this->actingAs($user)
            ->withSession(['oauth_link_user' => $user->id])
            ->get('/auth/microsoft/callback')
            ->assertRedirect('/settings/profile')
            ->assertSessionHas('success', 'Microsoft account linked.');

        $this->assertDatabaseHas('identities', [
            'user_id' => $user->id,
            'provider' => 'microsoft',
            'provider_user_id' => 'ms-linked',
            'email' => 'linked@example.test',
        ]);
    }

    /**
     * @param  array{id: string, name: string, email: string|null}  $attributes
     */
    private function fakeSocialiteUser(array $attributes): void
    {
        $this->fakeSsoProvider('microsoft', $attributes);
        $this->beginSsoFixture('microsoft', 'staff', auth()->check());
    }
}
