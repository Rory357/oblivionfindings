<?php

namespace Tests\Feature\Emar;

use App\Models\BreakGlassAccessEvent;
use App\Models\BreakGlassFlagDismissal;
use App\Models\BreakGlassPolicy;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientIncident;
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
            ])
            ->assertSessionHasNoErrors();

        $access->refresh();
        $this->assertSame('justified', $access->review_outcome);
        $this->assertSame($user->id, $access->reviewed_by);
        $this->assertNotNull($access->reviewed_at);
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

    public function test_policy_payload_falls_back_to_constant_defaults(): void
    {
        ['user' => $user, 'site' => $site] = $this->seedAccess();

        $this->actingAs($user)
            ->get('/emar/emergency-access?site_id='.$site->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('policy.default_minutes', ClientBreakGlassAccess::DEFAULT_MINUTES)
                ->where('policy.max_minutes', ClientBreakGlassAccess::MAX_MINUTES)
                ->where('policy.repeat_threshold_count', 4)
                ->where('policy.repeat_window_days', 7)
                ->where('can_edit_policy', true)
            );
    }

    public function test_admin_updates_org_policy_and_it_is_served_and_enforced(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedAccess();

        $this->actingAs($user)
            ->from('/emar/emergency-access')
            ->put('/emar/break-glass-policy', [
                'default_minutes' => 45,
                'max_minutes' => 90,
                'extend_minutes' => 15,
                'reason_required' => true,
                'repeat_threshold_count' => 2,
                'repeat_window_days' => 3,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('break_glass_policies', [
            'organization_id' => $user->organization_id,
            'default_minutes' => 45,
            'max_minutes' => 90,
            'repeat_threshold_count' => 2,
        ]);

        // Enforced: a grant beyond the new max is rejected by validation.
        $this->actingAs($user)
            ->from('/emar/emergency-access')
            ->post("/clients/{$client->id}/break-glass", ['reason' => 'x', 'minutes' => 200])
            ->assertSessionHasErrors('minutes');

        // Served back to the page.
        $this->actingAs($user)
            ->get('/emar/emergency-access')
            ->assertInertia(fn (Assert $page) => $page->where('policy.max_minutes', 90));
    }

    public function test_policy_update_is_denied_for_non_admin(): void
    {
        $this->seed(RbacSeeder::class);
        $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $worker->roles()->syncWithoutDetaching([Role::query()->where('name', 'support_worker')->first()->id]);
        $this->grantPermissions($worker, ['medications.breakglass']);

        $this->actingAs($worker)
            ->put('/emar/break-glass-policy', [
                'default_minutes' => 45, 'max_minutes' => 90, 'extend_minutes' => 15,
                'reason_required' => true, 'repeat_threshold_count' => 2, 'repeat_window_days' => 3,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('break_glass_policies', 0);
    }

    public function test_repeat_threshold_from_policy_drives_the_flag(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedAccess();
        BreakGlassPolicy::query()->create(array_merge(BreakGlassPolicy::defaults(), [
            'organization_id' => $user->organization_id,
            'repeat_threshold_count' => 2,
        ]));
        // seedAccess already created one grant by $user; a second crosses the lowered threshold.
        ClientBreakGlassAccess::query()->create([
            'client_id' => $client->id, 'user_id' => $user->id,
            'reason' => 'second activation', 'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($user)
            ->get('/emar/emergency-access')
            ->assertInertia(fn (Assert $page) => $page
                ->has('flaggedSignals', 1)
                ->where('flaggedSignals.0.type', 'repeat')
            );
    }

    public function test_reviewer_can_acknowledge_repeat_signal_and_it_is_suppressed(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedAccess();
        BreakGlassPolicy::query()->create(array_merge(BreakGlassPolicy::defaults(), [
            'organization_id' => $user->organization_id, 'repeat_threshold_count' => 2,
        ]));
        ClientBreakGlassAccess::query()->create([
            'client_id' => $client->id, 'user_id' => $user->id, 'reason' => 'second', 'expires_at' => now()->addHour(),
        ]);

        // Repeat signal present.
        $this->actingAs($user)->get('/emar/emergency-access')
            ->assertInertia(fn (Assert $page) => $page->has('flaggedSignals', 1)->where('flaggedSignals.0.type', 'repeat'));

        // Acknowledge it.
        $this->actingAs($user)
            ->from('/emar/emergency-access')
            ->post('/emar/break-glass-flags/dismiss', ['type' => 'repeat', 'key' => (string) $user->id, 'reason' => 'Genuine cover'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('break_glass_flag_dismissals', [
            'signal_type' => 'repeat', 'signal_key' => (string) $user->id, 'dismissed_by' => $user->id,
        ]);

        // Now suppressed.
        $this->actingAs($user)->get('/emar/emergency-access')
            ->assertInertia(fn (Assert $page) => $page->has('flaggedSignals', 0));
    }

    public function test_acknowledged_signal_resurfaces_when_newer_activity_exists(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedAccess();
        BreakGlassPolicy::query()->create(array_merge(BreakGlassPolicy::defaults(), [
            'organization_id' => $user->organization_id, 'repeat_threshold_count' => 2,
        ]));
        ClientBreakGlassAccess::query()->create([
            'client_id' => $client->id, 'user_id' => $user->id, 'reason' => 'second', 'expires_at' => now()->addHour(),
        ]);

        // A stale acknowledgement (before the latest activity) must NOT suppress the signal.
        BreakGlassFlagDismissal::query()->create([
            'organization_id' => $user->organization_id, 'signal_type' => 'repeat', 'signal_key' => (string) $user->id,
            'dismissed_by' => $user->id, 'dismissed_through' => now()->subMinutes(30),
        ]);

        $this->actingAs($user)->get('/emar/emergency-access')
            ->assertInertia(fn (Assert $page) => $page->has('flaggedSignals', 1)->where('flaggedSignals.0.type', 'repeat'));
    }

    public function test_flag_dismissal_requires_audit_permission(): void
    {
        $this->seed(RbacSeeder::class);
        $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $worker->roles()->syncWithoutDetaching([Role::query()->where('name', 'support_worker')->first()->id]);
        $this->grantPermissions($worker, ['medications.breakglass']);

        $response = $this->actingAs($worker)
            ->post('/emar/break-glass-flags/dismiss', ['type' => 'repeat', 'key' => '1']);

        $this->assertContains($response->status(), [403, 404]);
        $this->assertDatabaseCount('break_glass_flag_dismissals', 0);
    }

    public function test_review_links_a_real_incident_report(): void
    {
        ['user' => $user, 'client' => $client, 'access' => $access] = $this->seedAccess();
        $access->forceFill(['expires_at' => now()->subMinutes(5)])->save();
        $incident = ClientIncident::factory()->create(['client_id' => $client->id]);

        $this->actingAs($user)
            ->from('/emar/emergency-access')
            ->post("/emar/clients/{$client->id}/break-glass/{$access->id}/review", [
                'review_outcome' => 'justified',
                'incident_report_id' => $incident->id,
            ])
            ->assertSessionHasNoErrors();

        $access->refresh();
        $this->assertSame($incident->id, $access->incident_report_id);
        $this->assertTrue((bool) $access->incident_report_linked);
    }

    public function test_review_rejects_incident_from_another_client(): void
    {
        ['user' => $user, 'client' => $client, 'access' => $access] = $this->seedAccess();
        $other = Client::factory()->create(['site_id' => $client->site_id, 'status' => 'active']);
        $foreignIncident = ClientIncident::factory()->create(['client_id' => $other->id]);

        $this->actingAs($user)
            ->from('/emar/emergency-access')
            ->post("/emar/clients/{$client->id}/break-glass/{$access->id}/review", [
                'review_outcome' => 'justified',
                'incident_report_id' => $foreignIncident->id,
            ])
            ->assertSessionHasErrors('incident_report_id');
    }

    public function test_access_scope_event_is_recorded_and_surfaced(): void
    {
        ['user' => $user, 'client' => $client, 'access' => $access] = $this->seedAccess();

        BreakGlassAccessEvent::recordFor($user, $client, 'viewed_mar');
        BreakGlassAccessEvent::recordFor($user, $client, 'recorded_dose', 'Paracetamol · given');

        $this->assertDatabaseHas('break_glass_access_events', ['break_glass_access_id' => $access->id, 'action' => 'viewed_mar']);
        $this->assertDatabaseHas('break_glass_access_events', ['break_glass_access_id' => $access->id, 'action' => 'recorded_dose', 'detail' => 'Paracetamol · given']);

        // Surfaced on the audit row for the review modal.
        $this->actingAs($user)
            ->get('/emar/emergency-access')
            ->assertInertia(fn (Assert $page) => $page->has('auditLog.0.events', 2));
    }

    public function test_access_scope_record_is_noop_without_active_grant(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedAccess();
        $other = Client::factory()->create(['site_id' => $client->site_id, 'status' => 'active']);

        // No grant for this user on $other → nothing recorded.
        BreakGlassAccessEvent::recordFor($user, $other, 'viewed_mar');

        $this->assertDatabaseCount('break_glass_access_events', 0);
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
