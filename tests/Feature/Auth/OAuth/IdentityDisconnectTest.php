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

    public function test_failed_required_audit_rolls_back_unlink_and_retains_pending_flow(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);
        $identity = $this->createIdentity($user, 'google');
        $fail = true;
        AuditLog::creating(function (AuditLog $audit) use (&$fail): void {
            if ($fail && $audit->action === 'identity.disconnected') {
                throw new \RuntimeException('Isolated identity audit failure');
            }
        });
        try {
            $this->actingAs($user)->withSession(['sso.flow' => ['provider' => 'google']])
                ->post('/auth/google/disconnect')->assertStatus(500)
                ->assertSessionHas('sso.flow.provider', 'google');
        } finally {
            $fail = false;
        }
        $this->assertDatabaseHas('identities', ['id' => $identity->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'identity.disconnected']);
        $this->post('/auth/google/disconnect')->assertRedirect()->assertSessionMissing('sso.flow');
        $this->assertDatabaseMissing('identities', ['id' => $identity->id]);
    }

    public function test_unlink_only_affects_current_user_and_clears_the_pending_sign_in_flow(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);
        $this->createIdentity($user, 'google');
        $other = User::factory()->create(['approved_at' => now()]);
        $otherIdentity = Identity::create(['user_id' => $other->id, 'provider' => 'google', 'provider_user_id' => 'other-google-subject', 'email' => $other->email]);
        $this->actingAs($user)->withSession(['sso.flow' => ['provider' => 'google'], 'oauth_link_user' => $user->id])
            ->post('/auth/google/disconnect')->assertRedirect()
            ->assertSessionMissing('sso.flow')->assertSessionMissing('oauth_link_user');
        $this->assertDatabaseHas('identities', ['id' => $otherIdentity->id]);
        $audit = AuditLog::where('action', 'identity.disconnected')->firstOrFail();
        $this->assertStringNotContainsString('google-token', json_encode($audit->meta));
    }

    public function test_revoked_account_cannot_disconnect_using_a_stale_authenticated_user(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);
        $identity = $this->createIdentity($user, 'google');
        $this->actingAs($user);
        User::whereKey($user->id)->update(['approved_at' => null]);
        $this->post('/auth/google/disconnect')->assertForbidden();
        $this->assertDatabaseHas('identities', ['id' => $identity->id]);
    }

    private function createIdentity(User $user, string $provider): Identity
    {
        return Identity::create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_user_id' => $provider.'-user',
            'email' => $user->email,
            'access_token' => $provider.'-token',
            'token_expires_at' => now()->addHour(),
        ]);
    }
}
