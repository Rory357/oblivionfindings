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

    public function test_a_rule_naming_a_controlled_medicine_is_concealed_from_a_settings_manager_without_controlled_access(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $this->order($client, 'Methylphenidate', controlled: true);
        $this->order($client, 'Metformin');
        $cdManager = $this->staff(['medications.settings.manage', 'sites.viewAll', 'clients.viewAny']);
        $rule = fn (string $name): array => [
            'site_id' => null,
            'match_type' => 'medicine_name',
            'match_value' => $name,
            'requires_countersign' => true,
            'required_observations' => [],
            'active' => true,
        ];
        $this->actingAs($cdManager)->post('/emar/settings/rules', $rule('Methylphenidate'))->assertSessionHasNoErrors();
        $this->actingAs($cdManager)->post('/emar/settings/rules', $rule('Metformin'))->assertSessionHasNoErrors();
        $controlledRule = MedicationAdminRule::query()->where('match_value', 'Methylphenidate')->sole();
        $this->assertTrue(MedicationSettingChange::query()->where('setting_key', 'rule:'.$controlledRule->id)->sole()->controlled);

        $manager = $this->staff(['medications.settings.manage', 'sites.viewAll', 'clients.viewAny'], deny: ['medications.controlled.view']);

        // The list and the history say nothing about the controlled medicine.
        $this->actingAs($manager)
            ->get('/emar/settings')
            ->assertDontSee('Methylphenidate')
            ->assertInertia(fn (Assert $page) => $page
                ->has('rules', 2)
                ->where('rules', fn ($rules) => collect($rules)->contains(fn ($r) => $r['id'] === $controlledRule->id
                    && $r['concealed'] === true
                    && $r['what'] === 'Controlled-medicine rule'
                    && $r['match_value'] === ''
                    && $r['can_change'] === false)
                    && collect($rules)->contains(fn ($r) => $r['what'] === 'Metformin' && $r['concealed'] === false))
                ->where('settings.history', fn ($history) => collect($history)->contains(fn ($h) => $h['key'] === 'rule:'.$controlledRule->id
                    && $h['label'] === 'Controlled-medicine rule'
                    && $h['after_text'] === 'Details need controlled-medicine access'
                    && $h['concealed'] === true)));

        // The preview leaves controlled orders out.
        $this->actingAs($manager)
            ->getJson('/emar/settings/rules/preview?match_type=medicine_name&match_value=Methylphenidate')
            ->assertOk()
            ->assertJson(['medicines' => 0, 'people' => 0, 'rows' => [], 'limited' => true]);

        // Changing, pausing or writing it answers as if it weren't there.
        $this->actingAs($manager)
            ->put("/emar/settings/rules/{$controlledRule->id}", $rule('Methylphenidate'))
            ->assertNotFound();
        $this->actingAs($manager)
            ->post("/emar/settings/rules/{$controlledRule->id}/active", ['active' => false])
            ->assertNotFound();
        $this->actingAs($manager)
            ->from('/emar/settings')
            ->post('/emar/settings/rules', $rule('Methylphenidate'))
            ->assertSessionHasErrors('match_value');
        $this->assertTrue($controlledRule->fresh()->active);
        $this->assertSame(2, MedicationAdminRule::query()->count());

        // With controlled-medicine access, everything shows.
        $this->actingAs($cdManager)
            ->get('/emar/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->where('rules', fn ($rules) => collect($rules)->contains(fn ($r) => $r['what'] === 'Methylphenidate' && $r['can_change'] === true)));
        $this->actingAs($cdManager)
            ->getJson('/emar/settings/rules/preview?match_type=medicine_name&match_value=Methylphenidate')
            ->assertJson(['medicines' => 1, 'people' => 1, 'limited' => false]);
    }

    public function test_the_preview_and_overlaps_follow_the_mar_person_scope(): void
    {
        $otherSite = Site::factory()->create(['is_active' => true, 'name' => 'Rimu House']);
        $aroha = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active', 'first_name' => 'Aroha', 'last_name' => 'Ngata', 'preferred_name' => null]);
        $ben = Client::factory()->create(['site_id' => $otherSite->id, 'status' => 'active', 'first_name' => 'Ben', 'last_name' => 'Clarke', 'preferred_name' => null]);
        $this->order($aroha, 'Insulin glargine', route: 'Subcutaneous injection');
        $this->order($ben, 'Insulin glargine', route: 'Subcutaneous injection');
        $routeRule = MedicationAdminRule::query()->create([
            'site_id' => null,
            'match_type' => 'route',
            'match_value' => 'Subcutaneous injection',
            'requires_countersign' => true,
            'required_observations' => [],
            'active' => true,
        ]);
        $query = '/emar/settings/rules/preview?match_type=medicine_name&match_value=Insulin%20glargine';

        // A house manager sees only the people at their house: no count of anyone else.
        $houseManager = $this->staff(['medications.settings.manage', 'clients.viewAny']);
        $this->actingAs($houseManager)
            ->getJson($query)
            ->assertOk()
            ->assertExactJson([
                'medicines' => 1,
                'people' => 1,
                'rows' => [['person' => 'Aroha Ngata', 'medicine' => 'Insulin glargine', 'house' => 'Kōwhai House']],
                'more' => 0,
                'limited' => false,
                // House managers don't see rules for every house (MedicationSettingsSiteScopeTest).
                'overlaps' => [],
            ]);
        // A house they can't see is refused, not previewed.
        $this->actingAs($houseManager)->getJson($query.'&site_id='.$otherSite->id)->assertNotFound();

        $allSites = $this->staff(['medications.settings.manage', 'sites.viewAll', 'clients.viewAny']);
        $this->actingAs($allSites)
            ->getJson($query)
            ->assertJson([
                'medicines' => 1,
                'people' => 2,
                'overlaps' => [['id' => $routeRule->id, 'sentence' => 'Before saving a dose of any medicine given by subcutaneous injection at All houses: a second person confirms with their witness PIN.']],
            ]);
        $this->actingAs($allSites)
            ->getJson($query.'&site_id='.$otherSite->id)
            ->assertJson(['people' => 1, 'rows' => [['person' => 'Ben Clarke', 'house' => 'Rimu House']]]);

        // The list shows the same overlap on the route rule's row.
        $this->actingAs($allSites)->post('/emar/settings/rules', [
            'site_id' => null,
            'match_type' => 'medicine_name',
            'match_value' => 'Insulin glargine',
            'requires_countersign' => false,
            'required_observations' => ['blood_glucose'],
        ])->assertSessionHasNoErrors();
        $this->actingAs($allSites)
            ->get('/emar/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->where('rules', fn ($rules) => collect($rules)->firstWhere('id', $routeRule->id)['overlaps'] === [MedicationAdminRule::query()->where('match_type', 'medicine_name')->value('id')]));
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
