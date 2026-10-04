<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationSettingChange;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Controlled\ControlledPolicy;
use App\Services\Medication\Controlled\ControlledRegisterService;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Tests\Support\CommittedDatabaseTestCase;

/** Real committed stock restoration against an older publication read view. */
class ControlledWeeklyPublicationConcurrencyTest extends CommittedDatabaseTestCase
{
    public function test_restored_historical_stock_blocks_weekly_publication_with_an_older_repeatable_read_snapshot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 10:30', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        Cache::flush();
        $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $context = ServiceContext::factory()->create(['type' => 'residential', 'is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $context->id, 'status' => 'active']);
        $publisher = $this->staff('provider_manager', 'Synthetic policy publisher', $site, $client);
        $registerActor = $this->staff('team_lead', 'Synthetic register restorer', $site, $client);
        $witness = $this->staff('support_worker', 'Synthetic restoration witness', $site, $client);
        $this->assertNotSame($publisher->id, $registerActor->id);
        $this->assertNotSame($publisher->id, $witness->id);
        $medicine = ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Synthetic historical controlled medicine', 'dosage' => '1 tablet',
            'frequency' => 'Daily', 'dose_times' => ['09:30'], 'active' => false, 'state' => 'ceased',
            'approval_status' => 'verified', 'controlled_drug' => true, 'nz_controlled_class' => 'B',
            'controlled_class_source' => 'Synthetic reviewed configuration', 'ceased_at' => now()->subDays(8),
            'ceased_by' => $registerActor->id, 'ceased_reason' => 'Synthetic historical order',
        ]);
        DB::table('client_medications')->where('id', $medicine->id)->update(['created_at' => now()->subDays(9), 'deleted_at' => now()->subDays(8)]);
        $stock = ClientMedicationStock::query()->create(['client_medication_id' => $medicine->id, 'on_hand' => 0, 'unit' => 'tablet']);
        $original = ClientControlledDrugEntry::query()->create([
            'client_id' => $client->id, 'client_medication_id' => $medicine->id, 'service_context_id' => $context->id,
            'entry_type' => 'transfer_out', 'quantity' => 10, 'unit' => 'tablet', 'on_hand_before' => 10,
            'on_hand_after' => 0, 'recorded_by' => $registerActor->id, 'witnessed_by' => $witness->id,
            'recorded_at' => now()->subDays(8), 'reason' => 'Synthetic historical transfer',
        ]);
        $anchor = '{"day":4,"time":"09:00"}';
        foreach ([ControlledPolicy::COUNT_CADENCE => 'week', ControlledPolicy::COUNT_WEEKLY_ANCHOR => $anchor] as $key => $value) {
            AppSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        DB::table('app_settings')->whereIn('key', [ControlledPolicy::COUNT_CADENCE, ControlledPolicy::COUNT_WEEKLY_ANCHOR])
            ->update(['created_at' => now()->subDays(9), 'updated_at' => now()->subDays(9)]);

        $publisherConnection = DB::getDefaultConnection();
        $writerConnection = 'weekly_restoration';
        config(['database.connections.'.$writerConnection => config('database.connections.'.$publisherConnection)]);
        $writer = DB::connection($writerConnection);
        $this->assertIsolatedTestConnection($writer);
        $observerConfig = config('database.connections.'.$publisherConnection);
        $this->assertSame('127.0.0.1', $observerConfig['host']);
        $observer = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s', $observerConfig['host'], $observerConfig['port'], $observerConfig['database']),
            $observerConfig['username'], $observerConfig['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertSame(DB::connection($publisherConnection)->getDatabaseName(), $observer->query('SELECT DATABASE()')->fetchColumn());
        $this->assertFalse($observer->inTransaction());
        foreach ([DB::connection($publisherConnection), $writer] as $connection) {
            $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $connection->statement('SET SESSION innodb_lock_wait_timeout = 2');
        }
        $this->assertNotSame(DB::connection($publisherConnection)->selectOne('SELECT CONNECTION_ID() AS id')->id,
            $writer->selectOne('SELECT CONNECTION_ID() AS id')->id);
        $injected = false;
        $restorationCompleted = false;
        $interleavingFailure = null;
        $active = true;
        $auditAfterRestoration = null;
        DB::connection($publisherConnection)->beforeExecuting(function (string $query, array $bindings, $connection) use (
            $publisherConnection, $writerConnection, $publisher, $registerActor, $witness, $stock, $medicine, $original,
            $observer,
            &$injected, &$restorationCompleted, &$interleavingFailure, &$active, &$auditAfterRestoration,
        ): void {
            // Interleave at the observed first actor lock, before publication's
            // RBAC evidence locks could block the independent register writer.
            if (! $active || $injected || $connection->transactionLevel() < 1
                || $query !== 'select * from `users` where `id` in (?) order by `id` asc for update'
                || $bindings !== [(int) $publisher->id]) {
                return;
            }
            $injected = true;
            $this->assertGreaterThan(0, DB::connection($publisherConnection)->transactionLevel());
            $this->assertTrue(DB::connection($publisherConnection)->getPdo()->inTransaction());
            $this->assertFalse(DB::connection($writerConnection)->getPdo()->inTransaction());
            // This ordinary read fixes the old view before the writer commits.
            $this->assertSame('0.00', (string) DB::table('client_medication_stocks')->where('id', $stock->id)->value('on_hand'));
            DB::setDefaultConnection($writerConnection);
            try {
                app(ControlledRegisterService::class)->perform(User::query()->findOrFail($registerActor->id), 'void', [
                    'client_medication_id' => $medicine->id, 'client_request_uuid' => (string) Str::uuid(),
                    'expected_balance' => 0, 'expected_entry_id' => $original->id, 'target_id' => $original->id,
                    'witnessed_by' => $witness->id, 'witness_credential' => UserFactory::TEST_WITNESS_PIN,
                    'notes' => 'Synthetic witnessed restoration of an erroneous historical transfer.',
                ]);
                $this->assertSame(0, DB::transactionLevel(), 'The independent register writer must commit before publication resumes.');
                $this->assertFalse(DB::connection($writerConnection)->getPdo()->inTransaction());
                $this->assertSame('10.00', (string) DB::table('client_medication_stocks')->where('id', $stock->id)->value('on_hand'));
                $observe = $observer->prepare('SELECT on_hand FROM client_medication_stocks WHERE id = ?');
                $observe->execute([$stock->id]);
                $observed = $observe->fetchColumn();
                $this->assertSame('10.00', (string) $observed, 'A fresh physical observer must see the independently committed stock restoration.');
                $restoredMedicine = ClientMedication::withTrashed()->with(['client.site', 'stock'])->findOrFail($medicine->id);
                $this->assertSame('overdue', app(ControlledPolicy::class)->countStatus($restoredMedicine, now())['status']);
                $auditAfterRestoration = AuditLog::query()->count();
                $restorationCompleted = true;
            } catch (\Throwable $failure) {
                $interleavingFailure = $failure;
                // An injected writer failure must never look like a genuine
                // publication race after the controller's concurrency retry.
                throw new \RuntimeException('Synthetic concurrency interleaving failed.', 0, $failure);
            } finally {
                DB::setDefaultConnection($publisherConnection);
            }
            $this->assertSame('0.00', (string) DB::table('client_medication_stocks')->where('id', $stock->id)->value('on_hand'),
                'The publisher must still have the pre-restoration nonlocking view.');
        });
        try {
            $response = $this->actingAs($publisher->fresh())->putJson('/emar/settings/changes', ['view' => 'rules', 'changes' => [
                ['group' => 'controlled_counts', 'key' => 'weekly_anchor', 'from' => $anchor, 'value' => '{"day":4,"time":"13:00"}'],
            ], 'confirm_loosening' => true]);
            $this->assertSame(0, DB::connection($publisherConnection)->transactionLevel());
            $this->assertFalse(DB::connection($publisherConnection)->getPdo()->inTransaction());
            $this->assertTrue($injected, 'The real publication actor lock must trigger the restoration interleaving.');
            $this->assertNull($interleavingFailure, $interleavingFailure?->getMessage() ?? '');
            $this->assertTrue($restorationCompleted, 'The canonical restoration must physically commit before publication resumes.');
            $this->assertIsInt($auditAfterRestoration);
            $response->assertUnprocessable()->assertJsonValidationErrors('controlled_counts.weekly_anchor');
            $errors = json_encode($response->json('errors'), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString($medicine->name, $errors);
            $this->assertStringNotContainsString($client->first_name, $errors);
            $this->assertSame($anchor, AppSetting::query()->where('key', ControlledPolicy::COUNT_WEEKLY_ANCHOR)->sole()->value);
            $this->assertSame(0, MedicationSettingChange::query()->count());
            $this->assertSame($auditAfterRestoration, AuditLog::query()->count());
            $this->assertSame('10.00', $stock->fresh()->on_hand);
            $this->assertDatabaseCount('client_controlled_drug_entries', 2);
            $this->assertDatabaseCount('controlled_product_requests', 1);
            $this->assertTrue(ClientMedication::withTrashed()->findOrFail($medicine->id)->trashed());
            foreach (['client_medications' => $medicine->id, 'client_medication_stocks' => $stock->id] as $table => $id) {
                $writer->beginTransaction();
                try {
                    $writer->table($table)->where('id', $id)->lockForUpdate()->first();
                    $busy = $this->actingAs($publisher->fresh())->putJson('/emar/settings/changes', ['view' => 'rules', 'changes' => [
                        ['group' => 'controlled_counts', 'key' => 'weekly_anchor', 'from' => $anchor, 'value' => '{"day":4,"time":"13:00"}'],
                    ], 'confirm_loosening' => true])->assertUnprocessable()->assertJsonValidationErrors('controlled_counts.weekly_anchor');
                    $busyErrors = json_encode($busy->json('errors'), JSON_THROW_ON_ERROR);
                    $this->assertStringContainsString('Counts are being recorded now', $busyErrors, $table);
                    $this->assertStringNotContainsString($medicine->name, $busyErrors);
                    $this->assertStringNotContainsString($client->first_name, $busyErrors);
                    $this->assertSame($anchor, AppSetting::query()->where('key', ControlledPolicy::COUNT_WEEKLY_ANCHOR)->sole()->value);
                    $this->assertSame(0, MedicationSettingChange::query()->count());
                    $this->assertSame($auditAfterRestoration, AuditLog::query()->count());
                } finally {
                    $writer->rollBack();
                }
            }
        } finally {
            $active = false;
            DB::setDefaultConnection($publisherConnection);
            $writer->rollBack(0);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(string $roleName, string $name, Site $site, Client $client): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now(), 'name' => $name]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $roleName)->sole()->id]);
        $permissions = [
            'medications.view' => true, 'medications.administer.record' => true, 'medications.controlled.view' => true,
            'medications.controlled.record' => true, 'medications.controlled.witness' => true,
            ControlledRegisterService::MANAGE => in_array($roleName, ['team_lead', 'provider_manager'], true),
        ];
        $user->permissionOverrides()->syncWithoutDetaching(Permission::query()->whereIn('key', array_keys($permissions))->get()
            ->mapWithKeys(fn (Permission $permission): array => [$permission->id => ['allowed' => $permissions[$permission->key]]])->all());
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => now()->subYear(), 'end_date' => null, 'is_active' => true,
        ]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $user->id, 'assessor_id' => User::factory()->create()->id, 'assessment_type' => 'annual',
            'status' => 'passed', 'assessment_date' => now()->subMonth()->toDateString(), 'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(), 'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true, 'can_witness_controlled' => true, 'controlled_drugs' => true,
            'restricted' => false, 'not_seen_areas' => [],
        ]);
        Shift::factory()->create([
            'user_id' => $user->id, 'client_id' => $client->id, 'site_id' => $site->id,
            'service_context_id' => $client->service_context_id, 'starts_at' => now()->subHour()->utc(),
            'ends_at' => now()->addHours(3)->utc(), 'actual_starts_at' => now()->subMinutes(30)->utc(),
            'actual_ends_at' => null, 'status' => 'in_progress',
        ]);
        Cache::flush();

        return $user;
    }
}
