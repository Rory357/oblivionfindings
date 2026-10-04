<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\BreakGlassAccessEvent;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientMedicalProfile;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationAdminRule;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationDoseSlot;
use App\Models\MedicationDowntime;
use App\Models\MedicationEvent;
use App\Models\MedicationPaperEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Downtime\DowntimeAccess;
use App\Services\Medication\Downtime\DowntimeEvents;
use App\Services\Medication\Downtime\DowntimePackPdf;
use App\Services\Medication\Downtime\DowntimePackService;
use App\Services\Medication\Downtime\DowntimeService;
use App\Services\Medication\Downtime\PaperAdministrationWriter;
use App\Services\Medication\Downtime\PaperEntryService;
use App\Services\Medication\EmergencyAccess\EmergencyAccessService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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
        // Internal release evidence retains concealed records; public outputs must not.
        $this->assertStringNotContainsString('Concealed synthetic medicine', json_encode(array_diff_key($pack, ['_source' => true])));
        $this->actingAs($reader)->postJson('/emar/downtime/pack/preview', ['site_id' => $this->site->id, 'nz_date' => '2026-10-03'])
            ->assertOk()->assertDontSee('Concealed synthetic medicine');
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

    public static function nonGivenPaperOutcomes(): array
    {
        return ['refused' => ['refused'], 'withheld' => ['withheld']];
    }

    #[DataProvider('nonGivenPaperOutcomes')]
    public function test_non_given_mapping_retains_paper_reason_without_given_only_fields_and_requires_explicit_posting(string $outcome): void
    {
        $this->coveringAuthority();
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
        $this->assertTrue($preview['can_reconcile']);
        $this->assertNull($preview['unavailable']);
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('medication_paper_postings', 0);
    }

    #[DataProvider('nonGivenPaperOutcomes')]
    public function test_confirmed_historical_non_given_outcome_posts_once_without_stock_or_inferred_clinical_values(string $outcome): void
    {
        $shift = $this->coveringAuthority();
        $stock = ClientMedicationStock::query()->create(['client_medication_id' => $this->order->id, 'on_hand' => 12, 'unit' => 'tablets']);
        $stockBefore = $stock->fresh()->getRawOriginal();
        $downtime = $this->declare();
        $entry = $this->capture($downtime, ['outcome' => $outcome, 'dose_on_paper' => null,
            'notes' => 'Exact signed paper explanation; no cause category recorded.', 'observations' => ['blood_glucose_level' => 4.2]]);
        $paperBefore = $entry->fresh()->getRawOriginal();
        // The completed, actual assignment covers the original paper instant.
        // Posting two days later records the true entry time without changing it.
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Pacific/Auckland')->utc());
        $service = app(PaperEntryService::class);
        $preview = $service->reconciliationPreview($this->lead, $downtime, $entry);
        $this->assertTrue($preview['can_reconcile']);
        $result = $service->reconcile($this->lead, $downtime, $entry, $preview['preview_token']);
        $this->assertTrue($result['success'], json_encode($result));
        $administration = ClientMedicationAdministration::query()->sole();
        $this->assertSame($entry->given_by, $administration->administered_by);
        $this->assertSame($shift->id, $administration->shift_id);
        $this->assertSame($outcome, $administration->status);
        $this->assertSame($outcome, $administration->reason_code);
        $this->assertSame($entry->notes, $administration->reason);
        $this->assertTrue($administration->administered_at->equalTo($entry->given_at));
        $this->assertTrue($administration->scheduled_for->equalTo($entry->scheduled_for));
        $this->assertTrue($administration->created_at->equalTo(now()));
        foreach (['dose_given', 'quantity_given', 'amount_mode', 'blood_glucose_level', 'witnessed_by', 'witnessed_at', 'witness_method', 'second_person_kind'] as $field) {
            $this->assertNull($administration->getAttribute($field), $field.' must not be inferred from a non-given paper outcome.');
        }
        $this->assertSame($paperBefore, $entry->fresh()->getRawOriginal());
        $this->assertSame($stockBefore, $stock->fresh()->getRawOriginal());
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('medication_stock_movements', 0);
        $this->assertDatabaseCount('medication_refusal_followups', 0);
        $target = $downtime->doses()->whereKey($entry->downtime_dose_id)->firstOrFail();
        $slot = MedicationDoseSlot::query()->findOrFail($target->dose_slot_id);
        $this->assertSame($outcome, $slot->outcome);
        $this->assertSame($administration->id, $slot->outcome_administration_id);
        $this->assertDatabaseHas('medication_paper_postings', ['paper_entry_id' => $entry->id, 'administration_id' => $administration->id, 'posted_by' => $this->lead->id]);
        $this->assertSame(1, MedicationEvent::query()->where('kind', 'downtime.paper_reconciled')->count());
        $this->assertSame(1, DB::table('medication_idempotency_results')->where('scope', 'administration.record')->count());

        $replay = $service->reconcile($this->lead, $downtime, $entry, $preview['preview_token']);
        $this->assertTrue($replay['duplicate']);
        $this->assertSame($administration->id, $replay['administration_id']);
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('medication_paper_postings', 1);
        $this->assertSame(1, MedicationEvent::query()->where('kind', 'downtime.paper_reconciled')->count());
        $this->assertSame($stockBefore, $stock->fresh()->getRawOriginal());

        // A corrected assignment no longer covers the original actor. Keep
        // the completed Shift and its actual times; cancellation is invalid.
        $shift->forceFill(['user_id' => User::factory()->create(['approved_at' => now()])->id])->save();
        $deniedReplay = $service->reconcile($this->lead, $downtime, $entry, $preview['preview_token']);
        $this->assertFalse($deniedReplay['success']);
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('medication_paper_postings', 1);
    }

    public static function paperAuthorities(): array
    {
        return ['completed assignment' => ['assignment'], 'usable emergency grant' => ['grant']];
    }

    #[DataProvider('paperAuthorities')]
    public function test_final_paper_posting_event_failure_rolls_back_clinical_slot_receipt_posting_and_grant_use_then_retry_succeeds(string $authority): void
    {
        $grant = $authority === 'grant' ? $this->usableEmergencyAuthority() : null;
        if ($grant === null) {
            $this->coveringAuthority();
        }
        $downtime = $this->declare();
        $entry = $this->capture($downtime, ['outcome' => 'withheld', 'dose_on_paper' => null]);
        $service = app(PaperEntryService::class);
        $preview = $service->reconciliationPreview($this->lead, $downtime, $entry);
        $eventsBefore = MedicationEvent::query()->count();
        $realEvents = app(DowntimeEvents::class);
        $failing = Mockery::mock(DowntimeEvents::class);
        $failing->shouldReceive('record')->once()->andThrow(new \RuntimeException('Synthetic final reconciliation failure'));
        $this->app->instance(DowntimeEvents::class, $failing);
        $this->app->forgetInstance(PaperEntryService::class);
        try {
            app(PaperEntryService::class)->reconcile($this->lead, $downtime, $entry, $preview['preview_token']);
            $this->fail('The final paper event is part of the same transaction.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic final reconciliation failure', $error->getMessage());
        }
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('medication_paper_postings', 0);
        $this->assertSame(0, DB::table('medication_idempotency_results')->where('scope', 'administration.record')->count());
        $this->assertSame($eventsBefore, MedicationEvent::query()->count());
        $this->assertSame(0, BreakGlassAccessEvent::query()->where('action', 'reconciled_paper_outcome')->count());
        $target = $downtime->doses()->whereKey($entry->downtime_dose_id)->firstOrFail();
        $this->assertNull(MedicationDoseSlot::query()->findOrFail($target->dose_slot_id)->outcome);
        $this->assertDatabaseHas('medication_paper_confirmations', ['paper_entry_id' => $entry->id, 'kind' => 'giver']);
        $this->app->instance(DowntimeEvents::class, $realEvents);
        $this->app->forgetInstance(PaperEntryService::class);
        $this->assertTrue(app(PaperEntryService::class)->reconcile($this->lead, $downtime, $entry, $preview['preview_token'])['success']);
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('medication_paper_postings', 1);
        $this->assertSame($grant ? 1 : 0, BreakGlassAccessEvent::query()->where('action', 'reconciled_paper_outcome')->count());
        if ($grant) {
            $this->assertDatabaseHas('break_glass_access_events', ['break_glass_access_id' => $grant->id, 'action' => 'reconciled_paper_outcome']);
        }
    }

    public function test_historical_paper_outcome_records_one_emergency_grant_use_and_rechecks_it_on_replay(): void
    {
        $grant = $this->usableEmergencyAuthority();
        $grantBefore = $grant->fresh()->getRawOriginal();
        $downtime = $this->declare();
        $entry = $this->capture($downtime, ['outcome' => 'refused', 'dose_on_paper' => null]);
        $service = app(PaperEntryService::class);
        $preview = $service->reconciliationPreview($this->lead, $downtime, $entry);
        $this->assertTrue($preview['can_reconcile']);
        $result = $service->reconcile($this->lead, $downtime, $entry, $preview['preview_token']);
        $this->assertTrue($result['success'], json_encode($result));
        $administration = ClientMedicationAdministration::query()->sole();
        $this->assertNull($administration->shift_id);
        $this->assertSame($entry->request_uuid, $administration->client_request_uuid);
        $use = BreakGlassAccessEvent::query()->where('action', 'reconciled_paper_outcome')->sole();
        $this->assertSame($grant->id, $use->break_glass_access_id);
        $this->assertSame('Paper entry '.$entry->id.'; refused', $use->detail);
        $this->assertSame($grantBefore, $grant->fresh()->getRawOriginal());
        $replay = $service->reconcile($this->lead, $downtime, $entry, $preview['preview_token']);
        $this->assertTrue($replay['duplicate']);
        $this->assertSame($administration->id, $replay['administration_id']);
        $this->assertSame(1, BreakGlassAccessEvent::query()->where('action', 'reconciled_paper_outcome')->count());
        $this->assertSame(1, MedicationEvent::query()->where('kind', 'downtime.paper_reconciled')->count());
        app(EmergencyAccessService::class)->end($this->lead, $grant);
        $this->assertFalse($service->reconcile($this->lead, $downtime, $entry, $preview['preview_token'])['success']);
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('medication_paper_postings', 1);
        $this->assertSame(1, BreakGlassAccessEvent::query()->where('action', 'reconciled_paper_outcome')->count());
        $this->assertDatabaseCount('medication_stock_movements', 0);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
    }

    public function test_paper_reconciliation_locks_recording_shifts_and_rules_before_any_authorization_user(): void
    {
        $this->coveringAuthority();
        $downtime = $this->declare();
        $entry = $this->capture($downtime, ['outcome' => 'withheld', 'dose_on_paper' => null]);
        $service = app(PaperEntryService::class);
        $preview = $service->reconciliationPreview($this->lead, $downtime, $entry);
        $lockingQueries = [];
        $collecting = true;
        DB::listen(function (QueryExecuted $query) use (&$lockingQueries, &$collecting): void {
            if ($collecting && preg_match('/for (?:update|share)/i', $query->sql)) {
                $lockingQueries[] = strtolower($query->sql);
            }
        });
        try {
            $result = $service->reconcile($this->lead, $downtime, $entry, $preview['preview_token']);
        } finally {
            $collecting = false;
        }
        $this->assertTrue($result['success'], json_encode($result));
        $firstShift = collect($lockingQueries)->search(fn (string $sql) => str_contains($sql, 'from `shifts`'));
        $firstRule = collect($lockingQueries)->search(fn (string $sql) => str_contains($sql, 'from `medication_admin_rules`'));
        $firstUser = collect($lockingQueries)->search(fn (string $sql) => str_contains($sql, 'from `users`'));
        $this->assertNotFalse($firstShift, json_encode($lockingQueries));
        $this->assertNotFalse($firstRule, json_encode($lockingQueries));
        $this->assertNotFalse($firstUser, json_encode($lockingQueries));
        $this->assertLessThan($firstRule, $firstShift, 'Recording presence must be locked before the rule set.');
        $this->assertLessThan($firstUser, $firstRule, 'The rule set must be locked before any User authorization lock.');
    }

    public static function historicalPaperRevocations(): array
    {
        return ['approval' => ['approval'], 'recording capability' => ['recording'], 'approved site' => ['site'], 'covering assignment' => ['assignment']];
    }

    #[DataProvider('historicalPaperRevocations')]
    public function test_historical_non_given_posting_rechecks_current_authority_without_any_clinical_effect(string $revocation): void
    {
        $shift = $this->coveringAuthority();
        $downtime = $this->declare();
        $entry = $this->capture($downtime, ['outcome' => 'refused', 'dose_on_paper' => null]);
        $service = app(PaperEntryService::class);
        $preview = $service->reconciliationPreview($this->lead, $downtime, $entry);
        $this->assertTrue($preview['can_reconcile']);
        $this->lead->load(['permissionOverrides', 'roles.permissions', 'hrEmployeeProfile']);
        match ($revocation) {
            'approval' => User::query()->whereKey($this->lead->id)->update(['approved_at' => null]),
            'recording' => $this->lead->permissionOverrides()->syncWithoutDetaching([
                Permission::query()->where('key', 'medications.administer.record')->value('id') => ['allowed' => false],
            ]),
            'site' => HrEmployeeProfile::query()->where('user_id', $this->lead->id)->update(['primary_site_id' => Site::factory()->create(['is_active' => true])->id]),
            'assignment' => $shift->forceFill(['user_id' => User::factory()->create(['approved_at' => now()])->id])->save(),
        };
        $eventsBefore = MedicationEvent::query()->count();
        try {
            $result = $service->reconcile($this->lead, $downtime, $entry, $preview['preview_token']);
            $this->assertFalse($result['success']);
        } catch (HttpExceptionInterface|ValidationException $error) {
            if ($error instanceof HttpExceptionInterface) {
                $this->assertContains($error->getStatusCode(), [403, 404]);
            } else {
                $this->assertArrayHasKey('preview_token', $error->errors());
            }
        }
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('medication_paper_postings', 0);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('medication_stock_movements', 0);
        $this->assertSame(0, DB::table('medication_idempotency_results')->where('scope', 'administration.record')->count());
        $this->assertSame($eventsBefore, MedicationEvent::query()->count());
    }

    public function test_a_clinical_outcome_that_arrives_before_paper_posting_invalidates_the_preview_atomically(): void
    {
        $this->coveringAuthority();
        $downtime = $this->declare();
        $entry = $this->capture($downtime, ['outcome' => 'refused', 'dose_on_paper' => null]);
        $service = app(PaperEntryService::class);
        $preview = $service->reconciliationPreview($this->lead, $downtime, $entry);
        $existing = ClientMedicationAdministration::query()->create([
            'client_id' => $entry->client_id, 'client_medication_id' => $entry->client_medication_id,
            'administered_by' => $this->lead->id, 'scheduled_for' => $entry->scheduled_for,
            'administered_at' => $entry->given_at, 'status' => 'withheld', 'reason_code' => 'withheld',
            'reason' => 'Independent existing clinical evidence.',
        ]);
        $before = $existing->fresh()->getRawOriginal();
        $eventsBefore = MedicationEvent::query()->count();
        try {
            $service->reconcile($this->lead, $downtime, $entry, $preview['preview_token']);
            $this->fail('A competing clinical outcome must require review of a new preview.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('preview_token', $error->errors());
        }
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertSame($before, $existing->fresh()->getRawOriginal());
        $this->assertDatabaseCount('medication_paper_postings', 0);
        $this->assertSame(0, DB::table('medication_idempotency_results')->where('scope', 'administration.record')->count());
        $this->assertSame($eventsBefore, MedicationEvent::query()->count());
    }

    public static function heldNonGivenPaperKinds(): array
    {
        return ['controlled' => ['controlled'], 'named second person' => ['witness'], 'as needed' => ['prn']];
    }

    #[DataProvider('heldNonGivenPaperKinds')]
    public function test_non_given_paper_adapter_retains_controlled_second_person_and_prn_holds(string $kind): void
    {
        $this->coveringAuthority();
        ClientMedication::withoutEvents(fn () => $this->order->update(match ($kind) {
            'controlled' => ['controlled_drug' => true],
            'prn' => ['is_prn' => true],
            default => [],
        }));
        $downtime = $this->declare();
        $witness = $kind === 'witness' ? $this->staff($this->site, 'support_worker', ['medications.view', 'medications.administer.record', 'clients.viewAny']) : null;
        $facts = ['client_medication_id' => $this->order->id, 'downtime_dose_id' => $downtime->doses()->value('id'),
            'outcome' => 'withheld', 'given_at' => '2026-10-03T09:10', 'given_by' => $this->lead->id,
            'witness_id' => $witness?->id, 'dose_on_paper' => null, 'notes' => 'Exact signed paper explanation.', 'observations' => []];
        $service = app(PaperEntryService::class);
        $preview = $service->preview($this->lead, $downtime, $facts);
        $this->assertTrue($preview['can_submit'], json_encode($preview));
        $entry = $service->capture($this->lead, $downtime, $facts + ['request_uuid' => (string) Str::uuid(), 'preview_token' => $preview['preview_token'], 'accountable_confirmation' => true]);
        $reconciliation = $service->reconciliationPreview($this->lead, $downtime, $entry);
        $this->assertFalse($reconciliation['can_reconcile']);
        $this->assertNotNull($reconciliation['unavailable']);
        $result = $service->reconcile($this->lead, $downtime, $entry, $reconciliation['preview_token']);
        $this->assertFalse($result['success']);
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('medication_paper_postings', 0);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('medication_stock_movements', 0);
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
        $rule->forceFill(['updated_at' => now()->addMinute()])->save();
        $this->assertTrue($rule->fresh()->updated_at->greaterThan(now()));
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
        $this->assertStringNotContainsString('Synthetic medicine', json_encode(array_diff_key($pack, ['_source' => true])));
        $this->actingAs($reader->fresh())->postJson('/emar/downtime/pack/preview', ['site_id' => $this->site->id, 'nz_date' => '2026-10-03'])
            ->assertOk()->assertDontSee('Synthetic medicine');
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

    public static function changedPackSources(): array
    {
        return ['matching rule' => ['rule'], 'dose projection' => ['projection']];
    }

    #[DataProvider('changedPackSources')]
    public function test_changed_rule_or_projection_evidence_during_render_releases_no_bytes_or_export_event(string $source): void
    {
        // Separate requests have separate controller/PDF instances, so each
        // case must execute its own intended mutation exactly once.
        $this->fakePackRender(function () use ($source): void {
            if ($source === 'rule') {
                MedicationAdminRule::query()->create(['site_id' => $this->site->id, 'match_type' => 'route', 'match_value' => 'oral',
                    'requires_countersign' => true, 'required_observations' => [], 'active' => true]);
            } else {
                $changed = DB::table('medication_dose_slots')->where('client_medication_id', $this->order->id)->where('nz_date', '2026-10-03')->update(['outcome' => 'refused']);
                $this->assertGreaterThan(0, $changed);
            }
        });
        $response = $this->actingAs($this->lead)->postJson('/emar/downtime/pack', ['site_id' => $this->site->id, 'nz_date' => '2026-10-03']);
        $response->assertConflict();
        $this->assertStringNotContainsString('%PDF-sensitive', $response->getContent());
        $this->assertSame(0, MedicationEvent::query()->where('kind', 'export.downtime_pack')->count());
    }

    public function test_changed_canonical_allergy_reaction_during_render_releases_no_bytes_or_export_event(): void
    {
        $this->assertCanonicalAllergyEditWithheld('reaction', 'Anaphylaxis');
    }

    public function test_changed_canonical_allergy_severity_during_render_releases_no_bytes_or_export_event(): void
    {
        $this->assertCanonicalAllergyEditWithheld('severity', 'life_threatening');
    }

    private function assertCanonicalAllergyEditWithheld(string $field, string $value): void
    {
        $entry = ['key' => (string) Str::uuid(), 'allergen' => 'Synthetic allergen', 'reaction' => 'Rash', 'severity' => 'mild'];
        $profile = ClientMedicalProfile::query()->create([
            'client_id' => $this->client->id, 'allergies' => ['Synthetic allergen'],
            'allergy_records' => [$entry], 'allergies_canonical_at' => now(),
        ]);
        $this->fakePackRender(fn () => $profile->update(['allergy_records' => [[...$entry, $field => $value]]]));
        $response = $this->actingAs($this->lead)->postJson('/emar/downtime/pack', ['site_id' => $this->site->id, 'nz_date' => '2026-10-03']);
        $response->assertConflict();
        $this->assertSame(['Synthetic allergen'], $profile->fresh()->allergies);
        $this->assertStringNotContainsString('%PDF-sensitive', $response->getContent());
        $this->assertSame(0, MedicationEvent::query()->where('kind', 'export.downtime_pack')->count());
    }

    public function test_giver_confirmation_rechecks_cached_account_approval_before_writing(): void
    {
        $this->assertGiverConfirmationDeniedAfterRevocation(
            fn (User $giver) => DB::table('users')->where('id', $giver->id)->update(['approved_at' => null]), 403,
        );
    }

    public function test_giver_confirmation_rechecks_cached_record_permission_before_writing(): void
    {
        $this->assertGiverConfirmationDeniedAfterRevocation(function (User $giver): void {
            $id = Permission::query()->where('key', 'medications.administer.record')->firstOrFail()->id;
            $giver->permissionOverrides()->syncWithoutDetaching([$id => ['allowed' => false]]);
        }, 403);
    }

    public function test_giver_confirmation_rechecks_cached_approved_site_before_writing(): void
    {
        $this->assertGiverConfirmationDeniedAfterRevocation(
            fn (User $giver) => DB::table('hr_employee_profiles')->where('user_id', $giver->id)->update(['primary_site_id' => null, 'secondary_site_ids' => '[]']), 404,
        );
    }

    public function test_declaration_rechecks_cached_site_authority_before_writing(): void
    {
        app(DowntimeAccess::class)->clients($this->lead, $this->site->id);
        DB::table('hr_employee_profiles')->where('user_id', $this->lead->id)->update(['primary_site_id' => null, 'secondary_site_ids' => '[]']);
        $before = MedicationEvent::query()->count();
        try {
            app(DowntimeService::class)->declare($this->lead, $this->declaration());
            $this->fail('Revoked Site authority must not declare a downtime.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $this->assertDatabaseCount('medication_downtimes', 0);
        $this->assertSame($before, MedicationEvent::query()->count());
    }

    public function test_finishing_collection_rechecks_cached_site_authority_before_writing(): void
    {
        $downtime = $this->declare();
        $this->capture($downtime);
        app(DowntimeAccess::class)->downtime($this->lead, $downtime->id);
        DB::table('hr_employee_profiles')->where('user_id', $this->lead->id)->update(['primary_site_id' => null, 'secondary_site_ids' => '[]']);
        $before = MedicationEvent::query()->count();
        try {
            app(DowntimeService::class)->finish($this->lead, $downtime);
            $this->fail('Revoked Site authority must not finish paper collection.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $this->assertNull($downtime->fresh()->finished_at);
        $this->assertSame($before, MedicationEvent::query()->count());
    }

    private function assertGiverConfirmationDeniedAfterRevocation(callable $revoke, int $status): void
    {
        $downtime = $this->declare();
        $giver = $this->staff($this->site, 'support_worker', ['medications.view', 'medications.administer.record', 'clients.viewAny']);
        $entry = $this->capture($downtime, ['given_by' => $giver->id]);
        // Same request actor: preflight has already cached its grants and Site.
        $giver->load(['permissionOverrides', 'roles.permissions']);
        $access = app(DowntimeAccess::class);
        $access->downtime($giver, $downtime->id);
        $access->entry($giver, $downtime, $entry->id);
        $this->assertTrue($giver->canDo('medications.administer.record'));
        $revoke($giver);
        $before = MedicationEvent::query()->count();
        try {
            app(PaperEntryService::class)->confirm($giver, $downtime, $entry, 'giver', null);
            $this->fail('Revoked authority must not confirm paper evidence.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
        $this->assertDatabaseMissing('medication_paper_confirmations', ['paper_entry_id' => $entry->id, 'kind' => 'giver']);
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertSame($before, MedicationEvent::query()->count());
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

    private function usableEmergencyAuthority(): ClientBreakGlassAccess
    {
        $this->lead->permissionOverrides()->syncWithoutDetaching([
            Permission::query()->where('key', 'medications.breakglass')->firstOrFail()->id => ['allowed' => true],
        ]);
        $this->lead = $this->lead->fresh();
        $current = Carbon::now();
        try {
            Carbon::setTestNow(Carbon::parse('2026-10-03 09:00', 'Pacific/Auckland')->utc());

            return app(EmergencyAccessService::class)->start($this->lead, $this->client, [
                'reason' => 'Synthetic urgent support when no rostered assignment covers the person.',
                'reason_category' => 'urgent_support', 'minutes' => 240, 'authorization_mode' => 'self',
                'acknowledged_min_necessary' => true, 'acknowledged_incident_report' => true,
            ]);
        } finally {
            Carbon::setTestNow($current);
        }
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
