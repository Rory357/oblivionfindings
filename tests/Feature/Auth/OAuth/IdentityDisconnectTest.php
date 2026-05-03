<?php

namespace Tests\Feature\Auth\OAuth;

use App\Models\AuditLog;
use App\Models\Identity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdentityDisconnectTest extends TestCase
{
    use RefreshDatabase;

    public function test_disconnect_requires_authentication(): void
    {
        $this->post('/auth/google/disconnect')->assertRedirect('/login');
    }

    public function test_disconnect_rejects_unknown_provider(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($user)
            ->post('/auth/github/disconnect')
            ->assertNotFound();
    }

    public function test_disconnect_deletes_selected_identity_and_audits_action(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);
        $google = $this->createIdentity($user, 'google');
        $microsoft = $this->createIdentity($user, 'microsoft');

        $this->actingAs($user)
            ->post('/auth/google/disconnect')
            ->assertRedirect()
            ->assertSessionHas('success', 'Google account disconnected.');

        $this->assertDatabaseMissing('identities', ['id' => $google->id]);
        $this->assertDatabaseHas('identities', ['id' => $microsoft->id]);

        $audit = AuditLog::where('action', 'identity.disconnected')->firstOrFail();
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('google', $audit->meta['provider']);
        $this->assertSame(1, $audit->meta['deleted']);
    }

    private function createIdentity(User $user, string $provider): Identity
    {
        return Identity::create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_user_id' => $provider . '-user',
            'email' => $user->email,
            'access_token' => $provider . '-token',
            'token_expires_at' => now()->addHour(),
        ]);
    }
}
