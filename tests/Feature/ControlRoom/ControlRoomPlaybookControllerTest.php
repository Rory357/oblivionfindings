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

    public function test_store_generates_code_when_omitted(): void
    {
        // The guided wizard never sends `code` (it's an internal handle), but
        // the column is NOT NULL — the controller must derive a unique one.
        $this->actingAs($this->admin)
            ->post('/control-room/playbooks', [
                'name' => 'Door Left Open Response',
                'category' => 'safety',
                'steps' => [['title' => 'Check the door on camera', 'type' => 'task']],
            ])
            ->assertRedirect();

        $playbook = Playbook::where('name', 'Door Left Open Response')->first();
        $this->assertNotNull($playbook);
        $this->assertNotNull($playbook->code, 'A code must be generated when none is supplied.');
        $this->assertSame('door_left_open_response', $playbook->code);
    }

    public function test_update_keeps_code_when_omitted(): void
    {
        // The edit wizard doesn't send `code` — update() must not null the
        // NOT NULL column; it keeps the existing code.
        $playbook = Playbook::create([
            'name' => 'Keep Code', 'code' => 'keep_code', 'category' => 'safety',
            'version' => 1, 'is_active' => true,
        ]);
        $step = PlaybookStep::create(['playbook_id' => $playbook->id, 'order' => 1, 'title' => 'S1', 'type' => 'task']);

        $this->actingAs($this->admin)
            ->put("/control-room/playbooks/{$playbook->id}", [
                'name' => 'Keep Code Renamed',
                'category' => 'safety',
                'steps' => [['id' => $step->id, 'title' => 'S1 edited', 'type' => 'task']],
            ])
            ->assertRedirect();

        $playbook->refresh();
        $this->assertSame('Keep Code Renamed', $playbook->name);
        $this->assertSame('keep_code', $playbook->code, 'Existing code must survive an update that omits it.');
        $this->assertSame(2, $playbook->version);
    }

    public function test_store_generated_code_is_unique(): void
    {
        $payload = [
            'name' => 'Duplicate Name',
            'category' => 'emergency',
            'steps' => [['title' => 'Do a thing', 'type' => 'task']],
        ];
        $this->actingAs($this->admin)->post('/control-room/playbooks', $payload)->assertRedirect();
        $this->actingAs($this->admin)->post('/control-room/playbooks', $payload)->assertRedirect();

        $codes = Playbook::where('name', 'Duplicate Name')->pluck('code');
        $this->assertCount(2, $codes);
        $this->assertSame($codes->count(), $codes->unique()->count(), 'Generated codes must be unique.');
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
