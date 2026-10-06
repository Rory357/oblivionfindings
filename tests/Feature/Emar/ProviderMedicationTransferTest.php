<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedicalProfile;
use App\Models\ClientMedication;
use App\Models\MedicationAllergy;
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
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\ExternalClinicalFixtures;
use Tests\TestCase;

final class ProviderMedicationTransferTest extends TestCase
{
    use ExternalClinicalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectedFixtures();
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
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        DB::commit();
        $primary = DB::connection();
        $connectionName = 'external_transfer_allergy_writer';
        $connectionConfig = config('database.connections.'.DB::getDefaultConnection());
        $this->assertSame('127.0.0.1', $connectionConfig['host']);
        $this->assertStringStartsWith('emar_connected_external_20261007_', $primary->getDatabaseName());
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
