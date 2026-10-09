<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationAllergy;
use App\Models\MedicationDoseScheduleVersion;
use App\Models\MedicationDoseSlot;
use App\Models\MedicationEvent;
use App\Models\MedicationFollowup;
use App\Models\MedicationOrderAction;
use App\Models\MedicationOrderRevision;
use App\Models\MedicationPrescriberOrder;
use App\Models\MedicationReconciliation;
use App\Models\MedicationReview;
use App\Models\MedicationReviewEvent;
use App\Models\MedicationReviewItem;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\CheckedOrderAllergyConfirmation;
use App\Services\MedicationSafetyService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * eMAR Lane C3 — orders (EA-051, EA-113, EA-114, EA-132, EA-135).
 * Synthetic people and medicines only.
 */
class OrdersLaneC3Test extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private User $enterer;

    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();
        // 10:45 NZDT on 3 Oct is 21:45 UTC on 2 Oct: the NZ and UTC dates differ.
        Carbon::setTestNow(Carbon::parse('2026-10-03 10:45', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        Storage::fake('local');
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $context = ServiceContext::factory()->create(['type' => 'residential', 'is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $context->id, 'status' => 'active']);
        $this->enterer = $this->staff();
        $this->checker = $this->staff();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_new_order_sent_back_and_re_entered_unchanged_gets_its_doses_once_checked(): void
    {
        // EA-051: enter, send back for the source evidence, re-enter the same
        // prescription, check — the order must own its schedule and doses.
        $first = $this->enter();
        $order = $first->medication;
        $this->actingAs($this->checker)->post('/emar/order-revisions/'.$first->id.'/send-back', ['reason' => 'The read-back evidence was missing.'])->assertSessionHasNoErrors();
        $again = $this->enter($order->fresh());
        $this->check($again);

        $order->refresh();
        $this->assertSame('verified', $order->approval_status);
        $this->assertTrue($order->isAdministrable());
        $this->assertTrue(MedicationDoseScheduleVersion::query()->where('client_medication_id', $order->id)->whereNotNull('verified_at')->exists(),
            'The checked order needs a verified schedule version.');
        $this->assertTrue(MedicationDoseSlot::query()->where('client_medication_id', $order->id)->where('due_at', '>', now())->whereNull('superseded_at')->exists(),
            'The checked order must have doses due.');
    }

    public function test_stopping_an_order_keeps_its_check_and_ends_on_the_nz_date(): void
    {
        // EA-132: 10:45 NZDT on 3 Oct is still 2 Oct in UTC.
        $order = $this->order();
        $this->actingAs($this->enterer)->post('/emar/orders/'.$order->id.'/stop', ['reason' => 'Prescriber stopped it today.', 'request_key' => 'stop-nz-date'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('ceased', $order->state);
        $this->assertSame('2026-10-03', $order->end_date->toDateString());
        $this->assertSame('verified', $order->approval_status);
        $this->assertSame($this->checker->id, (int) $order->verified_by);
        $this->assertNotNull($order->verified_at);
    }

    public function test_a_reconciliation_stop_records_the_stop_and_closes_the_order_checks(): void
    {
        // EA-135: a change waiting for its check, then a discharge
        // reconciliation stops the medicine.
        $order = $this->order();
        $waiting = $this->enter($order, ['dosage' => '20 mg']);
        $check = MedicationFollowup::query()->where('source_key', 'order-check:'.$waiting->id)->sole();
        $this->assertNull($check->completed_at);

        $record = $this->startReconciliation();
        $item = $record->items()->sole();
        $this->actingAs($this->enterer)->put('/emar/reconciliations/'.$record->id, ['items' => [['id' => $item->id, 'decision' => 'stop', 'notes' => 'Stopped at hospital discharge.']]])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->enterer)->post('/emar/reconciliations/'.$record->id.'/apply')->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('ceased', $order->refresh()->state);
        $this->assertSame(1, MedicationOrderAction::query()->where('client_medication_id', $order->id)->where('action', 'stopped')->count());
        $this->assertNotNull($check->fresh()->completed_at);
        $this->assertSame(1, MedicationEvent::query()->where('kind', 'order.stopped')->count());
    }

    public function test_a_profile_stop_records_the_stop_and_closes_the_order_checks(): void
    {
        // EA-135 via the person profile's discontinue (P04 is the one stop).
        $order = $this->order();
        $waiting = $this->enter($order, ['dosage' => '20 mg']);
        $this->actingAs($this->enterer)->post('/clients/'.$this->client->id.'/medical/medications/'.$order->id.'/discontinue', ['reason' => 'Prescriber stopped it.'])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame('ceased', $order->refresh()->state);
        $this->assertSame(1, MedicationOrderAction::query()->where('client_medication_id', $order->id)->where('action', 'stopped')->count());
        $this->assertNotNull(MedicationFollowup::query()->where('source_key', 'order-check:'.$waiting->id)->sole()->completed_at);
    }

    public function test_a_review_includes_held_orders_and_orders_waiting_for_their_first_check(): void
    {
        // EA-113.
        $held = $this->order(['name' => 'Held anticoagulant']);
        $this->actingAs($this->enterer)->post('/emar/orders/'.$held->id.'/hold', ['reason' => 'Hold until the INR result.'])->assertSessionHasNoErrors();
        $waiting = $this->enter(null, ['name' => 'New antibiotic'])->medication;
        $active = $this->order(['name' => 'Everyday medicine']);
        $review = MedicationReview::query()->create(['client_id' => $this->client->id, 'review_type' => 'regular', 'status' => 'scheduled',
            'scheduled_date' => '2026-10-03', 'requested_by' => $this->checker->id, 'owner_id' => $this->checker->id]);

        $this->actingAs($this->checker)->get('/emar/reviews?review='.$review->id)->assertOk();
        $this->actingAs($this->checker)->postJson('/emar/reviews/'.$review->id.'/complete', [
            'revision' => $review->fresh()->revision, 'completed_date' => '2026-10-03',
            'reviewer_name' => 'Dr Synthetic Reviewer', 'reviewer_role' => 'GP', 'review_location' => 'house',
            'participants' => ['person' => 'took', 'whanau' => 'told', 'whanau_detail' => 'Synthetic guardian informed.'],
            'clinical_summary' => 'Synthetic clinician summary.',
            'items' => [
                ['client_medication_id' => $held->id, 'outcome' => 'stop', 'recommendation' => 'Stop the held anticoagulant.'],
                ['client_medication_id' => $waiting->id, 'outcome' => 'continue'],
                ['client_medication_id' => $active->id, 'outcome' => 'continue'],
            ],
        ])->assertOk()->assertJsonPath('saved', true);

        $this->assertEqualsCanonicalizing([$held->id, $waiting->id, $active->id],
            MedicationReviewItem::query()->where('review_id', $review->id)->pluck('client_medication_id')->all());
    }

    public function test_a_sent_back_review_change_returns_to_be_entered_again(): void
    {
        // EA-114.
        $order = $this->order();
        $item = $this->recommendation($order, 'change');
        $input = $this->entryInput($order, ['dosage' => '20 mg']) + ['review_item' => $item->id, 'source_file' => UploadedFile::fake()->create('source.pdf', 1, 'application/pdf')];
        $this->actingAs($this->enterer)->post('/emar/orders', $input)->assertSessionHasNoErrors();
        $sent = MedicationOrderRevision::latest('id')->firstOrFail();
        $this->assertSame($sent->medication_order_version_id, $item->refresh()->linked_order_version_id);

        $this->actingAs($this->checker)->post('/emar/order-revisions/'.$sent->id.'/send-back', ['reason' => 'Typo in the dose.'])->assertSessionHasNoErrors();
        $this->assertNull($item->refresh()->linked_order_version_id, 'The agreed change waits to be entered again.');
        $this->assertTrue(MedicationReviewEvent::query()->where('review_id', $item->review_id)->where('event', 'order_sent_back')->exists());

        $retry = $this->entryInput($order->fresh(), ['dosage' => '20 mg']) + ['review_item' => $item->id, 'source_file' => UploadedFile::fake()->create('source-2.pdf', 1, 'application/pdf')];
        $this->actingAs($this->enterer)->post('/emar/orders', $retry)->assertSessionHasNoErrors();
        $again = MedicationOrderRevision::latest('id')->firstOrFail();
        $this->assertNotSame($sent->id, $again->id);
        $this->assertSame($again->medication_order_version_id, $item->refresh()->linked_order_version_id);
    }

    public function test_a_class_allergy_match_asks_the_prescriber_at_the_order_check_and_then_allows_the_dose(): void
    {
        // EA-052: the order check and the dose check use one matcher.
        MedicationAllergy::create(['client_id' => $this->client->id, 'allergen' => 'Penicillin', 'severity' => 'severe', 'reaction' => 'Synthetic rash', 'recorded_by' => $this->enterer->id]);
        $revision = $this->enter(null, ['name' => 'Amoxicillin']);
        $this->actingAs($this->checker)->postJson('/emar/order-revisions/'.$revision->id.'/check',
            ['source_matches' => true, 'dose_route_times_checked' => true, 'allergies_interactions_checked' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('allergy');
        $this->actingAs($this->enterer)->post('/emar/order-revisions/'.$revision->id.'/allergy-confirmation', ['prescriber' => 'Dr Test', 'method' => 'phone',
            'confirmed_at' => '2026-10-03T10:40', 'instruction' => 'Confirmed amoxicillin is safe after review of the recorded penicillin reaction.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->check($revision);

        $order = $revision->medication->refresh();
        $this->assertNotNull(app(CheckedOrderAllergyConfirmation::class)->forOrder($order));
        $safety = app(MedicationSafetyService::class)->performSafetyCheck($this->client, $order);
        $allergyWarnings = collect($safety['warnings'] ?? [])->where('type', 'allergy');
        $this->assertCount(1, $allergyWarnings);
        $this->assertSame('warning', $allergyWarnings->first()['severity'], 'The prescriber-confirmed class match no longer blocks the dose.');
    }

    public function test_a_reviewed_class_rule_also_applies_at_the_dose(): void
    {
        // EA-052, the reverse: a maintained class rule isn't only an order-time rule.
        DB::table('medication_allergy_class_rules')->insert(['allergen' => 'Cephalosporin', 'medicine' => 'Cefalexin', 'source' => 'Synthetic formulary',
            'source_version' => '2026.1', 'reviewed_at' => now()->subDay(), 'expires_at' => now()->addYear()]);
        MedicationAllergy::create(['client_id' => $this->client->id, 'allergen' => 'Cephalosporin', 'severity' => 'moderate', 'reaction' => 'Synthetic hives', 'recorded_by' => $this->enterer->id]);
        $order = $this->order(['name' => 'Cefalexin']);

        $this->assertTrue(app(MedicationSafetyService::class)->checkAllergies($this->client, $order)['has_match']);
        $this->assertSame('Synthetic formulary', app(\App\Services\Medication\OrderAllergyMatcher::class)->inspect($this->client, 'Cefalexin')['matches'][0]['match_source']);
    }

    public function test_the_old_prescriber_order_register_is_read_only(): void
    {
        // EA-053: a confirmed Change never reached the chart; a Cease bypassed P04.
        $order = $this->order(['name' => 'Metformin', 'dosage' => '500 mg']);
        $legacy = MedicationPrescriberOrder::query()->create(['client_id' => $this->client->id, 'client_medication_id' => $order->id,
            'controlled_drug_snapshot' => false, 'order_type' => 'change', 'status' => 'pending', 'prescriber_name' => 'Dr Test',
            'medication_name' => 'Metformin', 'dose' => '1 g', 'route' => 'oral', 'frequency' => 'Twice daily', 'order_date' => today(),
            'requires_countersign' => false, 'received_by' => $this->enterer->id]);

        $this->actingAs($this->checker)->postJson('/emar/prescriptions/'.$legacy->id.'/confirm', ['confirmation' => true])->assertStatus(409);
        $this->assertSame('pending', $legacy->fresh()->status);
        $this->actingAs($this->enterer)->postJson('/emar/prescriptions', ['client_id' => $this->client->id, 'order_type' => 'new',
            'prescriber_name' => 'Dr Test', 'medication_name' => 'Quetiapine', 'dose' => '25 mg', 'route' => 'oral', 'frequency' => 'Nightly'])->assertStatus(409);
        $this->assertSame(1, MedicationPrescriberOrder::query()->count());
        foreach (['countersign', 'dispense', 'cancel'] as $action) {
            $this->actingAs($this->checker)->postJson('/emar/prescriptions/'.$legacy->id.'/'.$action, [])->assertStatus(409);
        }
        $this->assertSame('500 mg', $order->fresh()->dosage);

        $this->actingAs($this->checker)->get('/emar/prescriptions/legacy')->assertOk()->assertInertia(fn ($page) => $page
            ->where('can_create_manual_order', false)->where('orders.0.can_confirm', false)->where('orders.0.can_cancel', false));
    }

    public function test_the_person_profile_cannot_write_orders_outside_p04(): void
    {
        // EA-023: a held anticoagulant could be made active again with no
        // prescriber instruction; a create skipped the version and its check.
        $held = $this->order(['name' => 'Warfarin']);
        $this->actingAs($this->enterer)->post('/emar/orders/'.$held->id.'/hold', ['reason' => 'Hold until the INR result.'])->assertSessionHasNoErrors();
        // The profile routes sit behind clients.update as well.
        $this->enterer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'clients.update')->firstOrFail()->id => ['allowed' => true]]);
        $this->enterer = $this->enterer->fresh();
        $this->actingAs($this->enterer)->putJson('/clients/'.$this->client->id.'/medical/medications/'.$held->id, ['name' => 'Warfarin', 'state' => 'active'])
            ->assertStatus(409);
        $this->assertSame('paused', $held->fresh()->state);
        $this->assertFalse($held->fresh()->active);

        $before = ClientMedication::query()->count();
        $this->actingAs($this->enterer)->postJson('/clients/'.$this->client->id.'/medical/medications', ['name' => 'Quetiapine', 'dosage' => '25 mg', 'frequency' => 'Nightly', 'route' => 'oral'])
            ->assertStatus(409);
        $this->actingAs($this->enterer)->postJson('/emar/medications/import', ['csv_file' => UploadedFile::fake()->createWithContent('orders.csv', "client_id,name,dosage\n".$this->client->id.",Morphine,10 mg\n")])
            ->assertStatus(409);
        $this->assertSame($before, ClientMedication::query()->count());
    }

    private function startReconciliation(): MedicationReconciliation
    {
        $this->actingAs($this->enterer)->post('/emar/reconciliations', ['client_id' => $this->client->id, 'reason' => 'hospital_discharge', 'sources' => 'Signed GP prescription and hospital discharge list.'])->assertSessionHasNoErrors();

        return MedicationReconciliation::latest('id')->firstOrFail();
    }

    private function enter(?ClientMedication $order = null, array $changes = []): MedicationOrderRevision
    {
        $input = $this->entryInput($order, $changes);
        $input['source_file'] = UploadedFile::fake()->create('prescription.pdf', 1, 'application/pdf');
        $this->actingAs($this->enterer)->post('/emar/orders', $input)->assertSessionHasNoErrors()->assertRedirect();

        return MedicationOrderRevision::latest('id')->firstOrFail();
    }

    private function entryInput(?ClientMedication $order = null, array $changes = []): array
    {
        return ['client_id' => $this->client->id, 'medication_id' => $order?->id, 'expected_version' => $order?->version, 'request_key' => 'entry-'.bin2hex(random_bytes(8)), 'change_reason' => 'Prescriber source instruction',
            'source' => ['type' => 'written', 'prescriber' => 'Dr Test', 'received_at' => now()->subMinute()->toIso8601String(), 'description' => 'Signed source'],
            'prescription' => array_merge(['name' => 'Example medicine', 'dosage' => '10 mg', 'dose_amount' => 10, 'dose_unit' => 'mg', 'frequency' => 'Once daily', 'dose_times' => ['20:00'], 'is_prn' => false, 'route' => 'oral', 'indication' => 'Indication from source', 'start_date' => now()->timezone('Pacific/Auckland')->toDateString(), 'controlled_drug' => false, 'high_risk' => false, 'witness_required' => false], $changes)];
    }

    private function check(MedicationOrderRevision $revision, ?User $actor = null): void
    {
        $this->actingAs($actor ?? $this->checker)->post('/emar/order-revisions/'.$revision->id.'/check',
            ['source_matches' => true, 'dose_route_times_checked' => true, 'allergies_interactions_checked' => true])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function order(array $changes = []): ClientMedication
    {
        $order = ClientMedication::create(array_merge($this->entryInput()['prescription'], ['client_id' => $this->client->id, 'created_by' => $this->enterer->id, 'active' => true, 'state' => 'active', 'version' => 1], $changes));
        $order->forceFill(['approval_status' => 'verified', 'verified_by' => $this->checker->id, 'verified_at' => now()->subDay()])->saveQuietly();

        return $order;
    }

    private function staff(): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $ids = Permission::whereIn('key', ['medications.view', 'medications.orders.manage', 'medications.orders.verify', 'medications.followups.manage', 'medications.reviews.manage', 'clients.viewAny'])->pluck('id');
        $user->permissionOverrides()->syncWithoutDetaching($ids->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);
        Shift::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'user_id' => $user->id, 'starts_at' => now()->subHours(2), 'ends_at' => now()->addHours(2), 'actual_starts_at' => now()->subHour(), 'actual_ends_at' => null, 'started_by' => $user->id, 'status' => 'in_progress']);

        return $user;
    }

    private function recommendation(ClientMedication $order, string $outcome): MedicationReviewItem
    {
        $review = MedicationReview::create(['client_id' => $this->client->id, 'review_type' => 'quarterly', 'status' => 'completed',
            'scheduled_date' => '2026-10-03', 'completed_date' => '2026-10-03', 'completed_by' => $this->checker->id, 'requested_by' => $this->checker->id]);

        return MedicationReviewItem::create(['review_id' => $review->id, 'client_id' => $this->client->id, 'client_medication_id' => $order->id,
            'name_snapshot' => $order->name, 'controlled_snapshot' => false, 'classification_pending' => false, 'outcome' => $outcome,
            'decision' => 'agreed', 'prescriber_name' => 'Dr Test', 'recommendation' => 'Agreed prescriber recommendation from the review source.']);
    }
}
