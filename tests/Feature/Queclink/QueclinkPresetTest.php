<?php

namespace Tests\Feature\Queclink;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\Queclink\QueclinkPreset;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\QueclinkPresetSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $device = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'category' => 'personal_tracker',
            'imei' => $imei,
            'device_uid' => $imei,
        ]);

        return QueclinkDevice::create([
            'imei' => $imei,
            'device_id' => $device->id,
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

    public function test_applying_resident_safety_preset_hands_off_without_queuing_provider_commands(): void
    {
        $device = $this->pairedGl30();
        $preset = QueclinkPreset::where('slug', 'resident-safety')->firstOrFail();

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/presets/{$preset->id}/apply")
            ->assertRedirectContains('/security-devices/devices/'.$device->device_id)
            ->assertRedirectContains('action=configuration.apply');

        $this->assertSame(0, QueclinkPendingCommand::query()->count());
        $this->assertNotNull($preset->configurationProfile);
        $this->assertSame([], $preset->payload);
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
        $this->assertEquals(120, $preset->sectionPayloads()['tracking']['continuous_send_interval_seconds']);
        $this->assertArrayNotHasKey('battery_low_percentage', $preset->sectionPayloads()['tracking']);
        $this->assertSame([], $preset->payload);
        $this->assertNotNull($preset->configurationProfile);
        $this->assertStringNotContainsString(
            'continuous_send_interval_seconds',
            (string) DB::table('device_configuration_profiles')
                ->where('id', $preset->device_configuration_profile_id)
                ->value('encrypted_payload'),
        );
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

    public function test_bulk_apply_preset_hands_off_to_governed_bulk_management(): void
    {
        $devices = collect(range(1, 3))->map(fn (int $i) => $this->pairedGl30('86796306991690'.$i));
        $preset = QueclinkPreset::where('slug', 'resident-safety')->firstOrFail();

        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/queclink/bulk', [
                'device_ids' => $devices->pluck('id')->all(),
                'action' => 'apply_preset',
                'preset_id' => $preset->id,
            ])
            ->assertRedirectContains('/security-devices/tracking')
            ->assertRedirectContains('bulk_action=configuration.apply');

        $this->assertSame(0, QueclinkPendingCommand::query()->count());
    }
}
