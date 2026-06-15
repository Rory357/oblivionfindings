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

    public function test_grant_persists_structured_fields_and_cosign(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedAccess();
        $cosigner = User::factory()->create(['organization_id' => $user->organization_id, 'approved_at' => now()]);

        $this->actingAs($user)
            ->from('/emar/emergency-access')
            ->post("/clients/{$client->id}/break-glass", [
                'reason' => 'Covering sick leave; 16:00 meds due',
                'reason_category' => 'Staff absence / cover',
                'minutes' => 120,
                'authorization_mode' => 'co_sign',
                'co_signed_by' => $cosigner->id,
                'acknowledged_min_necessary' => true,
                'acknowledged_incident_report' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('client_break_glass_accesses', [
            'client_id' => $client->id,
            'reason_category' => 'Staff absence / cover',
            'authorization_mode' => 'co_sign',
            'co_signed_by' => $cosigner->id,
            'acknowledged_min_necessary' => true,
            'acknowledged_incident_report' => true,
        ]);
    }

    public function test_cosign_must_be_a_different_person(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedAccess();

        $this->actingAs($user)
            ->from('/emar/emergency-access')
            ->post("/clients/{$client->id}/break-glass", [
                'reason' => 'x', 'authorization_mode' => 'co_sign', 'co_signed_by' => $user->id,
            ])
            ->assertSessionHasErrors('co_signed_by');
    }

    public function test_grant_duration_is_capped_at_policy_max(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedAccess();

        $this->actingAs($user)
            ->from('/emar/emergency-access')
            ->post("/clients/{$client->id}/break-glass", ['reason' => 'x', 'minutes' => 5000])
            ->assertSessionHasErrors('minutes');
    }

    public function test_extend_adds_time_within_cap(): void
    {
        ['user' => $user, 'client' => $client, 'access' => $access] = $this->seedAccess();
        $original = $access->expires_at;

        $this->actingAs($user)
            ->from('/emar/emergency-access')
            ->post("/emar/clients/{$client->id}/break-glass/{$access->id}/extend")
            ->assertSessionHasNoErrors();

        $this->assertTrue($access->refresh()->expires_at->greaterThan($original));
    }

    public function test_extend_refuses_past_the_maximum_window(): void
    {
        ['user' => $user, 'client' => $client, 'access' => $access] = $this->seedAccess();
        // Push the window out to the policy cap (created_at + max).
        $access->forceFill(['expires_at' => $access->created_at->copy()->addMinutes(ClientBreakGlassAccess::MAX_MINUTES)])->save();
        $capped = $access->refresh()->expires_at;

        $this->actingAs($user)
            ->from('/emar/emergency-access')
            ->post("/emar/clients/{$client->id}/break-glass/{$access->id}/extend")
            ->assertSessionHas('error');

        $this->assertSame($capped->timestamp, $access->refresh()->expires_at->timestamp);
    }

    public function test_review_records_outcome_and_reviewer(): void
    {
        ['user' => $user, 'client' => $client, 'access' => $access] = $this->seedAccess();
        $access->forceFill(['expires_at' => now()->subMinutes(5)])->save(); // completed activation

        $this->actingAs($user)
            ->from('/emar/emergency-access')
            ->post("/emar/clients/{$client->id}/break-glass/{$access->id}/review", [
                'review_outcome' => 'justified',
                'review_notes' => 'Appropriate emergency use',
                'incident_report_linked' => true,
            ])
            ->assertSessionHasNoErrors();

        $access->refresh();
        $this->assertSame('justified', $access->review_outcome);
        $this->assertSame($user->id, $access->reviewed_by);
        $this->assertNotNull($access->reviewed_at);
        $this->assertTrue($access->incident_report_linked);
    }

    public function test_review_is_denied_without_audit_permission(): void
    {
        ['client' => $client, 'access' => $access] = $this->seedAccess();
        $plain = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->grantPermissions($plain, ['medications.breakglass']);

        // Reviewer must hold medications.audit.view (route-gated). A break-glass-only
        // user is blocked, so the activation is never marked reviewed.
        $response = $this->actingAs($plain)
            ->post("/emar/clients/{$client->id}/break-glass/{$access->id}/review", ['review_outcome' => 'justified']);

        $this->assertContains($response->status(), [403, 404]);
        $this->assertNull($access->refresh()->review_outcome);
    }

    public function test_page_serves_approvers_and_awaiting_review(): void
    {
        ['user' => $user, 'site' => $site, 'access' => $access] = $this->seedAccess();
        User::factory()->create(['organization_id' => $user->organization_id, 'approved_at' => now()]);
        $access->forceFill(['expires_at' => now()->subMinutes(5)])->save(); // expired, unreviewed

        $this->actingAs($user)
            ->get('/emar/emergency-access?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_review', true)
                ->has('approvers')
                ->where('stats.awaiting_review', 1)
            );
    }

    public function test_request_client_query_prefills_the_request_wizard(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedAccess();

        $this->actingAs($user)
            ->get('/emar/emergency-access?request_client='.$client->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('request_client.id', $client->id)
                ->where('request_client.first_name', $client->first_name)
                ->where('request_client.last_name', $client->last_name)
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
