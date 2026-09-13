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

    public function test_failed_malformed_or_partial_directory_reads_never_remove_roles(): void
    {
        [$user, $mapping] = $this->removalFixture();
        foreach ([
            [['error' => 'unavailable'], 503],
            [[], 200],
            [['value' => [['displayName' => 'Missing identity']]], 200],
            [['value' => [], '@odata.nextLink' => 'https://untrusted.example.test/collect'], 200],
        ] as [$body, $status]) {
            Http::fake(['*' => Http::response($body, $status)]);
            try {
                app(AzureAdGroupService::class)->syncUserRoles($user);
                $this->fail('Incomplete provider evidence must fail.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('No roles were changed', $exception->getMessage());
            }
            $this->assertDatabaseHas('role_user', ['user_id' => $user->id, 'role_id' => $mapping->role_id]);
            $this->assertNull($mapping->fresh()->last_synced_at);
        }
        $this->assertDatabaseMissing('audit_logs', ['action' => 'settings.sso.groups_synced']);
    }

    public function test_authoritative_empty_membership_removes_only_configured_role_and_is_audited(): void
    {
        [$user, $mapping] = $this->removalFixture();
        Http::fake(['*' => Http::response(['value' => []])]);
        app(AzureAdGroupService::class)->syncUserRoles($user);
        $this->assertDatabaseMissing('role_user', ['user_id' => $user->id, 'role_id' => $mapping->role_id]);
        $this->assertNotNull($mapping->fresh()->last_synced_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.sso.groups_synced']);
    }

    public function test_paginated_membership_retains_a_role_covered_by_another_current_group(): void
    {
        [$user, $mapping] = $this->removalFixture();
        SsoGroupMapping::create(['provider' => 'microsoft', 'external_group_id' => 'kept', 'external_group_name' => 'Synthetic kept group', 'role_id' => $mapping->role_id, 'auto_assign' => true, 'auto_remove' => true]);
        Http::fakeSequence()->push(['value' => [], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/memberOf?$skiptoken=synthetic'])
            ->push(['value' => [['id' => 'kept', '@odata.type' => '#microsoft.graph.group']]]);
        app(AzureAdGroupService::class)->syncUserRoles($user);
        Http::assertSentCount(2);
        $this->assertDatabaseHas('role_user', ['user_id' => $user->id, 'role_id' => $mapping->role_id]);
    }

    public function test_identity_revoked_during_fetch_cannot_publish_group_role_changes(): void
    {
        [$user, $mapping, $identity] = $this->removalFixture();
        Http::fake(function () use ($identity) {
            $identity->delete();

            return Http::response(['value' => []]);
        });
        try {
            app(AzureAdGroupService::class)->syncUserRoles($user);
            $this->fail('Revoked identity cannot publish membership.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('identity changed', $exception->getMessage());
        }
        $this->assertDatabaseHas('role_user', ['user_id' => $user->id, 'role_id' => $mapping->role_id]);
        $this->assertNull($mapping->fresh()->last_synced_at);
    }

    private function removalFixture(): array
    {
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create(['approved_at' => now()]);
        $role = Role::where('name', 'support_worker')->firstOrFail();
        $user->roles()->attach($role);
        $identity = Identity::create(['user_id' => $user->id, 'provider' => 'microsoft', 'provider_user_id' => 'synthetic-subject', 'email' => $user->email, 'access_token' => 'synthetic-access-token', 'token_expires_at' => now()->addHour()]);
        $mapping = SsoGroupMapping::create(['provider' => 'microsoft', 'external_group_id' => 'removed', 'external_group_name' => 'Synthetic removal group', 'role_id' => $role->id, 'auto_assign' => true, 'auto_remove' => true]);

        return [$user, $mapping, $identity];
    }
}
