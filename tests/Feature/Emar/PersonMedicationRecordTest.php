<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientInrRecord;
use App\Models\ClientMedication;
use App\Models\MedicationSyringeDriver;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PersonMedicationRecordTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;
    private Client $person;
    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-03 09:00', 'Pacific/Auckland'));
        $this->seed(RbacSeeder::class);
        config(['medications.person_record' => 'p02']);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->person = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'Aroha']);
        $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->reader->permissionOverrides()->syncWithoutDetaching(Permission::query()->whereIn('key', ['clients.viewAssigned', 'medications.view'])->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all());
        HrEmployeeProfile::factory()->create(['user_id' => $this->reader->id, 'primary_site_id' => $this->site->id, 'start_date' => '2026-01-01', 'is_active' => true]);
        $this->person->supportWorkers()->attach($this->reader->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_reading_medicines_redacts_controlled_details_and_is_not_cached(): void
    {
        $this->medicine('Paracetamol');
        $hidden = $this->medicine('Secret controlled medicine', ['controlled_drug' => true]);
        $response = $this->actingAs($this->reader)->getJson($this->url('/medicines'))->assertOk();
        $response->assertJsonPath('hidden', 1)->assertJsonCount(2, 'rows')->assertDontSee('Secret controlled medicine', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->getJson($this->url('/medicines/'.$hidden->id))->assertNotFound();
    }

    public function test_a_medicine_identifier_from_another_person_is_not_found(): void
    {
        $other = Client::factory()->create(['site_id' => $this->site->id]);
        $medicine = $this->medicine('Other person medicine', ['client_id' => $other->id]);
        $this->actingAs($this->reader)->getJson($this->url('/medicines/'.$medicine->id))->assertNotFound();
    }

    public function test_reading_sections_follow_the_person_to_the_current_house(): void
    {
        $this->medicine('Paracetamol');
        $this->person->update(['site_id' => Site::factory()->create(['is_active' => true])->id]);
        foreach (['/medicines', '/support'] as $view) {
            $this->actingAs($this->reader)->getJson($this->url($view))->assertNotFound();
        }
    }

    public function test_an_unassigned_person_at_the_same_house_is_concealed(): void
    {
        $other = Client::factory()->create(['site_id' => $this->site->id]);
        $this->actingAs($this->reader)->getJson('/emar/clients/'.$other->id.'/record/medicines')->assertNotFound();
    }

    public function test_the_header_never_exposes_a_controlled_linked_inr_result(): void
    {
        $hidden = $this->medicine('Secret controlled anticoagulant', ['controlled_drug' => true]);
        ClientInrRecord::query()->create(['client_id' => $this->person->id, 'client_medication_id' => $hidden->id, 'inr_value' => 6.5, 'tested_on' => '2026-10-03', 'recorded_by' => $this->reader->id]);
        $this->actingAs($this->reader)->get('/emar/mar?client_id='.$this->person->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('emar/record/show')->where('meters.inr', null));
    }

    public function test_the_header_keeps_unlinked_inr_results_visible(): void
    {
        ClientInrRecord::query()->create(['client_id' => $this->person->id, 'inr_value' => 2.4, 'tested_on' => '2026-10-03', 'recorded_by' => $this->reader->id]);
        $this->actingAs($this->reader)->get('/emar/mar?client_id='.$this->person->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('emar/record/show')->where('meters.inr.value', 2.4));
    }

    public function test_a_driver_at_the_previous_house_is_not_the_current_driver(): void
    {
        $order = $this->medicine('Paracetamol');
        MedicationSyringeDriver::query()->create(['client_id' => $this->person->id, 'site_id' => Site::factory()->create()->id, 'status' => 'running', 'commenced_at' => now(), 'commenced_by' => $this->reader->id, 'contents' => [['client_medication_id' => $order->id, 'name' => $order->name]]]);
        $this->actingAs($this->reader)->get('/emar/mar?client_id='.$this->person->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('emar/record/show')->where('meters.driver', null));
    }

    private function url(string $suffix): string
    {
        return '/emar/clients/'.$this->person->id.'/record'.$suffix;
    }

    private function medicine(string $name, array $extra = []): ClientMedication
    {
        return ClientMedication::query()->create(array_merge(['client_id' => $this->person->id, 'name' => $name, 'dosage' => '1 tablet', 'frequency' => 'Daily', 'dose_times' => ['08:00'], 'active' => true, 'state' => 'active', 'is_prn' => false, 'start_date' => '2026-10-01'], $extra));
    }
}
