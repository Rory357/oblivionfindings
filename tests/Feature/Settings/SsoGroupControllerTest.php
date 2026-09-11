<?php

namespace Tests\Feature\Settings;

use App\Models\Identity;
use App\Models\Role;
use App\Models\SsoGroupMapping;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SsoGroupControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private Role $supportRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->firstOrFail());

        $this->staff = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $this->staff->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());

        $this->supportRole = Role::where('name', 'support_worker')->firstOrFail();
    }

    public function test_sso_group_routes_require_authentication(): void
    {
        $mapping = $this->createMapping();

        $this->get('/settings/sso-groups')->assertRedirect('/login');
        $this->post('/settings/sso-groups')->assertRedirect('/login');
        $this->put("/settings/sso-groups/{$mapping->id}")->assertRedirect('/login');
        $this->delete("/settings/sso-groups/{$mapping->id}")->assertRedirect('/login');
        $this->post('/settings/sso-groups/fetch')->assertRedirect('/login');
    }

    public function test_sso_group_routes_deny_support_workers(): void
    {
        $mapping = $this->createMapping();

        $this->actingAs($this->staff)->get('/settings/sso-groups')->assertForbidden();
        $this->actingAs($this->staff)->post('/settings/sso-groups', $this->mappingPayload())->assertForbidden();
        $this->actingAs($this->staff)->put("/settings/sso-groups/{$mapping->id}", [
            'role_id' => $this->supportRole->id,
            'auto_assign' => true,
            'auto_remove' => false,
        ])->assertForbidden();
        $this->actingAs($this->staff)->delete("/settings/sso-groups/{$mapping->id}")->assertForbidden();
        $this->actingAs($this->staff)->post('/settings/sso-groups/fetch')->assertForbidden();
    }

    public function test_index_redirects_to_the_canonical_sso_workspace_and_json_returns_saved_rules(): void
    {
        $this->createMapping([
            'provider' => 'microsoft',
            'external_group_name' => 'Care Coordinators',
        ]);

        $this->actingAs($this->admin)
            ->get('/settings/sso-groups')
            ->assertRedirect('/settings/sso?view=groups');
        $this->getJson('/settings/sso-groups')->assertOk()->assertJsonCount(1, 'mappings')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_store_creates_mapping(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/sso-groups', $this->mappingPayload([
                'external_group_id' => 'group-new',
                'external_group_name' => 'New Group',
            ]))
            ->assertRedirect()
            ->assertSessionHas('success', 'Group mapping created.');

        $this->assertDatabaseHas('sso_group_mappings', [
            'provider' => 'microsoft',
            'external_group_id' => 'group-new',
            'external_group_name' => 'New Group',
            'role_id' => $this->supportRole->id,
        ]);
    }

    public function test_update_changes_mapping_flags(): void
    {
        $mapping = $this->createMapping([
            'auto_assign' => true,
            'auto_remove' => false,
        ]);
        $replacementRole = Role::where('name', 'admin')->firstOrFail();

        $this->actingAs($this->admin)
            ->put("/settings/sso-groups/{$mapping->id}", [
                'role_id' => $replacementRole->id,
                'expected_version' => $mapping->version,
                'auto_assign' => false,
                'auto_remove' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Group mapping updated.');

        $this->assertDatabaseHas('sso_group_mappings', [
            'id' => $mapping->id,
            'role_id' => $replacementRole->id,
            'auto_assign' => false,
            'auto_remove' => true,
        ]);
    }

    public function test_destroy_removes_mapping(): void
    {
        $mapping = $this->createMapping();

        $this->actingAs($this->admin)
            ->delete("/settings/sso-groups/{$mapping->id}", ['expected_version' => $mapping->version])
            ->assertRedirect()
            ->assertSessionHas('success', 'Group mapping deleted.');

        $this->assertDatabaseMissing('sso_group_mappings', ['id' => $mapping->id]);
    }

    public function test_fetch_groups_requires_connected_microsoft_identity(): void
    {
        $this->actingAs($this->admin)
            ->post('/settings/sso-groups/fetch')
            ->assertRedirect()
            ->assertSessionHas('error', 'No Microsoft identity found for your account. Please connect a Microsoft account first.');
    }

    public function test_fetch_groups_requires_unexpired_token(): void
    {
        $this->createMicrosoftIdentity($this->admin, now()->subMinute());

        $this->actingAs($this->admin)
            ->post('/settings/sso-groups/fetch')
            ->assertRedirect()
            ->assertSessionHas('error', 'Microsoft token has expired. Please reconnect your Microsoft account.');
    }

    public function test_fetch_groups_returns_microsoft_graph_groups(): void
    {
        $identity = $this->createMicrosoftIdentity($this->admin, now()->addHour());
        Http::fake([
            'https://graph.microsoft.com/v1.0/groups*' => Http::response([
                'value' => [
                    ['id' => 'graph-group-1', 'displayName' => 'Graph Group', 'securityEnabled' => true],
                ],
            ]),
        ]);

        $this->actingAs($this->admin)
            ->post('/settings/sso-groups/fetch')
            ->assertRedirect()
            ->assertSessionHas('groups', [
                ['id' => 'graph-group-1', 'displayName' => 'Graph Group', 'securityEnabled' => true],
            ]);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.$identity->access_token)
            && str_starts_with($request->url(), 'https://graph.microsoft.com/v1.0/groups'));
    }

    public function test_fetch_groups_reports_microsoft_graph_failure(): void
    {
        $this->createMicrosoftIdentity($this->admin, now()->addHour());
        Http::fake([
            'https://graph.microsoft.com/v1.0/groups*' => Http::response(['error' => 'unavailable'], 503),
        ]);

        $this->actingAs($this->admin)
            ->post('/settings/sso-groups/fetch')
            ->assertRedirect()
            ->assertSessionHas('error', 'Could not fetch Microsoft groups. Please try again or reconnect your Microsoft account.');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mappingPayload(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'microsoft',
            'external_group_id' => 'group-1',
            'external_group_name' => 'Group 1',
            'role_id' => $this->supportRole->id,
            'auto_assign' => true,
            'auto_remove' => false,
            'confirm_role_assignment' => true,
            'assignment_reason' => 'Synthetic approved group assignment.',
        ], $overrides);
    }

    public function test_role_grants_require_deliberate_review_and_changes_are_audited(): void
    {
        $this->actingAs($this->admin)->postJson('/settings/sso-groups', $this->mappingPayload(['confirm_role_assignment' => false]))
            ->assertUnprocessable()->assertJsonValidationErrors('assignment_reason');
        $this->assertDatabaseCount('sso_group_mappings', 0);
        $response = $this->postJson('/settings/sso-groups', $this->mappingPayload(['role_id' => (string) $this->supportRole->id]))->assertOk()->assertJsonPath('status', 'saved')->assertJsonPath('mapping.role_id', (int) $this->supportRole->id);
        $mapping = SsoGroupMapping::findOrFail($response->json('mapping.id'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.sso.group_mapping_created', 'auditable_id' => $mapping->id]);
        $this->postJson('/settings/sso-groups', $this->mappingPayload())->assertUnprocessable()->assertJsonValidationErrors('external_group_id');
        $payload = ['role_id' => $mapping->role_id, 'auto_assign' => false, 'auto_remove' => true, 'expected_version' => $mapping->version];
        $this->putJson('/settings/sso-groups/'.$mapping->id, $payload)->assertOk();
        $this->putJson('/settings/sso-groups/'.$mapping->id, $payload)->assertConflict();
        $this->deleteJson('/settings/sso-groups/'.$mapping->id, ['expected_version' => $mapping->version])->assertConflict();
        $this->deleteJson('/settings/sso-groups/'.$mapping->id, ['expected_version' => $mapping->fresh()->version])->assertOk()->assertJsonPath('status', 'removed');
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.sso.group_mapping_deleted', 'auditable_id' => $mapping->id]);
    }

    public function test_directory_result_is_withheld_if_current_actor_access_was_revoked_during_fetch(): void
    {
        $this->createMicrosoftIdentity($this->admin, now()->addHour());
        Http::fake(function () {
            $this->admin->roles()->detach();
            $this->admin->forceFill(['role' => 'support_worker'])->save();

            return Http::response(['value' => [['id' => 'private-group', 'displayName' => 'Synthetic restricted group']]]);
        });
        $this->actingAs($this->admin)->postJson('/settings/sso-groups/fetch')->assertForbidden()->assertJsonMissingPath('groups');
    }

    public function test_directory_result_is_withheld_if_its_identity_was_disconnected_during_fetch(): void
    {
        $identity = $this->createMicrosoftIdentity($this->admin, now()->addHour());
        Http::fake(function () use ($identity) {
            $identity->delete();

            return Http::response(['value' => [['id' => 'private-group', 'displayName' => 'Synthetic restricted group']]]);
        });
        $this->actingAs($this->admin)->postJson('/settings/sso-groups/fetch')->assertConflict()->assertJsonMissingPath('groups');
    }

    private function createMicrosoftIdentity(User $user, $expiresAt): Identity
    {
        return Identity::create([
            'user_id' => $user->id,
            'provider' => 'microsoft',
            'provider_user_id' => 'ms-'.$user->id,
            'email' => $user->email,
            'access_token' => 'token-'.$user->id,
            'refresh_token' => 'refresh-'.$user->id,
            'token_expires_at' => $expiresAt,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createMapping(array $overrides = []): SsoGroupMapping
    {
        return SsoGroupMapping::create(array_merge([
            'provider' => 'microsoft',
            'external_group_id' => 'existing-group',
            'external_group_name' => 'Existing Group',
            'role_id' => $this->supportRole->id,
            'auto_assign' => true,
            'auto_remove' => false,
        ], $overrides));
    }
}
