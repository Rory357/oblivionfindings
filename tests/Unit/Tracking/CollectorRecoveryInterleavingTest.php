<?php

namespace Tests\Unit\Tracking;

use App\Domain\Monitoring\Exceptions\RuntimePayloadInvalid;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Management\Data\CommandSigningPayload;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Services\CollectorCommandContract;
use App\Domain\SecurityDevices\Management\Services\CollectorCommandRecoveryService;
use App\Domain\SecurityDevices\Management\Services\CollectorCommandResultService;
use App\Domain\SecurityDevices\Management\Services\CommandRequestSigner;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandContractVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CommittedDatabaseTestCase;
use Tests\Support\DeviceCommandAuditFixture;

class CollectorRecoveryInterleavingTest extends CommittedDatabaseTestCase
{
    private string $primary;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->primary = DB::getDefaultConnection();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertIsolatedTestConnection(DB::connection());
        config(['database.connections.recovery_interleaving' => [...DB::connection()->getConfig(), 'name' => 'recovery_interleaving']]);
        $other = DB::connection('recovery_interleaving');
        $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $this->assertSame(DB::connection()->getDatabaseName(), $other->getDatabaseName());
        $this->assertNotSame(DB::connection()->getPdo(), $other->getPdo());
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->primary);
        DB::connection('recovery_interleaving')->rollBack(0);
        DB::rollBack(0);
        DB::purge('recovery_interleaving');
        parent::tearDown();
    }

    private function fixture(): array
    {
        $request = DeviceCommandAuditFixture::request(CommandStatus::Accepted);
        $collector = MonitoringCollector::factory()->create(['site_id' => $request->site_id]);
        $at = CarbonImmutable::now('UTC')->startOfSecond();
        $signer = app(CommandRequestSigner::class);
        $signature = $signer->sign(new CommandSigningPayload(
            commandUuid: $request->command_uuid, deviceId: $request->device_id, siteId: $request->site_id,
            requestedByUserId: $request->requested_by_user_id, capability: 'access.door.unlock_timed', capabilityVersion: 1,
            managementLevel: 'control', risk: 'high', idempotencyKey: $request->idempotency_key,
            parametersHash: $signer->parametersHash([]), reasonHash: $signer->reasonHash($request->reason),
            expectedState: [], reconciliationRule: 'provider_acknowledged', expiresAt: $at->subSecond(),
            itChangeId: null, collectorId: $collector->id, isBreakGlass: false, provider: null,
        ));
        // Complete the signed fixture before starting either concurrent operation.
        DB::table('device_command_requests')->where('id', $request->id)->update([
            'collector_id' => $collector->id, 'capability' => 'access.door.unlock_timed',
            'expires_at' => $at->subSecond(), 'signature' => $signature['signature'],
        ]);
        $request = $request->fresh();
        $this->assertTrue(app(DeviceCommandContractVerifier::class)->verify($request));
        $attempt = DeviceCommandAttempt::query()->create([
            'device_command_request_id' => $request->id, 'attempt_number' => 1,
            'status' => CommandAttemptStatus::Accepted, 'runtime' => 'collector',
            'provider_request_reference' => 'collector:synthetic',
        ]);
        $payload = [
            'item_type' => 'command_result', 'command_uuid' => $request->command_uuid,
            'attempt_uuid' => $attempt->attempt_uuid, 'attempt_number' => 1, 'site_id' => $request->site_id,
            'device_id' => (string) $request->device_id, 'capability' => $request->capability,
            'contract_hash' => app(CollectorCommandContract::class)->hash($request, $attempt, $collector),
            'execution_status' => 'succeeded',
            'safe_result' => ['provider_state' => 'accepted', 'previous_lock_state' => 'locked', 'unlock_duration_seconds' => 15],
            'provider_request_reference' => 'unifi-access:'.$request->command_uuid, 'safe_failure_reason' => null,
            'accepted_at' => $at->subMinute()->toISOString(), 'started_at' => $at->subMinute()->toISOString(),
            'completed_at' => $at->subSeconds(2)->toISOString(),
            'reconciliation' => ['outcome' => 'matched', 'observed_state' => ['locked' => true],
                'observation_reference' => 'synthetic:locked',
                'safe_evidence_summary' => 'The remote Site collector freshly confirmed that the door relay returned to locked.',
                'observed_at' => $at->subSeconds(2)->toISOString()],
        ];

        return [$request, $attempt, $collector, $payload, $at];
    }

    public function test_result_winning_after_recovery_discovery_is_not_overwritten(): void
    {
        [$request, $attempt, $collector, $payload, $at] = $this->fixture();
        $interleave = true;
        DB::listen(function (QueryExecuted $query) use (&$interleave, $collector, $payload): void {
            if ($interleave && str_starts_with($query->sql, 'select `id` from `device_command_attempts`')) {
                $interleave = false;
                DB::setDefaultConnection('recovery_interleaving');
                try {
                    app(CollectorCommandResultService::class)->record($collector, 1, $payload);
                } finally {
                    DB::setDefaultConnection($this->primary);
                }
            }
        });
        $this->assertSame(['expired_before_delivery' => 0, 'uncertain_after_delivery' => 0], app(CollectorCommandRecoveryService::class)->recover($at));
        $this->assertFalse($interleave, 'The result committed after the actual discovery query.');
        $this->assertSame(CommandStatus::Reconciled, $request->fresh()->status);
        $this->assertSame(CommandAttemptStatus::Succeeded, $attempt->fresh()->status);
        $this->assertFalse($request->auditEvents()->where('action', 'collector_result_timeout_uncertain')->exists());
    }

    public function test_recovery_locks_parent_before_attempt_and_a_late_result_preserves_its_outcome(): void
    {
        [$request, $attempt, $collector, $payload, $at] = $this->fixture();
        $lockQueries = [];
        $capture = true;
        DB::listen(function (QueryExecuted $query) use (&$lockQueries, &$capture): void {
            if ($capture && str_contains($query->sql, 'for update')) {
                $lockQueries[] = $query->sql;
            }
        });
        DB::beginTransaction();
        $this->assertSame(['expired_before_delivery' => 0, 'uncertain_after_delivery' => 1], app(CollectorCommandRecoveryService::class)->recover($at));
        $capture = false;
        $this->assertStringContainsString('`device_command_requests`', $lockQueries[0]);
        $this->assertStringContainsString('`device_command_attempts`', $lockQueries[1]);
        DB::setDefaultConnection('recovery_interleaving');
        try {
            app(CollectorCommandResultService::class)->record($collector, 1, $payload);
            $this->fail('The result must wait while recovery owns the request.');
        } catch (QueryException $error) {
            $this->assertSame(1205, (int) $error->errorInfo[1]);
        }
        DB::setDefaultConnection($this->primary);
        DB::commit();
        DB::setDefaultConnection('recovery_interleaving');
        try {
            app(CollectorCommandResultService::class)->record($collector, 1, $payload);
            $this->fail('Late conflicting results must not replace the terminal outcome.');
        } catch (RuntimePayloadInvalid $error) {
            $this->assertStringContainsString('immutable evidence', $error->getMessage());
        }
        $this->assertSame(CommandStatus::Uncertain, $request->fresh()->status);
        $this->assertSame(CommandAttemptStatus::Uncertain, $attempt->fresh()->status);
        $this->assertSame(1, $request->auditEvents()->where('action', 'collector_result_timeout_uncertain')->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }
}
