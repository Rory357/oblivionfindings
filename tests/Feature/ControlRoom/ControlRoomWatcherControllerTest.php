<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ControlRoom\AlertWatcher;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ControlRoomWatcherControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $other;

    protected Site $site;

    protected ControlRoomAlert $alert;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->other = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->other->roles()->attach(Role::where('name', 'admin')->first());

        $this->site = Site::factory()->create([
            'name' => 'Control Room Watcher Site',
        ]);
        foreach ([$this->admin, $this->other] as $user) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $user->id,
                'primary_site_id' => $this->site->id,
                'secondary_site_ids' => [],
                'start_date' => today()->subMonth(),
                'end_date' => null,
                'is_active' => true,
            ]);
        }

        $this->alert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $this->site->id,
        ]);
    }

    public function test_index_requires_manage_permission(): void
    {
        $stranger = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($stranger)
            ->getJson("/control-room/alerts/{$this->alert->id}/watchers")
            ->assertForbidden();
    }

    public function test_index_returns_watchers(): void
    {
        $watcher = AlertWatcher::create([
            'alert_id' => $this->alert->id,
            'user_id' => $this->other->id,
            'added_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->getJson("/control-room/alerts/{$this->alert->id}/watchers")
            ->assertOk()
            ->assertJsonCount(1, 'watchers')
            ->assertJsonPath('watchers.0.user_id', $this->other->id);
    }

    public function test_store_adds_watcher(): void
    {
        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$this->alert->id}/watchers", [
                'user_id' => $this->other->id,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('control_room_alert_watchers', [
            'alert_id' => $this->alert->id,
            'user_id' => $this->other->id,
        ]);
    }

    public function test_store_rejects_duplicate_watcher(): void
    {
        $watcher = AlertWatcher::create([
            'alert_id' => $this->alert->id,
            'user_id' => $this->other->id,
            'added_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$this->alert->id}/watchers", [
                'user_id' => $this->other->id,
            ])
            ->assertStatus(422);
    }

    public function test_store_validates_user_id(): void
    {
        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$this->alert->id}/watchers", [
                'user_id' => 99999,
            ])
            ->assertSessionHasErrors('user_id');
    }

    public function test_toggle_adds_then_removes_self(): void
    {
        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$this->alert->id}/watchers/toggle")
            ->assertOk()
            ->assertJson(['watching' => true]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$this->alert->id}/watchers/toggle")
            ->assertOk()
            ->assertJson(['watching' => false]);

        $this->assertDatabaseMissing('control_room_alert_watchers', [
            'alert_id' => $this->alert->id,
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_destroy_removes_watcher(): void
    {
        $watcher = AlertWatcher::create([
            'alert_id' => $this->alert->id,
            'user_id' => $this->other->id,
            'added_by_user_id' => $this->admin->id,
        ]);
        // Mirror the increment side-effect that controller::store() would have
        // performed; otherwise destroy's decrement underflows the unsigned column.
        $this->alert->update(['watchers_count' => 1]);

        $this->actingAs($this->admin)
            ->delete("/control-room/alerts/{$this->alert->id}/watchers/{$watcher->id}")
            ->assertOk();

        $this->assertDatabaseMissing('control_room_alert_watchers', [
            'alert_id' => $this->alert->id,
            'user_id' => $this->other->id,
        ]);
    }

    public function test_destroy_returns_404_for_nonexistent_watcher(): void
    {
        $this->actingAs($this->admin)
            ->delete("/control-room/alerts/{$this->alert->id}/watchers/99999")
            ->assertNotFound();
    }

    public function test_destroy_replay_does_not_decrement_or_audit_twice(): void
    {
        $watcher = AlertWatcher::create([
            'alert_id' => $this->alert->id,
            'user_id' => $this->other->id,
            'added_by_user_id' => $this->admin->id,
        ]);
        $this->alert->update(['watchers_count' => 1]);

        $url = "/control-room/alerts/{$this->alert->id}/watchers/{$watcher->id}";
        $this->actingAs($this->admin)->delete($url)->assertOk();
        $this->actingAs($this->admin)->delete($url)->assertNotFound();

        $this->assertSame(0, $this->alert->fresh()->watchers_count);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('action', 'controlRoom.watcher.removed')
            ->where('auditable_id', $this->alert->id)
            ->count());
    }

    public function test_destroy_redirects_back_for_inertia_requests(): void
    {
        $watcher = AlertWatcher::create([
            'alert_id' => $this->alert->id,
            'user_id' => $this->other->id,
            'added_by_user_id' => $this->admin->id,
        ]);
        $this->alert->update(['watchers_count' => 1]);

        // The workspace removes watchers via an Inertia router.delete — the
        // endpoint must redirect back, not return bare JSON (which would break
        // the Inertia visit).
        $this->actingAs($this->admin)
            ->withHeader('X-Inertia', 'true')
            ->delete("/control-room/alerts/{$this->alert->id}/watchers/{$watcher->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('control_room_alert_watchers', [
            'alert_id' => $this->alert->id,
            'user_id' => $this->other->id,
        ]);
    }
}
