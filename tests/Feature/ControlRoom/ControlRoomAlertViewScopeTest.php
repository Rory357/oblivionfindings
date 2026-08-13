<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomAlertViewScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_site_bound_view_only_worker_receives_only_matching_rows_counts_and_no_creation_datasets(): void
    {
        $siteA = Site::factory()->create(['name' => 'Visible Kauri House']);
        $siteB = Site::factory()->create(['name' => 'Hidden Rimu House']);
        $viewer = $this->siteBoundRoleUser($siteA, 'support_worker');
        $queue = $this->queue();
        $visibleClient = Client::factory()->create([
            'site_id' => $siteA->id,
            'first_name' => 'Visible',
            'last_name' => 'Resident',
        ]);
        $hiddenClient = Client::factory()->create([
            'site_id' => $siteB->id,
            'first_name' => 'Hidden',
            'last_name' => 'Resident',
        ]);
        $visible = ControlRoomAlert::factory()->open()->critical()->create([
            'site_id' => $siteA->id,
            'client_id' => $visibleClient->id,
            'queue_id' => $queue->id,
        ]);
        $hidden = ControlRoomAlert::factory()->open()->critical()->create([
            'site_id' => $siteB->id,
            'client_id' => $hiddenClient->id,
            'queue_id' => $queue->id,
        ]);

        $response = $this->actingAs($viewer)->get('/control-room/alerts');

        $response
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/alerts/index')
                ->has('alerts.data', 1)
                ->where('alerts.data.0.id', $visible->id)
                ->where('stats.total', 1)
                ->where('stats.open', 1)
                ->where('stats.critical', 1)
                ->where('stats.unassigned', 1)
                ->where('queues.0.active_alerts', 1)
                ->where('can.create', false)
                ->missing('clients')
                ->missing('sites'));

        $props = $response->inertiaProps();
        $this->assertNotContains($hidden->id, collect(data_get($props, 'alerts.data'))->pluck('id'));
        $this->assertArrayNotHasKey('clients', $props);
        $this->assertArrayNotHasKey('sites', $props);
        $this->assertFalse($viewer->canDo('controlRoom.viewAny'));
        $this->assertTrue($viewer->canDo('controlRoom.alerts.view'));
    }

    public function test_create_authorised_site_actor_receives_only_relational_options_from_accessible_sites(): void
    {
        $siteA = Site::factory()->create(['name' => 'Allowed Totara House']);
        $siteB = Site::factory()->create(['name' => 'Foreign Nikau House']);
        $creator = $this->siteBoundPermissionUser($siteA, [
            'controlRoom.alerts.view',
            'controlRoom.alerts.create',
        ]);
        $allowedClient = Client::factory()->create([
            'site_id' => $siteA->id,
            'first_name' => 'Allowed',
            'last_name' => 'Person',
        ]);
        $foreignClient = Client::factory()->create([
            'site_id' => $siteB->id,
            'first_name' => 'Foreign',
            'last_name' => 'Person',
        ]);

        $response = $this->actingAs($creator)->get('/control-room/alerts');

        $response
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.create', true)
                ->has('sites', 1)
                ->where('sites.0.id', $siteA->id)
                ->has('clients', 1)
                ->where('clients.0.id', $allowedClient->id)
                ->where('clients.0.site_id', $siteA->id));

        $props = $response->inertiaProps();
        $this->assertNotContains($siteB->id, collect($props['sites'])->pluck('id'));
        $this->assertNotContains($foreignClient->id, collect($props['clients'])->pluck('id'));
        $this->assertNotContains('Foreign Nikau House', collect($props['sites'])->pluck('name'));
        $this->assertNotContains('Foreign Person', collect($props['clients'])->pluck('name'));

        $this->actingAs($creator)
            ->postJson('/control-room/alerts', [
                'source' => 'manual',
                'alert_type' => 'welfare_check',
                'severity' => 'high',
                'client_id' => $foreignClient->id,
                'site_id' => $siteB->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_explicit_application_wide_role_retains_rows_counts_and_creation_options_for_all_sites(): void
    {
        $siteA = Site::factory()->create(['name' => 'Kowhai House']);
        $siteB = Site::factory()->create(['name' => 'Pohutukawa House']);
        $globalOperator = $this->siteBoundRoleUser($siteA, 'provider_manager');
        $queue = $this->queue();
        $clientA = Client::factory()->create(['site_id' => $siteA->id]);
        $clientB = Client::factory()->create(['site_id' => $siteB->id]);
        ControlRoomAlert::factory()->open()->create([
            'site_id' => $siteA->id,
            'client_id' => $clientA->id,
            'queue_id' => $queue->id,
        ]);
        ControlRoomAlert::factory()->open()->create([
            'site_id' => $siteB->id,
            'client_id' => $clientB->id,
            'queue_id' => $queue->id,
        ]);

        $this->assertTrue($globalOperator->canDo('reports.viewAny'));

        $this->actingAs($globalOperator)
            ->get('/control-room/alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('alerts.data', 2)
                ->where('stats.total', 2)
                ->where('queues.0.active_alerts', 2)
                ->has('sites', 2)
                ->has('clients', 2)
                ->where('can.create', true));
    }

    public function test_wrong_site_show_and_mutation_are_concealed_without_state_or_audit_side_effects(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $manager = $this->siteBoundRoleUser($siteA, 'coordinator');
        $foreign = ControlRoomAlert::factory()->open()->create(['site_id' => $siteB->id]);
        $auditCount = AuditLog::query()->count();

        $this->actingAs($manager)
            ->get("/control-room/alerts/{$foreign->id}")
            ->assertNotFound();

        $this->actingAs($manager)
            ->post("/control-room/alerts/{$foreign->id}/acknowledge", [
                'notes' => 'Must not be written',
            ])
            ->assertNotFound();

        $foreign->refresh();
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $foreign->status);
        $this->assertNull($foreign->acknowledged_at);
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_mixed_site_bulk_acknowledgement_fails_before_any_mutation(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $manager = $this->siteBoundRoleUser($siteA, 'coordinator');
        $visible = ControlRoomAlert::factory()->open()->create(['site_id' => $siteA->id]);
        $foreign = ControlRoomAlert::factory()->open()->create(['site_id' => $siteB->id]);
        $auditCount = AuditLog::query()->count();

        $this->actingAs($manager)
            ->post('/control-room/alerts/bulk-acknowledge', [
                'alert_ids' => [$visible->id, $foreign->id],
            ])
            ->assertForbidden();

        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $visible->refresh()->status);
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $foreign->refresh()->status);
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    private function queue(): TriageQueue
    {
        return TriageQueue::query()->create([
            'name' => 'Scoped alert queue',
            'code' => 'scoped-alert-queue',
            'tier' => 1,
            'is_active' => true,
        ]);
    }

    private function siteBoundRoleUser(Site $site, string $roleName): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());
        $this->scopeUserToSite($user, $site);

        return $user;
    }

    /** @param list<string> $permissionKeys */
    private function siteBoundPermissionUser(Site $site, array $permissionKeys): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        $permissions = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
            ->all();
        $user->permissionOverrides()->sync($permissions);
        $this->scopeUserToSite($user, $site);

        return $user;
    }

    private function scopeUserToSite(User $user, Site $site): void
    {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);
    }
}
