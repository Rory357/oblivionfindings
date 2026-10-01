<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\MedicationAdminRule;
use App\Models\MedicationSettingChange;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationSafetyPolicySettings;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * eMAR P11 answer 6: auditors read Medication Settings and its change
 * history, and change nothing — every write refuses them, and the page gives
 * them no edit control, no "Review changes" and no "Keep today's value".
 */
class MedicationSettingsAuditorAccessTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
    }

    public function test_an_auditor_reads_every_setting_and_the_history_read_only(): void
    {
        $manager = $this->staff(['medications.settings.manage', 'sites.viewAll']);
        $this->actingAs($manager)
            ->put('/emar/settings/changes', [
                'view' => 'rules',
                'changes' => [['group' => 'safety', 'key' => 'restricted_competency', 'value' => 'block', 'from' => 'off']],
            ])
            ->assertSessionHasNoErrors();
        MedicationAdminRule::query()->create([
            'site_id' => null,
            'match_type' => 'medicine_name',
            'match_value' => 'Insulin glargine',
            'requires_countersign' => false,
            'required_observations' => ['blood_glucose'],
            'active' => true,
            'created_by' => $manager->id,
        ]);

        $auditor = $this->staff(['medications.view', 'medications.audit.view']);
        $this->actingAs($auditor)
            ->get('/emar/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/Settings')
                ->where('settingsAccess', true)
                ->where('readOnlyAudit', true)
                ->where('settings.can_manage_organisation', false)
                ->where('settings.values.safety.restricted_competency', 'block')
                ->has('settings.history', 1)
                ->where('settings.history.0.who', $manager->name)
                ->where('can.manage', false)
                ->where('can.manage_global', false)
                ->where('witnessPin.can_reset', false)
                // Organisation-wide medicine rules are part of what they audit.
                ->has('rules', 1)
                ->where('rules.0.match_value', 'Insulin glargine'));

        // A manager is not read-only. Someone who only reads medications
        // reaches the round templates, read-only (P11 Q2), and nothing an
        // auditor sees: no settings history, no rules, no PIN list.
        $this->actingAs($manager)
            ->get('/emar/settings')
            ->assertInertia(fn (Assert $page) => $page->where('readOnlyAudit', false));
        $this->actingAs($this->staff(['medications.view']))
            ->get('/emar/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('settingsAccess', false)
                ->where('readOnlyAudit', false)
                ->where('rules', [])
                ->where('settings.history', [])
                ->where('witnessPin.staff', [])
                ->where('templateAccess.read', true)
                ->where('templateAccess.manage', false));
    }

    public function test_every_settings_write_refuses_an_auditor(): void
    {
        $auditor = $this->staff(['medications.view', 'medications.audit.view']);
        $colleague = $this->staff(['medications.administer.record']);
        $rule = MedicationAdminRule::query()->create([
            'site_id' => $this->site->id,
            'match_type' => 'route',
            'match_value' => 'Subcutaneous injection',
            'requires_countersign' => true,
            'required_observations' => [],
            'active' => true,
        ]);
        $ruleFields = [
            'site_id' => $this->site->id,
            'match_type' => 'medicine_name',
            'match_value' => 'Warfarin',
            'requires_countersign' => true,
            'required_observations' => [],
            'active' => true,
        ];

        $this->actingAs($auditor);
        $this->put('/emar/settings/changes', [
            'view' => 'rules',
            'changes' => [['group' => 'safety', 'key' => 'restricted_competency', 'value' => 'block', 'from' => 'off']],
        ])->assertForbidden();
        $this->post('/emar/settings/keep', [
            'items' => [['group' => 'safety', 'key' => 'competency_areas']],
        ])->assertForbidden();
        $this->post('/emar/settings/rules', $ruleFields)->assertForbidden();
        $this->put("/emar/settings/rules/{$rule->id}", $ruleFields)->assertForbidden();
        $this->post("/emar/settings/rules/{$rule->id}/active", ['active' => false])->assertForbidden();
        $this->post("/emar/settings/witness-pins/{$colleague->id}/reset")->assertForbidden();

        $this->assertSame('off', app(MedicationSafetyPolicySettings::class)->restrictedCompetencyMode());
        $this->assertSame(0, AppSetting::query()->where('key', MedicationSafetyPolicySettings::COMPETENCY_AREAS)->count());
        $this->assertSame(0, MedicationSettingChange::query()->count());
        $this->assertSame('Subcutaneous injection', $rule->fresh()->match_value);
        $this->assertSame(1, MedicationAdminRule::query()->count());
    }

    private function staff(array $permissions): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $role = Role::query()->where('name', 'support_worker')->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()
                ->whereIn('key', $permissions)
                ->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user->fresh();
    }
}
