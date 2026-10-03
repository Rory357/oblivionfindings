<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientInrRecord;
use App\Models\ClientMedication;
use App\Models\MedicationSyringeDriver;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\MedicationProfileAuditPrivacy;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
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
        Carbon::setTestNow(Carbon::parse('2026-10-03 09:00', 'Pacific/Auckland')->utc());
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

    public function test_profile_audit_keeps_ordinary_medicine_events_and_conceals_controlled_events(): void
    {
        $ordinary = $this->medicine('Paracetamol');
        $controlled = $this->medicine('Secret controlled medicine', ['controlled_drug' => true]);
        $ordinaryLog = $this->audit($ordinary);
        $controlledLog = $this->audit($controlled);
        $ids = app(MedicationProfileAuditPrivacy::class)->apply(AuditLog::query()->where('client_id', $this->person->id), $this->reader, $this->person)->pluck('id')->all();
        $this->assertContains($ordinaryLog->id, $ids);
        $this->assertNotContains($controlledLog->id, $ids);
    }

    public function test_profile_audit_rejects_a_forged_medicine_owner(): void
    {
        $other = Client::factory()->create(['site_id' => $this->site->id]);
        $foreign = $this->medicine('Other person medicine', ['client_id' => $other->id]);
        $log = $this->audit($foreign);
        $ids = app(MedicationProfileAuditPrivacy::class)->apply(AuditLog::query()->where('client_id', $this->person->id), $this->reader, $this->person)->pluck('id')->all();
        $this->assertNotContains($log->id, $ids);
    }

    public function test_profile_audit_rechecks_medication_person_access(): void
    {
        $log = $this->audit($this->medicine('Paracetamol'));
        $this->person->supportWorkers()->detach($this->reader->id);
        $ids = app(MedicationProfileAuditPrivacy::class)->apply(AuditLog::query()->where('client_id', $this->person->id), $this->reader, $this->person)->pluck('id')->all();
        $this->assertNotContains($log->id, $ids);
    }

    private function audit(ClientMedication $medicine): AuditLog
    {
        return AuditLog::query()->create(['user_id' => $this->reader->id, 'client_id' => $this->person->id, 'action' => 'medications.updated', 'auditable_type' => ClientMedication::class, 'auditable_id' => $medicine->id, 'meta' => ['name' => $medicine->name]]);
    }

    public function test_hub_omits_unreadable_people_and_controlled_medicines(): void
    {
        $this->medicine('Visible medicine');
        $this->medicine('Hidden controlled medicine', ['controlled_drug' => true]);
        $other = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'Unassigned person']);
        $this->medicine('Other medicine', ['client_id' => $other->id]);
        $this->actingAs($this->reader)->get('/emar/medications')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('emar/record/hub')->has('page.data', 1)->where('page.data.0.name', 'Visible medicine')->where('meters.people', 1)->where('meters.medicines', 1)->where('controlled_left_out', true));
    }

    public function test_support_reader_uses_the_canonical_p03_summary_without_a_missing_relation(): void
    {
        $this->medicine('Visible medicine');
        $this->actingAs($this->reader)->getJson($this->url('/support'))->assertOk()->assertJsonPath('plan.client_id', $this->person->id)->assertJsonCount(1, 'plan.medicines')->assertJsonCount(0, 'changes');
    }

    public function test_changes_page_combines_retained_audits_with_new_events_and_conceals_controlled_facts(): void
    {
        $this->reader->permissionOverrides()->syncWithoutDetaching(Permission::where('key', 'medications.audit.view')->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all());
        $this->reader = $this->reader->fresh();
        $legacy = $this->audit($this->medicine('Paracetamol'));
        app(MedicationEventRecorder::class)->append(new MedicationEventData(siteId: $this->site->id, kind: 'clinical.inr', subjectType: 'client', subjectId: (string) $this->person->id, actorId: $this->reader->id, occurredAt: CarbonImmutable::now('UTC'), summary: 'Ordinary event', facts: [], clientId: $this->person->id));
        app(MedicationEventRecorder::class)->append(new MedicationEventData(siteId: $this->site->id, kind: 'correction.requested', subjectType: 'client', subjectId: (string) $this->person->id, actorId: $this->reader->id, occurredAt: CarbonImmutable::now('UTC'), summary: 'Secret event summary', facts: ['secret' => 'Controlled detail'], clientId: $this->person->id, controlled: true));
        $response = $this->actingAs($this->reader)->getJson($this->url('/history?view=changes'))->assertOk()->assertDontSee('Secret event summary')->assertDontSee('Controlled detail');
        $this->assertContains('audit'.$legacy->id, collect($response->json('page.data'))->pluck('key')->all());
        $this->assertContains('clinical.inr', collect($response->json('page.data'))->pluck('action')->all());
        $this->assertSame(1, collect($response->json('page.data'))->where('concealed', true)->count());
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
