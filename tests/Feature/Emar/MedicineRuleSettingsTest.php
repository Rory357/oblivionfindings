<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationAdminRule;
use App\Models\MedicationSettingChange;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\MedicationRuleService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * eMAR P11 B1 chunk 3 — Medication rules › Medicine rules on the P00 v5
 * builder: every add, change, pause and turn-on is in the change history in
 * the rule's own words; rules are paused, not deleted; "Controlled status"
 * matches controlled orders; the builder's choices come from current orders,
 * without controlled medicines for people who can't see them.
 */
class MedicineRuleSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true, 'name' => 'Kōwhai House']);
    }

    public function test_adding_changing_and_pausing_a_rule_is_recorded_in_its_own_words(): void
    {
        $manager = $this->staff(['medications.settings.manage', 'sites.viewAll']);

        $this->actingAs($manager)
            ->from('/emar/settings')
            ->post('/emar/settings/rules', [
                'site_id' => null,
                'match_type' => 'medicine_name',
                'match_value' => 'Insulin glargine',
                'requires_countersign' => false,
                'required_observations' => ['blood_glucose'],
                'active' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('medication_settings_saved', 'Rule added. It applies from the next dose saved.');
        $rule = MedicationAdminRule::query()->sole();

        $this->actingAs($manager)
            ->put("/emar/settings/rules/{$rule->id}", [
                'site_id' => $this->site->id,
                'match_type' => 'medicine_name',
                'match_value' => 'Insulin glargine',
                'requires_countersign' => true,
                'required_observations' => ['blood_glucose'],
                'active' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($manager)
            ->post("/emar/settings/rules/{$rule->id}/active", ['active' => false])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('medication_settings_saved', 'Rule paused. Doses no longer need it.');
        $this->assertFalse($rule->fresh()->active);

        // Pausing a paused rule changes nothing and records nothing.
        $this->actingAs($manager)
            ->post("/emar/settings/rules/{$rule->id}/active", ['active' => false])
            ->assertSessionHas('medication_settings_saved', 'This rule is already paused.');

        $this->actingAs($manager)
            ->post("/emar/settings/rules/{$rule->id}/active", ['active' => true])
            ->assertSessionHas('medication_settings_saved', 'Rule turned back on. It applies from the next dose saved.');

        $history = MedicationSettingChange::query()->orderBy('id')->get();
        $this->assertSame(
            ['Medicine rule added', 'Medicine rule changed', 'Medicine rule paused', 'Medicine rule turned back on'],
            $history->pluck('label')->all(),
        );
        $this->assertSame('—', $history[0]->before_text);
        $this->assertSame('Before saving a dose of Insulin glargine at All houses: record blood sugar (BSL).', $history[0]->after_text);
        $this->assertNull($history[0]->site_id);
        $this->assertSame(
            'Before saving a dose of Insulin glargine at Kōwhai House: a second person confirms with their witness PIN and record blood sugar (BSL).',
            $history[1]->after_text,
        );
        $this->assertSame($this->site->id, $history[1]->site_id);
        $this->assertSame(['Active', true], [$history[2]->before_text, $history[2]->loosens]);
        $this->assertSame(['Paused', false], [$history[3]->before_text, $history[3]->loosens]);
        $this->assertTrue($history->every(fn (MedicationSettingChange $h): bool => $h->setting_group === 'medicine_rules'
            && $h->setting_key === 'rule:'.$rule->id
            && $h->section === 'medicines'
            && $h->before_value === null));
        $this->assertSame('medicationadminrule.create', $history[0]->audit_event);

        $this->actingAs($manager)
            ->get('/emar/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->has('rules', 1)
                ->where('rules.0.what', 'Insulin glargine')
                ->where('rules.0.needs', 'a second person confirms with their witness PIN and record blood sugar (BSL)')
                ->where('rules.0.can_change', true)
                ->where('rules.0.last_changed_by', $manager->name)
                ->where('settings.history.0.label', 'Medicine rule turned back on')
                // A rule change can't be "put back" from the history.
                ->where('settings.history.0.before_value', null));
    }

    public function test_a_rule_must_ask_for_a_second_person_or_an_observation(): void
    {
        $manager = $this->staff(['medications.settings.manage', 'sites.viewAll']);

        $this->actingAs($manager)
            ->from('/emar/settings')
            ->post('/emar/settings/rules', [
                'match_type' => 'route',
                'match_value' => 'Subcutaneous injection',
                'requires_countersign' => false,
                'required_observations' => [],
            ])
            ->assertSessionHasErrors(['requires_countersign' => 'Turn on at least one: a second person or an observation.']);

        $this->assertSame(0, MedicationAdminRule::query()->count());
        $this->assertSame(0, MedicationSettingChange::query()->count());
    }

    public function test_controlled_status_matches_controlled_orders_only(): void
    {
        $manager = $this->staff(['medications.settings.manage', 'sites.viewAll']);
        $this->actingAs($manager)
            ->from('/emar/settings')
            ->post('/emar/settings/rules', [
                'match_type' => 'controlled',
                'requires_countersign' => true,
                'required_observations' => [],
            ])
            ->assertSessionHasNoErrors();

        $rule = MedicationAdminRule::query()->sole();
        $this->assertSame('controlled', $rule->match_type);
        $this->assertSame('', $rule->match_value);
        $this->assertSame(
            'Before saving a dose of any controlled medicine at All houses: a second person confirms with their witness PIN.',
            MedicationSettingChange::query()->sole()->after_text,
        );

        $client = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $rules = app(MedicationRuleService::class);
        $this->assertTrue($rules->requirementsFor($this->order($client, 'Methylphenidate', controlled: true))['requires_countersign']);
        $this->assertFalse($rules->requirementsFor($this->order($client, 'Metformin'))['requires_countersign']);
    }

    public function test_builder_choices_come_from_current_orders_without_controlled_names_for_people_who_cant_see_them(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $this->order($client, 'Metformin', route: 'Oral');
        $this->order($client, 'Insulin glargine', route: 'Subcutaneous injection', nzulm: '9000052');
        $this->order($client, 'Methylphenidate', route: 'Oral', controlled: true);

        $names = fn (User $user) => $this->actingAs($user)->get('/emar/settings')->viewData('page')['props']['ruleOptions']['names'];

        // The support worker role reads controlled medicines; deny it here.
        $withoutControlled = $this->staff(['medications.settings.manage', 'sites.viewAll'], deny: ['medications.controlled.view']);
        $this->assertSame(['Insulin glargine', 'Metformin'], array_column($names($withoutControlled), 'name'));

        $withControlled = $this->staff(['medications.settings.manage', 'sites.viewAll', 'medications.controlled.view']);
        $this->assertSame(['Insulin glargine', 'Metformin', 'Methylphenidate'], array_column($names($withControlled), 'name'));

        $this->actingAs($withoutControlled)
            ->get('/emar/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->where('ruleOptions.routes', ['Oral', 'Subcutaneous injection'])
                ->where('ruleOptions.nzulm', [['code' => '9000052', 'name' => 'Insulin glargine']]));
    }

    public function test_only_people_with_authority_over_the_rule_can_pause_it(): void
    {
        $rule = MedicationAdminRule::query()->create([
            'site_id' => null,
            'match_type' => 'medicine_name',
            'match_value' => 'Digoxin',
            'requires_countersign' => false,
            'required_observations' => ['pulse'],
            'active' => true,
        ]);
        $houseManager = $this->staff(['medications.settings.manage']);

        // A house manager can't change a rule for every house (concealed, like editing it).
        $this->actingAs($houseManager)
            ->post("/emar/settings/rules/{$rule->id}/active", ['active' => false])
            ->assertNotFound();
        $this->actingAs($this->staff(['medications.view', 'medications.audit.view']))
            ->post("/emar/settings/rules/{$rule->id}/active", ['active' => false])
            ->assertForbidden();

        $this->assertTrue($rule->fresh()->active);
        $this->assertSame(0, MedicationSettingChange::query()->count());
        $this->actingAs($houseManager)
            ->get('/emar/settings')
            ->assertInertia(fn (Assert $page) => $page->has('rules', 0));
    }

    private function order(Client $client, string $name, string $route = 'Oral', ?string $nzulm = null, bool $controlled = false): ClientMedication
    {
        $order = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => $name,
            'dosage' => '1 tablet',
            'frequency' => 'Once daily',
            'route' => $route,
            'controlled_drug' => $controlled,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        // The product code isn't mass-assignable on orders.
        if ($nzulm !== null) {
            $order->forceFill(['nzulm_code' => $nzulm])->saveQuietly();
        }

        return $order;
    }

    private function staff(array $permissions, array $deny = []): User
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
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()
                ->whereIn('key', $deny)
                ->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => false]])
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
