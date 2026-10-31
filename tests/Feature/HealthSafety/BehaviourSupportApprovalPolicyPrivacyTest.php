<?php

namespace Tests\Feature\HealthSafety;

use App\Models\Permission;
use App\Models\Role;
use App\Services\HealthSafety\BehaviourSupportApprovalPolicySettings as Policy;
use Illuminate\Support\Facades\DB;

final class BehaviourSupportApprovalPolicyPrivacyTest extends BehaviourSupportPolicyTestCase
{
    public function test_navigation_capability_is_fresh_without_reading_or_materializing_policy_history(): void
    {
        $sourceManager = $this->staff(['restraints.manage']);
        DB::connection()->enableQueryLog();
        try {
            $this->assertTrue($this->policy()->canManageSettings($this->manager));
            $this->assertFalse($this->policy()->canManageSettings($sourceManager));
            $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        } finally {
            DB::connection()->disableQueryLog();
        }
        $this->assertStringNotContainsString('app_settings', $queries);
        $this->assertStringNotContainsString('health_safety_setting_changes', $queries);
        $this->assertDatabaseMissing('app_settings', ['key' => Policy::REVISION_KEY]);
        $this->assertDatabaseMissing('app_settings', ['key' => Policy::KEY]);
        $this->assertDatabaseCount('health_safety_setting_changes', 0);
        $this->manager->load('permissionOverrides', 'roles');
        $permission = Permission::where('key', Policy::PERMISSION)->sole();
        $this->manager->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        $this->assertFalse($this->policy()->canManageSettings($this->manager));
    }

    public function test_seeded_settings_grant_is_only_the_intended_administrator_role(): void
    {
        $permission = Permission::where('key', Policy::PERMISSION)->sole();
        $this->assertSame(['admin'], $permission->roles()->orderBy('name')->pluck('name')->all());
    }

    public function test_settings_configuration_does_not_require_source_or_all_site_visibility(): void
    {
        $this->site->update(['is_active' => false]);
        $this->assertTrue($this->policy()->editor($this->manager)['can_manage']);
        $this->assertFalse($this->manager->canDo('healthSafety.viewAllSites'));
        $this->assertFalse($this->manager->canDo('clients.viewAny'));
    }

    public function test_settings_permission_is_separate_from_source_and_other_settings_authority(): void
    {
        $this->assertFalse($this->manager->canDo('restraints.view'));
        $this->assertFalse($this->manager->canDo('restraints.manage'));
        $this->assertFalse($this->manager->canDo('healthSafety.viewAllSites'));
        $this->assertTrue($this->policy()->editor($this->manager)['can_manage']);
        $reader = $this->staff(['restraints.view', 'restraints.review', 'restraints.manage', 'hazards.manage', 'settings.manage', 'controlRoom.alerts.manage']);
        $this->actingAs($reader)->getJson('/health-safety/settings')->assertForbidden();
        $this->putJson('/health-safety/settings/restraint-plan-approval', $this->payload())->assertForbidden();
    }

    public function test_fresh_revocation_denies_editor_save_and_accepted_replay_despite_loaded_relations(): void
    {
        $payload = $this->payload();
        $this->policy()->save($this->manager, $payload);
        $this->manager->load('permissionOverrides', 'roles');
        $permission = Permission::where('key', Policy::PERMISSION)->sole();
        $this->manager->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        $this->denied(fn () => $this->policy()->editor($this->manager));
        $this->denied(fn () => $this->policy()->save($this->manager, $payload));
        $this->assertSame(1, $this->policy()->snapshot()['policy_revision']);
    }

    public function test_role_grant_revocation_is_current_not_cached(): void
    {
        $actor = $this->staff();
        $role = Role::create(['name' => 'synthetic_policy_manager', 'level' => 50, 'type' => 'custom']);
        $role->permissions()->attach(Permission::where('key', Policy::PERMISSION)->sole()->id);
        $actor->roles()->attach($role);
        $actor->load('roles.permissions', 'permissionOverrides');
        $this->policy()->assertManager($actor);
        $role->permissions()->detach();
        $this->denied(fn () => $this->policy()->assertManager($actor));
    }

    public function test_current_staff_dates_approval_and_activity_are_required_independently_of_grant(): void
    {
        foreach ([
            ['is_active' => false], ['start_date' => now()->addDay()->toDateString()], ['end_date' => now()->subDay()->toDateString()],
        ] as $profile) {
            $actor = $this->staff([Policy::PERMISSION]);
            $actor->hrEmployeeProfile->update($profile);
            $this->denied(fn () => $this->policy()->editor($actor));
        }
        $unapproved = $this->staff([Policy::PERMISSION], ['approved_at' => null]);
        $this->denied(fn () => $this->policy()->save($unapproved, $this->payload()));
    }

    public function test_portal_and_external_roles_with_lingering_hr_and_positive_grants_still_deny(): void
    {
        foreach (['client', 'next_of_kin', 'external_clinician'] as $role) {
            $actor = $this->staff([Policy::PERMISSION], ['role' => $role]);
            $this->denied(fn () => $this->policy()->editor($actor));
            $this->actingAs($actor)->putJson('/health-safety/settings/restraint-plan-approval', $this->payload())->assertForbidden();
        }
    }

    public function test_safe_policy_projection_never_exposes_history_actor_reason_or_manager_directory(): void
    {
        $payload = $this->payload('authorised_reviewer');
        $payload['reason'] = "  Private operational rationale\nRetain exactly.  ";
        $this->policy()->save($this->manager, $payload);
        $safe = json_encode($this->policy()->snapshot(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($payload['reason'], $safe);
        $this->assertStringNotContainsString($this->manager->name, $safe);
        $this->assertArrayNotHasKey('actor', $this->policy()->snapshot());
        $history = $this->policy()->editor($this->manager)['history']['data'][0];
        $this->assertSame($payload['reason'], $history['reason']);
        $this->assertSame($this->manager->name, $history['actor']['name']);
    }
}
