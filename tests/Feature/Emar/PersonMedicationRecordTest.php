<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientInrRecord;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationAllergy;
use App\Models\MedicationSyringeDriver;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\DoseSlots\DoseSlotGenerator;
use App\Services\Medication\MarLinkService;
use App\Services\Medication\MedicationProfileAuditPrivacy;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_chart_work_filters_keep_only_people_with_the_requested_work_and_preserve_the_source(): void
    {
        // The projection owes only doses after the order was entered. Model
        // mass assignment does not accept created_at, so establish real entry time.
        Carbon::setTestNow(Carbon::parse('2026-10-02 00:00', 'Pacific/Auckland')->utc());
        $people = [];
        foreach (['Due person' => '09:15', 'Overdue person' => '08:00', 'Later person' => '12:00'] as $name => $time) {
            $person = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => $name]);
            $person->supportWorkers()->attach($this->reader->id);
            $order = $this->medicine($name.' medicine', ['client_id' => $person->id, 'dose_times' => [$time],
                'approval_status' => 'verified']);
            DB::transaction(fn () => app(DoseSlotGenerator::class)->generate($order, '2026-10-03', '2026-10-04', CarbonImmutable::now()));
            $people[$name] = $person;
        }
        Carbon::setTestNow(Carbon::parse('2026-10-03 09:30', 'Pacific/Auckland')->utc());
        $source = '/my-day?date=2026-10-03';
        foreach (['due' => 'Due person', 'overdue' => 'Overdue person'] as $work => $name) {
            $response = $this->actingAs($this->reader)->get('/emar/mar?'.http_build_query([
                'site_id' => $this->site->id, 'work' => $work, 'return_to' => $source,
            ]))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.work', $work)->has('page.data', 1)
                ->where('page.data.0.client_id', $people[$name]->id)->where('page.data.0.'.$work, 1)
                ->where('meters.due', 1)->where('meters.overdue', 1));
            parse_str(parse_url($response->inertiaProps('page.data.0.href'), PHP_URL_QUERY), $query);
            $this->assertSame((string) $people[$name]->id, $query['client_id']);
            $this->assertSame((string) $this->site->id, $query['site_id']);
            $this->assertSame('2026-10-03', $query['date']);
            $this->assertSame($source, $query['return_to']);
        }
        // The rows follow the chosen day; the header's Due now / Late /
        // Recorded today meters are always today's (approved P02, EA-111).
        $this->actingAs($this->reader)->get('/emar/mar?date=2026-10-04')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.date', '2026-10-04')
                ->has('page.data', 3)->where('page.data.0.due', 0)->where('page.data.0.overdue', 0)
                ->where('meters.due', 1)->where('meters.overdue', 1)
                ->where('page.data.0.can_record', false));
        $this->getJson('/emar/mar?date=2026-10-05')->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->getJson('/emar/mar?work=foreign')->assertUnprocessable()->assertJsonValidationErrors('work');
    }

    /** EA-111 (Lane C9): the approved hub meters, and Needs help as a Show filter with its reason. */
    public function test_hub_meters_and_needs_help_follow_todays_schedule(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-02 00:00', 'Pacific/Auckland')->utc());
        $people = [];
        foreach (['Due person' => '09:15', 'Late person' => '08:00', 'Blocked person' => '09:20'] as $name => $time) {
            $person = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => $name]);
            $person->supportWorkers()->attach($this->reader->id);
            $order = $this->medicine($name.' medicine', ['client_id' => $person->id, 'dose_times' => [$time], 'approval_status' => 'verified']);
            DB::transaction(fn () => app(DoseSlotGenerator::class)->generate($order, '2026-10-03', '2026-10-04', CarbonImmutable::now()));
            $people[$name] = [$person, $order];
        }
        // A change to the blocked person's order waits for its check.
        DB::table('medication_dose_slots')->where('client_medication_id', $people['Blocked person'][1]->id)->update(['order_change_pending' => 1]);
        Carbon::setTestNow(Carbon::parse('2026-10-03 09:30', 'Pacific/Auckland')->utc());

        $this->actingAs($this->reader)->get('/emar/mar?'.http_build_query(['site_id' => $this->site->id, 'work' => 'help']))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.work', 'help')->has('page.data', 1)
                ->where('page.data.0.client_id', $people['Blocked person'][0]->id)->where('page.data.0.help', 1)
                ->where('page.data.0.help_reason', 'A change to the order is waiting to be checked')
                ->where('meters.due', 1)->where('meters.due_people', 1)->where('meters.overdue', 1)->where('meters.help', 1)
                // Staff doses due so far: the due and the late one; the blocked dose isn't owed yet.
                ->where('meters.so_far', 2)->where('meters.recorded', 0)->where('meters.as_needed', 0));
    }

    /** EA-111 (Lane C9g): the charts list is grouped by house, then name, and shows each person's allergy record. */
    public function test_hub_charts_group_by_house_and_show_allergy_status(): void
    {
        $this->site->update(['name' => 'Zzz House']);
        $second = Site::factory()->create(['is_active' => true, 'name' => 'Aaa House']);
        // The person rule: an assigned worker reads people at the houses on their HR profile.
        HrEmployeeProfile::query()->where('user_id', $this->reader->id)->firstOrFail()->update(['secondary_site_ids' => [$second->id]]);
        $zoe = Client::factory()->create(['site_id' => $second->id, 'first_name' => 'Zoe', 'last_name' => 'Allergic']);
        $amy = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'Amy', 'last_name' => 'Plain']);
        foreach ([$zoe, $amy, $this->person] as $person) {
            $person->supportWorkers()->syncWithoutDetaching([$this->reader->id]);
            $this->medicine($person->first_name.' medicine', ['client_id' => $person->id]);
        }
        MedicationAllergy::query()->create(['client_id' => $zoe->id, 'allergen' => 'Penicillin', 'severity' => 'severe', 'reaction' => 'Rash', 'recorded_by' => $this->reader->id]);

        $this->actingAs($this->reader)->get('/emar/mar')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('page.data', 3)
            ->where('page.data.0.person', 'Zoe Allergic')->where('page.data.0.house_id', $second->id)
            ->where('page.data.0.allergy.status', 'recorded')->where('page.data.0.allergy.count', 1)->where('page.data.0.allergy.reviewed', false)
            ->where('page.data.1.person', 'Amy Plain')->where('page.data.1.house_id', $this->site->id)
            ->where('page.data.1.allergy.status', 'none')->where('page.data.1.allergy.count', 0)
            ->where('page.data.2.client_id', $this->person->id)
            ->where('house_counts.'.$second->id, 1)->where('house_counts.'.$this->site->id, 2));
    }

    /** EA-110 (Lane C9): the record header's Due now, Late and Recorded today for one person. */
    public function test_record_header_counts_the_persons_doses_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-02 00:00', 'Pacific/Auckland')->utc());
        $order = $this->medicine('Three times daily medicine', ['dose_times' => ['08:00', '09:15', '12:00'], 'approval_status' => 'verified']);
        DB::transaction(fn () => app(DoseSlotGenerator::class)->generate($order, '2026-10-03', '2026-10-04', CarbonImmutable::now()));
        Carbon::setTestNow(Carbon::parse('2026-10-03 09:30', 'Pacific/Auckland')->utc());

        $this->actingAs($this->reader)->get('/emar/mar?client_id='.$this->person->id)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('emar/record/show')
                ->where('meters.doses.scheduled', 3)->where('meters.doses.due', 1)->where('meters.doses.late', 1)
                ->where('meters.doses.so_far', 2)->where('meters.doses.recorded', 0)
                ->whereNot('meters.doses.due_until', null)->whereNot('meters.doses.oldest_late', null)
                ->where('can.print', false)->where('today', '2026-10-03'));
    }

    public function test_medicine_rows_keep_canonical_person_house_date_and_filtered_return_location(): void
    {
        $order = $this->medicine('Visible medicine');
        $source = '/emar/medications?site_id='.$this->site->id.'&q=Visible&date=2026-10-02';
        $response = $this->actingAs($this->reader)->get($source)->assertOk();
        parse_str(parse_url($response->inertiaProps('page.data.0.href'), PHP_URL_QUERY), $query);
        $this->assertSame((string) $this->person->id, $query['client_id']);
        $this->assertSame((string) $this->site->id, $query['site_id']);
        $this->assertSame((string) $order->id, $query['medication_id']);
        $this->assertSame('2026-10-02', $query['date']);
        $this->assertSame('medicines', $query['tab']);
        $this->assertSame($source, $query['return_to']);
    }

    public function test_mar_links_require_person_authority_and_the_canonical_house(): void
    {
        $links = app(MarLinkService::class);
        $source = '/operations/handovers?site_id='.$this->site->id;
        $url = $links->urlFor($this->reader, $this->person->id, '2026-10-02', ['site_id' => $this->site->id, 'return_to' => $source]);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('2026-10-02', $query['date']);
        $this->assertSame($source, $query['return_to']);
        $this->assertNull($links->urlFor($this->reader, $this->person->id, '2026-10-02', ['site_id' => $this->site->id + 10000]));
        $other = Client::factory()->create(['site_id' => $this->site->id]);
        $this->assertNull($links->urlFor($this->reader, $other->id, '2026-10-02'));
        $url = $links->urlFor($this->reader, $this->person->id, null, ['return_to' => '//example.com']);
        $this->assertStringNotContainsString('return_to', $url);
    }

    public function test_as_needed_history_rows_open_the_actual_nz_dose_day_and_keep_the_history_period(): void
    {
        $order = $this->medicine('As needed medicine', ['is_prn' => true]);
        $dose = ClientMedicationAdministration::query()->create([
            'client_id' => $this->person->id, 'client_medication_id' => $order->id,
            'administered_by' => $this->reader->id, 'status' => 'given',
            'administered_at' => Carbon::parse('2026-10-02 23:30', 'Pacific/Auckland')->utc(),
            'dose_given' => '1 tablet',
        ]);
        $source = '/emar/prn?range=7&date=2026-10-03';
        $response = $this->actingAs($this->reader)->get($source)->assertOk();
        parse_str(parse_url($response->inertiaProps('page.data.0.href'), PHP_URL_QUERY), $query);
        $this->assertSame((string) $this->person->id, $query['client_id']);
        $this->assertSame((string) $this->site->id, $query['site_id']);
        $this->assertSame((string) $dose->id, $query['dose_id']);
        $this->assertSame('history', $query['tab']);
        $this->assertSame('2026-10-02', $query['date']);
        $this->assertSame($source, $query['return_to']);
    }

    /** EA-109 (Lane C9): History's Range and Outcome filters are applied by the server, in NZ days. */
    public function test_history_range_and_outcome_filters(): void
    {
        $order = $this->medicine('As needed medicine', ['is_prn' => true]);
        foreach ([['given', '2026-09-28 08:00'], ['refused', '2026-09-20 08:00'], ['given', '2026-08-01 08:00']] as [$status, $at]) {
            ClientMedicationAdministration::query()->create([
                'client_id' => $this->person->id, 'client_medication_id' => $order->id, 'administered_by' => $this->reader->id,
                'status' => $status, 'administered_at' => Carbon::parse($at, 'Pacific/Auckland')->utc(), 'dose_given' => '1 tablet',
                'reason' => $status === 'refused' ? 'Declined' : null,
            ]);
        }
        $count = fn (string $query) => $this->actingAs($this->reader)->getJson($this->url('/history?view=doses&'.$query))->assertOk()->json('page.total');

        $this->assertSame(1, $count('range=7d'));
        $this->assertSame(2, $count('range=30d'));
        $this->assertSame(3, $count('range=all'));
        $this->assertSame(3, $count(''), 'Without a range the history stays complete.');
        $this->assertSame(1, $count('range=30d&outcome=notgiven'));
        $this->assertSame(2, $count('range=all&outcome=given'));
        $this->assertSame(0, $count('range=all&outcome=corrected'));
        $this->getJson($this->url('/history?range=1y'))->assertUnprocessable()->assertJsonValidationErrors('range');
    }

    /** EA-109 (Lane C9f): Clinical's Range filter is applied by the server in NZ days; a running driver always shows. */
    public function test_clinical_range_filter(): void
    {
        $order = $this->medicine('Warfarin');
        foreach (['2026-09-20', '2026-05-01', '2025-06-01'] as $tested) {
            ClientInrRecord::query()->create(['client_id' => $this->person->id, 'client_medication_id' => $order->id, 'inr_value' => 2.5, 'tested_on' => $tested, 'recorded_by' => $this->reader->id]);
        }
        $nz = fn (string $at) => Carbon::parse($at, 'Pacific/Auckland')->utc();
        $driver = ['client_id' => $this->person->id, 'site_id' => $this->site->id, 'commenced_by' => $this->reader->id, 'contents' => [['client_medication_id' => $order->id, 'name' => $order->name, 'dose' => '1', 'unit' => 'mg']]];
        MedicationSyringeDriver::query()->create($driver + ['status' => 'running', 'commenced_at' => $nz('2025-08-01 10:00')]);
        MedicationSyringeDriver::query()->create($driver + ['status' => 'completed', 'commenced_at' => $nz('2026-02-01 10:00'), 'completed_at' => $nz('2026-02-03 10:00')]);
        MedicationSyringeDriver::query()->create($driver + ['status' => 'completed', 'commenced_at' => $nz('2025-03-01 10:00'), 'completed_at' => $nz('2025-03-04 10:00')]);
        foreach (['2026-09-01 08:00', '2026-03-01 08:00', '2025-01-10 08:00'] as $at) {
            ClientMedicationAdministration::query()->create([
                'client_id' => $this->person->id, 'client_medication_id' => $order->id, 'administered_by' => $this->reader->id,
                'status' => 'given', 'administered_at' => $nz($at), 'dose_given' => '1 tablet', 'blood_glucose_level' => 6.1,
            ]);
        }
        $counts = function (string $query) {
            $json = $this->actingAs($this->reader)->getJson($this->url('/clinical'.($query === '' ? '' : '?'.$query)))->assertOk()->json();

            return [count($json['inr']['rows']), count($json['drivers']), count($json['observations']['rows'])];
        };

        // 3 months back from Sat 3 Oct (NZ) is 3 Jul; the long-running driver still shows.
        $this->assertSame([1, 1, 1], $counts('range=3m'));
        $this->assertSame([2, 2, 2], $counts('range=12m'));
        $this->assertSame([3, 3, 3], $counts('range=all'));
        $this->assertSame([3, 3, 3], $counts(''), 'Without a range the clinical record stays complete.');
        $this->getJson($this->url('/clinical?range=7d'))->assertUnprocessable()->assertJsonValidationErrors('range');
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
