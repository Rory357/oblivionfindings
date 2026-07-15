<?php

namespace Tests\Feature\ControlRoom;

use App\Models\ControlRoom\AlertDiscussion;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomDiscussionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $other;

    protected ControlRoomAlert $alert;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->other = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->other->roles()->attach(Role::where('name', 'admin')->first());

        $site = Site::factory()->create([
            'tenant_id' => $this->admin->organization_id,
        ]);
        $this->alert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $site->id,
        ]);
    }

    public function test_index_requires_view_permission(): void
    {
        $stranger = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($stranger)
            ->getJson("/control-room/alerts/{$this->alert->id}/discussions")
            ->assertForbidden();
    }

    public function test_index_returns_threaded_discussions(): void
    {
        AlertDiscussion::create([
            'alert_id' => $this->alert->id,
            'user_id' => $this->admin->id,
            'content' => 'Top-level note',
            'type' => 'comment',
            'is_internal' => true,
        ]);

        $this->actingAs($this->admin)
            ->getJson("/control-room/alerts/{$this->alert->id}/discussions")
            ->assertOk()
            ->assertJsonCount(1, 'discussions');
    }

    public function test_store_creates_discussion(): void
    {
        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$this->alert->id}/discussions", [
                'content' => 'New investigation note',
                'type' => 'internal_note',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('control_room_alert_discussions', [
            'alert_id' => $this->alert->id,
            'user_id' => $this->admin->id,
            'content' => 'New investigation note',
            'type' => 'internal_note',
        ]);
    }

    public function test_store_validates_content(): void
    {
        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$this->alert->id}/discussions", [])
            ->assertSessionHasErrors('content');
    }

    public function test_owner_can_edit_own_discussion(): void
    {
        $discussion = AlertDiscussion::create([
            'alert_id' => $this->alert->id,
            'user_id' => $this->admin->id,
            'content' => 'Original',
            'type' => 'comment',
            'is_internal' => true,
        ]);

        $this->actingAs($this->admin)
            ->put("/control-room/discussions/{$discussion->id}", [
                'content' => 'Edited',
            ])
            ->assertOk();

        $discussion->refresh();
        $this->assertSame('Edited', $discussion->content);
        $this->assertNotNull($discussion->edited_at);
    }

    public function test_non_owner_cannot_edit_discussion(): void
    {
        $discussion = AlertDiscussion::create([
            'alert_id' => $this->alert->id,
            'user_id' => $this->other->id,
            'content' => 'Other person wrote this',
            'type' => 'comment',
            'is_internal' => true,
        ]);

        $this->actingAs($this->admin)
            ->put("/control-room/discussions/{$discussion->id}", [
                'content' => 'Should not be allowed',
            ])
            ->assertForbidden();
    }

    public function test_owner_can_soft_delete_own_discussion(): void
    {
        $discussion = AlertDiscussion::create([
            'alert_id' => $this->alert->id,
            'user_id' => $this->admin->id,
            'content' => 'To delete',
            'type' => 'comment',
            'is_internal' => true,
        ]);

        $this->actingAs($this->admin)
            ->delete("/control-room/discussions/{$discussion->id}")
            ->assertOk();

        $this->assertSame('[deleted]', $discussion->fresh()->content);
    }

    public function test_manager_can_soft_delete_others_discussion(): void
    {
        $discussion = AlertDiscussion::create([
            'alert_id' => $this->alert->id,
            'user_id' => $this->other->id,
            'content' => 'Owned by other',
            'type' => 'comment',
            'is_internal' => true,
        ]);

        $this->actingAs($this->admin)
            ->delete("/control-room/discussions/{$discussion->id}")
            ->assertOk();

        $this->assertSame('[deleted]', $discussion->fresh()->content);
    }
}
