<?php

namespace Tests\Feature\Privacy;

use App\Domain\Privacy\Retention\RetentionContractException;
use App\Domain\Privacy\Retention\RetentionExecutionService;
use App\Domain\Privacy\Retention\RetentionOwnerRegistry;
use App\Jobs\EnforceDataRetentionJob;
use App\Models\AnonymizationLog;
use App\Models\Client;
use App\Models\DataRetentionExecution;
use App\Models\DataRetentionExecutionItem;
use App\Models\DataRetentionPolicy;
use App\Models\LegalHold;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class EnforceDataRetentionJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_registry_is_closed_and_rejects_unknown_owner_and_condition_columns(): void
    {
        $registry = app(RetentionOwnerRegistry::class);

        $this->assertContains(Client::class, $registry->identifiers());
        $this->assertNotContains(User::class, $registry->identifiers());

        foreach ($registry->identifiers() as $identifier) {
            $nativePolicy = new DataRetentionPolicy([
                'model_type' => $identifier,
                'retention_conditions' => null,
            ]);
            $registry->resolve($identifier)->validateNativeContract($nativePolicy);
        }

        try {
            $registry->resolve(User::class);
            $this->fail('Unknown owner was accepted.');
        } catch (RetentionContractException $exception) {
            $this->assertSame('unknown_owner', $exception->reasonCode);
        }

        $policy = $this->policy([
            'retention_conditions' => ['email' => 'victim@example.com'],
        ]);

        $this->expectExceptionObject(new RetentionContractException(
            'unknown_condition',
            'The retention policy contains an unsupported condition.',
        ));
        $registry->resolve(Client::class)->validateNativeContract($policy);
    }

    public function test_preview_requires_an_independent_approver_and_policy_changes_invalidate_execution(): void
    {
        Carbon::setTestNow('2026-08-14 10:00:00');
        $service = app(RetentionExecutionService::class);
        $previewer = User::factory()->create();
        $approver = User::factory()->create();
        $client = $this->oldClient();
        $policy = $this->policy(['retention_period_years' => 1]);

        $snapshot = $service->preview($policy, $previewer);
        $this->assertSame(1, $snapshot['eligible_count']);
        $this->assertSame('previewed', $policy->fresh()->execution_state);

        try {
            $service->approve($policy->fresh(), $previewer);
            $this->fail('The previewer approved their own preview.');
        } catch (RetentionContractException $exception) {
            $this->assertSame('independent_approval_required', $exception->reasonCode);
        }

        $service->approve($policy->fresh(), $approver);
        $policy->refresh()->forceFill(['retention_period_years' => 2])->save();

        try {
            $service->execute($policy->fresh(), 'manual', $approver);
            $this->fail('A changed contract executed with stale approval.');
        } catch (RetentionContractException $exception) {
            $this->assertSame('approval_required', $exception->reasonCode);
        }

        $this->assertFalse(Client::withTrashed()->findOrFail($client->id)->trashed());
        $this->assertDatabaseHas('data_retention_executions', [
            'data_retention_policy_id' => $policy->id,
            'status' => 'blocked',
            'failure_code' => 'approval_required',
        ]);
    }

    public function test_manual_and_scheduled_runs_use_the_same_conditions_holds_and_exemptions(): void
    {
        Carbon::setTestNow('2026-08-14 10:00:00');
        $service = app(RetentionExecutionService::class);
        $previewer = User::factory()->create();
        $approver = User::factory()->create();
        $manualSite = Site::factory()->create();
        $scheduledSite = Site::factory()->create();
        $manualClient = $this->oldClient(['site_id' => $manualSite->id]);
        $scheduledClient = $this->oldClient(['site_id' => $scheduledSite->id]);
        $activeClient = $this->oldClient(['site_id' => $manualSite->id, 'status' => 'active']);
        $heldClient = $this->oldClient(['site_id' => $scheduledSite->id]);

        LegalHold::factory()->create([
            'holdable_type' => Client::class,
            'holdable_id' => $heldClient->id,
            'status' => 'active',
            'imposed_at' => now(),
        ]);

        $manualPolicy = $this->approvedPolicy($service, $previewer, $approver, [
            'policy_name' => 'Manual site client retention',
            'retention_period_years' => 1,
            'retention_conditions' => ['site_id' => $manualSite->id],
            'active_case_exemption' => true,
        ]);
        $scheduledPolicy = $this->approvedPolicy($service, $previewer, $approver, [
            'policy_name' => 'Scheduled site client retention',
            'retention_period_years' => 1,
            'retention_conditions' => ['site_id' => $scheduledSite->id],
            'active_case_exemption' => true,
        ]);

        $manual = $service->execute($manualPolicy, 'manual', $approver);
        app(EnforceDataRetentionJob::class)->handle($service);

        $this->assertSame(1, $manual['result']['soft_deleted']);
        $this->assertTrue(Client::withTrashed()->findOrFail($manualClient->id)->trashed());
        $this->assertTrue(Client::withTrashed()->findOrFail($scheduledClient->id)->trashed());
        $this->assertFalse(Client::withTrashed()->findOrFail($activeClient->id)->trashed());
        $this->assertFalse(Client::withTrashed()->findOrFail($heldClient->id)->trashed());

        $this->assertDatabaseHas('data_retention_executions', [
            'data_retention_policy_id' => $manualPolicy->id,
            'source' => 'manual',
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('data_retention_executions', [
            'data_retention_policy_id' => $scheduledPolicy->id,
            'source' => 'scheduled',
            'status' => 'completed',
        ]);
        $this->assertSame(2, DataRetentionExecutionItem::query()
            ->where('action', 'soft_delete')->count());
    }

    public function test_legal_holds_are_mandatory_even_when_a_legacy_policy_flag_is_false(): void
    {
        Carbon::setTestNow('2026-08-14 10:00:00');
        $service = app(RetentionExecutionService::class);
        $previewer = User::factory()->create();
        $approver = User::factory()->create();
        $client = $this->oldClient();
        LegalHold::factory()->create([
            'holdable_type' => Client::class,
            'holdable_id' => $client->id,
            'status' => 'active',
            'imposed_at' => now(),
        ]);
        $policy = $this->policy([
            'retention_period_years' => 1,
            'legal_hold_exemption' => false,
        ]);

        $snapshot = $service->preview($policy, $previewer);
        $this->assertSame(0, $snapshot['eligible_count']);
        $this->assertSame(1, $snapshot['exempt_count']);
        $service->approve($policy->fresh(), $approver);
        $outcome = $service->execute($policy->fresh(), 'manual', $approver);

        $this->assertSame(0, $outcome['result']['soft_deleted']);
        $this->assertFalse(Client::withTrashed()->findOrFail($client->id)->trashed());
    }

    public function test_global_legal_hold_blocks_preview_approval_and_execution(): void
    {
        Carbon::setTestNow('2026-08-14 10:00:00');
        $service = app(RetentionExecutionService::class);
        $previewer = User::factory()->create();
        $approver = User::factory()->create();
        $this->oldClient();
        $policy = $this->policy(['retention_period_years' => 1]);
        LegalHold::factory()->create([
            'holdable_type' => null,
            'holdable_id' => null,
            'status' => 'active',
            'imposed_at' => now(),
        ]);

        $snapshot = $service->preview($policy, $previewer);
        $this->assertTrue($snapshot['blocked']);

        try {
            $service->approve($policy->fresh(), $approver);
            $this->fail('A globally blocked preview was approved.');
        } catch (RetentionContractException $exception) {
            $this->assertSame('preview_blocked', $exception->reasonCode);
        }
    }

    public function test_execution_is_idempotent_and_concurrent_retry_safe(): void
    {
        Carbon::setTestNow('2026-08-14 10:00:00');
        $service = app(RetentionExecutionService::class);
        $previewer = User::factory()->create();
        $approver = User::factory()->create();
        $this->oldClient();
        $policy = $this->approvedPolicy($service, $previewer, $approver, [
            'retention_period_years' => 1,
        ]);

        $first = $service->execute($policy, 'manual', $approver);
        $retry = $service->execute($policy->fresh(), 'scheduled');

        $this->assertSame('completed', $first['status']);
        $this->assertSame('already_completed', $retry['status']);
        $this->assertSame(1, DataRetentionExecution::query()->count());
        $this->assertSame(1, DataRetentionExecutionItem::query()->count());
        $this->assertSame(1, AnonymizationLog::query()->count());

        Carbon::setTestNow('2026-08-15 10:00:00');
        $nextDay = $service->execute($policy->fresh(), 'scheduled');
        $this->assertSame('completed', $nextDay['status']);
        $this->assertSame(0, $nextDay['result']['soft_deleted']);
        $this->assertSame(2, DataRetentionExecution::query()->count());
        $this->assertSame(1, DataRetentionExecutionItem::query()->count());

        Carbon::setTestNow('2026-08-16 10:00:00');
        $fingerprint = $service->fingerprint($policy->fresh());
        $runningKey = hash('sha256', implode('|', [
            'data-retention-v1',
            $policy->id,
            $fingerprint,
            now('UTC')->toDateString(),
        ]));
        DataRetentionExecution::query()->create([
            'data_retention_policy_id' => $policy->id,
            'source' => 'scheduled',
            'idempotency_key' => $runningKey,
            'contract_fingerprint' => $fingerprint,
            'status' => 'running',
            'previewed_by_user_id' => $policy->previewed_by_user_id,
            'approved_by_user_id' => $policy->approved_by_user_id,
            'preview_snapshot' => $policy->preview_snapshot,
            'started_at' => now(),
        ]);
        $concurrent = $service->execute($policy->fresh(), 'manual', $approver);
        $this->assertSame('already_running', $concurrent['status']);
        $this->assertSame(3, DataRetentionExecution::query()->count());
    }

    public function test_entire_execution_and_evidence_roll_back_together_when_a_later_record_fails(): void
    {
        Carbon::setTestNow('2026-08-14 10:00:00');
        $service = app(RetentionExecutionService::class);
        $previewer = User::factory()->create();
        $approver = User::factory()->create();
        $firstClient = $this->oldClient();
        $secondClient = $this->oldClient();
        $policy = $this->approvedPolicy($service, $previewer, $approver, [
            'retention_period_years' => 1,
        ]);
        $itemAttempts = 0;
        DataRetentionExecutionItem::creating(function () use (&$itemAttempts): void {
            $itemAttempts++;

            if ($itemAttempts === 2) {
                throw new RuntimeException('Simulated evidence persistence failure.');
            }
        });

        try {
            $service->execute($policy, 'manual', $approver);
            $this->fail('The simulated failure did not propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated evidence persistence failure.', $exception->getMessage());
        } finally {
            DataRetentionExecutionItem::flushEventListeners();
        }

        $this->assertSame(2, $itemAttempts);
        $this->assertFalse(Client::withTrashed()->findOrFail($firstClient->id)->trashed());
        $this->assertFalse(Client::withTrashed()->findOrFail($secondClient->id)->trashed());
        $this->assertSame(0, DataRetentionExecutionItem::query()->count());
        $this->assertSame(0, AnonymizationLog::query()->count());
        $this->assertDatabaseHas('data_retention_executions', [
            'data_retention_policy_id' => $policy->id,
            'status' => 'failed',
            'failure_code' => 'runtime_exception',
        ]);
    }

    public function test_anonymization_and_archival_are_governed_and_audited(): void
    {
        Carbon::setTestNow('2026-08-14 10:00:00');
        $service = app(RetentionExecutionService::class);
        $previewer = User::factory()->create();
        $approver = User::factory()->create();
        $anonymized = $this->oldClient([
            'first_name' => 'Private',
            'last_name' => 'Person',
            'date_of_birth' => '1980-01-01',
            'status' => 'anonymize-me',
        ]);
        $archived = $this->oldClient(['status' => 'archive-me']);
        $anonymizePolicy = $this->approvedPolicy($service, $previewer, $approver, [
            'policy_name' => 'Anonymize old client',
            'hard_delete_after_years' => 1,
            'retention_conditions' => ['status' => 'anonymize-me'],
        ]);
        $archivePolicy = $this->approvedPolicy($service, $previewer, $approver, [
            'policy_name' => 'Archive old client',
            'archive_after_years' => 1,
            'retention_conditions' => ['status' => 'archive-me'],
        ]);

        $service->execute($anonymizePolicy, 'manual', $approver);
        $service->execute($archivePolicy, 'manual', $approver);

        $anonymized->refresh();
        $this->assertSame('[REDACTED]', $anonymized->first_name);
        $this->assertSame('[REDACTED]', $anonymized->last_name);
        $this->assertNull($anonymized->date_of_birth);
        $this->assertTrue(Client::withTrashed()->findOrFail($archived->id)->trashed());
        $this->assertDatabaseHas('data_retention_execution_items', ['action' => 'anonymize']);
        $this->assertDatabaseHas('data_retention_execution_items', ['action' => 'archive']);
    }

    private function oldClient(array $attributes = []): Client
    {
        $client = Client::factory()->create(array_merge(['status' => 'inactive'], $attributes));
        $client->forceFill([
            'created_at' => now()->subYears(2),
            'updated_at' => now()->subYears(2),
        ])->save();

        return $client;
    }

    private function policy(array $attributes = []): DataRetentionPolicy
    {
        return DataRetentionPolicy::factory()->create(array_merge([
            'model_type' => Client::class,
            'policy_name' => 'Client retention',
            'retention_period_years' => null,
            'archive_after_years' => null,
            'hard_delete_after_years' => null,
            'retention_conditions' => null,
            'applies_to_soft_deleted' => true,
            'legal_hold_exemption' => true,
            'active_case_exemption' => false,
            'active' => true,
            'execution_state' => 'draft',
        ], $attributes));
    }

    private function approvedPolicy(
        RetentionExecutionService $service,
        User $previewer,
        User $approver,
        array $attributes,
    ): DataRetentionPolicy {
        $policy = $this->policy($attributes);
        $service->preview($policy, $previewer);
        $service->approve($policy->fresh(), $approver);

        return $policy->fresh();
    }
}
