<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceGroup;
use App\Domain\SecurityDevices\Services\DeviceGroupAutoRuleService;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the DeviceGroupAutoRuleService and the preview + sync controller endpoints.
 *
 * Routes:
 *   GET  /security-devices/device-groups/{group}/auto-rules/preview
 *   POST /security-devices/device-groups/{group}/auto-rules/sync
 */
class DeviceGroupAutoRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private DeviceGroupAutoRuleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->service = app(DeviceGroupAutoRuleService::class);
    }

    public function test_empty_conditions_match_zero_devices_footgun_guard(): void
    {
        Device::factory()->count(3)->create();

        $count = $this->service
            ->queryFromRules(['match' => 'all', 'conditions' => []])
            ->count();

        $this->assertSame(0, $count, 'Empty conditions must not match everything');
    }

    public function test_match_all_ands_two_conditions(): void
    {
        Device::factory()->security()->create(['provider' => 'unifi']); // matches both
        Device::factory()->security()->create(['provider' => 'manual']); // fails provider
        Device::factory()->itInfrastructure()->create(['provider' => 'unifi']); // fails domain

        $count = $this->service->queryFromRules([
            'match' => 'all',
            'conditions' => [
                ['field' => 'domain', 'op' => 'equals', 'value' => 'security'],
                ['field' => 'provider', 'op' => 'equals', 'value' => 'unifi'],
            ],
        ])->count();

        $this->assertSame(1, $count);
    }

    public function test_match_any_ors_two_conditions(): void
    {
        Device::factory()->security()->create(['provider' => 'manual']);
        Device::factory()->itInfrastructure()->create(['provider' => 'unifi']);
        Device::factory()->tracking()->create(['provider' => 'queclink']);

        $count = $this->service->queryFromRules([
            'match' => 'any',
            'conditions' => [
                ['field' => 'domain', 'op' => 'equals', 'value' => 'security'],
                ['field' => 'provider', 'op' => 'equals', 'value' => 'unifi'],
            ],
        ])->count();

        $this->assertSame(2, $count);
    }

    public function test_apply_to_group_returns_correct_delta_and_is_idempotent(): void
    {
        $group = DeviceGroup::create([
            'tenant_id' => 1,
            'name' => 'Security Cameras',
            'type' => 'functional',
            'auto_rules' => [
                'match' => 'all',
                'conditions' => [['field' => 'domain', 'op' => 'equals', 'value' => 'security']],
            ],
        ]);

        Device::factory()->count(3)->security()->create();
        Device::factory()->itInfrastructure()->create(); // excluded

        $first = $this->service->applyToGroup($group);
        $this->assertSame(3, $first['added']);
        $this->assertSame(0, $first['removed']);
        $this->assertSame(0, $first['kept']);
        $this->assertSame(3, $first['total']);

        // Second call is a no-op — rules are a pure function of current devices.
        $second = $this->service->applyToGroup($group);
        $this->assertSame(0, $second['added']);
        $this->assertSame(0, $second['removed']);
        $this->assertSame(3, $second['kept']);
        $this->assertSame(3, $second['total']);
    }

    public function test_preview_endpoint_returns_json_count_and_sample(): void
    {
        $group = DeviceGroup::create([
            'tenant_id' => 1,
            'name' => 'Tracking Devices',
            'type' => 'functional',
            'auto_rules' => [
                'match' => 'all',
                'conditions' => [['field' => 'domain', 'op' => 'equals', 'value' => 'tracking']],
            ],
        ]);

        Device::factory()->count(2)->tracking()->create();
        Device::factory()->security()->create();

        $this->actingAs($this->admin)
            ->getJson("/security-devices/device-groups/{$group->id}/auto-rules/preview")
            ->assertOk()
            ->assertJsonStructure(['count', 'sample'])
            ->assertJson(['count' => 2]);
    }

    public function test_sync_endpoint_redirects_with_success_flash_and_errors_when_rules_empty(): void
    {
        // Group WITH rules → success redirect.
        $group = DeviceGroup::create([
            'tenant_id' => 1,
            'name' => 'IT Infra',
            'type' => 'functional',
            'auto_rules' => [
                'match' => 'all',
                'conditions' => [['field' => 'domain', 'op' => 'equals', 'value' => 'it_infrastructure']],
            ],
        ]);
        Device::factory()->itInfrastructure()->create();

        $this->actingAs($this->admin)
            ->post("/security-devices/device-groups/{$group->id}/auto-rules/sync")
            ->assertRedirect()
            ->assertSessionHas('success');

        // Group WITHOUT rules → error redirect.
        $emptyGroup = DeviceGroup::create([
            'tenant_id' => 1,
            'name' => 'Empty',
            'type' => 'custom',
            'auto_rules' => null,
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/device-groups/{$emptyGroup->id}/auto-rules/sync")
            ->assertRedirect()
            ->assertSessionHas('error');
    }
}
