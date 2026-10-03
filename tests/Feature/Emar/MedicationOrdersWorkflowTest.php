<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationAllergy;
use App\Models\MedicationCovertAuthorisation;
use App\Models\MedicationEvent;
use App\Models\MedicationFollowup;
use App\Models\MedicationOrderAction;
use App\Models\MedicationOrderRevision;
use App\Models\MedicationReconciliation;
use App\Models\MedicationReview;
use App\Models\MedicationReviewItem;
use App\Models\Permission;
use App\Models\RespiteBooking;
use App\Models\RespiteMedicationReconciliation;
use App\Models\RespiteStay;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\CheckedOrderAllergyConfirmation;
use App\Services\Medication\MedicationOrderWorkflow;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class MedicationOrdersWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private User $enterer;

    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();
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

    public function test_pending_change_keeps_checked_prescription_and_check_publishes_it_atomically(): void
    {
        $order = $this->order();
        $revision = $this->enter($order, ['dosage' => '20 mg']);
        $this->assertSame('10 mg', $order->refresh()->dosage);
        $this->assertSame(1, $order->version);
        $this->assertTrue($order->isAdministrable());
        $this->assertSame('20 mg', $revision->version->dosage);
        $this->actingAs($this->enterer)->postJson('/emar/order-revisions/'.$revision->id.'/check', $this->checkInput())->assertUnprocessable()->assertJsonValidationErrors('checker');
        $this->check($revision);
        $this->assertSame('20 mg', $order->refresh()->dosage);
        $this->assertSame(2, $order->version);
        $this->assertSame('verified', $order->approval_status);
        $this->assertNotNull(MedicationFollowup::where('source_key', 'order-check:'.$revision->id)->sole()->completed_at);
        $this->assertSame(1, MedicationEvent::where('kind', 'order.checked')->count());
    }

    public function test_identical_entry_retry_keeps_one_version_file_followup_and_event(): void
    {
        $input = $this->entryInput();
        $input['source_file'] = UploadedFile::fake()->create('prescription.pdf', 1, 'application/pdf');
        $this->actingAs($this->enterer)->post('/emar/orders', $input)->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->enterer)->post('/emar/orders', $input)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, MedicationOrderRevision::count());
        $this->assertSame(1, MedicationFollowup::where('type', 'order_check')->count());
        $this->assertSame(1, MedicationEvent::where('kind', 'order.entered')->count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $input['prescription']['dosage'] = 'Different dose';
        $this->actingAs($this->enterer)->postJson('/emar/orders', $input)->assertUnprocessable()->assertJsonValidationErrors('request_key');
    }

    public function test_superseded_sent_back_history_does_not_stay_in_to_check(): void
    {
        $order = $this->order();
        $rejected = $this->enter($order, ['dosage' => '20 mg']);
        $this->actingAs($this->checker)->post('/emar/order-revisions/'.$rejected->id.'/send-back', ['reason' => 'Source dose did not match.'])->assertSessionHasNoErrors();
        $fixed = $this->enter($order, ['dosage' => '15 mg']);
        $this->check($fixed);
        $this->actingAs($this->checker)->get('/emar/prescriptions?view=to_check')->assertOk()->assertInertia(fn (Assert $page) => $page->component('emar/Orders')->has('orders.data', 0)->where('counts.to_check', 0));
        $this->assertSame('sent_back', $rejected->refresh()->status);
        $this->assertSame(3, $order->refresh()->version);
    }

    public function test_stopped_unchecked_order_does_not_count_as_waiting_to_check(): void
    {
        $revision = $this->enter();
        $this->actingAs($this->enterer)->post('/emar/orders/'.$revision->client_medication_id.'/stop', ['reason' => 'Prescriber withdrew this prescription before the first dose.', 'request_key' => 'stop-unchecked'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->checker)->get('/emar/prescriptions?view=to_check')->assertInertia(fn (Assert $page) => $page->has('orders.data', 0)->where('counts.to_check', 0));
    }

    public function test_new_order_blocks_doses_and_lone_check_needs_reason_and_independent_second_check(): void
    {
        $revision = $this->enter();
        $this->assertFalse($revision->medication->isAdministrable());
        $this->actingAs($this->enterer)->postJson('/emar/order-revisions/'.$revision->id.'/check', $this->checkInput() + ['mode' => 'alone'])->assertUnprocessable();
        $this->actingAs($this->enterer)->post('/emar/order-revisions/'.$revision->id.'/check', $this->checkInput() + ['mode' => 'alone', 'lone_reason' => 'Only authorised lead available for the first dose.'])->assertSessionHasNoErrors();
        $this->assertSame('checked_alone', $revision->refresh()->status);
        $this->assertSame('2026-10-04T10:59:59+00:00', $revision->second_due_at->utc()->toIso8601String());
        $this->actingAs($this->enterer)->postJson('/emar/order-revisions/'.$revision->id.'/check', $this->checkInput() + ['mode' => 'second'])->assertUnprocessable();
        $this->actingAs($this->checker)->post('/emar/order-revisions/'.$revision->id.'/check', $this->checkInput() + ['mode' => 'second'])->assertSessionHasNoErrors();
        $this->assertNotNull($revision->refresh()->second_checked_at);
        $this->assertNotNull(MedicationFollowup::where('source_key', 'second-check:'.$revision->id)->sole()->completed_at);
    }

    public function test_readback_witness_cannot_check_and_written_confirmation_is_a_real_file_after_stop(): void
    {
        $input = $this->entryInput();
        $input['source'] += ['read_back_confirmed' => true, 'witness_id' => $this->checker->id, 'witness_pin' => UserFactory::TEST_WITNESS_PIN];
        $input['source']['type'] = 'phone';
        $this->actingAs($this->enterer)->post('/emar/orders', $input)->assertSessionHasNoErrors();
        $revision = MedicationOrderRevision::sole();
        $this->assertSame('2026-10-04T10:59:59+00:00', $revision->written_due_at->utc()->toIso8601String());
        $this->actingAs($this->checker)->postJson('/emar/order-revisions/'.$revision->id.'/check', $this->checkInput())->assertUnprocessable();
        $third = $this->staff();
        $this->check($revision, $third);
        $this->actingAs($this->enterer)->post('/emar/orders/'.$revision->client_medication_id.'/stop', ['reason' => 'Prescriber stopped the course.', 'client_id' => $this->client->id, 'request_key' => 'stop-phone'])->assertSessionHasNoErrors();
        $this->actingAs($this->enterer)->post('/emar/order-revisions/'.$revision->id.'/written-confirmation', ['method' => 'signed_prescription', 'received_at' => '2026-10-03T10:40', 'matches' => true, 'file' => UploadedFile::fake()->create('signed.pdf', 1, 'application/pdf')])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($revision->refresh()->written_confirmation);
        $this->assertNotNull(MedicationFollowup::where('source_key', 'phone-written-confirmation:'.$revision->id)->sole()->completed_at);
        $this->actingAs($this->enterer)->get('/emar/order-files/'.$revision->written_confirmation['file_id'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_every_allergy_match_blocks_check_and_confirmation_expires_when_evidence_changes(): void
    {
        $allergy = MedicationAllergy::create(['client_id' => $this->client->id, 'allergen' => 'Example medicine', 'severity' => 'mild', 'reaction' => 'Recorded reaction', 'recorded_by' => $this->enterer->id]);
        $revision = $this->enter();
        $this->actingAs($this->checker)->postJson('/emar/order-revisions/'.$revision->id.'/check', $this->checkInput())->assertUnprocessable()->assertJsonValidationErrors('allergy');
        $confirmation = ['prescriber' => 'Dr Test', 'method' => 'phone', 'confirmed_at' => '2026-10-03T10:40', 'instruction' => 'Confirmed this exact prescribed version following review of the recorded reaction.'];
        $this->actingAs($this->enterer)->post('/emar/order-revisions/'.$revision->id.'/allergy-confirmation', $confirmation)->assertRedirect()->assertSessionHasNoErrors();
        $allergy->update(['reaction' => 'Updated reaction evidence']);
        $this->actingAs($this->checker)->postJson('/emar/order-revisions/'.$revision->id.'/check', $this->checkInput())->assertUnprocessable()->assertJsonValidationErrors('allergy');
        $this->actingAs($this->enterer)->post('/emar/order-revisions/'.$revision->id.'/allergy-confirmation', $confirmation)->assertRedirect()->assertSessionHasNoErrors();
        $this->check($revision);
        $this->assertNotNull(app(CheckedOrderAllergyConfirmation::class)->forOrder($revision->medication->refresh()));
        $allergy->update(['severity' => 'severe']);
        $this->assertNull(app(CheckedOrderAllergyConfirmation::class)->forOrder($revision->medication->refresh()));
    }

    public function test_audit_failure_rolls_back_order_workflow_and_cleans_uploaded_source(): void
    {
        $recorder = \Mockery::mock(MedicationEventRecorder::class);
        $recorder->shouldReceive('appendMany')->andThrow(new RuntimeException('Audit failure'));
        $this->app->instance(MedicationEventRecorder::class, $recorder);
        try {
            $this->withoutExceptionHandling()->actingAs($this->enterer)->post('/emar/orders', $this->entryInput() + ['source_file' => UploadedFile::fake()->create('prescription.pdf', 1, 'application/pdf')]);
            $this->fail('Audit failure was not surfaced.');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit failure', $error->getMessage());
        }
        $this->assertSame(0, MedicationOrderRevision::count());
        $this->assertSame(0, MedicationOrderAction::count());
        $this->assertSame(0, MedicationFollowup::count());
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_office_authority_does_not_replace_covering_clocked_shift_for_order_entry(): void
    {
        $office = $this->staff(false);
        $this->actingAs($office)->postJson('/emar/orders', $this->entryInput())->assertForbidden();
        $this->assertSame(0, MedicationOrderRevision::count());
    }

    public function test_legacy_flat_create_and_edit_return_links_without_persisting_changes(): void
    {
        $input = ['client_id' => $this->client->id, 'medication_name' => 'Flat medicine', 'dose' => '20 mg', 'frequency' => 'Daily'];
        $this->actingAs($this->enterer)->postJson('/emar/medications', $input)->assertStatus(409)
            ->assertJsonPath('orders_url', '/emar/prescriptions?client_id='.$this->client->id.'&action=entry');
        $this->assertSame(0, ClientMedication::count());
        $order = $this->order();
        $this->actingAs($this->enterer)->putJson('/emar/medications/'.$order->id, $input)->assertStatus(409)
            ->assertJsonPath('orders_url', '/emar/prescriptions?client_id='.$this->client->id.'&order_id='.$order->id.'&action=entry');
        $this->assertSame('10 mg', $order->refresh()->dosage);
        $this->assertSame('verified', $order->approval_status);
        $this->assertSame(1, $order->version);
        $this->assertSame(0, MedicationOrderRevision::count());
        $this->assertSame(0, MedicationEvent::count());
    }

    public function test_legacy_source_edit_replays_once_and_stale_version_is_denied(): void
    {
        $order = $this->order();
        $input = $this->entryInput($order, ['dosage' => '20 mg']) + ['source_file' => UploadedFile::fake()->create('legacy-source.pdf', 1, 'application/pdf')];
        $this->actingAs($this->enterer)->put('/emar/medications/'.$order->id, $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->enterer)->put('/emar/medications/'.$order->id, $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('10 mg', $order->refresh()->dosage);
        $this->assertSame('verified', $order->approval_status);
        $this->assertSame(1, MedicationOrderRevision::where('status', 'pending')->count());
        $this->assertSame(1, MedicationEvent::where('kind', 'order.entered')->count());
        $input['request_key'] = 'stale-legacy-source';
        $input['expected_version'] = 0;
        $this->actingAs($this->enterer)->putJson('/emar/medications/'.$order->id, $input)->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->assertSame(1, MedicationEvent::where('kind', 'order.entered')->count());
    }

    public function test_legacy_verify_uses_exact_revision_independent_check_and_single_receipt(): void
    {
        $order = $this->order();
        $revision = $this->enter($order, ['dosage' => '20 mg']);
        $this->actingAs($this->checker)->postJson('/emar/medications/'.$order->id.'/verify')->assertStatus(409)
            ->assertJsonPath('orders_url', '/emar/prescriptions?client_id='.$this->client->id.'&order_id='.$order->id.'&action=check');
        $this->assertSame('10 mg', $order->refresh()->dosage);
        $input = $this->checkInput() + ['revision_id' => $revision->id];
        $this->actingAs($this->enterer)->postJson('/emar/medications/'.$order->id.'/verify', $input)->assertUnprocessable()->assertJsonValidationErrors('checker');
        $this->actingAs($this->checker)->post('/emar/medications/'.$order->id.'/verify', $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('20 mg', $order->refresh()->dosage);
        $this->actingAs($this->checker)->postJson('/emar/medications/'.$order->id.'/verify', $input)->assertUnprocessable();
        $this->assertSame(1, MedicationEvent::where('kind', 'order.checked')->count());
    }

    public function test_legacy_reject_sends_back_only_the_proposed_revision(): void
    {
        $order = $this->order();
        $revision = $this->enter($order, ['dosage' => '20 mg']);
        $this->actingAs($this->checker)->postJson('/emar/medications/'.$order->id.'/reject', ['rejection_reason' => 'Source mismatch.'])->assertStatus(409);
        $this->actingAs($this->checker)->post('/emar/medications/'.$order->id.'/reject', ['revision_id' => $revision->id, 'rejection_reason' => 'Source mismatch.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('sent_back', $revision->refresh()->status);
        $this->assertSame('verified', $order->refresh()->approval_status);
        $this->assertSame('10 mg', $order->dosage);
    }

    public function test_legacy_bridge_retains_shift_authority_and_canonical_revision_ownership(): void
    {
        $order = $this->order();
        $revision = $this->enter($order, ['dosage' => '20 mg']);
        $office = $this->staff(false);
        $this->actingAs($office)->putJson('/emar/medications/'.$order->id, ['dose' => '30 mg'])->assertForbidden();
        $this->actingAs($office)->postJson('/emar/medications/'.$order->id.'/verify', $this->checkInput() + ['revision_id' => $revision->id])->assertForbidden();
        $other = $this->order(['name' => 'Another medicine']);
        $this->actingAs($this->checker)->postJson('/emar/medications/'.$other->id.'/verify', $this->checkInput() + ['revision_id' => $revision->id])->assertNotFound();
        $this->assertSame('pending', $revision->refresh()->status);
        $this->assertSame('10 mg', $order->refresh()->dosage);
    }

    public function test_legacy_covert_without_signed_structured_source_saves_nothing(): void
    {
        $order = $this->order();
        $this->actingAs($this->enterer)->postJson('/emar/prescriptions/covert', ['client_id' => $this->client->id, 'client_medication_id' => $order->id, 'authorised_by_name' => 'Dr Test', 'clinical_justification' => 'Flat evidence', 'authorised_date' => '2026-10-03', 'review_date' => '2027-01-03'])->assertStatus(409)
            ->assertJsonPath('orders_url', '/emar/prescriptions?client_id='.$this->client->id.'&order_id='.$order->id.'&action=covert');
        $this->assertSame(0, MedicationCovertAuthorisation::count());
        $this->assertSame(0, MedicationOrderAction::count());
    }

    public function test_legacy_inertia_link_opens_the_source_wizard_without_writing(): void
    {
        $order = $this->order();
        $url = '/emar/prescriptions?client_id='.$this->client->id.'&order_id='.$order->id.'&action=entry';
        $this->actingAs($this->enterer)->withHeader('X-Inertia', 'true')->put('/emar/medications/'.$order->id, ['dose' => '20 mg'])->assertStatus(409)->assertHeader('X-Inertia-Location', $url);
        $this->assertSame('10 mg', $order->refresh()->dosage);
        $this->actingAs($this->enterer)->withHeader('X-Inertia', '')->get($url)->assertInertia(fn (Assert $page) => $page->where('open_order_action', 'entry')->where('open_order_id', $order->id));
    }

    public function test_controlled_order_detail_source_and_candidates_are_concealed_from_restricted_reader(): void
    {
        $permissionIds = Permission::whereIn('key', ['medications.controlled.view', 'medications.controlled.record'])->pluck('id');
        $this->enterer->permissionOverrides()->syncWithoutDetaching($permissionIds->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all());
        $this->enterer->unsetRelations();
        $this->checker->permissionOverrides()->syncWithoutDetaching($permissionIds->mapWithKeys(fn ($id) => [$id => ['allowed' => false]])->all());
        $this->checker->unsetRelations();
        $order = $this->order(['controlled_drug' => true]);
        $revision = $this->enter($order, ['controlled_drug' => true, 'dosage' => '20 mg']);
        $this->actingAs($this->checker)->getJson('/emar/orders/'.$order->id)->assertNotFound();
        $this->actingAs($this->checker)->get('/emar/order-files/'.$revision->files()->sole()->id)->assertNotFound();
        $this->actingAs($this->checker)->getJson('/emar/orders/candidates/'.$this->client->id)->assertOk()->assertJsonCount(0, 'orders');
        $this->actingAs($this->checker)->get('/emar/prescriptions')->assertInertia(fn (Assert $page) => $page->has('orders.data', 0));
    }

    public function test_cross_person_covert_link_is_not_serialized_even_with_access_to_both_people(): void
    {
        $order = $this->order();
        $other = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->client->service_context_id, 'status' => 'active']);
        MedicationCovertAuthorisation::create(['client_id' => $other->id, 'client_medication_id' => $order->id, 'status' => 'active', 'authorised_by_name' => 'Foreign evidence', 'authorised_date' => '2026-10-03', 'review_date' => '2027-01-03', 'legal_basis' => 'Foreign source', 'clinical_justification' => 'Other person assessment', 'administration_method' => 'Other person method', 'recorded_by' => $this->enterer->id]);
        $this->actingAs($this->checker)->get('/emar/prescriptions?view=covert')->assertInertia(fn (Assert $page) => $page->has('covert', 0)->where('counts.covert', 0));
    }

    public function test_revision_with_another_medicines_version_is_concealed_in_detail_candidates_and_files(): void
    {
        $order = $this->order();
        $revision = $this->enter($order, ['dosage' => '20 mg']);
        $fileId = $revision->files()->sole()->id;
        $foreign = $this->order(['name' => 'Foreign medicine']);
        $workflow = app(MedicationOrderWorkflow::class);
        $workflow->snapshotExisting($foreign);
        // The existing snapshot already has its own unique revision. Build a
        // free foreign version so this fixture reaches the ownership check.
        $foreignVersion = $workflow->snapshot($foreign, $workflow->payload($foreign), 2, $this->enterer->id, ['type' => 'written'], 'Foreign source version');
        $revision->forceFill(['medication_order_version_id' => $foreignVersion->id])->save();
        $this->actingAs($this->checker)->getJson('/emar/orders/'.$order->id)->assertOk()->assertJsonCount(1, 'revisions')->assertJsonMissing(['name' => 'Foreign medicine']);
        $this->actingAs($this->checker)->get('/emar/order-files/'.$fileId)->assertNotFound();
        $response = $this->actingAs($this->checker)->getJson('/emar/orders/candidates/'.$this->client->id)->assertOk();
        $this->assertNull(collect($response->json('orders'))->firstWhere('id', $order->id)['pending']);
    }

    public function test_agreed_review_change_links_waiting_version_and_replays_without_publication(): void
    {
        $order = $this->order();
        $item = $this->recommendation($order, 'change');
        $input = $this->entryInput($order, ['dosage' => '20 mg']) + ['review_item' => $item->id, 'source_file' => UploadedFile::fake()->create('source.pdf', 1, 'application/pdf')];
        $this->actingAs($this->enterer)->get('/emar/prescriptions?client_id='.$this->client->id.'&review_item='.$item->id)->assertInertia(fn (Assert $page) => $page->where('review_handoff.id', $item->id));
        $this->actingAs($this->enterer)->post('/emar/orders', $input)->assertSessionHasNoErrors();
        $revision = MedicationOrderRevision::latest('id')->firstOrFail();
        $this->assertSame($revision->medication_order_version_id, $item->refresh()->linked_order_version_id);
        $this->assertSame('10 mg', $order->refresh()->dosage);
        $this->assertSame('pending', $revision->status);
        $this->actingAs($this->enterer)->post('/emar/orders', $input)->assertSessionHasNoErrors();
        $this->assertSame(1, MedicationOrderAction::where('action', 'entered')->count());
    }

    public function test_review_swap_requires_explicit_stop_and_retains_two_distinct_clinical_links(): void
    {
        $old = $this->order();
        $item = $this->recommendation($old, 'swap');
        $input = $this->entryInput(null, ['name' => 'Replacement medicine']) + ['review_item' => $item->id, 'stop_reason' => 'Prescriber source replaces the previous medicine.', 'source_file' => UploadedFile::fake()->create('swap.pdf', 1, 'application/pdf')];
        $this->actingAs($this->enterer)->postJson('/emar/orders', $input)->assertUnprocessable()->assertJsonValidationErrors('confirm_swap');
        $this->assertSame('active', $old->refresh()->state);
        $this->assertNull($item->refresh()->linked_order_version_id);
        $input['confirm_swap'] = true;
        $this->actingAs($this->enterer)->post('/emar/orders', $input)->assertSessionHasNoErrors();
        $new = ClientMedication::where('name', 'Replacement medicine')->sole();
        $this->assertSame('ceased', $old->refresh()->state);
        $this->assertSame('pending_verification', $new->approval_status);
        $this->assertFalse($new->isAdministrable());
        $item->refresh();
        $this->assertNotNull($item->linked_stopped_order_version_id);
        $this->assertNotNull($item->linked_replacement_order_version_id);
        $this->assertNotSame($item->linked_stopped_order_version_id, $item->linked_replacement_order_version_id);
        $this->assertSame($item->linked_replacement_order_version_id, $item->linked_order_version_id);
        $this->actingAs($this->enterer)->post('/emar/orders', $input)->assertSessionHasNoErrors();
        $this->assertSame(1, MedicationOrderAction::where('action', 'stopped')->count());
    }

    public function test_agreed_review_stop_links_cessation_once_without_replacement_or_check(): void
    {
        $order = $this->order();
        $item = $this->recommendation($order, 'stop');
        $input = ['review_item' => $item->id, 'reason' => 'Prescriber confirmed stopping.', 'request_key' => 'review-stop-request'];
        $this->actingAs($this->enterer)->post('/emar/orders/'.$order->id.'/stop', $input)->assertSessionHasNoErrors();
        $this->assertSame('ceased', $order->refresh()->state);
        $this->assertSame($order->versions()->where('version_number', $order->version)->sole()->id, $item->refresh()->linked_stopped_order_version_id);
        $this->assertNull($item->linked_replacement_order_version_id);
        $this->actingAs($this->enterer)->post('/emar/orders/'.$order->id.'/stop', $input)->assertSessionHasNoErrors();
        $this->assertSame(1, MedicationEvent::where('kind', 'order.stopped')->count());
    }

    public function test_reconciliation_stores_summer_nz_times_as_correct_utc_instants(): void
    {
        $this->assertNzReconciliationTimes('2026-10-03', '2026-10-02T21:30:00+00:00', '2026-10-02T22:00:00+00:00');
    }

    public function test_reconciliation_stores_winter_nz_times_as_correct_utc_instants(): void
    {
        $this->assertNzReconciliationTimes('2026-08-03', '2026-08-02T22:30:00+00:00', '2026-08-02T23:00:00+00:00');
    }

    public function test_reconciliation_last_given_evidence_excludes_later_refusal_and_chart_drift_blocks_apply(): void
    {
        $order = $this->order();
        $given = ClientMedicationAdministration::create(['client_id' => $this->client->id, 'client_medication_id' => $order->id, 'status' => 'given', 'administered_at' => now()->subHour(), 'administered_by' => $this->enterer->id, 'dose_given' => '10 mg', 'is_correction' => false]);
        ClientMedicationAdministration::create(['client_id' => $this->client->id, 'client_medication_id' => $order->id, 'status' => 'refused', 'reason' => 'Person declined the later offer.', 'administered_at' => now()->subMinutes(20), 'administered_by' => $this->enterer->id, 'is_correction' => false]);
        $record = $this->startReconciliation();
        $item = $record->items()->sole();
        $this->assertSame($given->id, $item->last_dose_evidence['administration_id']);
        $this->actingAs($this->enterer)->put('/emar/reconciliations/'.$record->id, ['items' => [['id' => $item->id, 'decision' => 'continue', 'notes' => 'Prescription matches.']]])->assertSessionHasNoErrors();
        $this->order(['name' => 'Another chart medicine']);
        $this->actingAs($this->enterer)->postJson('/emar/reconciliations/'.$record->id.'/apply')->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertNull($item->refresh()->applied_at);
    }

    public function test_respite_gate_waits_for_linked_version_and_releases_after_independent_check(): void
    {
        $order = $this->order();
        $booking = RespiteBooking::factory()->create(['client_id' => $this->client->id]);
        $stay = RespiteStay::create(['booking_id' => $booking->id, 'client_id' => $this->client->id, 'status' => 'active', 'actual_start' => now(), 'created_by' => $this->enterer->id]);
        $record = $this->startReconciliation(['reason' => 'respite_arriving', 'respite_stay_id' => $stay->id]);
        $item = $record->items()->sole();
        $this->actingAs($this->enterer)->put('/emar/reconciliations/'.$record->id, ['items' => [['id' => $item->id, 'decision' => 'change', 'notes' => 'Changed dose on signed source.']]])->assertSessionHasNoErrors();
        $revision = $this->enter($order, ['dosage' => '20 mg']);
        $this->actingAs($this->enterer)->post('/emar/reconciliations/'.$record->id.'/apply', ['revision_ids' => [$item->id => $revision->id]])->assertSessionHasNoErrors();
        $this->actingAs($this->checker)->post('/emar/reconciliations/'.$record->id.'/sign-off')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('in_progress', RespiteMedicationReconciliation::where('stay_id', $stay->id)->sole()->status);
        $this->check($revision);
        $this->assertSame('completed', RespiteMedicationReconciliation::where('stay_id', $stay->id)->sole()->status);
        $this->assertSame($this->checker->id, MedicationEvent::where('kind', 'reconciliation.signed_off')->sole()->actor_id);
    }

    public function test_reconciliation_cannot_sign_off_after_another_medicine_arrives(): void
    {
        $this->order();
        $record = $this->startReconciliation();
        $item = $record->items()->sole();
        $this->actingAs($this->enterer)->put('/emar/reconciliations/'.$record->id, ['items' => [['id' => $item->id, 'decision' => 'continue']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->enterer)->post('/emar/reconciliations/'.$record->id.'/apply')->assertRedirect()->assertSessionHasNoErrors();
        $this->order(['name' => 'Another chart medicine']);
        $this->actingAs($this->checker)->postJson('/emar/reconciliations/'.$record->id.'/sign-off')->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertNull($record->refresh()->signed_off_at);
    }

    public function test_reconciliation_verification_denial_preserves_signoff_query_and_followup_evidence(): void
    {
        $this->order();
        $record = $this->startReconciliation();
        $item = $record->items()->sole();
        $this->actingAs($this->enterer)->put('/emar/reconciliations/'.$record->id, ['items' => [['id' => $item->id, 'decision' => 'ask', 'notes' => 'Confirm dose with prescriber.', 'next_dose_at' => now()->addHour()->toIso8601String()]]])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->enterer)->post('/emar/reconciliations/'.$record->id.'/apply')->assertRedirect()->assertSessionHasNoErrors();
        $permission = Permission::where('key', 'medications.orders.verify')->sole();
        $this->checker->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        $this->checker->unsetRelations();
        $eventsBefore = MedicationEvent::count();

        $this->actingAs($this->checker)->postJson('/emar/reconciliations/'.$record->id.'/sign-off')->assertForbidden();
        $this->actingAs($this->checker)->postJson('/emar/reconciliations/'.$record->id.'/items/'.$item->id.'/query', ['prescriber' => 'Dr Test', 'method' => 'phone', 'confirmed_at' => now()->subMinute()->toIso8601String(), 'instruction' => 'Documented response.'])->assertForbidden();

        $this->assertNull($record->refresh()->signed_off_at);
        $this->assertNull($item->refresh()->prescriber_query_resolved_at);
        $this->assertNull(MedicationFollowup::where('source_key', 'reconciliation-query:'.$item->id)->sole()->completed_at);
        $this->assertSame($eventsBefore, MedicationEvent::count());
    }

    public function test_reconciliation_cannot_sign_off_a_continued_order_changed_after_apply(): void
    {
        $order = $this->order();
        $record = $this->startReconciliation();
        $item = $record->items()->sole();
        $this->actingAs($this->enterer)->put('/emar/reconciliations/'.$record->id, ['items' => [['id' => $item->id, 'decision' => 'continue']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->enterer)->post('/emar/reconciliations/'.$record->id.'/apply')->assertRedirect()->assertSessionHasNoErrors();
        $this->check($this->enter($order, ['dosage' => '20 mg']));
        $this->actingAs($this->checker)->postJson('/emar/reconciliations/'.$record->id.'/sign-off')->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertNull($record->refresh()->signed_off_at);
    }

    public function test_unchecked_chart_entry_cannot_be_applied_as_continue(): void
    {
        $this->enter();
        $record = $this->startReconciliation();
        $item = $record->items()->sole();
        $this->actingAs($this->enterer)->put('/emar/reconciliations/'.$record->id, ['items' => [['id' => $item->id, 'decision' => 'continue']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->enterer)->postJson('/emar/reconciliations/'.$record->id.'/apply')->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertNull($item->refresh()->applied_at);
    }

    public function test_covert_requires_pharmacist_capacity_and_signed_source_and_keeps_review_day_inclusive(): void
    {
        $order = $this->order();
        $input = ['capacity_lacking' => true, 'capacity_assessor' => 'Assessor Test', 'capacity_date' => '2026-10-03', 'capacity_record' => 'Source assessment', 'consulted_name' => 'Guardian Test', 'consulted_role' => 'Welfare guardian', 'consulted_record' => 'Source consultation', 'pharmacist_name' => 'Pharmacist Test', 'pharmacist_advice' => 'Medicine-specific documented advice', 'authorised_by_name' => 'Dr Test', 'authorised_date' => '2026-10-03', 'legal_basis' => 'Recorded legal source', 'administration_method' => 'Method from signed authorisation', 'review_date' => '2027-01-03', 'request_key' => 'covert-test', 'gp_file' => UploadedFile::fake()->create('gp.pdf', 1, 'application/pdf')];
        $missing = $input;
        unset($missing['pharmacist_advice']);
        $this->actingAs($this->enterer)->postJson('/emar/orders/'.$order->id.'/covert', $missing)->assertUnprocessable()->assertJsonValidationErrors('pharmacist_advice');
        $missing = $input;
        $missing['capacity_lacking'] = false;
        $this->actingAs($this->enterer)->postJson('/emar/orders/'.$order->id.'/covert', $missing)->assertUnprocessable()->assertJsonValidationErrors('capacity_lacking');
        $this->actingAs($this->enterer)->post('/emar/prescriptions/covert', $input + ['client_id' => $this->client->id, 'client_medication_id' => $order->id])->assertRedirect()->assertSessionHasNoErrors();
        $auth = MedicationCovertAuthorisation::sole();
        $this->actingAs($this->checker)->get('/emar/prescriptions?view=covert')->assertInertia(fn (Assert $page) => $page
            ->where('covert.0.authorised_date', '2026-10-03')->where('covert.0.review_date', '2027-01-03')->has('covert.0.files', 1)->missing('covert.0.files.0.file_path'));
        $this->assertSame('covert_review', MedicationFollowup::where('source_key', 'covert-review:'.$auth->id)->sole()->type);
        $this->actingAs($this->enterer)->post('/emar/prescriptions/covert/'.$auth->id.'/revoke', ['reason' => 'The prescriber confirmed it will be offered openly.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('The prescriber confirmed it will be offered openly.', $auth->refresh()->revoke_reason);
        $this->assertNotNull(MedicationFollowup::where('source_key', 'covert-review:'.$auth->id)->sole()->completed_at);
        Carbon::setTestNow(Carbon::parse('2027-01-03 23:59', 'Pacific/Auckland')->utc());
        $this->assertFalse($auth->isExpired());
        Carbon::setTestNow(Carbon::parse('2027-01-04 00:00', 'Pacific/Auckland')->utc());
        $this->assertTrue($auth->isExpired());
    }

    private function assertNzReconciliationTimes(string $date, string $lastUtc, string $nextUtc): void
    {
        Carbon::setTestNow(Carbon::parse($date.' 10:45', 'Pacific/Auckland')->utc());
        Shift::query()->update(['starts_at' => now()->subHours(2), 'ends_at' => now()->addHours(2), 'actual_starts_at' => now()->subHour()]);
        $this->order();
        $record = $this->startReconciliation();
        $item = $record->items()->sole();
        $this->actingAs($this->enterer)->put('/emar/reconciliations/'.$record->id, ['items' => [['id' => $item->id, 'decision' => 'ask', 'notes' => 'Confirm dose with prescriber', 'next_dose_at' => $date.'T11:00', 'external_last_dose' => ['given_at' => $date.'T10:30', 'source' => 'Hospital source and named nurse', 'dose_given' => '10 mg']]]])->assertSessionHasNoErrors();
        $this->assertSame($lastUtc, $item->refresh()->last_dose_evidence['external']['given_at']);
        $this->assertSame($nextUtc, $item->next_dose_at->utc()->toIso8601String());
        $this->actingAs($this->enterer)->post('/emar/reconciliations/'.$record->id.'/apply')->assertSessionHasNoErrors();
        $this->assertSame($nextUtc, MedicationFollowup::where('source_key', 'reconciliation-query:'.$item->id)->sole()->due_at->utc()->toIso8601String());
        $this->actingAs($this->checker)->post('/emar/reconciliations/'.$record->id.'/items/'.$item->id.'/query', ['prescriber' => 'Dr Test', 'method' => 'phone', 'confirmed_at' => $date.'T10:40', 'instruction' => 'Documented response; any dose change needs a separate order.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull(MedicationFollowup::where('source_key', 'reconciliation-query:'.$item->id)->sole()->completed_at);
    }

    private function startReconciliation(array $extra = []): MedicationReconciliation
    {
        $this->actingAs($this->enterer)->post('/emar/reconciliations', array_merge(['client_id' => $this->client->id, 'reason' => 'hospital_discharge', 'sources' => 'Signed GP prescription and hospital discharge list.'], $extra))->assertSessionHasNoErrors();

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
            'prescription' => array_merge(['name' => 'Example medicine', 'dosage' => '10 mg', 'dose_amount' => 10, 'dose_unit' => 'mg', 'frequency' => 'Once daily', 'dose_times' => ['11:00'], 'is_prn' => false, 'route' => 'oral', 'indication' => 'Indication from source', 'start_date' => now()->timezone('Pacific/Auckland')->toDateString(), 'controlled_drug' => false, 'high_risk' => false, 'witness_required' => false], $changes)];
    }

    private function checkInput(): array
    {
        return ['source_matches' => true, 'dose_route_times_checked' => true, 'allergies_interactions_checked' => true];
    }

    private function check(MedicationOrderRevision $revision, ?User $actor = null): void
    {
        $this->actingAs($actor ?? $this->checker)->post('/emar/order-revisions/'.$revision->id.'/check', $this->checkInput())->assertRedirect()->assertSessionHasNoErrors();
    }

    private function order(array $changes = []): ClientMedication
    {
        $order = ClientMedication::create(array_merge($this->entryInput()['prescription'], ['client_id' => $this->client->id, 'created_by' => $this->enterer->id, 'active' => true, 'state' => 'active', 'version' => 1], $changes));
        $order->forceFill(['approval_status' => 'verified', 'verified_by' => $this->checker->id, 'verified_at' => now()->subDay()])->saveQuietly();

        return $order;
    }

    private function staff(bool $withShift = true): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $ids = Permission::whereIn('key', ['medications.view', 'medications.orders.manage', 'medications.orders.verify', 'medications.followups.manage'])->pluck('id');
        $user->permissionOverrides()->syncWithoutDetaching($ids->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);
        if ($withShift) {
            Shift::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'user_id' => $user->id, 'starts_at' => now()->subHours(2), 'ends_at' => now()->addHours(2), 'actual_starts_at' => now()->subHour(), 'actual_ends_at' => null, 'started_by' => $user->id, 'status' => 'in_progress']);
        }

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
