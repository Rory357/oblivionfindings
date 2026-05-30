<?php

namespace Tests\Feature\Queclink;

use App\Models\Queclink\QueclinkAuditEvent;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\Queclink\QueclinkPreset;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\QueclinkPresetSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QueclinkPresetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $this->seed(QueclinkPresetSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
    }

    private function pairedGl30(string $imei = '867963069916998'): QueclinkDevice
    {
        return QueclinkDevice::create([
            'imei' => $imei,
            'tenant_id' => 1,
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GL30MEU',
        ]);
    }

    public function test_seeder_ships_idempotent_system_presets(): void
    {
        // Re-running the seeder must not duplicate the built-in presets.
        $this->seed(QueclinkPresetSeeder::class);

        $this->assertSame(1, QueclinkPreset::where('slug', 'resident-safety')->where('is_system', true)->count());
        $this->assertSame(1, QueclinkPreset::where('slug', 'lone-worker')->where('is_system', true)->count());
    }

    public function test_hub_exposes_available_presets(): void
    {
        $this->actingAs($this->admin)
            ->get('/security-devices/integrations/queclink')
            ->assertInertia(fn ($page) => $page
                ->has('presets')
                ->where('presets', fn ($presets) => collect($presets)->pluck('slug')->contains('resident-safety')
                    && collect($presets)->pluck('slug')->contains('lone-worker')
                    && collect($presets)->firstWhere('slug', 'resident-safety')['is_system'] === true));
    }

    public function test_applying_resident_safety_preset_queues_identical_cfg_to_the_button(): void
    {
        $device = $this->pairedGl30();
        $preset = QueclinkPreset::where('slug', 'resident-safety')->firstOrFail();

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/presets/{$preset->id}/apply")
            ->assertRedirect();

        $this->assertSame(1, QueclinkPendingCommand::query()->where('queclink_device_id', $device->id)->count());

        $cmd = QueclinkPendingCommand::first();
        $this->assertSame('GTCFG', $cmd->command_word);
        // Byte-for-byte the same payload the one-click resident-safety button emits.
        $this->assertStringContainsString(
            'AT+GTCFG=gl30,,GL30MEU,150,08E3,006F,1,30,,0,1200,,1,,,,1,1,0000,,,20,1,,1,2,1,0,',
            $cmd->raw_command,
        );

        $this->assertDatabaseHas('queclink_audit_events', [
            'queclink_device_id' => $device->id,
            'event_type' => 'preset_apply',
            'section' => 'tracking',
            'raw_command' => $cmd->raw_command,
        ]);
    }

    public function test_apply_preset_rejects_unpaired_device(): void
    {
        $device = QueclinkDevice::create([
            'imei' => '867963069916001',
            'tenant_id' => 1,
            'status' => QueclinkDevice::STATUS_PENDING,
            'model_hint' => 'GL30MEU',
        ]);
        $preset = QueclinkPreset::where('slug', 'resident-safety')->firstOrFail();

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/presets/{$preset->id}/apply")
            ->assertStatus(422);

        $this->assertSame(0, QueclinkPendingCommand::query()->count());
    }

    public function test_operator_can_save_a_custom_preset(): void
    {
        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/queclink/presets', [
                'name' => 'Night shift',
                'description' => 'Slower cadence overnight.',
                'sections' => [
                    'tracking' => [
                        'continuous_send_interval_seconds' => 120,
                        'function_button_mode' => 1,
                        'sos_report_mode' => 1,
                        'battery_low_percentage' => '', // blanks are stripped
                    ],
                ],
            ])
            ->assertRedirect();

        $preset = QueclinkPreset::where('slug', 'night-shift')->firstOrFail();
        $this->assertFalse($preset->is_system);
        $this->assertSame(1, (int) $preset->tenant_id);
        $this->assertSame($this->admin->id, $preset->created_by_user_id);
        $this->assertEquals(120, $preset->payload['tracking']['continuous_send_interval_seconds']);
        $this->assertArrayNotHasKey('battery_low_percentage', $preset->payload['tracking']);
    }

    public function test_saving_a_preset_rejects_unknown_sections(): void
    {
        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/queclink/presets', [
                'name' => 'Bogus',
                'sections' => [
                    'made_up_section' => ['foo' => 1],
                ],
            ])
            ->assertSessionHasErrors('sections');

        $this->assertSame(0, QueclinkPreset::where('is_system', false)->count());
    }

    public function test_system_presets_cannot_be_deleted(): void
    {
        $preset = QueclinkPreset::where('slug', 'resident-safety')->firstOrFail();

        $this->actingAs($this->admin)
            ->delete("/security-devices/integrations/queclink/presets/{$preset->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('queclink_presets', ['id' => $preset->id]);
    }

    public function test_operator_can_delete_their_own_preset(): void
    {
        $preset = QueclinkPreset::create([
            'tenant_id' => 1,
            'name' => 'Temporary',
            'slug' => 'temporary',
            'target_category' => 'personal_tracker',
            'payload' => ['tracking' => ['continuous_send_interval_seconds' => 90]],
            'is_system' => false,
            'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->delete("/security-devices/integrations/queclink/presets/{$preset->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('queclink_presets', ['id' => $preset->id]);
    }

    public function test_bulk_apply_preset_queues_one_command_per_section_for_each_device(): void
    {
        $devices = collect(range(1, 3))->map(fn (int $i) => QueclinkDevice::create([
            'imei' => '86796306991690'.$i,
            'tenant_id' => 1,
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GL30MEU',
        ]));
        $preset = QueclinkPreset::where('slug', 'resident-safety')->firstOrFail();

        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/queclink/bulk', [
                'device_ids' => $devices->pluck('id')->all(),
                'action' => 'apply_preset',
                'preset_id' => $preset->id,
            ])
            ->assertRedirect();

        // resident-safety has one section (tracking) → one GTCFG per device.
        $this->assertSame(3, QueclinkPendingCommand::query()->where('command_word', 'GTCFG')->count());
        $this->assertSame(3, QueclinkAuditEvent::query()
            ->where('event_type', 'bulk_apply')
            ->where('section', 'tracking')
            ->count());
    }
}
