<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationPrescriberOrder;
use App\Models\MedicationRound;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationScopeDecision;
use App\Services\Medication\MedicationScopeDecisionService;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class MedicationScopeDecisionLockOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private Site $site;

    private ServiceContext $serviceContext;

    private Client $client;

    private ClientMedication $medication;

    private ClientMedication $prnMedication;

    private ClientMedicationAdministration $administration;

    private MedicationPrescriberOrder $prescription;

    private MedicationRound $round;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-27 09:30:00', config('app.worker_timezone', 'Pacific/Auckland')));

        $this->site = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Medication lock order',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $this->worker = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $administerPermission = Permission::query()->create([
            'key' => 'medications.administer.record',
            'description' => 'Record medication administration in lock-order tests',
            'group' => 'medications',
            'module' => 'medications',
        ]);
        $this->worker->permissionOverrides()->sync([
            $administerPermission->id => ['allowed' => true],
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->worker->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => $this->workerToday()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'active',
        ]);
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->worker->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'started_by' => $this->worker->id,
            'created_by' => $this->worker->id,
            'status' => 'in_progress',
        ]);

        $this->medication = $this->createMedication($this->client, false, 'Scheduled lock-order medication');
        $this->prnMedication = $this->createMedication($this->client, true, 'PRN lock-order medication');
        $this->administration = ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $this->prnMedication->id,
            'administered_by' => $this->worker->id,
            'administered_at' => now()->subHour(),
            'status' => 'given',
        ]);
        $this->prescription = MedicationPrescriberOrder::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $this->medication->id,
            'order_type' => 'new',
            'status' => 'pending',
            'prescriber_name' => 'Dr Lock Order',
            'medication_name' => $this->medication->name,
            'dose' => $this->medication->dosage,
            'route' => 'Oral',
            'frequency' => 'Once daily',
            'order_date' => $this->workerToday(),
        ]);
        $this->round = MedicationRound::query()->create([
            'service_context_id' => $this->serviceContext->id,
            'site_id' => $this->site->id,
            'name' => 'Lock-order morning round',
            'scheduled_time' => '09:30',
            'window_minutes' => 60,
            'round_date' => $this->workerToday(),
            'status' => 'in_progress',
            'assigned_to' => $this->worker->id,
            'started_by' => $this->worker->id,
            'started_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_prn_effectiveness_locks_client_then_medication_then_administration(): void
    {
        [$scope, $lockedTables] = $this->captureLockQueries(fn (): MedicationScopeDecision => $this->scope()
            ->forPrnEffectiveness(
                $this->worker,
                $this->administration,
                now(),
                fn (MedicationScopeDecision $scope): MedicationScopeDecision => $scope,
            ));

        $this->assertSame($this->client->id, $scope->client->id);
        $this->assertSame($this->prnMedication->id, $scope->medication?->id);
        $this->assertSame($this->administration->id, $scope->administration?->id);
        $this->assertMysqlLockOrder(
            $lockedTables,
            ['clients', 'client_medications', 'client_medication_administrations', 'shifts'],
        );
    }

    public function test_guided_round_administration_locks_assignment_evidence_before_round(): void
    {
        [$scope, $lockedTables] = $this->captureLockQueries(fn (): MedicationScopeDecision => $this->scope()
            ->forAdministration(
                $this->worker,
                $this->client,
                $this->medication,
                now(),
                now(),
                null,
                $this->round,
                fn (MedicationScopeDecision $scope, ?array $payload): MedicationScopeDecision => $scope,
                scopedInputResolver: fn (): array => [
                    'scheduled_for' => now(),
                    'action_at' => now(),
                    'payload' => [],
                ],
            ));

        $this->assertSame($this->client->id, $scope->client->id);
        $this->assertSame($this->medication->id, $scope->medication?->id);
        $this->assertSame($this->round->id, $scope->round?->id);
        $this->assertMysqlLockOrder(
            $lockedTables,
            [
                'clients',
                'client_medications',
                'shifts', // Complete performer/witness presence union.
                'shifts', // Selected canonical performer assignment.
                'medication_rounds',
            ],
        );
    }

    public function test_medication_does_not_lock_site_after_the_client_and_medication_aggregate(): void
    {
        [$scope, $lockedTables] = $this->captureLockQueries(fn (): MedicationScopeDecision => $this->scope()
            ->forMedication(
                $this->worker,
                $this->medication,
                now(),
                fn (MedicationScopeDecision $scope): MedicationScopeDecision => $scope,
                requireAdministrable: true,
                submittedClientId: $this->client->id,
            ));

        $this->assertSame($this->client->id, $scope->client->id);
        $this->assertSame($this->medication->id, $scope->medication?->id);
        $this->assertMysqlLockOrder(
            $lockedTables,
            ['clients', 'client_medications', 'shifts'],
        );
        $this->assertNotContains('sites', $lockedTables);
    }

    public function test_prescription_locks_client_then_medication_then_prescription(): void
    {
        [$scope, $lockedTables] = $this->captureLockQueries(fn (): MedicationScopeDecision => $this->scope()
            ->forPrescription(
                $this->worker,
                $this->prescription,
                now(),
                fn (MedicationScopeDecision $scope): MedicationScopeDecision => $scope,
            ));

        $this->assertSame($this->client->id, $scope->client->id);
        $this->assertSame($this->medication->id, $scope->medication?->id);
        $this->assertSame($this->prescription->id, $scope->prescription?->id);
        $this->assertMysqlLockOrder(
            $lockedTables,
            ['clients', 'client_medications', 'medication_prescriber_orders', 'shifts'],
        );
    }

    public function test_round_locks_context_and_assignment_evidence_before_site_and_round(): void
    {
        [$scope, $lockedTables] = $this->captureLockQueries(fn (): MedicationScopeDecision => $this->scope()
            ->forRound(
                $this->worker,
                $this->round,
                now(),
                fn (MedicationScopeDecision $scope): MedicationScopeDecision => $scope,
                ['in_progress'],
            ));

        $this->assertSame($this->client->id, $scope->client->id);
        $this->assertSame($this->round->id, $scope->round?->id);
        $this->assertMysqlLockOrder($lockedTables, [
            'service_contexts',
            'clients',
            'shifts',
            'users',
            'hr_employee_profiles',
            'sites',
            'medication_rounds',
        ]);
    }

    public function test_relationship_and_round_assignment_mismatches_remain_concealed(): void
    {
        $otherClient = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'active',
        ]);
        $otherMedication = $this->createMedication($otherClient, true, 'Foreign PRN medication');
        $mismatchedAdministration = ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $otherMedication->id,
            'administered_by' => $this->worker->id,
            'administered_at' => now()->subHour(),
            'status' => 'given',
        ]);
        $mismatchedPrescription = MedicationPrescriberOrder::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $otherMedication->id,
            'order_type' => 'new',
            'status' => 'pending',
            'prescriber_name' => 'Dr Foreign Relationship',
            'medication_name' => $otherMedication->name,
            'order_date' => $this->workerToday(),
        ]);
        $otherWorker = User::factory()->create();
        $foreignRound = MedicationRound::query()->create([
            'service_context_id' => $this->serviceContext->id,
            'site_id' => $this->site->id,
            'name' => 'Another worker round',
            'scheduled_time' => '09:30',
            'window_minutes' => 60,
            'round_date' => $this->workerToday(),
            'status' => 'in_progress',
            'assigned_to' => $otherWorker->id,
            'started_by' => $otherWorker->id,
        ]);

        $this->assertNotFound(fn () => $this->scope()->forPrnEffectiveness(
            $this->worker,
            $mismatchedAdministration,
            now(),
            fn () => $this->fail('The mismatched administration callback must not run.'),
        ));
        $this->assertNotFound(fn () => $this->scope()->forMedication(
            $this->worker,
            $this->medication,
            now(),
            fn () => $this->fail('The mismatched Client callback must not run.'),
            submittedClientId: $otherClient->id,
        ));
        $this->assertNotFound(fn () => $this->scope()->forPrescription(
            $this->worker,
            $mismatchedPrescription,
            now(),
            fn () => $this->fail('The mismatched prescription callback must not run.'),
        ));
        $this->assertNotFound(fn () => $this->scope()->forRound(
            $this->worker,
            $foreignRound,
            now(),
            fn () => $this->fail('The foreign round callback must not run.'),
            ['in_progress'],
        ));
    }

    private function scope(): MedicationScopeDecisionService
    {
        return app(MedicationScopeDecisionService::class);
    }

    private function createMedication(Client $client, bool $isPrn, string $name): ClientMedication
    {
        return ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => $name,
            'dosage' => '1 tablet',
            'frequency' => $isPrn ? 'As required' : 'Once daily',
            'dose_times' => $isPrn ? [] : ['09:30'],
            'is_prn' => $isPrn,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'start_date' => $this->workerToday()->subMonth(),
        ]);
    }

    /** @return array{0: mixed, 1: array<int, string>} */
    private function captureLockQueries(Closure $callback): array
    {
        $lockedTables = [];
        $capturing = true;
        DB::listen(function (QueryExecuted $query) use (&$lockedTables, &$capturing): void {
            if (! $capturing || ! str_contains(strtolower($query->sql), 'for update')) {
                return;
            }

            if (preg_match('/\bfrom\s+[`"]?([a-z0-9_]+)/i', $query->sql, $matches) === 1) {
                $lockedTables[] = strtolower($matches[1]);
            }
        });

        try {
            $result = $callback();
        } finally {
            $capturing = false;
        }

        return [$result, $lockedTables];
    }

    /** @param array<int, string> $lockedTables @param array<int, string> $expected */
    private function assertMysqlLockOrder(array $lockedTables, array $expected): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->addToAssertionCount(1);

            return;
        }

        $relevantLocks = array_values(array_filter(
            $lockedTables,
            fn (string $table): bool => in_array($table, $expected, true),
        ));

        $this->assertSame($expected, $relevantLocks);
    }

    private function assertNotFound(Closure $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the medication scope to conceal the mismatched object.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    private function workerToday(): Carbon
    {
        return Carbon::today(config('app.worker_timezone', 'Pacific/Auckland'));
    }
}
