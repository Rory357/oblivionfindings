<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The redesigned Emergency-access (break-glass) page resolves the active site's
 * brand colour, serves the active grants + audit log + policy, and — the key
 * §5 fix — revoking now SOFT-deletes so the activation is retained for the audit
 * trail (shown as "revoked"), never hard-erased.
 */
class EmergencyAccessTest extends TestCase
{
    use RefreshDatabase;

    private function seedAccess(): array
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.breakglass', 'medications.audit.view']);
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#5E35B1']);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $access = ClientBreakGlassAccess::query()->create([
            'client_id' => $client->id, 'user_id' => $user->id, 'reason' => 'Clinical urgency — resident unwell',
            'expires_at' => now()->addHour(),
        ]);

        return compact('user', 'site', 'client', 'access');
    }

    public function test_page_serves_brand_colour_active_grants_and_audit(): void
    {
        ['user' => $user, 'site' => $site] = $this->seedAccess();

        $this->actingAs($user)
            ->get('/emar/emergency-access?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emergency/access')
                ->where('site_brand_colour', '#5E35B1')
                ->has('activeAccesses', 1)
                ->where('activeAccesses.0.can_revoke', true)
                ->has('auditLog', 1)
                ->where('auditLog.0.status', 'active')
                ->has('policy')
                ->where('stats.active', 1)
            );
    }

    public function test_revoke_soft_deletes_and_retains_audit(): void
    {
        ['user' => $user, 'client' => $client, 'access' => $access] = $this->seedAccess();

        $this->actingAs($user)
            ->from('/emar/emergency-access')
            ->delete("/emar/clients/{$client->id}/break-glass/{$access->id}")
            ->assertSessionHasNoErrors();

        // Retained (not hard-deleted) and attributed to the revoker.
        $this->assertSoftDeleted('client_break_glass_accesses', ['id' => $access->id]);
        $this->assertSame($user->id, $access->refresh()->revoked_by);

        // No longer "active", but still present in the audit log as "revoked".
        $this->actingAs($user)
            ->get('/emar/emergency-access')
            ->assertInertia(fn (Assert $page) => $page
                ->has('activeAccesses', 0)
                ->has('auditLog', 1)
                ->where('auditLog.0.status', 'revoked')
            );
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
    }
}
