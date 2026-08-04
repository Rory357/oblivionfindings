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
use Inertia\Testing\AssertableInertia as Assert;
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

    public function test_malformed_historic_rules_fail_closed_without_removing_members(): void
    {
        $member = Device::factory()->security()->create();
        Device::factory()->tracking()->create();
        $group = DeviceGroup::create([
            'name' => 'Historic malformed rule',
            'type' => 'custom',
            'auto_rules' => [
                'match' => 'all',
                'conditions' => [['field' => 'unsafe_column', 'op' => 'equals', 'value' => 'anything']],
            ],
        ]);
        $group->devices()->attach($member);

        $this->assertSame(0, $this->service->queryFromRules($group->auto_rules)->count());
        $this->assertSame(
            ['added' => 0, 'removed' => 0, 'kept' => 0, 'total' => 0],
            $this->service->applyToGroup($group),
        );
        $this->assertTrue($group->devices()->whereKey($member->id)->exists());

        $this->actingAs($this->admin)
            ->post("/security-devices/device-groups/{$group->id}/auto-rules/sync")
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertTrue($group->devices()->whereKey($member->id)->exists());
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

    public function test_group_rules_can_be_authored_and_are_returned_to_the_edit_form(): void
    {
        $rules = [
            'match' => 'all',
            'conditions' => [
                ['field' => 'domain', 'op' => 'equals', 'value' => 'security'],
                ['field' => 'category', 'op' => 'in', 'value' => ['camera', 'nvr']],
            ],
        ];

        $this->actingAs($this->admin)
            ->post('/security-devices/device-groups', [
                'name' => 'Managed cameras',
                'type' => 'functional',
                'description' => 'Automatically managed camera estate.',
                'auto_rules' => $rules,
            ])
            ->assertRedirect();

        $group = DeviceGroup::where('name', 'Managed cameras')->firstOrFail();
        $this->assertEquals($rules, $group->auto_rules);

        $this->actingAs($this->admin)
            ->get("/security-devices/device-groups/{$group->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('security-devices/device-groups/edit')
                ->where('group.auto_rules', $rules)
            );
    }

    public function test_group_rules_reject_unrecognised_or_unsafe_conditions(): void
    {
        $invalidRules = [
            'unknown field' => [[
                'match' => 'all',
                'conditions' => [['field' => 'site_id', 'op' => 'equals', 'value' => '1']],
            ], 'auto_rules.conditions.0.field'],
            'unknown operator' => [[
                'match' => 'all',
                'conditions' => [['field' => 'domain', 'op' => 'contains', 'value' => 'sec']],
            ], 'auto_rules.conditions.0.op'],
            'empty scalar value' => [[
                'match' => 'all',
                'conditions' => [['field' => 'domain', 'op' => 'equals', 'value' => '  ']],
            ], 'auto_rules.conditions.0.value'],
            'empty list value' => [[
                'match' => 'all',
                'conditions' => [['field' => 'category', 'op' => 'in', 'value' => []]],
            ], 'auto_rules.conditions.0.value'],
        ];

        foreach ($invalidRules as $label => [$rules, $errorKey]) {
            $response = $this->actingAs($this->admin)
                ->from('/security-devices/device-groups/create')
                ->post('/security-devices/device-groups', [
                    'name' => "Invalid {$label}",
                    'type' => 'custom',
                    'auto_rules' => $rules,
                ]);

            $response->assertRedirect('/security-devices/device-groups/create');
            $response->assertSessionHasErrors($errorKey);
            $this->assertDatabaseMissing('device_groups', ['name' => "Invalid {$label}"]);
        }
    }

    public function test_draft_rule_preview_is_scoped_and_never_mutates_membership(): void
    {
        $matching = Device::factory()->security()->create(['name' => 'Lobby camera']);
        Device::factory()->tracking()->create(['name' => 'Fleet tracker']);

        $this->actingAs($this->admin)
            ->postJson('/security-devices/device-groups/auto-rules/preview', [
                'auto_rules' => [
                    'match' => 'all',
                    'conditions' => [['field' => 'domain', 'op' => 'equals', 'value' => 'security']],
                ],
            ])
            ->assertOk()
            ->assertJson([
                'count' => 1,
                'sample' => [[
                    'id' => $matching->id,
                    'name' => 'Lobby camera',
                ]],
            ]);

        $this->assertDatabaseCount('device_group_members', 0);
    }

    public function test_saved_rule_preview_reports_membership_changes_before_sync(): void
    {
        $group = DeviceGroup::create([
            'name' => 'Security estate',
            'type' => 'functional',
            'auto_rules' => [
                'match' => 'all',
                'conditions' => [['field' => 'domain', 'op' => 'equals', 'value' => 'security']],
            ],
        ]);
        $kept = Device::factory()->security()->create();
        Device::factory()->security()->create();
        $removed = Device::factory()->tracking()->create();
        $group->devices()->attach([$kept->id, $removed->id]);

        $this->actingAs($this->admin)
            ->getJson("/security-devices/device-groups/{$group->id}/auto-rules/preview")
            ->assertOk()
            ->assertJson([
                'count' => 2,
                'changes' => [
                    'added' => 1,
                    'removed' => 1,
                    'kept' => 1,
                    'total' => 2,
                ],
            ]);

        $this->assertTrue($group->devices()->whereKey($removed->id)->exists());
    }

    public function test_sync_endpoint_redirects_with_success_flash_and_errors_when_rules_empty(): void
    {
        // Group WITH rules → success redirect.
        $group = DeviceGroup::create([
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
