<?php

namespace Tests\Feature\Queclink;

use App\Domain\SecurityDevices\Management\Models\DeviceConfigurationProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\AuditLog;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\Queclink\QueclinkPreset;
use App\Models\Role;
use App\Models\User;
use App\Services\Queclink\QueclinkConfigurationProfileService;
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

    public function test_operator_reasonedly_retires_preset_and_governed_profile_without_losing_history(): void
    {
        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/queclink/presets', [
                'name' => 'Temporary',
                'sections' => [
                    'tracking' => ['continuous_send_interval_seconds' => 90],
                ],
            ])
            ->assertRedirect();

        $preset = QueclinkPreset::query()->where('slug', 'temporary')->firstOrFail();
        $profile = $preset->configurationProfile()->firstOrFail();

        $this->actingAs($this->admin)
            ->delete("/security-devices/integrations/queclink/presets/{$preset->id}")
            ->assertSessionHasErrors('reason');

        $this->assertNull($preset->fresh()->retired_at);
        $this->assertSame(DeviceConfigurationProfile::STATUS_ACTIVE, $profile->fresh()->status);

        $this->actingAs($this->admin)
            ->delete("/security-devices/integrations/queclink/presets/{$preset->id}", [
                'reason' => 'Replaced by the approved current tracker baseline.',
            ])
            ->assertRedirect();

        $retired = $preset->fresh();
        $this->assertNotNull($retired->retired_at);
        $this->assertSame($this->admin->id, $retired->retired_by_user_id);
        $this->assertSame('Replaced by the approved current tracker baseline.', $retired->retirement_reason);
        $this->assertSame(DeviceConfigurationProfile::STATUS_RETIRED, $profile->fresh()->status);
        $this->assertFalse(
            app(QueclinkConfigurationProfileService::class)
                ->compatibleProfiles($this->pairedGl30()->device)
                ->contains('id', $profile->id),
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'security_devices.queclink.preset.retired',
            'auditable_id' => $preset->id,
        ]);
        $audit = AuditLog::query()
            ->where('action', 'security_devices.queclink.preset.retired')
            ->where('auditable_id', $preset->id)
            ->sole();
        $this->assertSame('Replaced by the approved current tracker baseline.', $audit->meta['reason']);

        $device = $this->pairedGl30('867963069916997');
        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/presets/{$preset->id}/apply")
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->get('/security-devices/integrations/queclink')
            ->assertInertia(fn ($page) => $page
                ->where('presets', fn ($presets) => ! collect($presets)->pluck('id')->contains($preset->id))
                ->where('retiredPresets.0.id', $preset->id)
                ->where('retiredPresets.0.retired_by', $this->admin->name)
                ->where('retiredPresets.0.retirement_reason', 'Replaced by the approved current tracker baseline.')
                ->where('retiredPresets.0.profile_version', $profile->version));

        $this->expectException(\UnexpectedValueException::class);
        $retired->delete();
    }

    public function test_retired_preset_actor_reason_and_retirement_time_are_immutable(): void
    {
        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/queclink/presets', [
                'name' => 'Immutable retirement',
                'sections' => [
                    'tracking' => ['continuous_send_interval_seconds' => 90],
                ],
            ])
            ->assertRedirect();
        $preset = QueclinkPreset::query()->where('slug', 'immutable-retirement')->firstOrFail();
        $this->actingAs($this->admin)
            ->delete("/security-devices/integrations/queclink/presets/{$preset->id}", [
                'reason' => 'Replaced by the approved current tracker baseline.',
            ])
            ->assertRedirect();

        $this->expectException(\UnexpectedValueException::class);
        $preset->fresh()->forceFill([
            'retirement_reason' => 'Rewritten after retirement.',
        ])->save();
    }

    public function test_preset_and_profile_retirement_roll_back_together_when_audit_fails(): void
    {
        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/queclink/presets', [
                'name' => 'Rollback preset',
                'sections' => [
                    'tracking' => ['continuous_send_interval_seconds' => 90],
                ],
            ])
            ->assertRedirect();

        $preset = QueclinkPreset::query()->where('slug', 'rollback-preset')->firstOrFail();
        $profile = $preset->configurationProfile()->firstOrFail();
        $failOnce = true;
        AuditLog::creating(function () use (&$failOnce): void {
            if ($failOnce) {
                $failOnce = false;
                throw new \RuntimeException('Simulated preset audit failure.');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->admin)
                ->delete("/security-devices/integrations/queclink/presets/{$preset->id}", [
                    'reason' => 'Replaced by the approved current tracker baseline.',
                ]);
            $this->fail('The simulated audit failure was not raised.');
        } catch (\RuntimeException $failure) {
            $this->assertSame('Simulated preset audit failure.', $failure->getMessage());
        }

        $this->assertNull($preset->fresh()->retired_at);
        $this->assertSame(DeviceConfigurationProfile::STATUS_ACTIVE, $profile->fresh()->status);
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
