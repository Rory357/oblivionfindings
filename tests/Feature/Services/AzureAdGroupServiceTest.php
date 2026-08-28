<?php

namespace Tests\Feature\Services;

use App\Models\Identity;
use App\Models\Role;
use App\Models\SsoGroupMapping;
use App\Models\User;
use App\Services\AzureAdGroupService;
use App\Services\SsoGroupMappingLockService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AzureAdGroupServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_sync_replays_the_current_locked_mapping_before_publication(): void
    {
        $this->seed(RbacSeeder::class);

        $user = User::factory()->create(['approved_at' => now()]);
        Identity::create([
            'user_id' => $user->id,
            'provider' => 'microsoft',
            'provider_user_id' => 'microsoft-'.$user->id,
            'email' => $user->email,
            'access_token' => 'microsoft-access-token',
            'refresh_token' => 'microsoft-refresh-token',
            'token_expires_at' => now()->addHour(),
        ]);
        $staleRole = Role::query()->where('name', 'support_worker')->firstOrFail();
        $currentRole = Role::query()->where('name', 'admin')->firstOrFail();
        $mapping = SsoGroupMapping::create([
            'provider' => 'microsoft',
            'external_group_id' => 'current-group',
            'external_group_name' => 'Current group',
            'role_id' => $staleRole->id,
            'auto_assign' => true,
            'auto_remove' => false,
        ]);

        $this->app->instance(SsoGroupMappingLockService::class, new class((int) $mapping->id, (int) $currentRole->id) extends SsoGroupMappingLockService
        {
            public function __construct(
                private readonly int $mappingId,
                private readonly int $currentRoleId,
            ) {}

            public function lockMappingSet(): Collection
            {
                SsoGroupMapping::query()->whereKey($this->mappingId)->update([
                    'role_id' => $this->currentRoleId,
                ]);

                return parent::lockMappingSet();
            }
        });
        Http::fake([
            'https://graph.microsoft.com/v1.0/me/memberOf*' => Http::response([
                'value' => [[
                    '@odata.type' => '#microsoft.graph.group',
                    'id' => 'current-group',
                    'displayName' => 'Current group',
                ]],
            ]),
        ]);

        app(AzureAdGroupService::class)->syncUserRoles($user);

        $this->assertDatabaseHas('sso_group_mappings', [
            'id' => $mapping->id,
            'role_id' => $currentRole->id,
        ]);
        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => $currentRole->id,
        ]);
        $this->assertDatabaseMissing('role_user', [
            'user_id' => $user->id,
            'role_id' => $staleRole->id,
        ]);
    }
}
