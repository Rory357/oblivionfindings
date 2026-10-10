<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedicalProfile;
use App\Models\ClientMedication;
use App\Models\MedicationAllergy;
use App\Models\MedicationDoseSlot;
use App\Models\MedicationProviderTransfer;
use App\Models\MedicationProviderTransferEvent;
use App\Models\MedicationReconciliation;
use App\Models\Permission;
use App\Models\Site;
use App\Services\Medication\ExternalClinical\ProviderMedicationTransfers;
use Carbon\Carbon;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\ConnectedCareSwitches;
use Tests\Support\ExternalClinicalFixtures;
use Tests\TestCase;

final class ProviderMedicationTransferTest extends TestCase
{
    use ExternalClinicalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // D4: handovers run only while switched on; the two-person review rule
        // is switched back on in the test_b10_ tests below.
        ConnectedCareSwitches::on('provider_transfers', 'prescriber_portal');
        ConnectedCareSwitches::off('two_person_handover', 'two_person_identity');
        $this->connectedFixtures();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function transferInput(string $direction = 'outgoing', string $key = 'handover-1'): array
    {
        return ['client_id' => $this->person->id, 'direction' => $direction, 'provider_name' => 'Separate care provider',
            'recipient_name' => 'Named receiving clinician', 'purpose' => 'Approved care transfer',
            'disclosure_basis' => 'Recorded permitted care disclosure', 'identity_evidence' => 'Identity matched using named person and date of birth',
            'request_key' => $key, ...($direction === 'incoming' ? ['identity_confirmed' => true, 'source_reference' => 'Reviewed provider chart',
                'source_snapshot' => ['captured_at' => now()->subHour()->toIso8601String(),
                    'person' => ['name' => $this->person->full_name, 'date_of_birth' => $this->person->date_of_birth->toDateString(), 'nhi_number' => $this->person->nhi_number],
                    'medications' => [['prescription' => $this->prescription(['name' => 'Incoming source medicine']), 'last_dose' => null, 'next_due_at' => null]],
                    'allergies' => [['allergen' => 'Incoming allergen', 'reaction' => 'Source reaction', 'severity' => 'mild', 'notes' => 'Unverified source evidence']]]] : [])];
    }

    private function action(string $name, int $version, string $key): array
    {
        return ['action' => $name, 'expected_version' => $version, 'request_key' => $key, 'note' => 'Reviewed named evidence',
            ...($name === 'review' ? ['identity_confirmed' => true, 'facts_checked' => true, 'recipient_confirmed' => true] : []),
            ...($name === 'receipt' ? ['receipt_reference' => 'Named recipient receipt via agreed secure channel'] : []),
            ...($name === 'start_reconciliation' ? ['identity_confirmed' => true] : [])];
    }

    public function test_outgoing_snapshot_is_reviewed_before_export_and_never_discharges_source_care(): void
    {
        $order = $this->chart();
        $status = $this->person->status;
        $service = app(ProviderMedicationTransfers::class);
        $record = $service->create($this->manager, $this->transferInput());
        $this->actingAs($this->manager)->getJson('/emar/connected-care/transfers/'.$record->id.'/packet')->assertNotFound();
        $this->postJson('/emar/connected-care/transfers/'.$record->id.'/transition', $this->action('review', 1, 'review-1'))->assertOk();
        $this->getJson('/emar/connected-care/transfers/'.$record->id.'/packet')->assertOk()
            ->assertJsonPath('format', 'oblivion-medication-handover')->assertJsonPath('format_version', 1)
            ->assertJsonPath('snapshot.medications.0.id', $order->id)->assertJsonPath('snapshot.medications.0.approval_status', 'verified')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('active', $order->refresh()->state);
        $this->assertSame($status, $this->person->refresh()->status);
        $this->assertSame(0, MedicationReconciliation::count());
        $this->assertNotSame(json_encode($record->snapshot), DB::table('medication_provider_transfers')->value('snapshot'));
    }

    public function test_current_chart_allergy_and_person_identity_changes_block_stale_review_or_export(): void
    {
        $order = $this->chart();
        $record = app(ProviderMedicationTransfers::class)->create($this->manager, $this->transferInput());
        $this->actingAs($this->manager)->postJson('/emar/connected-care/transfers/'.$record->id.'/transition', $this->action('review', 1, 'review-1'))->assertOk();
        MedicationAllergy::create(['client_id' => $this->person->id, 'allergen' => 'Changed allergy', 'severity' => 'severe', 'recorded_by' => $this->manager->id]);
        $this->getJson('/emar/connected-care/transfers/'.$record->id.'/packet')->assertUnprocessable();
        $fresh = app(ProviderMedicationTransfers::class)->create($this->manager, $this->transferInput(key: 'fresh'));
        $order->forceFill(['version' => 2])->saveQuietly();
        $this->postJson('/emar/connected-care/transfers/'.$fresh->id.'/transition', $this->action('review', 1, 'review-2'))->assertUnprocessable();
        $this->assertSame('draft', $fresh->refresh()->status);
    }

    public static function ambiguousIncomingInstants(): array
    {
        return [
            'capture timestamp' => ['captured_at', 'captured_at'],
            'last given dose timestamp' => ['medications.0.last_dose.given_at', 'given_at'],
            'next due timestamp' => ['medications.0.next_due_at', 'medications.0.next_due_at'],
        ];
    }

    #[DataProvider('ambiguousIncomingInstants')]
    public function test_incoming_timestamp_without_offset_is_rejected_before_any_source_facts_persist(string $field, string $error): void
    {
        $input = $this->timedTransferInput();
        data_set($input, 'source_snapshot.'.$field, '2026-10-06 22:00');
        $this->actingAs($this->manager)->postJson('/emar/connected-care/transfers', $input)->assertUnprocessable()->assertJsonValidationErrors($error);
        $this->assertDatabaseCount('medication_provider_transfers', 0);
        $this->assertDatabaseCount('medication_provider_transfer_events', 0);
        $this->assertDatabaseCount('medication_reconciliations', 0);
        $this->assertDatabaseCount('client_medications', 0);
        $this->assertDatabaseCount('medication_allergies', 0);
        // Rejected source details consume no idempotency key; corrected source can be received.
        $this->postJson('/emar/connected-care/transfers', $this->timedTransferInput())->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseCount('medication_provider_transfers', 1);
        Mail::assertNothingSent();
    }

    public static function explicitIncomingInstants(): array
    {
        return ['non-UTC offsets' => [false], 'UTC Z timestamps and empty allergy list' => [true]];
    }

    #[DataProvider('explicitIncomingInstants')]
    public function test_incoming_instants_normalize_to_utc_in_reviewed_packet_and_reconciliation_while_calendar_dates_stay_dates(bool $z): void
    {
        $input = $this->timedTransferInput();
        if ($z) {
            $input['source_snapshot']['captured_at'] = '2026-10-06T23:30:00Z';
            $input['source_snapshot']['medications'][0]['last_dose']['given_at'] = '2026-10-06T23:00:00.125Z';
            $input['source_snapshot']['medications'][0]['next_due_at'] = '2026-10-07T00:30:00Z';
            $input['source_snapshot']['allergies'] = [];
        }
        $response = $this->actingAs($this->manager)->postJson('/emar/connected-care/transfers', $input)->assertOk();
        $record = MedicationProviderTransfer::findOrFail($response->json('id'));
        $expected = [
            'captured_at' => '2026-10-06T23:30:00+00:00',
            'medications.0.last_dose.given_at' => '2026-10-06T23:00:00.125000+00:00',
            'medications.0.next_due_at' => '2026-10-07T00:30:00+00:00',
            'person.date_of_birth' => $this->person->date_of_birth->toDateString(),
            'medications.0.prescription.start_date' => '2026-10-07',
            'medications.0.prescription.end_date' => '2026-10-08',
        ];
        foreach ($expected as $field => $value) {
            $this->assertSame($value, data_get($record->snapshot, $field));
        }
        $this->assertFalse($record->snapshot['verified']);
        $this->assertFalse($record->snapshot['medications'][0]['verified']);
        $this->assertSame($input['source_snapshot']['allergies'], $record->snapshot['allergies']);
        $service = app(ProviderMedicationTransfers::class);
        $service->transition($this->manager, $record->id, $this->action('review', 1, 'review'));
        $packet = $this->getJson('/emar/connected-care/transfers/'.$record->id.'/packet')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        foreach ($expected as $field => $value) {
            $packet->assertJsonPath('snapshot.'.$field, $value);
        }
        $service->transition($this->manager, $record->id, $this->action('receipt', 2, 'receipt'));
        $service->transition($this->manager, $record->id, $this->action('start_reconciliation', 3, 'reconcile'));
        $source = $record->fresh()->reconciliation->items()->whereNull('client_medication_id')->sole();
        $this->assertSame($expected['medications.0.last_dose.given_at'], $source->last_dose_evidence['external']['given_at']);
        $this->assertSame($expected['medications.0.next_due_at'], $source->source_order['provider_source']['next_due_at']);
        $this->assertSame($expected['medications.0.next_due_at'], $source->next_dose_at->utc()->toIso8601String());
        $this->get('/emar/prescriptions?view=reconciliation')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('emar/Orders')->has('reconciliations', 1)
                ->where('reconciliations.0.items.0.id', $source->id)
                ->where('reconciliations.0.items.0.next_dose_at', '2026-10-07T00:30:00.000000Z')
                ->where('reconciliations.0.items.0.source_order.verified', false)
                ->where('reconciliations.0.items.0.last_dose_evidence.source', 'unverified_provider')
                ->where('reconciliations.0.items.0.last_dose_evidence.external.given_at', $expected['medications.0.last_dose.given_at']));
        $this->assertSame('2026-10-07', $source->source_order['provider_source']['prescription']['start_date']);
        $this->assertSame('2026-10-08', $source->source_order['provider_source']['prescription']['end_date']);
        $this->assertFalse($source->source_order['verified']);
        $this->assertDatabaseCount('client_medications', 0);
        $this->assertDatabaseCount('medication_allergies', 0);
        Mail::assertNothingSent();
    }

    public function test_provider_review_times_map_to_their_source_items_without_replacing_canonical_doses_or_bypassing_signoff(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        $order = $this->chart(['name' => 'Internal chart medicine']);
        $slot = MedicationDoseSlot::create(['client_id' => $this->person->id, 'client_medication_id' => $order->id,
            'nz_date' => '2026-10-07', 'ordered_time' => '15:00', 'due_at' => now()->addHours(2), 'generated_at' => now()]);
        $orderBefore = (array) DB::table('client_medications')->where('id', $order->id)->first();
        $slotBefore = (array) DB::table('medication_dose_slots')->where('id', $slot->id)->first();
        $input = $this->timedTransferInput();
        $input['source_snapshot']['medications'][] = ['prescription' => $this->prescription(['name' => 'No provider dose time']),
            'last_dose' => null, 'next_due_at' => null];
        $input['source_snapshot']['medications'][] = ['prescription' => $this->prescription(['name' => 'Third source medicine', 'dosage' => '20 mg']),
            'last_dose' => ['given_at' => '2026-10-06T22:45:00Z', 'dose_given' => '20 mg', 'source' => 'Named provider record'],
            'next_due_at' => '2026-10-07T14:45:00+13:00'];
        $response = $this->actingAs($this->manager)->postJson('/emar/connected-care/transfers', $input)->assertOk();
        $record = MedicationProviderTransfer::findOrFail($response->json('id'));
        $url = '/emar/connected-care/transfers/'.$record->id.'/transition';
        $this->postJson($url, $this->action('review', 1, 'mixed-review'))->assertOk();
        $this->postJson($url, $this->action('receipt', 2, 'mixed-receipt'))->assertOk();
        $this->postJson($url, $this->action('start_reconciliation', 3, 'mixed-reconcile'))->assertOk();
        $record->refresh();
        $reconciliation = $record->reconciliation;
        $this->assertNull($reconciliation->signed_off_at);
        $canonical = $reconciliation->items()->where('client_medication_id', $order->id)->sole();
        $this->assertSame('2026-10-07T02:00:00+00:00', $canonical->next_dose_at->utc()->toIso8601String());
        $this->assertSame('Internal chart medicine', $canonical->medicine_name);
        $this->assertSame('not_recorded', $canonical->last_dose_evidence['source']);
        $this->assertArrayNotHasKey('provider_source', $canonical->source_order);
        $sourceItems = $reconciliation->items()->whereNull('client_medication_id')->orderBy('id')->get();
        $this->assertCount(3, $sourceItems);
        foreach ($sourceItems as $index => $item) {
            $source = $record->snapshot['medications'][$index];
            $this->assertEquals($source, $item->source_order['provider_source']);
            $this->assertSame($source['prescription']['name'], $item->medicine_name);
            $this->assertSame($source['prescription']['controlled_drug'], $item->controlled);
            $this->assertFalse($item->source_order['verified']);
            $this->assertSame($record->id, $item->source_order['provider_transfer_id']);
            $this->assertSame('unverified_provider', $item->last_dose_evidence['source']);
            $this->assertEquals($source['last_dose'], $item->last_dose_evidence['external']);
            $this->assertSame($source['next_due_at'], $item->next_dose_at?->utc()->toIso8601String());
            $this->assertSame('Unverified provider source: '.json_encode($source, JSON_THROW_ON_ERROR), $item->notes);
        }
        $this->assertSame($input['source_snapshot']['allergies'], $record->events()->where('action', 'start_reconciliation')->sole()->evidence['unverified_allergies']);
        $this->get('/emar/prescriptions?view=reconciliation')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('reconciliations.0.items', 4)
                ->where('reconciliations.0.items.0.id', $canonical->id)
                ->where('reconciliations.0.items.0.next_dose_at', '2026-10-07T02:00:00.000000Z')
                ->where('reconciliations.0.items.1.next_dose_at', '2026-10-07T00:30:00.000000Z')
                ->where('reconciliations.0.items.2.next_dose_at', null)
                ->where('reconciliations.0.items.3.next_dose_at', '2026-10-07T01:45:00.000000Z'));
        $this->postJson('/emar/reconciliations/'.$reconciliation->id.'/sign-off')->assertUnprocessable();
        $this->postJson($url, $this->action('complete', 4, 'mixed-complete'))->assertUnprocessable();
        $this->assertNull($reconciliation->fresh()->signed_off_at);
        $this->assertSame('reconciliation_started', $record->fresh()->status);
        $this->assertDatabaseCount('client_medications', 1);
        $this->assertDatabaseCount('medication_dose_slots', 1);
        $this->assertSame($orderBefore, (array) DB::table('client_medications')->where('id', $order->id)->first());
        $this->assertSame($slotBefore, (array) DB::table('medication_dose_slots')->where('id', $slot->id)->first());
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('medication_allergies', 0);
        $this->assertDatabaseCount('client_medication_stocks', 0);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public static function keyedIncomingFacts(): array
    {
        return [
            'string-key medicine dictionary' => ['medications', 'medicine-a'],
            'nonzero numeric-key medicine dictionary' => ['medications', 1],
            'string-key allergy dictionary' => ['allergies', 'allergy-a'],
            'nonzero numeric-key allergy dictionary' => ['allergies', 1],
        ];
    }

    #[DataProvider('keyedIncomingFacts')]
    public function test_incoming_fact_dictionaries_are_rejected_before_persistence_or_reconciliation(string $field, string|int $key): void
    {
        $input = $this->timedTransferInput();
        $input['source_snapshot'][$field] = [$key => $input['source_snapshot'][$field][0]];
        $this->actingAs($this->manager)->postJson('/emar/connected-care/transfers', $input)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('medication_provider_transfers', 0);
        $this->assertDatabaseCount('medication_provider_transfer_events', 0);
        $this->assertDatabaseCount('medication_reconciliations', 0);
        $this->assertDatabaseCount('client_medications', 0);
        $this->assertDatabaseCount('medication_allergies', 0);
        $this->postJson('/emar/connected-care/transfers', $this->timedTransferInput())->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseCount('medication_provider_transfers', 1);
        Mail::assertNothingSent();
    }

    /** EA-104: with the two-person rule on (its default), the person who made a handover doesn't also review it. */
    public function test_b10_handover_review_needs_a_second_person(): void
    {
        ConnectedCareSwitches::on('two_person_handover');
        $this->chart();
        $record = app(ProviderMedicationTransfers::class)->create($this->manager, $this->transferInput());
        $this->actingAs($this->manager)->postJson('/emar/connected-care/transfers/'.$record->id.'/transition', $this->action('review', 1, 'review-self'))
            ->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->assertSame('draft', $record->fresh()->status);
        $this->actingAs($this->connectedStaff())->postJson('/emar/connected-care/transfers/'.$record->id.'/transition', $this->action('review', 1, 'review-other'))->assertOk();
        $this->assertSame('reviewed', $record->fresh()->status);
    }

    /** EA-087 + EA-085: the packet is a P09 export; the duplicate JSON route is gone; allergy status travels with it. */
    public function test_b10_handover_packet_is_recorded_in_export_history_and_says_the_allergy_status(): void
    {
        $this->chart();
        $record = app(ProviderMedicationTransfers::class)->create($this->manager, $this->transferInput());
        $this->actingAs($this->manager)->postJson('/emar/connected-care/transfers/'.$record->id.'/transition', $this->action('review', 1, 'review-1'))->assertOk();
        $this->getJson('/emar/connected-care/transfers/'.$record->id.'/handover')->assertNotFound();
        $this->getJson('/emar/connected-care/transfers/'.$record->id.'/packet')->assertOk()
            ->assertJsonPath('snapshot.allergy_status.status', 'not_assessed');
        $export = \App\Models\MedicationEvent::query()->where('kind', 'export.created')->where('subject_id', 'provider_handover')->sole();
        $this->assertSame((int) $this->manager->id, (int) $export->actor_id);
        $this->assertSame((int) $this->person->id, (int) $export->client_id);
        $this->assertStringContainsString('Approved care transfer', (string) json_encode($export->facts));
    }

    private function timedTransferInput(): array
    {
        $input = $this->transferInput('incoming');
        $input['source_snapshot']['captured_at'] = '2026-10-07T12:30:00+13:00';
        $input['source_snapshot']['medications'][0]['last_dose'] = ['given_at' => '2026-10-06T19:00:00.125-04:00', 'dose_given' => '10 mg', 'source' => 'Unverified provider medication chart'];
        $input['source_snapshot']['medications'][0]['next_due_at'] = '2026-10-07T06:15:00+05:45';
        $input['source_snapshot']['medications'][0]['prescription']['start_date'] = '2026-10-07';
        $input['source_snapshot']['medications'][0]['prescription']['end_date'] = '2026-10-08';

        return $input;
    }

    public function test_incoming_identity_mismatch_is_rejected_before_source_facts_persist(): void
    {
        $input = $this->transferInput('incoming');
        $input['source_snapshot']['person']['date_of_birth'] = '1900-01-01';
        $this->actingAs($this->manager)->postJson('/emar/connected-care/transfers', $input)->assertUnprocessable();
        $this->assertSame(0, MedicationProviderTransfer::count());
    }

    public function test_incoming_receipt_opens_canonical_reconciliation_without_importing_active_orders_or_allergies(): void
    {
        $this->chart();
        $service = app(ProviderMedicationTransfers::class);
        $record = $service->create($this->manager, $this->transferInput('incoming'));
        $service->transition($this->manager, $record->id, $this->action('review', 1, 'review'));
        $service->transition($this->manager, $record->id, $this->action('receipt', 2, 'receipt'));
        $service->transition($this->manager, $record->id, $this->action('start_reconciliation', 3, 'reconcile'));
        $record->refresh();
        $this->assertSame('reconciliation_started', $record->status);
        $reconciliation = MedicationReconciliation::findOrFail($record->reconciliation_id);
        $this->assertSame($this->person->id, $reconciliation->client_id);
        $source = $reconciliation->items()->whereNull('client_medication_id')->firstOrFail();
        $this->assertSame('Incoming source medicine', $source->medicine_name);
        $this->assertFalse($source->source_order['verified']);
        $this->assertSame($record->id, $source->source_order['provider_transfer_id']);
        $this->assertSame(1, ClientMedication::count());
        $this->assertSame(0, MedicationAllergy::count());
        $this->actingAs($this->manager)->postJson('/emar/connected-care/transfers/'.$record->id.'/transition', $this->action('complete', 4, 'complete'))->assertUnprocessable();
    }

    public function test_full_accepted_packet_retains_complete_encrypted_reconciliation_evidence_and_blocks_lossy_rollback(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        $order = $this->chart();
        $orderBefore = (array) DB::table('client_medications')->where('id', $order->id)->first();
        $input = $this->transferInput('incoming', 'full-byte-boundary');
        $allergies = array_map(fn (int $index) => ['allergen' => 'Source allergy '.$index,
            'reaction' => str_repeat('r', 2000), 'severity' => 'mild', 'notes' => str_repeat('n', 2000)], range(1, 63));
        $input['source_snapshot']['allergies'] = $allergies;
        $excess = strlen(json_encode($input['source_snapshot'], JSON_THROW_ON_ERROR)) - 256000;
        $this->assertGreaterThan(0, $excess);
        $this->assertLessThan(2000, $excess);
        $allergies[62]['notes'] = substr($allergies[62]['notes'], 0, 2000 - $excess);
        $input['source_snapshot']['allergies'] = $allergies;
        $this->assertSame(256000, strlen(json_encode($input['source_snapshot'], JSON_THROW_ON_ERROR)));
        $this->assertSame('longtext', Schema::getColumnType('medication_provider_transfer_events', 'evidence'));
        // The additive widening has run after the original TEXT table was created.
        $this->assertDatabaseHas('migrations', ['migration' => '2026_10_07_096000_expand_medication_provider_transfer_event_evidence']);

        $oversized = $input;
        $oversized['source_snapshot']['allergies'][62]['notes'] .= 'n';
        $this->assertSame(256001, strlen(json_encode($oversized['source_snapshot'], JSON_THROW_ON_ERROR)));
        $this->actingAs($this->manager)->postJson('/emar/connected-care/transfers', $oversized)->assertUnprocessable()->assertJsonValidationErrors('source_snapshot');
        $this->assertDatabaseCount('medication_provider_transfers', 0);
        $this->assertDatabaseCount('medication_provider_transfer_events', 0);
        $this->assertDatabaseCount('medication_reconciliations', 0);
        // Correcting the rejected packet can reuse the same request key.
        $response = $this->postJson('/emar/connected-care/transfers', $input)->assertOk();
        $record = MedicationProviderTransfer::findOrFail($response->json('id'));
        $this->assertSame($allergies, $record->snapshot['allergies']);
        $this->assertFalse($record->snapshot['verified']);
        $url = '/emar/connected-care/transfers/'.$record->id.'/transition';
        $this->postJson($url, $this->action('review', 1, 'boundary-review'))->assertOk();
        $this->postJson($url, $this->action('receipt', 2, 'boundary-receipt'))->assertOk();
        $start = $this->action('start_reconciliation', 3, 'boundary-reconcile');
        $this->postJson($url, $start)->assertOk();
        $record->refresh();
        $event = $record->events()->where('action', 'start_reconciliation')->sole();
        $expectedEvidence = ['note' => $start['note'], 'identity_confirmed' => true,
            'reconciliation_id' => $record->reconciliation_id, 'unverified_allergies' => $allergies];
        $this->assertSame($expectedEvidence, $event->evidence);
        $this->assertSame($this->manager->id, $event->actor_id);
        $this->assertGreaterThan(65535, strlen(json_encode($expectedEvidence, JSON_THROW_ON_ERROR)));
        $rawEvent = (array) DB::table('medication_provider_transfer_events')->where('id', $event->id)->first();
        $this->assertGreaterThan(65535, strlen($rawEvent['evidence']));
        $this->assertStringNotContainsString(str_repeat('n', 2000), $rawEvent['evidence']);

        $this->postJson($url, $start)->assertOk();
        $this->assertSame($rawEvent, (array) DB::table('medication_provider_transfer_events')->where('id', $event->id)->first());
        $this->assertSame('reconciliation_started', $record->fresh()->status);
        $this->assertSame(4, $record->fresh()->version);
        $this->assertDatabaseCount('medication_provider_transfer_events', 3);
        $this->assertDatabaseCount('medication_reconciliations', 1);
        $reconciliation = $record->fresh()->reconciliation;
        $this->assertSame($this->person->id, $reconciliation->client_id);
        $source = $reconciliation->items()->whereNull('client_medication_id')->sole();
        $this->assertSame($record->id, $source->source_order['provider_transfer_id']);
        $this->assertFalse($source->source_order['verified']);
        $this->assertDatabaseCount('client_medications', 1);
        $this->assertSame($orderBefore, (array) DB::table('client_medications')->where('id', $order->id)->first());
        $this->assertDatabaseCount('medication_allergies', 0);
        $this->assertDatabaseCount('client_medication_stocks', 0);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('medication_pharmacy_orders', 0);
        Mail::assertNothingSent();
        Http::assertNothingSent();

        $migration = require database_path('migrations/2026_10_07_096000_expand_medication_provider_transfer_event_evidence.php');
        try {
            $migration->down();
            $this->fail('Rollback must refuse to truncate retained encrypted handover evidence.');
        } catch (\RuntimeException $failure) {
            $this->assertSame('Retain full encrypted provider transfer evidence before reducing storage capacity.', $failure->getMessage());
        }
        $this->assertSame('longtext', Schema::getColumnType('medication_provider_transfer_events', 'evidence'));
        $this->assertSame($rawEvent, (array) DB::table('medication_provider_transfer_events')->where('id', $event->id)->first());
        $this->assertSame($expectedEvidence, $event->fresh()->evidence);
    }

    public function test_transfer_create_and_transition_replays_are_exact_and_do_not_duplicate_reconciliation(): void
    {
        $service = app(ProviderMedicationTransfers::class);
        $input = $this->transferInput('incoming');
        $record = $service->create($this->manager, $input);
        $this->assertSame($record->id, $service->create($this->manager, $input)->id);
        $review = $this->action('review', 1, 'same-review');
        $service->transition($this->manager, $record->id, $review);
        $this->assertSame(2, $service->transition($this->manager, $record->id, $review)->version);
        $this->actingAs($this->manager)->postJson('/emar/connected-care/transfers/'.$record->id.'/transition', [...$review, 'note' => 'Different details'])->assertConflict();
        $service->transition($this->manager, $record->id, $this->action('receipt', 2, 'receipt'));
        $start = $this->action('start_reconciliation', 3, 'reconciliation');
        $service->transition($this->manager, $record->id, $start);
        $service->transition($this->manager, $record->id, $start);
        $this->assertSame(1, MedicationReconciliation::count());
        $this->assertSame(3, MedicationProviderTransferEvent::count());
        $this->postJson('/emar/connected-care/transfers', [...$input, 'purpose' => 'Other purpose'])->assertConflict();
    }

    public function test_stale_expected_version_and_invalid_lifecycle_do_not_record_action(): void
    {
        $record = app(ProviderMedicationTransfers::class)->create($this->manager, $this->transferInput());
        $this->actingAs($this->manager)->postJson('/emar/connected-care/transfers/'.$record->id.'/transition', $this->action('receipt', 1, 'early-receipt'))->assertUnprocessable();
        $this->postJson('/emar/connected-care/transfers/'.$record->id.'/transition', $this->action('review', 2, 'stale-review'))->assertUnprocessable();
        $this->assertSame(0, MedicationProviderTransferEvent::count());
        $this->assertSame(1, $record->refresh()->version);
    }

    public function test_foreign_person_site_move_controlled_disclosure_and_export_permissions_fail_closed(): void
    {
        $this->chart();
        $record = app(ProviderMedicationTransfers::class)->create($this->manager, $this->transferInput());
        $other = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $hidden = Client::factory()->create(['site_id' => $other->id]);
        $this->actingAs($this->manager)->postJson('/emar/connected-care/transfers', [...$this->transferInput(), 'client_id' => $hidden->id, 'request_key' => 'hidden'])->assertNotFound();
        $this->person->update(['site_id' => $other->id]);
        $this->getJson('/emar/connected-care/transfers/'.$record->id.'/packet')->assertNotFound();
        $this->person->update(['site_id' => $this->site->id]);
        $this->postJson('/emar/connected-care/transfers/'.$record->id.'/transition', $this->action('review', 1, 'review'))->assertOk();
        $export = Permission::where('key', 'medications.reports.export')->firstOrFail();
        $this->manager->permissionOverrides()->updateExistingPivot($export->id, ['allowed' => false]);
        $this->getJson('/emar/connected-care/transfers/'.$record->id.'/packet')->assertForbidden();
        $this->chart(['name' => 'Controlled source', 'controlled_drug' => true]);
        $this->postJson('/emar/connected-care/transfers', [...$this->transferInput(), 'request_key' => 'controlled'])->assertNotFound();
    }

    public function test_external_identity_cannot_create_read_or_receive_internal_transfers(): void
    {
        $record = app(ProviderMedicationTransfers::class)->create($this->manager, $this->transferInput());
        $this->actingAs($this->clinician)->postJson('/emar/connected-care/transfers', $this->transferInput())->assertForbidden();
        $this->getJson('/emar/connected-care/transfers/'.$record->id.'/packet')->assertForbidden();
        $this->postJson('/emar/connected-care/transfers/'.$record->id.'/transition', $this->action('receipt', 1, 'no'))->assertForbidden();
    }

    public function test_retry_rechecks_current_transfer_permission_and_rolls_back_review(): void
    {
        $record = app(ProviderMedicationTransfers::class)->create($this->manager, $this->transferInput());
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        DB::commit();
        $transactions = new DatabaseTransactionsManager;
        $this->app->instance('db.transactions', $transactions);
        DB::connection()->setTransactionManager($transactions);
        $attempts = 0;
        MedicationProviderTransferEvent::created(function () use (&$attempts): void {
            if (++$attempts === 1) {
                throw new QueryException('mysql', 'select 1', [], new \PDOException(
                    'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock', 40001));
            }
        });
        $revoked = false;
        $permission = Permission::where('key', 'medications.transfers.manage')->firstOrFail();
        DB::connection()->beforeStartingTransaction(function ($connection) use (&$attempts, &$revoked, $permission): void {
            if ($connection->transactionLevel() === 0 && $attempts === 1 && ! $revoked) {
                $revoked = true;
                DB::table('permission_user')->where('user_id', $this->manager->id)->where('permission_id', $permission->id)->update(['allowed' => false]);
            }
        });
        $this->actingAs($this->manager)->postJson('/emar/connected-care/transfers/'.$record->id.'/transition', $this->action('review', 1, 'retry-review'))->assertForbidden();
        $this->assertTrue($revoked);
        $this->assertSame(1, $attempts);
        $this->assertSame('draft', $record->refresh()->status);
        $this->assertSame(1, $record->version);
        $this->assertSame(0, MedicationProviderTransferEvent::count());
    }

    public static function allergySources(): array
    {
        return ['legacy register' => ['register'], 'canonical health profile' => ['profile']];
    }

    #[DataProvider('allergySources')]
    public function test_reviewed_export_rechecks_committed_allergy_changes_despite_an_older_read_snapshot(string $source): void
    {
        $this->chart();
        $entry = ['key' => 'canonical-example', 'allergen' => 'Example allergen', 'reaction' => 'Rash', 'severity' => 'mild'];
        if ($source === 'register') {
            $record = MedicationAllergy::create(['client_id' => $this->person->id, ...array_diff_key($entry, ['key' => true]), 'recorded_by' => $this->manager->id]);
            $table = 'medication_allergies';
            $field = 'reaction';
            $update = ['reaction' => 'Anaphylaxis'];
        } else {
            $record = ClientMedicalProfile::create(['client_id' => $this->person->id, 'allergies' => ['Example allergen'],
                'allergy_records' => [$entry], 'allergies_canonical_at' => now()]);
            $table = 'client_medical_profiles';
            $field = 'allergy_records';
            $update = ['allergy_records' => json_encode([[...$entry, 'reaction' => 'Anaphylaxis']], JSON_THROW_ON_ERROR)];
        }
        $service = app(ProviderMedicationTransfers::class);
        $transfer = $service->create($this->manager, $this->transferInput());
        $this->assertSame('Rash', $transfer->snapshot['allergies'][0]['reaction']);
        $service->transition($this->manager, $transfer->id, $this->action('review', 1, 'review'));
        $primary = DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', $primary->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $this->assertSame('127.0.0.1', $primary->getConfig('host'));
        $this->assertGreaterThan(0, getmypid());
        $this->assertContains($primary->getDatabaseName(), [
            'oblivion_findings_codex_test_'.getmypid(),
            'emar_connected_external_20261007_'.getmypid(),
        ]);
        $this->assertSame(self::$isolatedMysqlDatabase, $primary->getDatabaseName());
        $this->assertSame($primary->getDatabaseName(), $primary->selectOne('SELECT DATABASE() AS owned_database')->owned_database);
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        DB::commit();
        $connectionName = 'external_transfer_allergy_writer';
        $connectionConfig = config('database.connections.'.DB::getDefaultConnection());
        $this->assertSame('127.0.0.1', $connectionConfig['host']);
        $connectionConfig['name'] = $connectionName;
        config(['database.connections.'.$connectionName => $connectionConfig]);
        $writer = DB::connection($connectionName);
        $this->assertSame($connectionName, $writer->getName());
        $this->assertNotSame($primary->getPdo(), $writer->getPdo());
        $this->assertSame($primary->getDatabaseName(), $writer->getDatabaseName());
        $this->assertSame('REPEATABLE-READ', $primary->selectOne('SELECT @@SESSION.transaction_isolation AS isolation_level')->isolation_level);
        $primary->beginTransaction();
        try {
            $old = $primary->table($table)->where('id', $record->id)->value($field);
            $writer->transaction(function () use ($writer, $table, $record, $update): void {
                $writer->table('clients')->where('id', $this->person->id)->lockForUpdate()->first();
                $writer->table($table)->where('id', $record->id)->update($update);
            });
            $this->assertSame(0, $writer->transactionLevel());
            $this->assertNotSame($old, $writer->table($table)->where('id', $record->id)->value($field));
            $this->assertSame($old, $primary->table($table)->where('id', $record->id)->value($field));
            try {
                $service->reviewed($this->manager, $transfer->id);
                $this->fail('A stale reviewed allergy snapshot must release no handover packet.');
            } catch (ValidationException $failure) {
                $this->assertArrayHasKey('snapshot', $failure->errors());
            }
        } finally {
            while ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            DB::purge($connectionName);
        }
    }

    public function test_complete_pagination_retains_older_transfer_work_and_site_scope(): void
    {
        $template = app(ProviderMedicationTransfers::class)->create($this->manager, $this->transferInput());
        for ($i = 0; $i < 101; $i++) {
            $copy = $template->replicate();
            $copy->request_key = 'page-'.$i;
            $copy->save();
        }
        $this->actingAs($this->manager)->get('/emar/connected-care?client_id='.$this->person->id.'&transfers_page=2')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('pagination.transfers.total', 102)->where('pagination.transfers.current_page', 2)
                ->where('pagination.transfers.last_page', 2)->has('transfers', 2)->where('transfers.1.id', $template->id));
    }
}
