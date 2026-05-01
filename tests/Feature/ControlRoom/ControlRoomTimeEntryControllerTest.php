<?php

namespace Tests\Feature\ControlRoom;

use App\Models\ControlRoom\TimeEntry;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomTimeEntryControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected ControlRoomAlert $alert;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->alert = ControlRoomAlert::factory()->open()->create();
    }

    public function test_index_requires_manage_permission(): void
    {
        $stranger = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($stranger)
            ->getJson("/control-room/alerts/{$this->alert->id}/time-entries")
            ->assertForbidden();
    }

    public function test_index_lists_entries_and_running_entry(): void
    {
        TimeEntry::create([
            'alert_id' => $this->alert->id,
            'user_id' => $this->admin->id,
            'started_at' => now()->subMinutes(10),
        ]);

        $this->actingAs($this->admin)
            ->getJson("/control-room/alerts/{$this->alert->id}/time-entries")
            ->assertOk()
            ->assertJsonCount(1, 'entries')
            ->assertJsonPath('running_entry.id', TimeEntry::first()->id);
    }

    public function test_start_creates_a_running_entry(): void
    {
        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$this->alert->id}/time-entries/start")
            ->assertStatus(201);

        $this->assertDatabaseHas('control_room_time_entries', [
            'alert_id' => $this->alert->id,
            'user_id' => $this->admin->id,
            'ended_at' => null,
        ]);
    }

    public function test_start_blocks_second_running_timer_for_same_user(): void
    {
        TimeEntry::create([
            'alert_id' => $this->alert->id,
            'user_id' => $this->admin->id,
            'started_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($this->admin)
            ->postJson("/control-room/alerts/{$this->alert->id}/time-entries/start")
            ->assertStatus(422);
    }

    public function test_stop_closes_a_running_entry_and_aggregates_alert(): void
    {
        $entry = TimeEntry::create([
            'alert_id' => $this->alert->id,
            'user_id' => $this->admin->id,
            'started_at' => now()->subMinutes(15),
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/time-entries/{$entry->id}/stop", ['description' => 'Triage time'])
            ->assertOk();

        $entry->refresh();
        $this->assertNotNull($entry->ended_at);
        $this->assertGreaterThan(0, $entry->duration_minutes);
        $this->assertSame('Triage time', $entry->description);

        $this->alert->refresh();
        $this->assertGreaterThan(0, $this->alert->time_spent_minutes);
    }

    public function test_stop_rejects_already_stopped_entry(): void
    {
        $entry = TimeEntry::create([
            'alert_id' => $this->alert->id,
            'user_id' => $this->admin->id,
            'started_at' => now()->subMinutes(20),
            'ended_at' => now()->subMinutes(5),
            'duration_minutes' => 15,
        ]);

        $this->actingAs($this->admin)
            ->postJson("/control-room/time-entries/{$entry->id}/stop")
            ->assertStatus(422);
    }

    public function test_store_creates_manual_entry(): void
    {
        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$this->alert->id}/time-entries", [
                'duration_minutes' => 30,
                'description' => 'Reviewed logs',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('control_room_time_entries', [
            'alert_id' => $this->alert->id,
            'user_id' => $this->admin->id,
            'duration_minutes' => 30,
        ]);
    }

    public function test_store_validates_duration(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/control-room/alerts/{$this->alert->id}/time-entries", [
                'duration_minutes' => 0,
            ])
            ->assertStatus(422);
    }

    public function test_destroy_deletes_entry_and_recalculates_alert(): void
    {
        $entry = TimeEntry::create([
            'alert_id' => $this->alert->id,
            'user_id' => $this->admin->id,
            'started_at' => now()->subMinutes(15),
            'ended_at' => now()->subMinutes(5),
            'duration_minutes' => 10,
        ]);
        $this->alert->update(['time_spent_minutes' => 10]);

        $this->actingAs($this->admin)
            ->delete("/control-room/time-entries/{$entry->id}")
            ->assertOk();

        $this->assertDatabaseMissing('control_room_time_entries', ['id' => $entry->id]);
        $this->assertSame(0, (int) $this->alert->fresh()->time_spent_minutes);
    }
}
