<?php

namespace Tests\Feature\ControlRoom;

use App\Models\ControlRoom\Playbook;
use App\Models\ControlRoom\PlaybookRun;
use App\Models\ControlRoom\PlaybookStep;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomPlaybookControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $supportWorker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());
    }

    public function test_index_requires_view_permission(): void
    {
        $stranger = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($stranger)
            ->get('/control-room/playbooks')
            ->assertForbidden();
    }

    public function test_index_lists_playbooks(): void
    {
        Playbook::create([
            'code' => 'fire-response',
            'name' => 'Fire Response',
            'category' => 'emergency',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get('/control-room/playbooks')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/playbooks/index')
                ->has('playbooks', 1)
                ->where('playbooks.0.name', 'Fire Response')
                ->has('can')
            );
    }

    public function test_show_renders_playbook_detail(): void
    {
        $playbook = Playbook::create([
            'code' => 'pb-test',
            'name' => 'Test Playbook',
            'category' => 'safety',
            'is_active' => true,
        ]);
        PlaybookStep::create([
            'playbook_id' => $playbook->id,
            'order' => 1,
            'title' => 'Step 1',
            'type' => 'task',
        ]);

        $this->actingAs($this->admin)
            ->get("/control-room/playbooks/{$playbook->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/playbooks/show')
                ->where('playbook.id', $playbook->id)
                ->has('playbook.steps', 1)
            );
    }

    public function test_store_creates_playbook_with_steps(): void
    {
        $this->actingAs($this->admin)
            ->post('/control-room/playbooks', [
                'name' => 'Lockdown',
                'code' => 'lockdown',
                'category' => 'emergency',
                'steps' => [
                    ['title' => 'Sound alarm', 'type' => 'task'],
                    ['title' => 'Notify lead', 'type' => 'notification'],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('control_room_playbooks', [
            'name' => 'Lockdown',
            'category' => 'emergency',
        ]);

        $playbook = Playbook::where('name', 'Lockdown')->first();
        $this->assertSame(2, $playbook->steps()->count());
    }

    public function test_store_blocked_without_manage_permission(): void
    {
        $this->actingAs($this->supportWorker)
            ->post('/control-room/playbooks', [
                'name' => 'Should fail',
                'category' => 'emergency',
                'steps' => [['title' => 'Step', 'type' => 'task']],
            ])
            ->assertForbidden();
    }

    public function test_store_validates_steps(): void
    {
        $this->actingAs($this->admin)
            ->post('/control-room/playbooks', [
                'name' => 'No Steps',
                'category' => 'emergency',
                'steps' => [],
            ])
            ->assertSessionHasErrors('steps');
    }

    public function test_toggle_active_flips_state(): void
    {
        $playbook = Playbook::create([
            'code' => 'tog',
            'name' => 'Toggle',
            'category' => 'safety',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/playbooks/{$playbook->id}/toggle-active")
            ->assertRedirect();

        $this->assertFalse($playbook->fresh()->is_active);
    }

    public function test_start_run_creates_in_progress_run(): void
    {
        $playbook = Playbook::create([
            'code' => 'pb-run',
            'name' => 'Runnable',
            'category' => 'safety',
            'is_active' => true,
        ]);
        PlaybookStep::create([
            'playbook_id' => $playbook->id,
            'order' => 1,
            'title' => 'Step',
            'type' => 'task',
        ]);

        $alert = ControlRoomAlert::factory()->open()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/playbook/start", [
                'playbook_id' => $playbook->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('control_room_playbook_runs', [
            'alert_id' => $alert->id,
            'playbook_id' => $playbook->id,
        ]);
    }

    public function test_start_run_blocks_inactive_playbook(): void
    {
        $playbook = Playbook::create([
            'code' => 'inactive',
            'name' => 'Off',
            'category' => 'safety',
            'is_active' => false,
        ]);
        $alert = ControlRoomAlert::factory()->open()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/playbook/start", [
                'playbook_id' => $playbook->id,
            ])
            ->assertSessionHasErrors('playbook');
    }
}
