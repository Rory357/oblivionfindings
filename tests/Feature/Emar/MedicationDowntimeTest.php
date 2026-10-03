<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationAdminRule;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationDowntime;
use App\Models\MedicationEvent;
use App\Models\MedicationPaperEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Downtime\DowntimeEvents;
use App\Services\Medication\Downtime\DowntimePackPdf;
use App\Services\Medication\Downtime\DowntimePackService;
use App\Services\Medication\Downtime\DowntimeService;
use App\Services\Medication\Downtime\PaperAdministrationWriter;
use App\Services\Medication\Downtime\PaperEntryService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery;
use Tests\TestCase;

class MedicationDowntimeTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private User $lead;

    private ClientMedication $order;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-03 00:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        // P09 owns the production grant foundation; this older P10 base
        // supplies the key only in isolated synthetic test fixtures.
        Permission::query()->firstOrCreate(['key' => 'medications.reports.view'], ['description' => 'View medication reports', 'group' => 'medications', 'module' => 'Clinical']);
        if (! Route::has('emar.downtime.index')) {
            Route::middleware('web')->group(base_path('routes/emar-downtime.php'));
        }
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $this->lead = $this->staff($this->site, 'team_lead', ['medications.view', 'medications.administer.record', 'clients.viewAny', 'medications.reports.view', 'medications.reports.export', 'medications.controlled.view']);
        $this->order = ClientMedication::factory()->create([
            'client_id' => $this->client->id, 'name' => 'Synthetic medicine', 'dosage' => '10 mg', 'dose_amount' => 10, 'dose_unit' => 'mg',
            'active' => true, 'state' => 'active', 'approval_status' => 'verified', 'verified_at' => now(),
            'start_date' => '2026-10-01', 'end_date' => null, 'dose_times' => ['09:00'], 'route' => 'oral', 'frequency' => 'Once daily', 'is_prn' => false, 'controlled_drug' => false, 'witness_required' => false,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-03 12:00', 'Pacific/Auckland')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_only_oversight_can_declare_and_foreign_objects_are_not_found(): void
    {
        $worker = $this->staff($this->site, 'support_worker', ['medications.view', 'medications.administer.record']);
        $this->actingAs($worker)->post('/emar/downtime', $this->declaration())->assertForbidden();
        $downtime = $this->declare();
        $foreign = Site::factory()->create(['is_active' => true]);
        $other = $this->staff($foreign, 'team_lead', ['medications.view', 'medications.administer.record', 'clients.viewAny']);
        $this->actingAs($other)->get('/emar/downtime/'.$downtime->id)->assertNotFound();
    }

    public function test_preview_checks_actual_time_without_inventing_a_paper_outcome(): void
    {
        $downtime = $this->declare();
        $preview = app(PaperEntryService::class)->preview($this->lead, $downtime, $this->facts($downtime, ['given_at' => '2026-10-03T11:10']));
        $this->assertFalse($preview['can_submit']);
        $this->assertContains('The time on paper must be inside this downtime.', $preview['errors']);
        $this->assertDatabaseCount('medication_paper_entries', 0);
        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_own_accountable_evidence_is_confirmed_but_not_clinically_posted(): void
    {
        $downtime = $this->declare();
        $entry = $this->capture($downtime);
        $this->assertDatabaseHas('medication_paper_confirmations', ['paper_entry_id' => $entry->id, 'kind' => 'giver', 'confirmed_by' => $this->lead->id]);
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertSame('ready_to_reconcile', app(PaperEntryService::class)->reconciliationPreview($this->lead, $downtime, $entry)['state']);
        $this->assertSame('10 mg', $entry->dose_on_paper);
        $this->assertSame($this->lead->id, $entry->given_by);
    }

    public function test_collecting_another_givers_paper_requires_that_giver_and_never_posts_it(): void
    {
        $downtime = $this->declare();
        $giver = $this->staff($this->site, 'support_worker', ['medications.view', 'medications.administer.record', 'clients.viewAny']);
        $entry = $this->capture($downtime, ['given_by' => $giver->id]);
        $this->assertSame('giver_to_confirm', app(PaperEntryService::class)->reconciliationPreview($this->lead, $downtime, $entry)['state']);
        $this->actingAs($this->lead)->post('/emar/downtime/'.$downtime->id.'/paper/'.$entry->id.'/confirm', ['kind' => 'giver', 'accountable_confirmation' => true])->assertNotFound();
        $this->actingAs($giver)->post('/emar/downtime/'.$downtime->id.'/paper/'.$entry->id.'/confirm', ['kind' => 'giver', 'accountable_confirmation' => true])->assertRedirect();
        $this->actingAs($giver)->post('/emar/downtime/'.$downtime->id.'/paper/'.$entry->id.'/confirm', ['kind' => 'giver', 'accountable_confirmation' => true])->assertRedirect();
        $this->assertDatabaseCount('medication_paper_confirmations', 1);
        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_paper_entry_needs_explicit_accountable_confirmation_on_the_server(): void
    {
        $downtime = $this->declare();
        $facts = $this->facts($downtime);
        $preview = app(PaperEntryService::class)->preview($this->lead, $downtime, $facts);
        $this->actingAs($this->lead)->postJson('/emar/downtime/'.$downtime->id.'/paper', $facts + ['request_uuid' => (string) Str::uuid(), 'preview_token' => $preview['preview_token'], 'accountable_confirmation' => false])->assertUnprocessable()->assertJsonValidationErrors('accountable_confirmation');
        $this->assertDatabaseCount('medication_paper_entries', 0);
    }

    public function test_overlapping_downtimes_cannot_collect_the_same_dose_twice(): void
    {
        $first = $this->declare();
        $this->capture($first);
        $second = $this->declare();
        $preview = app(PaperEntryService::class)->preview($this->lead, $second, $this->facts($second));
        $this->assertFalse($preview['can_submit']);
        $this->assertSame('paper_duplicate', $preview['conflicts'][0]['kind']);
        $this->assertDatabaseCount('medication_paper_entries', 1);
    }

    public function test_a_new_clinical_record_invalidates_the_reviewed_paper_preview(): void
    {
        $downtime = $this->declare();
        $facts = $this->facts($downtime);
        $preview = app(PaperEntryService::class)->preview($this->lead, $downtime, $facts);
        $target = $downtime->doses()->firstOrFail();
        ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id, 'client_medication_id' => $this->order->id, 'administered_by' => $this->lead->id,
            'scheduled_for' => $target->scheduled_for, 'administered_at' => CarbonImmutable::parse('2026-10-03T09:10', 'Pacific/Auckland')->utc(), 'status' => 'given',
        ]);
        $this->actingAs($this->lead)->postJson('/emar/downtime/'.$downtime->id.'/paper', $facts + ['request_uuid' => (string) Str::uuid(), 'preview_token' => $preview['preview_token'], 'accountable_confirmation' => true])->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        $this->assertDatabaseCount('medication_paper_entries', 0);
    }

    public function test_required_second_person_cannot_be_omitted_or_self_named(): void
    {
        MedicationAdminRule::query()->create(['match_type' => 'medicine_name', 'match_value' => 'Synthetic', 'requires_countersign' => true, 'required_observations' => [], 'active' => true]);
        $downtime = $this->declare();
        $missing = app(PaperEntryService::class)->preview($this->lead, $downtime, $this->facts($downtime));
        $this->assertFalse($missing['can_submit']);
        $this->assertTrue($missing['second_person_required']);
        $self = app(PaperEntryService::class)->preview($this->lead, $downtime, $this->facts($downtime, ['witness_id' => $this->lead->id]));
        $this->assertFalse($self['can_submit']);
        $this->assertContains('The giver and second person must be different people.', $self['errors']);
    }

    public function test_controlled_paper_evidence_does_not_rebalance_or_create_unsigned_movements(): void
    {
        ClientMedication::withoutEvents(fn () => $this->order->update(['controlled_drug' => true]));
        $downtime = $this->declare();
        $witness = $this->staff($this->site, 'support_worker', ['medications.view', 'medications.controlled.view', 'medications.controlled.witness']);
        $entry = $this->capture($downtime, ['witness_id' => $witness->id]);
        $preview = app(PaperEntryService::class)->reconciliationPreview($this->lead, $downtime, $entry);
        $this->assertSame('witness_to_confirm', $preview['state']);
        $this->assertStringContainsString('not configured', $preview['unavailable']);
        $this->assertFalse($preview['can_reconcile']);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('medication_paper_postings', 0);
        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_audit_failure_rolls_back_paper_facts_and_own_confirmation(): void
    {
        $downtime = $this->declare();
        $events = Mockery::mock(DowntimeEvents::class);
        $events->shouldReceive('record')->once()->andThrow(new \RuntimeException('Synthetic event failure'));
        $this->app->instance(DowntimeEvents::class, $events);
        try {
            $this->capture($downtime);
            $this->fail('The transaction should fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic event failure', $e->getMessage());
        }
        $this->assertDatabaseCount('medication_paper_entries', 0);
        $this->assertDatabaseCount('medication_paper_confirmations', 0);
    }

    public function test_saved_paper_facts_are_append_only(): void
    {
        $entry = $this->capture($this->declare());
        $this->expectException(LogicException::class);
        $entry->update(['given_by' => 999]);
    }

    public function test_finish_requires_each_paper_fact_but_does_not_post_the_confirmed_evidence(): void
    {
        $downtime = $this->declare();
        $this->actingAs($this->lead)->post('/emar/downtime/'.$downtime->id.'/finish')->assertSessionHasErrors('finish');
        $this->capture($downtime);
        $this->actingAs($this->lead)->post('/emar/downtime/'.$downtime->id.'/finish')->assertRedirect();
        $this->assertNotNull($downtime->fresh()->finished_at);
        $this->assertDatabaseCount('medication_paper_postings', 0);
    }

    public function test_pack_omits_cross_person_controlled_content_and_uses_rule_boxes(): void
    {
        $clock = Carbon::getTestNow();
        Carbon::setTestNow(Carbon::parse('2026-10-03 00:00', 'Pacific/Auckland')->utc());
        ClientMedication::factory()->create(['client_id' => $this->client->id, 'name' => 'Concealed synthetic medicine', 'start_date' => '2026-10-01', 'end_date' => null, 'approval_status' => 'verified', 'verified_at' => now(), 'controlled_drug' => true, 'dose_times' => ['09:00'], 'is_prn' => false, 'active' => true]);
        Carbon::setTestNow($clock);
        MedicationAdminRule::query()->create(['match_type' => 'medicine_name', 'match_value' => 'Synthetic medicine', 'requires_countersign' => true, 'required_observations' => ['blood_glucose'], 'active' => true]);
        $reader = $this->staff($this->site, 'clinical_lead', ['medications.view', 'clients.viewAny', 'medications.reports.view', 'medications.reports.export']);
        $deny = Permission::query()->where('key', 'medications.controlled.view')->firstOrFail();
        $reader->permissionOverrides()->syncWithoutDetaching([$deny->id => ['allowed' => false]]);
        $reader = $reader->fresh();
        $pack = app(DowntimePackService::class)->build($reader, $this->site->id, '2026-10-03');
        $this->assertFalse($pack['controlled_pages_included']);
        $this->assertSame([], $pack['controlled_registers']);
        $this->assertStringNotContainsString('Concealed synthetic medicine', json_encode($pack));
        $dose = collect($pack['rounds'])->firstWhere('medicine', 'Synthetic medicine');
        $this->assertTrue($dose['second_person_required']);
        $this->assertContains('Blood sugar (BSL)', $dose['readings']);
        $html = view('pdf.medication-downtime-pack', ['pack' => $pack])->render();
        $this->assertStringContainsString('Name / signature:', $html);
        $this->assertStringContainsString('Blood sugar (BSL)', $html);
        $this->assertStringNotContainsString('Concealed synthetic medicine', $html);
    }

    public function test_pack_refuses_an_incomplete_projection_instead_of_printing_missing_doses(): void
    {
        DB::table('medication_dose_slots')->where('client_medication_id', $this->order->id)->delete();
        $this->expectException(ValidationException::class);
        app(DowntimePackService::class)->build($this->lead, $this->site->id, '2026-10-03');
    }

    public function test_downtime_refuses_an_incomplete_projection_instead_of_finishing_an_empty_list(): void
    {
        DB::table('medication_dose_slots')->where('client_medication_id', $this->order->id)->delete();
        $this->expectException(ValidationException::class);
        $this->declare();
    }

    public function test_pack_today_and_tomorrow_are_nz_days_not_utc_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-03 00:10', 'Pacific/Auckland')->utc());
        $this->assertSame('2026-10-03', app(DowntimePackService::class)->build($this->lead, $this->site->id, '2026-10-03')['nz_date']);
        $this->assertSame('2026-10-04', app(DowntimePackService::class)->build($this->lead, $this->site->id, '2026-10-04')['nz_date']);
        $this->expectException(ValidationException::class);
        app(DowntimePackService::class)->build($this->lead, $this->site->id, '2026-10-02');
    }

    public function test_paper_cannot_post_using_read_permission_without_actual_covering_assignment(): void
    {
        $downtime = $this->declare();
        $entry = $this->capture($downtime);
        $preview = app(PaperEntryService::class)->reconciliationPreview($this->lead, $downtime, $entry);
        $this->assertFalse($preview['can_reconcile']);
        $this->assertStringContainsString('covering assignment', $preview['unavailable']);
        $result = app(PaperEntryService::class)->reconcile($this->lead, $downtime, $entry, $preview['preview_token']);
        $this->assertFalse($result['success']);
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('medication_paper_postings', 0);
    }

    public function test_completed_assignment_cannot_post_given_paper_without_historical_stock_disposition(): void
    {
        $this->coveringAuthority();
        $this->actingAs($this->lead);
        $downtime = $this->declare();
        $entry = $this->capture($downtime);
        $preview = app(PaperEntryService::class)->reconciliationPreview($this->lead, $downtime, $entry);
        $this->assertFalse($preview['can_reconcile']);
        $this->assertStringContainsString('physical stock disposition and count coverage', $preview['unavailable']);
        $result = app(PaperEntryService::class)->reconcile($this->lead, $downtime, $entry, $preview['preview_token']);
        $this->assertFalse($result['success']);
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('medication_paper_postings', 0);
        $this->assertDatabaseCount('medication_idempotency_results', 0);
        $this->assertSame(0, DB::table('medication_dose_slots')->whereNotNull('outcome_administration_id')->count());
        $this->assertSame('ready_to_reconcile', app(PaperEntryService::class)->reconciliationPreview($this->lead, $downtime, $entry)['state']);
    }

    public function test_final_export_event_failure_keeps_rendered_pdf_private(): void
    {
        $this->fakePackRender(fn () => null);
        $events = Mockery::mock(DowntimeEvents::class);
        $events->shouldReceive('packMade')->once()->andThrow(new \RuntimeException('Synthetic final event failure'));
        $this->app->instance(DowntimeEvents::class, $events);
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->lead)->postJson('/emar/downtime/pack', ['site_id' => $this->site->id, 'nz_date' => '2026-10-03']);
            $this->fail('Final event failure must prevent release of the rendered PDF.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic final event failure', $e->getMessage());
        }
        $this->assertSame(0, MedicationEvent::query()->where('kind', 'export.downtime_pack')->count());
    }

    public function test_non_given_mapping_retains_paper_reason_without_given_only_fields_or_clinical_posting(): void
    {
        foreach (['refused', 'withheld'] as $outcome) {
            if ($outcome === 'withheld') {
                $copy = $this->order->replicate();
                $copy->name = 'Synthetic withheld medicine';
                $copy->save();
                $this->order = $copy->fresh();
            }
            $downtime = $this->declare();
            $target = $downtime->doses()->where('client_medication_id', $this->order->id)->firstOrFail();
            $entry = $this->capture($downtime, ['downtime_dose_id' => $target->id, 'outcome' => $outcome, 'dose_on_paper' => null,
                'notes' => 'Exact synthetic paper explanation', 'observations' => ['blood_glucose' => 4.2]]);
            $facts = app(PaperAdministrationWriter::class)->canonicalData($entry);
            $this->assertSame($outcome, $facts['reason_code']);
            $this->assertSame('Exact synthetic paper explanation', $facts['reason']);
            $this->assertSame($outcome, $entry->snapshot['reason_code']);
            foreach (['dose_given', 'amount_mode', 'blood_glucose', 'reason_category', 'witness_id', 'witness_pin'] as $key) {
                $this->assertArrayNotHasKey($key, $facts);
            }
            $preview = app(PaperEntryService::class)->reconciliationPreview($this->lead, $downtime, $entry);
            $this->assertFalse($preview['can_reconcile']);
            $this->assertStringContainsString('canonical historical non-given adapter', $preview['unavailable']);
        }
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('medication_paper_postings', 0);
    }

    public function test_non_given_paper_requires_the_explanation_on_paper(): void
    {
        $downtime = $this->declare();
        $preview = app(PaperEntryService::class)->preview($this->lead, $downtime, $this->facts($downtime, ['outcome' => 'refused', 'dose_on_paper' => null, 'notes' => '']));
        $this->assertFalse($preview['can_submit']);
        $this->assertStringContainsString('explanation written on the paper', implode(' ', $preview['errors']));
    }

    public function test_reconciliation_preview_binds_schedule_dates_and_prn_limits_even_while_held(): void
    {
        $downtime = $this->declare();
        $entry = $this->capture($downtime);
        foreach (['dose_times' => ['10:00'], 'start_date' => '2026-10-02', 'end_date' => '2026-10-04', 'max_per_day' => 4, 'min_hours_between_doses' => 6] as $field => $value) {
            $preview = app(PaperEntryService::class)->reconciliationPreview($this->lead, $downtime, $entry, $this->order->fresh());
            ClientMedication::withoutEvents(fn () => $this->order->update([$field => $value]));
            try {
                app(PaperEntryService::class)->reconcile($this->lead, $downtime, $entry, $preview['preview_token']);
                $this->fail('Changed '.$field.' must invalidate the execution preview.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('preview_token', $e->errors());
            }
        }
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('medication_paper_postings', 0);
    }

    public function test_reconciliation_preview_binds_matching_rule_revision_even_when_requirements_are_unchanged(): void
    {
        $rule = MedicationAdminRule::query()->create(['site_id' => $this->site->id, 'match_type' => 'route', 'match_value' => 'oral',
            'requires_countersign' => false, 'required_observations' => [], 'active' => true]);
        $downtime = $this->declare();
        $entry = $this->capture($downtime);
        $preview = app(PaperEntryService::class)->reconciliationPreview($this->lead, $downtime, $entry);
        $rule->update(['updated_at' => now()->addMinute()]);
        $this->expectException(ValidationException::class);
        app(PaperEntryService::class)->reconcile($this->lead, $downtime, $entry, $preview['preview_token']);
    }

    public function test_direct_collection_service_requires_accountable_approval(): void
    {
        $downtime = $this->declare();
        $facts = $this->facts($downtime);
        $preview = app(PaperEntryService::class)->preview($this->lead, $downtime, $facts);
        $this->expectException(ValidationException::class);
        app(PaperEntryService::class)->capture($this->lead, $downtime, $facts + ['request_uuid' => (string) Str::uuid(), 'preview_token' => $preview['preview_token']]);
    }

    public function test_voluntary_named_witness_has_actionable_confirmation_and_historical_hold(): void
    {
        $downtime = $this->declare();
        $witness = $this->staff($this->site, 'support_worker', ['medications.view', 'medications.administer.record', 'clients.viewAny']);
        $entry = $this->capture($downtime, ['witness_id' => $witness->id]);
        $this->assertTrue($entry->snapshot['second_person_required']);
        $pending = app(DowntimeService::class)->pendingConfirmations($witness);
        $this->assertCount(1, $pending);
        $this->assertSame('witness', $pending[0]['kind']);
        $this->assertSame($this->client->id, $pending[0]['client_id']);
        $this->assertSame($this->site->id, $pending[0]['site_id']);
        $preview = app(PaperEntryService::class)->reconciliationPreview($this->lead, $downtime, $entry);
        $this->assertSame('witness_to_confirm', $preview['state']);
        $this->assertStringContainsString('Historical second-person', $preview['unavailable']);
    }

    public function test_synced_existing_dose_requires_reviewed_link_before_collection_can_finish(): void
    {
        $downtime = $this->declare();
        $target = $downtime->doses()->firstOrFail();
        $record = ClientMedicationAdministration::query()->create(['client_id' => $this->client->id, 'client_medication_id' => $this->order->id,
            'administered_by' => $this->lead->id, 'scheduled_for' => $target->scheduled_for, 'administered_at' => $target->scheduled_for->addMinutes(10), 'status' => 'given']);
        $this->actingAs($this->lead)->post('/emar/downtime/'.$downtime->id.'/finish')->assertSessionHasErrors('finish');
        $this->actingAs($this->lead)->post('/emar/downtime/'.$downtime->id.'/doses/'.$target->id.'/resolve', [
            'kind' => 'clinical', 'record_id' => $record->id, 'reason' => 'Synthetic signed paper checked against synced canonical record.', 'accountable_confirmation' => true,
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->lead)->post('/emar/downtime/'.$downtime->id.'/finish')->assertSessionHasNoErrors();
        $this->assertNotNull($downtime->fresh()->finished_at);
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('medication_paper_entries', 0);
        $this->assertDatabaseCount('medication_downtime_resolutions', 1);
    }

    public function test_overlap_resolution_links_existing_paper_without_collecting_a_second_entry(): void
    {
        $first = $this->declare();
        $entry = $this->capture($first);
        $second = $this->declare();
        $dose = $second->doses()->firstOrFail();
        app(PaperEntryService::class)->resolveDuplicate($this->lead, $second, $dose, 'paper', $entry->id, 'Synthetic signed paper matches the first collection.');
        app(DowntimeService::class)->finish($this->lead, $second);
        $this->assertNotNull($second->fresh()->finished_at);
        $this->assertDatabaseCount('medication_paper_entries', 1);
        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_controlled_targets_are_preserved_internally_and_snapshot_privacy_is_sticky(): void
    {
        ClientMedication::withoutEvents(fn () => $this->order->update(['controlled_drug' => true]));
        $downtime = $this->declare();
        $witness = $this->staff($this->site, 'support_worker', ['medications.view', 'medications.controlled.view', 'medications.controlled.witness']);
        $entry = $this->capture($downtime, ['witness_id' => $witness->id]);
        $reader = $this->staff($this->site, 'clinical_lead', ['medications.view', 'clients.viewAny', 'medications.reports.view', 'medications.reports.export']);
        $reader->permissionOverrides()->syncWithoutDetaching([Permission::query()->where('key', 'medications.controlled.view')->firstOrFail()->id => ['allowed' => false]]);
        $second = app(DowntimeService::class)->declare($reader->fresh(), $this->declaration());
        $this->assertCount(1, $second->doses);
        $this->assertTrue($second->doses->first()->snapshot['controlled']);
        ClientMedication::withoutEvents(fn () => $this->order->update(['controlled_drug' => false]));
        $this->actingAs($reader->fresh())->get('/emar/downtime/'.$downtime->id.'/paper/'.$entry->id.'/reconciliation')->assertNotFound();
    }

    public function test_pack_omits_reclassified_controlled_order_even_if_slot_remains_ordinary(): void
    {
        ClientMedication::withoutEvents(fn () => $this->order->update(['controlled_drug' => true]));
        $reader = $this->staff($this->site, 'clinical_lead', ['medications.view', 'clients.viewAny', 'medications.reports.view', 'medications.reports.export']);
        $reader->permissionOverrides()->syncWithoutDetaching([Permission::query()->where('key', 'medications.controlled.view')->firstOrFail()->id => ['allowed' => false]]);
        $pack = app(DowntimePackService::class)->build($reader->fresh(), $this->site->id, '2026-10-03');
        $this->assertStringNotContainsString('Synthetic medicine', json_encode($pack));
    }

    public function test_actual_pdf_download_renders_generic_concealment_notice_and_logs_release(): void
    {
        $this->lead->permissionOverrides()->syncWithoutDetaching([Permission::query()->where('key', 'medications.controlled.view')->firstOrFail()->id => ['allowed' => false]]);
        $response = $this->actingAs($this->lead->fresh())->post('/emar/downtime/pack', ['site_id' => $this->site->id, 'nz_date' => '2026-10-03']);
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertGreaterThan(1000, strlen($response->getContent()));
        $event = MedicationEvent::query()->where('kind', 'export.downtime_pack')->sole();
        $this->assertSame(DowntimePackService::PURPOSE, $event->facts['purpose']);
        $this->assertFalse($event->facts['controlled_pages_included']);
    }

    public function test_pack_requires_p09_report_and_export_capabilities(): void
    {
        $this->lead->permissionOverrides()->syncWithoutDetaching([Permission::query()->where('key', 'medications.reports.view')->firstOrFail()->id => ['allowed' => false]]);
        $this->actingAs($this->lead->fresh())->postJson('/emar/downtime/pack', ['site_id' => $this->site->id, 'nz_date' => '2026-10-03'])->assertForbidden();
        $this->assertSame(0, MedicationEvent::query()->where('kind', 'export.downtime_pack')->count());
    }

    public function test_finance_only_report_overrides_cannot_release_person_pack_details(): void
    {
        $finance = $this->staff($this->site, 'finance', ['medications.reports.view', 'medications.reports.export']);
        $this->actingAs($finance)->postJson('/emar/downtime/pack/preview', ['site_id' => $this->site->id, 'nz_date' => '2026-10-03'])->assertForbidden();
        $this->actingAs($finance)->postJson('/emar/downtime/pack', ['site_id' => $this->site->id, 'nz_date' => '2026-10-03'])->assertForbidden();
        $this->assertSame(0, MedicationEvent::query()->where('kind', 'export.downtime_pack')->count());
    }

    public function test_revoked_export_authority_during_render_releases_no_bytes_or_export_event(): void
    {
        $this->fakePackRender(function (): void {
            $this->lead->permissionOverrides()->syncWithoutDetaching([Permission::query()->where('key', 'medications.reports.export')->firstOrFail()->id => ['allowed' => false]]);
        });
        $response = $this->actingAs($this->lead)->postJson('/emar/downtime/pack', ['site_id' => $this->site->id, 'nz_date' => '2026-10-03']);
        $response->assertForbidden();
        $this->assertStringNotContainsString('%PDF-sensitive', $response->getContent());
        $this->assertSame(0, MedicationEvent::query()->where('kind', 'export.downtime_pack')->count());
    }

    public function test_revoked_account_approval_during_render_releases_no_bytes_or_export_event(): void
    {
        $this->fakePackRender(fn () => User::withoutEvents(fn () => $this->lead->forceFill(['approved_at' => null])->save()));
        $response = $this->actingAs($this->lead)->postJson('/emar/downtime/pack', ['site_id' => $this->site->id, 'nz_date' => '2026-10-03']);
        $response->assertForbidden();
        $this->assertStringNotContainsString('%PDF-sensitive', $response->getContent());
        $this->assertSame(0, MedicationEvent::query()->where('kind', 'export.downtime_pack')->count());
    }

    public function test_revoked_approved_site_during_render_releases_no_bytes_or_export_event(): void
    {
        $this->fakePackRender(fn () => $this->lead->hrEmployeeProfile->update(['primary_site_id' => null, 'secondary_site_ids' => []]));
        $response = $this->actingAs($this->lead)->postJson('/emar/downtime/pack', ['site_id' => $this->site->id, 'nz_date' => '2026-10-03']);
        $response->assertNotFound();
        $this->assertStringNotContainsString('%PDF-sensitive', $response->getContent());
        $this->assertSame(0, MedicationEvent::query()->where('kind', 'export.downtime_pack')->count());
    }

    public function test_controlled_view_revocation_during_render_releases_no_bytes_or_export_event(): void
    {
        $this->fakePackRender(function (): void {
            $this->lead->permissionOverrides()->syncWithoutDetaching([Permission::query()->where('key', 'medications.controlled.view')->firstOrFail()->id => ['allowed' => false]]);
        });
        $response = $this->actingAs($this->lead)->postJson('/emar/downtime/pack', ['site_id' => $this->site->id, 'nz_date' => '2026-10-03']);
        $response->assertConflict();
        $this->assertStringNotContainsString('%PDF-sensitive', $response->getContent());
        $this->assertSame(0, MedicationEvent::query()->where('kind', 'export.downtime_pack')->count());
    }

    public function test_controlled_reclassification_during_render_releases_no_bytes_or_export_event(): void
    {
        $this->fakePackRender(function (): void {
            ClientMedication::withoutEvents(fn () => $this->order->update(['controlled_drug' => true]));
        });
        $response = $this->actingAs($this->lead)->postJson('/emar/downtime/pack', ['site_id' => $this->site->id, 'nz_date' => '2026-10-03']);
        $response->assertConflict();
        $this->assertStringNotContainsString('%PDF-sensitive', $response->getContent());
        $this->assertSame(0, MedicationEvent::query()->where('kind', 'export.downtime_pack')->count());
    }

    public function test_person_move_during_render_releases_no_bytes_or_export_event(): void
    {
        $other = Site::factory()->create(['is_active' => true]);
        $this->fakePackRender(fn () => $this->client->update(['site_id' => $other->id]));
        $response = $this->actingAs($this->lead)->postJson('/emar/downtime/pack', ['site_id' => $this->site->id, 'nz_date' => '2026-10-03']);
        $response->assertConflict();
        $this->assertStringNotContainsString('%PDF-sensitive', $response->getContent());
        $this->assertSame(0, MedicationEvent::query()->where('kind', 'export.downtime_pack')->count());
    }

    public function test_changed_instructions_during_render_releases_no_bytes_or_export_event(): void
    {
        $this->fakePackRender(fn () => ClientMedication::withoutEvents(fn () => $this->order->update(['instructions' => 'Synthetic changed instructions'])));
        $response = $this->actingAs($this->lead)->postJson('/emar/downtime/pack', ['site_id' => $this->site->id, 'nz_date' => '2026-10-03']);
        $response->assertConflict();
        $this->assertStringNotContainsString('%PDF-sensitive', $response->getContent());
        $this->assertSame(0, MedicationEvent::query()->where('kind', 'export.downtime_pack')->count());
    }

    public function test_changed_rule_or_projection_evidence_during_render_releases_no_bytes_or_export_event(): void
    {
        $mutations = [
            fn () => MedicationAdminRule::query()->create(['site_id' => $this->site->id, 'match_type' => 'route', 'match_value' => 'oral',
                'requires_countersign' => true, 'required_observations' => [], 'active' => true]),
            fn () => DB::table('medication_dose_slots')->where('client_medication_id', $this->order->id)->where('nz_date', '2026-10-03')->update(['outcome' => 'refused']),
        ];
        foreach ($mutations as $mutation) {
            $this->fakePackRender($mutation);
            $response = $this->actingAs($this->lead)->postJson('/emar/downtime/pack', ['site_id' => $this->site->id, 'nz_date' => '2026-10-03']);
            $response->assertConflict();
            $this->assertStringNotContainsString('%PDF-sensitive', $response->getContent());
        }
        $this->assertSame(0, MedicationEvent::query()->where('kind', 'export.downtime_pack')->count());
    }

    private function fakePackRender(callable $duringRender): void
    {
        $pdf = Mockery::mock(DowntimePackPdf::class);
        $pdf->shouldReceive('render')->once()->andReturnUsing(function (array $pack) use ($duringRender): string {
            $duringRender();

            return '%PDF-sensitive-synthetic-person';
        });
        $this->app->instance(DowntimePackPdf::class, $pdf);
    }

    private function coveringAuthority(): Shift
    {
        MedicationCompetencyAssessment::query()->create(['user_id' => $this->lead->id, 'assessor_id' => User::factory()->create(['approved_at' => now()])->id,
            'assessment_type' => 'annual', 'status' => 'passed', 'assessment_date' => '2026-09-01', 'expiry_date' => '2027-09-01',
            'assessor_declared_at' => now()->subMonth(), 'staff_acknowledged_at' => now()->subMonth()->addMinute(), 'can_administer_unsupervised' => true]);

        return Shift::factory()->create(['user_id' => $this->lead->id, 'client_id' => $this->client->id, 'site_id' => $this->site->id,
            'starts_at' => Carbon::parse('2026-10-03 08:00', 'Pacific/Auckland')->utc(), 'ends_at' => Carbon::parse('2026-10-03 11:00', 'Pacific/Auckland')->utc(),
            'actual_starts_at' => Carbon::parse('2026-10-03 08:00', 'Pacific/Auckland')->utc(), 'actual_ends_at' => Carbon::parse('2026-10-03 11:00', 'Pacific/Auckland')->utc(), 'status' => 'completed']);
    }

    private function declare(): MedicationDowntime
    {
        return app(DowntimeService::class)->declare($this->lead, $this->declaration());
    }

    private function declaration(): array
    {
        return ['site_id' => $this->site->id, 'started_at' => '2026-10-03T08:30', 'ended_at' => '2026-10-03T10:30', 'description' => 'Synthetic connection interruption', 'request_uuid' => (string) Str::uuid()];
    }

    private function facts(MedicationDowntime $downtime, array $overrides = []): array
    {
        return array_merge(['client_medication_id' => $this->order->id, 'downtime_dose_id' => $downtime->doses()->firstOrFail()->id,
            'outcome' => 'given', 'given_at' => '2026-10-03T09:10', 'given_by' => $this->lead->id, 'witness_id' => null,
            'dose_on_paper' => '10 mg', 'notes' => 'Synthetic signed paper', 'observations' => []], $overrides);
    }

    private function capture(MedicationDowntime $downtime, array $overrides = []): MedicationPaperEntry
    {
        $facts = $this->facts($downtime, $overrides);
        $preview = app(PaperEntryService::class)->preview($this->lead, $downtime, $facts);
        $this->assertTrue($preview['can_submit'], json_encode($preview));

        return app(PaperEntryService::class)->capture($this->lead, $downtime, $facts + ['request_uuid' => (string) Str::uuid(), 'preview_token' => $preview['preview_token'], 'accountable_confirmation' => true]);
    }

    private function staff(Site $site, string $role, array $keys): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2026-01-01']);
        $permissions = Permission::query()->whereIn('key', $keys)->pluck('id');
        $this->assertCount(count($keys), $permissions);
        $user->permissionOverrides()->sync($permissions->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all());

        return $user->fresh();
    }
}
