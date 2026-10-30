<?php

namespace Tests\Feature\ControlRoom;

use App\Models\ControlRoom\Playbook;
use App\Models\ControlRoom\PlaybookStep;
use App\Models\ControlRoom\SignalType;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A procedure with "Start on its own" switched on has to say which alert
 * types and severities start it (Stephan, 24 Oct 2026): an empty list never
 * matches an alert, so it would only ever start by hand. Procedures already
 * saved that way are left as they are and flagged.
 */
class ControlRoomPlaybookAutoStartTriggersTest extends TestCase
{
    use RefreshDatabase;

    private const TYPES_MESSAGE = 'Choose at least one alert type that starts this procedure on its own.';

    private const SEVERITIES_MESSAGE = 'Choose at least one severity that starts this procedure on its own.';

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->firstOrFail());
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Fall at the house',
            'category' => 'safety',
            'steps' => [['title' => 'Call the house lead', 'type' => 'task']],
        ], $overrides);
    }

    private function savedPlaybook(array $attributes = []): Playbook
    {
        $playbook = Playbook::create(array_merge([
            'code' => 'pb_'.uniqid(),
            'name' => 'Saved procedure '.uniqid(),
            'category' => 'safety',
            'version' => 1,
            'is_active' => true,
        ], $attributes));
        PlaybookStep::create([
            'playbook_id' => $playbook->id,
            'order' => 1,
            'title' => 'Call the house lead',
            'type' => 'task',
        ]);

        return $playbook->fresh();
    }

    // ── Adding ────────────────────────────────────────────────────────

    public function test_adding_an_auto_start_procedure_without_alert_types_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post('/control-room/playbooks', $this->payload([
                'name' => 'No alert types',
                'auto_attach' => true,
                'trigger_alert_types' => [],
                'trigger_severities' => ['high'],
            ]))
            ->assertSessionHasErrors(['trigger_alert_types' => self::TYPES_MESSAGE])
            ->assertSessionDoesntHaveErrors(['trigger_severities']);

        $this->assertDatabaseMissing('control_room_playbooks', ['name' => 'No alert types']);
    }

    public function test_adding_an_auto_start_procedure_without_severities_answers_422(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/control-room/playbooks', $this->payload([
                'name' => 'No severities',
                'auto_attach' => true,
                'trigger_alert_types' => ['Fall Detected'],
                'trigger_severities' => [],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['trigger_severities' => self::SEVERITIES_MESSAGE])
            ->assertJsonMissingValidationErrors(['trigger_alert_types']);

        $this->assertDatabaseMissing('control_room_playbooks', ['name' => 'No severities']);
    }

    public function test_adding_an_auto_start_procedure_with_neither_list_names_both(): void
    {
        $this->actingAs($this->admin)
            ->post('/control-room/playbooks', $this->payload([
                'name' => 'Lists left out',
                'auto_attach' => true,
            ]))
            ->assertSessionHasErrors([
                'trigger_alert_types' => self::TYPES_MESSAGE,
                'trigger_severities' => self::SEVERITIES_MESSAGE,
            ]);

        $this->assertDatabaseMissing('control_room_playbooks', ['name' => 'Lists left out']);
    }

    public function test_adding_an_auto_start_procedure_with_its_alerts_saves_it_and_it_starts_on_its_own(): void
    {
        $this->actingAs($this->admin)
            ->post('/control-room/playbooks', $this->payload([
                'name' => 'Fall response',
                'auto_attach' => true,
                'trigger_alert_types' => ['Zz Test Fall Detected'],
                'trigger_severities' => ['high', 'critical'],
            ]))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $playbook = Playbook::where('name', 'Fall response')->firstOrFail();
        $this->assertTrue($playbook->auto_attach);
        $this->assertSame(['Zz Test Fall Detected'], $playbook->trigger_alert_types);
        $this->assertSame(['high', 'critical'], $playbook->trigger_severities);
        $this->assertFalse($playbook->autoStartMissingTriggers());
        $this->assertSame($playbook->id, Playbook::findForAlert('Zz Test Fall Detected', 'high')?->id);
    }

    public function test_adding_a_procedure_started_by_hand_still_allows_empty_lists(): void
    {
        $this->actingAs($this->admin)
            ->post('/control-room/playbooks', $this->payload([
                'name' => 'Started by hand',
                'auto_attach' => false,
                'trigger_alert_types' => [],
                'trigger_severities' => [],
            ]))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $playbook = Playbook::where('name', 'Started by hand')->firstOrFail();
        $this->assertFalse($playbook->auto_attach);
        $this->assertSame([], $playbook->trigger_alert_types);
        $this->assertFalse($playbook->autoStartMissingTriggers());
    }

    // ── Editing ───────────────────────────────────────────────────────

    public function test_editing_to_start_on_its_own_without_alert_types_is_refused_and_changes_nothing(): void
    {
        $playbook = $this->savedPlaybook(['name' => 'Manual one', 'auto_attach' => false]);

        $this->actingAs($this->admin)
            ->put("/control-room/playbooks/{$playbook->id}", $this->payload([
                'name' => 'Manual one renamed',
                'auto_attach' => true,
                'trigger_alert_types' => [],
                'trigger_severities' => [],
            ]))
            ->assertSessionHasErrors([
                'trigger_alert_types' => self::TYPES_MESSAGE,
                'trigger_severities' => self::SEVERITIES_MESSAGE,
            ]);

        $playbook->refresh();
        $this->assertSame('Manual one', $playbook->name);
        $this->assertFalse($playbook->auto_attach);
        $this->assertSame(1, $playbook->version);
    }

    public function test_editing_a_flagged_procedure_with_its_alerts_chosen_clears_the_flag(): void
    {
        $playbook = $this->savedPlaybook([
            'auto_attach' => true,
            'trigger_alert_types' => [],
            'trigger_severities' => [],
        ]);
        $this->assertTrue($playbook->autoStartMissingTriggers());

        $this->actingAs($this->admin)
            ->put("/control-room/playbooks/{$playbook->id}", $this->payload([
                'name' => $playbook->name,
                'auto_attach' => true,
                'trigger_alert_types' => ['Zz Test Door Forced'],
                'trigger_severities' => ['high'],
            ]))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $playbook->refresh();
        $this->assertSame(['Zz Test Door Forced'], $playbook->trigger_alert_types);
        $this->assertSame(['high'], $playbook->trigger_severities);
        $this->assertFalse($playbook->autoStartMissingTriggers());
        $this->assertSame(2, $playbook->version);
    }

    public function test_editing_can_switch_start_on_its_own_off_with_empty_lists(): void
    {
        $playbook = $this->savedPlaybook([
            'auto_attach' => true,
            'trigger_alert_types' => [],
            'trigger_severities' => ['high'],
        ]);

        $this->actingAs($this->admin)
            ->put("/control-room/playbooks/{$playbook->id}", $this->payload([
                'name' => $playbook->name,
                'auto_attach' => false,
                'trigger_alert_types' => [],
                'trigger_severities' => [],
            ]))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $playbook->refresh();
        $this->assertFalse($playbook->auto_attach);
        $this->assertFalse($playbook->autoStartMissingTriggers());
    }

    // ── The flag ──────────────────────────────────────────────────────

    public function test_only_an_empty_list_with_start_on_its_own_is_flagged(): void
    {
        $everyAlert = $this->savedPlaybook(['auto_attach' => true]);
        $this->assertNull($everyAlert->trigger_alert_types);
        $this->assertNull($everyAlert->trigger_severities);
        $this->assertFalse($everyAlert->autoStartMissingTriggers(), 'NULL still means every alert.');

        $this->assertTrue($this->savedPlaybook([
            'auto_attach' => true,
            'trigger_alert_types' => [],
            'trigger_severities' => ['high'],
        ])->autoStartMissingTriggers());

        $this->assertTrue($this->savedPlaybook([
            'auto_attach' => true,
            'trigger_alert_types' => ['Zz Test Fall Detected'],
            'trigger_severities' => [],
        ])->autoStartMissingTriggers());

        $this->assertFalse($this->savedPlaybook([
            'auto_attach' => true,
            'trigger_alert_types' => ['Zz Test Fall Detected'],
            'trigger_severities' => ['high'],
        ])->autoStartMissingTriggers());

        $this->assertFalse($this->savedPlaybook([
            'auto_attach' => false,
            'trigger_alert_types' => [],
            'trigger_severities' => [],
        ])->autoStartMissingTriggers(), 'A procedure started by hand is not flagged.');
    }

    public function test_the_list_and_the_procedure_page_show_the_flag_and_the_alert_types_to_choose(): void
    {
        SignalType::create([
            'code' => 'zz_test_active_signal',
            'name' => 'Zz Test Active Signal',
            'category' => SignalType::CATEGORY_PEOPLE_SAFETY,
            'default_severity' => 'high',
            'is_active' => true,
        ]);
        SignalType::create([
            'code' => 'zz_test_retired_signal',
            'name' => 'Zz Test Retired Signal',
            'category' => SignalType::CATEGORY_PEOPLE_SAFETY,
            'default_severity' => 'low',
            'is_active' => false,
        ]);
        ControlRoomAlert::factory()->open()->create([
            'site_id' => Site::factory()->create()->id,
            'alert_type' => 'zz_test_raised_type',
        ]);
        $flagged = $this->savedPlaybook([
            'name' => 'Zz flagged procedure',
            'auto_attach' => true,
            'trigger_alert_types' => [],
            'trigger_severities' => [],
        ]);
        $working = $this->savedPlaybook([
            'name' => 'Zz working procedure',
            'auto_attach' => true,
            'trigger_alert_types' => ['ZZ Test Saved Type'],
            'trigger_severities' => ['high'],
        ]);

        $index = $this->actingAs($this->admin)
            ->get('/control-room/playbooks')
            ->assertOk();
        $rows = collect($index->inertiaProps('playbooks'))->keyBy('id');
        $this->assertTrue($rows[$flagged->id]['auto_start_missing_triggers']);
        $this->assertFalse($rows[$working->id]['auto_start_missing_triggers']);
        $this->assertAlertTypeChoices($index->inertiaProps('alertTypes'));

        $show = $this->actingAs($this->admin)
            ->get("/control-room/playbooks/{$flagged->id}")
            ->assertOk();
        $this->assertTrue($show->inertiaProps('playbook.auto_start_missing_triggers'));
        $this->assertAlertTypeChoices($show->inertiaProps('alertTypes'));

        $this->actingAs($this->admin)
            ->get("/control-room/playbooks/{$working->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('playbook.auto_start_missing_triggers', false));
    }

    /** @param  mixed  $types */
    private function assertAlertTypeChoices($types): void
    {
        $this->assertIsArray($types);
        $this->assertContains('Zz Test Active Signal', $types, 'Active signal types are offered.');
        $this->assertContains('zz_test_raised_type', $types, 'Types alerts were raised with are offered.');
        $this->assertContains('ZZ Test Saved Type', $types, 'A type a procedure already uses is kept.');
        $this->assertNotContains('Zz Test Retired Signal', $types, 'Inactive signal types are not offered.');
        $this->assertNotContains('', $types);
        $this->assertSame(array_values(array_unique($types)), $types, 'Each type is offered once.');

        $sorted = $types;
        usort($sorted, fn (string $a, string $b) => strcmp(mb_strtolower($a), mb_strtolower($b)) ?: strcmp($a, $b));
        $this->assertSame($sorted, $types, 'Types are sorted without regard to case.');
    }
}
