<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\MedicationAdminRule;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicationSettingsSiteScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_site_manager_sees_only_assigned_site_rules_and_picker_and_cannot_create_outside_scope(): void
    {
        $localSite = Site::factory()->create(['is_active' => true]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $actor = $this->manager(['medications.settings.manage'], $localSite);
        $localRule = $this->rule($localSite, 'LOCAL RULE');
        $this->rule($foreignSite, 'FOREIGN RULE');
        $this->rule(null, 'GLOBAL RULE');

        $response = $this->actingAs($actor)
            ->get(route('emar.settings'))
            ->assertOk();

        $this->assertSame(
            [$localRule->id],
            collect($response->inertiaProps('rules'))->pluck('id')->all(),
        );
        $this->assertSame(
            [$localSite->id],
            collect($response->inertiaProps('sites'))->pluck('id')->all(),
        );
        $this->assertTrue($response->inertiaProps('can.manage'));
        $this->assertFalse($response->inertiaProps('can.manage_global'));

        $this->actingAs($actor)
            ->post(route('emar.settings.rules.store'), $this->payload($localSite->id, 'LOCAL CREATE'))
            ->assertRedirect();
        $this->assertDatabaseHas('medication_admin_rules', [
            'site_id' => $localSite->id,
            'match_value' => 'LOCAL CREATE',
            'created_by' => $actor->id,
        ]);

        $this->actingAs($actor)
            ->from(route('emar.settings'))
            ->post(route('emar.settings.rules.store'), $this->payload($foreignSite->id, 'FOREIGN CREATE'))
            ->assertSessionHasErrors('site_id');
        $this->actingAs($actor)
            ->post(route('emar.settings.rules.store'), $this->payload(null, 'GLOBAL CREATE'))
            ->assertForbidden();
        $this->assertDatabaseMissing('medication_admin_rules', ['match_value' => 'FOREIGN CREATE']);
        $this->assertDatabaseMissing('medication_admin_rules', ['match_value' => 'GLOBAL CREATE']);
    }

    public function test_site_manager_gets_not_found_for_foreign_and_global_rules_and_cannot_rebind_local_rule(): void
    {
        $localSite = Site::factory()->create(['is_active' => true]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $actor = $this->manager(['medications.settings.manage'], $localSite);
        $localRule = $this->rule($localSite, 'LOCAL ORIGINAL');
        $foreignRule = $this->rule($foreignSite, 'FOREIGN ORIGINAL');
        $globalRule = $this->rule(null, 'GLOBAL ORIGINAL');

        foreach ([$foreignRule, $globalRule] as $concealedRule) {
            $this->actingAs($actor)
                ->put(
                    route('emar.settings.rules.update', $concealedRule),
                    $this->payload($localSite->id, 'CONCEALED UPDATE'),
                )
                ->assertNotFound();
            $this->actingAs($actor)
                ->delete(route('emar.settings.rules.destroy', $concealedRule))
                ->assertNotFound();
        }
        $this->assertDatabaseHas('medication_admin_rules', [
            'id' => $foreignRule->id,
            'site_id' => $foreignSite->id,
            'match_value' => 'FOREIGN ORIGINAL',
        ]);
        $this->assertDatabaseHas('medication_admin_rules', [
            'id' => $globalRule->id,
            'site_id' => null,
            'match_value' => 'GLOBAL ORIGINAL',
        ]);

        $this->actingAs($actor)
            ->from(route('emar.settings'))
            ->put(
                route('emar.settings.rules.update', $localRule),
                $this->payload($foreignSite->id, 'FOREIGN REBIND'),
            )
            ->assertSessionHasErrors('site_id');
        $this->actingAs($actor)
            ->put(
                route('emar.settings.rules.update', $localRule),
                $this->payload(null, 'GLOBAL REBIND'),
            )
            ->assertForbidden();
        $this->assertDatabaseHas('medication_admin_rules', [
            'id' => $localRule->id,
            'site_id' => $localSite->id,
            'match_value' => 'LOCAL ORIGINAL',
        ]);

        $this->actingAs($actor)
            ->put(
                route('emar.settings.rules.update', $localRule),
                $this->payload($localSite->id, 'LOCAL UPDATED'),
            )
            ->assertRedirect();
        $this->assertDatabaseHas('medication_admin_rules', [
            'id' => $localRule->id,
            'site_id' => $localSite->id,
            'match_value' => 'LOCAL UPDATED',
        ]);

        $this->actingAs($actor)
            ->delete(route('emar.settings.rules.destroy', $localRule))
            ->assertRedirect();
        $this->assertDatabaseMissing('medication_admin_rules', ['id' => $localRule->id]);
    }

    public function test_explicit_global_site_authority_can_list_and_manage_site_bound_and_global_rules(): void
    {
        $firstSite = Site::factory()->create(['is_active' => true]);
        $secondSite = Site::factory()->create(['is_active' => true]);
        $actor = $this->manager([
            'medications.settings.manage',
            'sites.viewAll',
        ], $firstSite);
        $firstRule = $this->rule($firstSite, 'FIRST SITE RULE');
        $secondRule = $this->rule($secondSite, 'SECOND SITE RULE');
        $globalRule = $this->rule(null, 'GLOBAL RULE');

        $response = $this->actingAs($actor)
            ->get(route('emar.settings'))
            ->assertOk();
        $this->assertEqualsCanonicalizing(
            [$firstRule->id, $secondRule->id, $globalRule->id],
            collect($response->inertiaProps('rules'))->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$firstSite->id, $secondSite->id],
            collect($response->inertiaProps('sites'))->pluck('id')->all(),
        );
        $this->assertTrue($response->inertiaProps('can.manage_global'));

        $this->actingAs($actor)
            ->post(route('emar.settings.rules.store'), $this->payload(null, 'GLOBAL CREATE'))
            ->assertRedirect();
        $this->assertDatabaseHas('medication_admin_rules', [
            'site_id' => null,
            'match_value' => 'GLOBAL CREATE',
        ]);

        $this->actingAs($actor)
            ->put(
                route('emar.settings.rules.update', $globalRule),
                $this->payload($secondSite->id, 'GLOBAL REBOUND TO SITE'),
            )
            ->assertRedirect();
        $this->assertDatabaseHas('medication_admin_rules', [
            'id' => $globalRule->id,
            'site_id' => $secondSite->id,
            'match_value' => 'GLOBAL REBOUND TO SITE',
        ]);

        $this->actingAs($actor)
            ->delete(route('emar.settings.rules.destroy', $secondRule))
            ->assertRedirect();
        $this->assertDatabaseMissing('medication_admin_rules', ['id' => $secondRule->id]);
    }

    /** @param array<int, string> $permissions */
    private function manager(array $permissions, ?Site $primarySite = null): User
    {
        $actor = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $permissionIds = Permission::query()
            ->whereIn('key', $permissions)
            ->pluck('id');
        $this->assertCount(count($permissions), $permissionIds);
        $actor->permissionOverrides()->sync(
            $permissionIds->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])->all(),
        );

        if ($primarySite) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $actor->id,
                'primary_site_id' => $primarySite->id,
                'secondary_site_ids' => [],
                'is_active' => true,
                'start_date' => today()->subDay(),
                'end_date' => null,
            ]);
        }

        return $actor->fresh();
    }

    private function rule(?Site $site, string $matchValue): MedicationAdminRule
    {
        return MedicationAdminRule::query()->create([
            'site_id' => $site?->id,
            'match_type' => 'medicine_name',
            'match_value' => $matchValue,
            'requires_countersign' => true,
            'required_observations' => [],
            'active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(?int $siteId, string $matchValue): array
    {
        return [
            'site_id' => $siteId,
            'match_type' => 'medicine_name',
            'match_value' => $matchValue,
            'requires_countersign' => true,
            'required_observations' => [],
            'active' => true,
        ];
    }
}
