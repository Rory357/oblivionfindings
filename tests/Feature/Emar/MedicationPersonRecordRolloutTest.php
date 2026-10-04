<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Public routes use the completed record; legacy remains an explicit fallback. */
class MedicationPersonRecordRolloutTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $person;

    private Client $unassigned;

    private User $reader;

    private ClientMedication $ordinary;

    private ClientMedication $controlled;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->person = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'Visible rollout person']);
        $this->unassigned = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'Private unassigned rollout person']);
        $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $grants = ['clients.viewAssigned' => true, 'medications.view' => true,
            'medications.administer.record' => false, 'medications.orders.manage' => false,
            'medications.controlled.view' => false, 'medications.controlled.record' => false];
        $this->reader->permissionOverrides()->syncWithoutDetaching(Permission::whereIn('key', array_keys($grants))->get()
            ->mapWithKeys(fn (Permission $permission): array => [$permission->id => ['allowed' => $grants[$permission->key]]])->all());
        HrEmployeeProfile::factory()->create(['user_id' => $this->reader->id, 'primary_site_id' => $this->site->id,
            'start_date' => now()->subMonth()->toDateString(), 'end_date' => null, 'is_active' => true]);
        $this->person->supportWorkers()->attach($this->reader->id);
        $this->ordinary = $this->medicine($this->person, 'Visible as-needed rollout medicine');
        $this->controlled = $this->medicine($this->person, 'Private controlled rollout medicine', true);
        $this->medicine($this->unassigned, 'Private unassigned rollout medicine');
        ClientMedicationAdministration::query()->create(['client_id' => $this->person->id,
            'client_medication_id' => $this->ordinary->id, 'administered_by' => $this->reader->id,
            'administered_at' => now()->subHour(), 'status' => 'given']);
    }

    public function test_public_default_routes_open_the_completed_record_and_scoped_hubs(): void
    {
        // Do not force p02 here: a forgotten shipped default must fail this contract.
        $this->assertSame('p02', config('medications.person_record'));
        $this->actingAs($this->reader)->get('/emar/mar?client_id='.$this->person->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('emar/record/show')
                ->where('person.id', $this->person->id)->where('can.view_controlled', false))
            ->assertDontSee($this->controlled->name, false);
        foreach (['/emar/mar' => 'charts', '/emar/medications' => 'medicines', '/emar/prn' => 'asneeded'] as $url => $view) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('emar/record/hub')
                ->where('view', $view)->has('page.data', 1)->where('page.data.0.client_id', $this->person->id)
                ->where('controlled_left_out', true))
                ->assertDontSee($this->controlled->name, false)->assertDontSee($this->unassigned->first_name, false);
        }
        $this->get('/emar/self-admin')->assertOk()->assertInertia(fn (Assert $page) => $page->component('emar/SelfAdmin')
            ->has('register', 1)->where('register.0.client_id', $this->person->id));
        $this->get('/emar/self-admin/clients/'.$this->person->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('emar/SupportRecord')->where('support.client_id', $this->person->id));
    }

    public function test_default_routes_conceal_unassigned_people_and_denied_module_access(): void
    {
        $this->assertSame('p02', config('medications.person_record'));
        foreach (['/emar/mar', '/emar/medications', '/emar/prn', '/emar/self-admin'] as $url) {
            $this->actingAs($this->reader)->get($url.'?client_id='.$this->unassigned->id)->assertNotFound()
                ->assertDontSee($this->unassigned->first_name, false);
        }
        $this->get('/emar/self-admin/clients/'.$this->unassigned->id)->assertNotFound();
        $this->getJson('/emar/clients/'.$this->person->id.'/record/medicines/'.$this->controlled->id)->assertNotFound();
        $view = Permission::where('key', 'medications.view')->sole();
        $this->reader->permissionOverrides()->updateExistingPivot($view->id, ['allowed' => false]);
        $this->reader->unsetRelation('permissionOverrides')->unsetRelation('roles');
        foreach (['/emar/mar', '/emar/medications', '/emar/prn', '/emar/self-admin'] as $url) {
            $this->actingAs($this->reader->fresh())->get($url)->assertForbidden();
        }
    }

    public function test_default_record_reading_does_not_grant_recording_or_write_clinical_work(): void
    {
        $tables = ['client_medication_administrations', 'medication_followups', 'medication_followup_events',
            'medication_prn_effectiveness', 'medication_events'];
        $before = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->toJson()]);
        $this->actingAs($this->reader)->get('/emar/mar?client_id='.$this->person->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.manage_orders', false));
        $this->getJson('/emar/clients/'.$this->person->id.'/day')->assertOk()->assertJsonPath('can.record', false);
        $this->get('/emar/prn')->assertOk()->assertInertia(fn (Assert $page) => $page->where('page.data.0.can_record', false));
        $this->postJson('/meds/today/record', [])->assertForbidden();
        $this->postJson('/meds/today/prn', [])->assertForbidden();
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
    }

    public function test_an_explicit_legacy_override_keeps_the_existing_payload_routes(): void
    {
        config(['medications.person_record' => 'legacy']);
        foreach (['/emar/mar?client_id='.$this->person->id => 'emar/MarCharts',
            '/emar/medications' => 'emar/Medications', '/emar/prn' => 'emar/PrnRecords'] as $url => $component) {
            $this->actingAs($this->reader)->get($url)->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component($component))
                ->assertDontSee($this->controlled->name, false)->assertDontSee($this->unassigned->first_name, false);
        }
    }

    private function medicine(Client $person, string $name, bool $controlled = false): ClientMedication
    {
        return ClientMedication::query()->create(['client_id' => $person->id, 'name' => $name,
            'dosage' => '1 tablet', 'frequency' => 'As needed', 'is_prn' => true,
            'active' => true, 'state' => 'active', 'controlled_drug' => $controlled]);
    }
}
