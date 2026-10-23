<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\BehaviourSupportPlanVersion;
use App\Models\Permission;
use App\Models\RestraintCommandReceipt;
use App\Services\HealthSafety\RestraintDates;
use Illuminate\Support\Str;

final class BehaviourSupportPlanReplayTest extends BehaviourSupportPlanTestCase
{
    public function test_exact_create_replay_retains_original_candidate_and_parent_receipt(): void
    {
        $payload = ['client_id' => $this->client->id, 'title' => 'Original draft', 'idempotency_key' => (string) Str::uuid()];
        $first = $this->plans()->create($this->planCapturer, $payload);
        $again = $this->plans()->create($this->planCapturer, $payload);
        $this->assertTrue($again['replayed']);
        $this->assertSame($first['receipt_id'], $again['receipt_id']);
        $this->assertSame(1, BehaviourSupportPlanVersion::count());
        $this->assertSame(1, AuditLog::where('action', 'behaviour_support_plan.create')->count());
    }

    public function test_actor_verb_source_and_typed_payload_are_bound_to_one_global_uuid(): void
    {
        $plan = $this->makePlan();
        $payload = $this->command($plan, ['notes' => 'Original factual revision']);
        $accepted = $this->plans()->update($plan, $this->planManager, $payload);
        $other = $this->staff(['restraints.manage', 'clients.viewAny']);
        $this->validation(fn () => $this->plans()->update($plan, $other, $payload), 'idempotency_key');
        $this->validation(fn () => $this->plans()->update($plan, $this->planManager, array_replace($payload, ['notes' => 'Different'])), 'idempotency_key');
        $this->validation(fn () => $this->plans()->archive($plan, $this->planManager, ['expected_version' => $payload['expected_version'], 'idempotency_key' => $payload['idempotency_key']]), 'idempotency_key');
        $foreign = $this->makePlan();
        $this->validation(fn () => $this->plans()->update($foreign, $this->planManager, $payload), 'idempotency_key');
        $this->assertSame($accepted['version'], $plan->fresh()->lock_version);
    }

    public function test_event_ledger_key_cannot_be_reused_for_plan_creation_or_mutation(): void
    {
        $plan = $this->makePlan();
        $key = (string) Str::uuid();
        RestraintCommandReceipt::create(['source_type' => 'event', 'source_id' => 999, 'user_id' => $this->planManager->id, 'command' => 'review', 'idempotency_key' => $key, 'fingerprint' => str_repeat('a', 64), 'audit_log_id' => null, 'accepted_result' => ['accepted' => true], 'created_at' => now()]);
        $this->validation(fn () => $this->plans()->update($plan, $this->planManager, $this->command($plan, ['notes' => 'Collision'], $key)), 'idempotency_key');
        $this->validation(fn () => $this->plans()->create($this->planManager, ['client_id' => $this->client->id, 'title' => 'Collision', 'idempotency_key' => $key]), 'idempotency_key');
        $this->assertSame(1, $plan->fresh()->lock_version);
    }

    public function test_fresh_revocation_denies_accepted_replay_even_with_loaded_permission_relations(): void
    {
        $plan = $this->makePlan();
        $payload = $this->approvalPayload($plan);
        $this->approvals()->decide($plan, $this->planSpecialist, $payload);
        $this->planSpecialist->load('roles.permissions', 'permissionOverrides');
        $permission = Permission::where('key', 'restraints.approve')->sole();
        $this->planSpecialist->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        $this->denied(fn () => $this->approvals()->decide($plan, $this->planSpecialist, $payload));
    }

    public function test_actual_instants_reject_naive_rollover_future_and_preserve_microseconds_offsets(): void
    {
        foreach (['2026-10-10 12:00:00', '2026-02-30T12:00:00Z', '2026-10-10T24:00:00Z', '2026-10-10T12:00:00+14:30', '2030-01-01T00:00:00Z'] as $invalid) {
            $this->validation(fn () => RestraintDates::instant($invalid, 'approved_at'), 'approved_at');
        }
        $instant = RestraintDates::instant('2026-10-10T13:00:00.123456+13:00', 'approved_at');
        $this->assertSame('2026-10-10T00:00:00.123456+00:00', $instant->format('Y-m-d\TH:i:s.uP'));
        $this->validation(fn () => RestraintDates::day('2026-02-30', 'review_date'), 'review_date');
        $this->assertSame('2026-09-27', RestraintDates::day('2026-09-27', 'review_date'));
    }

    public function test_normalized_review_instant_remains_stable_for_exact_replay(): void
    {
        $plan = $this->makePlan();
        $payload = $this->command($plan, ['outcome' => 'continued', 'reviewed_at' => '2026-10-10T13:00:00.123456+13:00']);
        $accepted = $this->plans()->review($plan, $this->planReviewer, $payload);
        $this->travel(1)->hours();
        $again = $this->plans()->review($plan, $this->planReviewer, $payload);
        $this->assertTrue($again['replayed']);
        $this->assertSame($accepted['receipt_id'], $again['receipt_id']);
        $changed = array_replace($payload, ['reviewed_at' => '2026-10-10T13:00:00.654321+13:00']);
        $this->validation(fn () => $this->plans()->review($plan, $this->planReviewer, $changed), 'idempotency_key');
    }

    public function test_reordered_object_keys_replay_the_same_typed_command_without_another_candidate(): void
    {
        $plan = $this->makePlan();
        $input = $this->command($plan, ['title' => 'Changed title', 'notes' => 'Retained exact bytes']);
        $accepted = $this->plans()->update($plan, $this->planManager, $input);
        $again = $this->plans()->update($plan, $this->planManager, array_reverse($input, true));
        $this->assertTrue($again['replayed']);
        $this->assertSame($accepted['receipt_id'], $again['receipt_id']);
        $this->assertSame(2, BehaviourSupportPlanVersion::where('behaviour_support_plan_id', $plan->id)->count());
    }

    public function test_boolean_identifiers_and_untyped_evidence_lists_cannot_become_accepted_commands(): void
    {
        $this->validation(fn () => $this->plans()->create($this->planCapturer, ['client_id' => true, 'title' => 'Invalid tuple', 'idempotency_key' => (string) Str::uuid()]), 'client_id');
        $plan = $this->makePlan();
        $this->validation(fn () => $this->approvals()->decide($plan, $this->planSpecialist, $this->approvalPayload($plan, ['approver_id' => true])), 'approver_id');
        $this->validation(fn () => $this->approvals()->decide($plan, $this->planSpecialist, $this->approvalPayload($plan, ['evidence_references' => ['arbitrary' => ['type' => 'client_document', 'id' => 1]]])), 'evidence_references');
        $this->assertSame(1, $plan->fresh()->lock_version);
        $this->assertSame(1, RestraintCommandReceipt::where('source_type', 'plan')->count());
    }
}
