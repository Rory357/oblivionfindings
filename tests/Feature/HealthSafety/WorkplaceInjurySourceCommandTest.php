<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\HsEvent;
use App\Models\WorkplaceInjury;
use App\Models\WorkplaceInjuryCommandReceipt;
use App\Models\WorkplaceInjurySourceVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class WorkplaceInjurySourceCommandTest extends WorkplaceInjurySourceTestCase
{
    public function test_capture_only_records_submitted_treatment_but_confirmation_never_grants_private_read(): void
    {
        $payload = $this->createPayload();
        $receipt = $this->source()->create($this->capturer, $payload);
        $injury = WorkplaceInjury::findOrFail($receipt['injury_id']);
        $this->assertSame('Private treatment fact', $injury->immediate_treatment);
        $this->assertSame('PRIVATE-CLAIM', $injury->acc_claim_number);
        $this->assertSame(1, $injury->source_revision);
        $this->assertTrue($receipt['accepted']);
        $this->assertStringNotContainsString('PRIVATE-CLAIM', json_encode($receipt));
        $this->assertStringNotContainsString('Private enduring', json_encode($receipt));
        $recovered = $this->source()->confirmationForKey($this->capturer, strtoupper($payload['idempotency_key']));
        $this->assertSame($receipt['receipt_id'], $recovered['receipt_id']);
        $this->assertTrue($recovered['replayed']);
        $this->denial(fn () => $this->access()->withCurrentViewer($this->capturer,
            fn ($current, $reads, $sites) => $this->access()->project($injury, $current, $reads, $sites)));
        $this->assertDatabaseHas('hs_events', ['source_id' => $injury->id, 'staff_id' => $this->worker->id, 'site_id' => $this->site->id]);
    }

    public function test_exact_replay_after_later_version_is_one_audit_and_one_original_acceptance(): void
    {
        $injury = $this->injury();
        $payload = $this->command($injury, ['description' => 'Corrected factual description']);
        $first = $this->source()->update($injury, $this->manager, $payload);
        $this->source()->assign($injury, $this->manager, $this->command($injury,
            ['coordinator_user_id' => $this->factual->id, 'next_review_date' => '2026-10-20', 'reason' => 'Actual coordination']));
        $replayed = $this->source()->update($injury, $this->manager, $payload);
        $this->assertSame($first['receipt_id'], $replayed['receipt_id']);
        $this->assertSame(2, $replayed['version']);
        $this->assertSame(3, $injury->fresh()->source_revision);
        $this->assertSame(1, AuditLog::where('action', 'health_safety.injury.update')->count());
    }

    public function test_stale_changed_payload_and_cross_actor_uuid_cannot_mutate_or_clear_draft(): void
    {
        $injury = $this->injury();
        $payload = $this->command($injury, ['description' => 'Accepted correction']);
        $this->source()->update($injury, $this->manager, $payload);
        $this->validation(fn () => $this->source()->update($injury, $this->manager,
            [...$payload, 'idempotency_key' => (string) Str::uuid(), 'description' => 'Stale draft']), 'expected_version');
        $this->validation(fn () => $this->source()->update($injury, $this->manager,
            [...$payload, 'description' => 'Changed payload same UUID']), 'idempotency_key');
        $this->validation(fn () => $this->source()->update($injury, $this->factual, $payload), 'idempotency_key');
        $this->assertSame('Accepted correction', $injury->fresh()->description);
        $this->assertSame(2, WorkplaceInjuryCommandReceipt::count());
    }

    public function test_loaded_actor_grant_withdrawal_denies_accepted_replay_and_capture_recovery(): void
    {
        $payload = $this->createPayload();
        $accepted = $this->source()->create($this->capturer, $payload);
        $this->revoke($this->capturer, 'hazards.create');
        $this->denial(fn () => $this->source()->create($this->capturer, $payload));
        $this->denial(fn () => $this->source()->confirmationForKey($this->capturer, $payload['idempotency_key']));
        $this->assertSame(1, WorkplaceInjury::whereKey($accepted['injury_id'])->count());
    }

    public function test_audit_veto_rolls_back_source_journey_history_and_receipt(): void
    {
        $before = [WorkplaceInjury::count(), HsEvent::count(), WorkplaceInjurySourceVersion::count(), WorkplaceInjuryCommandReceipt::count()];
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::setEventDispatcher(clone $dispatcher);
        AuditLog::creating(fn (AuditLog $audit) => $audit->action === 'health_safety.injury.create' ? false : null);
        try {
            $this->source()->create($this->manager, $this->createPayload());
            $this->fail('Missing audit must refuse acceptance.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('audit', $error->getMessage());
        } finally { AuditLog::setEventDispatcher($dispatcher); }
        $this->assertSame($before, [WorkplaceInjury::count(), HsEvent::count(), WorkplaceInjurySourceVersion::count(), WorkplaceInjuryCommandReceipt::count()]);
    }


    public function test_first_acceptance_audit_mutation_is_refused_atomically(): void
    {
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::setEventDispatcher(clone $dispatcher);
        AuditLog::creating(function (AuditLog $audit) {
            if ($audit->action === 'health_safety.injury.create') {
                $audit->meta = [...$audit->meta, 'fingerprint' => str_repeat('0', 64)];
            }
        });
        try {
            $this->source()->create($this->manager, $this->createPayload());
            $this->fail('Altered audit cannot produce first acceptance.');
        } catch (LogicException $error) { $this->assertStringContainsString('audit', $error->getMessage()); }
        finally { AuditLog::setEventDispatcher($dispatcher); }
        $this->assertSame(0, WorkplaceInjury::count());
        $this->assertSame(0, WorkplaceInjuryCommandReceipt::count());
        $this->assertSame(0, WorkplaceInjurySourceVersion::count());
    }

    public function test_missing_immutable_version_veto_cannot_report_partial_success(): void
    {
        $dispatcher = WorkplaceInjurySourceVersion::getEventDispatcher();
        WorkplaceInjurySourceVersion::setEventDispatcher(clone $dispatcher);
        WorkplaceInjurySourceVersion::creating(fn () => false);
        try {
            $this->source()->create($this->manager, $this->createPayload());
            $this->fail('A vetoed source fact cannot be accepted.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('immutable', $error->getMessage());
        } finally { WorkplaceInjurySourceVersion::setEventDispatcher($dispatcher); }
        $this->assertSame(0, WorkplaceInjury::count());
        $this->assertSame(0, WorkplaceInjuryCommandReceipt::count());
    }

    public function test_tampered_receipt_and_missing_audit_fail_closed_without_private_result_fallback(): void
    {
        $injury = $this->injury();
        $receipt = WorkplaceInjuryCommandReceipt::where('workplace_injury_id', $injury->id)->sole();
        $original = $receipt->accepted_result;
        DB::table('workplace_injury_command_receipts')->where('id', $receipt->id)
            ->update(['accepted_result' => json_encode([...$original, 'notes' => 'Forged private narrative'])]);
        try {
            $this->source()->confirmationForViewer($this->manager, $injury->id, $receipt->idempotency_key);
            $this->fail('Polluted acceptance must not be returned.');
        } catch (LogicException $error) { $this->assertStringContainsString('identity', $error->getMessage()); }
        DB::table('workplace_injury_command_receipts')->where('id', $receipt->id)->update(['accepted_result' => json_encode($original)]);
        DB::table('audit_logs')->where('id', $receipt->audit_log_id)->update(['action' => 'unrelated.command']);
        $this->expectException(LogicException::class);
        $this->source()->confirmationForViewer($this->manager, $injury->id, $receipt->idempotency_key);
    }

    public function test_forged_other_actor_key_and_unknown_key_return_no_confirmation(): void
    {
        $injury = $this->injury();
        $key = WorkplaceInjuryCommandReceipt::where('workplace_injury_id', $injury->id)->sole()->idempotency_key;
        $this->assertNull($this->source()->confirmationForKey($this->capturer, $key));
        $this->assertNull($this->source()->confirmationForViewer($this->manager, $injury->id, (string) Str::uuid()));
        $this->assertNull($this->source()->confirmationForKey($this->manager, 'not-a-uuid'));
    }

    public function test_lifecycle_records_actual_return_only_when_explicit_and_never_closes_other_domains(): void
    {
        $injury = $this->injury();
        foreach (['under_treatment', 'recovered', 'closed'] as $status) {
            $this->source()->transition($injury, $this->manager, $this->command($injury, ['status' => $status, 'reason' => 'Actual case lifecycle']));
            $this->assertNull($injury->fresh()->actual_return_date);
        }
        $this->assertSame('closed', $injury->fresh()->status);
        $this->validation(fn () => $this->source()->update($injury, $this->manager,
            $this->command($injury, ['actual_return_date' => '2026-10-11'])), 'status');
    }

    public function test_missing_current_aggregate_version_cannot_be_hidden_by_a_later_factual_write(): void
    {
        $injury = $this->injury();
        $this->source()->assign($injury, $this->manager, $this->command($injury,
            ['coordinator_user_id' => $this->manager->id, 'next_review_date' => '2026-10-20', 'reason' => 'Actual coordination']));
        DB::table('workplace_injury_source_versions')->where('workplace_injury_id', $injury->id)->where('aggregate_revision', 2)->delete();
        $this->assertSame(1, WorkplaceInjurySourceVersion::count());
        try {
            $this->source()->update($injury, $this->factual, $this->command($injury, ['description' => 'Cannot paper over missing history']));
            $this->fail('Missing aggregate proof must refuse a later source command.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('aggregate revision', $error->getMessage());
        }
        $this->assertSame(2, $injury->fresh()->source_revision);
        $this->assertSame('Recorded lifting injury.', $injury->fresh()->description);
        $this->assertSame(2, WorkplaceInjuryCommandReceipt::count());
    }

    public function test_factual_writer_cannot_adopt_governed_parent_as_an_unknown_legacy_baseline(): void
    {
        $injury = $this->injury();
        DB::table('workplace_injuries')->where('id', $injury->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        try {
            $this->source()->update($injury, $this->factual, $this->command($injury, ['description' => 'Cannot downgrade attributed source']));
            $this->fail('Retained governed history must not be silently adopted as a legacy baseline.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('downgraded', $error->getMessage());
        }
        $this->assertSame('Recorded lifting injury.', $injury->fresh()->description);
        $this->assertSame(1, WorkplaceInjuryCommandReceipt::count());
        $this->assertSame(1, WorkplaceInjurySourceVersion::count());
    }

    public function test_medical_writer_cannot_hide_ungoverned_parent_mutation_in_a_new_version(): void
    {
        $injury = $this->injury();
        DB::table('workplace_injuries')->where('id', $injury->id)->update(['notes' => 'Ungoverned private mutation']);
        try {
            $this->recovery()->recordCapacity($injury, $this->manager, $this->capacityPayload($injury));
            $this->fail('External parent mutation must be reconciled before a new private source command.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('immutable version', $error->getMessage());
        }
        $this->assertSame(1, $injury->fresh()->source_revision);
        $this->assertSame(0, \App\Models\WorkCapacityAssessment::count());
        $this->assertSame(1, WorkplaceInjuryCommandReceipt::count());
    }

    public function test_invalid_dates_unknown_commands_and_noop_facts_do_not_advance_revision(): void
    {
        $injury = $this->injury();
        $this->validation(fn () => $this->source()->update($injury, $this->manager,
            $this->command($injury, ['actual_return_date' => '2026-02-30'])), 'actual_return_date');
        $this->validation(fn () => $this->source()->update($injury, $this->manager,
            $this->command($injury, ['actual_return_date' => '2026-10-13'])), 'actual_return_date');
        $this->validation(fn () => $this->source()->update($injury, $this->manager,
            $this->command($injury, ['approval' => true])), 'command');
        $noop = $this->source()->update($injury, $this->manager, $this->command($injury, ['description' => $injury->description]));
        $this->assertFalse($noop['changed']);
        $this->assertSame(1, $noop['version']);
        $this->assertSame(1, WorkplaceInjurySourceVersion::count());
    }
    public function test_atomic_site_correction_accepts_explicit_destination_only_coordinator_and_preserves_private_fields(): void
    {
        $injury = $this->injury();
        $destination = \App\Models\Site::factory()->create();
        $this->approveSite($this->factual, $destination);
        $this->approveSite($this->worker, $destination);
        $owner = $this->staff(['hazards.view'], $destination);
        $this->source()->assign($injury, $this->manager, $this->command($injury,
            ['coordinator_user_id' => $this->manager->id, 'next_review_date' => '2026-10-20', 'reason' => 'Original owner']));
        $before = $injury->fresh();
        $history = $before->sourceVersions()->orderBy('id')->get()->map(fn ($version) => $version->getAttributes())->all();
        $private = $before->only(['immediate_treatment', 'medical_treatment_type', 'acc_claim_number', 'notes']);
        $payload = $this->command($injury, ['site_id' => $destination->id, 'coordinator_user_id' => $owner->id,
            'reason' => 'Correct actual occurrence Site and explicitly name destination coordinator', 'next_review_date' => '2026-10-24']);
        $accepted = $this->source()->update($injury, $this->factual, $payload);
        $after = $injury->fresh();
        $this->assertSame('update', $accepted['command']);
        $this->assertSame('injury', $accepted['source_type']);
        $this->assertSame(3, $accepted['version']);
        $this->assertSame($destination->id, (int) $after->site_id);
        $this->assertSame($owner->id, (int) $after->coordinator_user_id);
        $this->assertSame(['id' => $owner->id, 'name' => $owner->name], $after->coordinator_snapshot);
        $this->assertSame('2026-10-24', $after->next_review_date->toDateString());
        $this->assertSame($private, $after->only(array_keys($private)));
        $this->assertSame($history, $after->sourceVersions()->where('aggregate_revision', '<', 3)->orderBy('id')
            ->get()->map(fn ($version) => $version->getAttributes())->all());
        $version = WorkplaceInjurySourceVersion::findOrFail($accepted['source_version_id']);
        $this->assertSame('factual', $version->content['scope']);
        $this->assertSame($this->site->id, (int) $version->content['before']['site_id']);
        $this->assertSame($destination->id, (int) $version->content['after']['site_id']);
        $this->assertSame($this->manager->id, (int) $version->content['before']['coordinator_user_id']);
        $this->assertSame($owner->id, (int) $version->content['after']['coordinator_user_id']);
        $this->assertSame($payload['reason'], $version->content['command_facts']['reason']);
        $this->assertSame($this->factual->id, (int) $version->recorded_by);
        $this->assertSame(['id' => $owner->id, 'name' => $owner->name], $version->factual_content['coordinator']);
        $this->assertDatabaseHas('hs_events', ['source_id' => $injury->id, 'site_id' => $destination->id, 'staff_id' => $this->worker->id]);
        $this->assertStringNotContainsString('Private', json_encode($accepted));
        $this->source()->assign($injury, $this->factual, $this->command($injury,
            ['coordinator_user_id' => $this->factual->id, 'next_review_date' => '2026-10-25', 'reason' => 'Later explicit assignment']));
        $replay = $this->source()->update($injury, $this->factual, $payload);
        $this->assertSame($accepted['receipt_id'], $replay['receipt_id']);
        $this->assertSame(3, $replay['version']);
        $this->assertSame(4, $injury->fresh()->source_revision);
        $this->assertSame($this->factual->id, (int) $injury->fresh()->coordinator_user_id);
        $this->assertSame($accepted['receipt_id'],
            $this->source()->confirmationForViewer($this->factual, $injury->id, $payload['idempotency_key'])['receipt_id']);
        $this->assertSame(1, AuditLog::where('action', 'health_safety.injury.update')->count());
    }

    public function test_site_correction_without_transfer_requires_fresh_retained_owner_and_never_rewrites_identity(): void
    {
        $injury = $this->injury();
        $destination = \App\Models\Site::factory()->create();
        $this->approveSite($this->manager, $destination);
        $this->approveSite($this->worker, $destination);
        $this->source()->assign($injury, $this->manager, $this->command($injury,
            ['coordinator_user_id' => $this->factual->id, 'next_review_date' => '2026-10-20', 'reason' => 'Named owner']));
        $before = $injury->fresh()->getAttributes();
        $this->validation(fn () => $this->source()->update($injury, $this->manager,
            $this->command($injury, ['site_id' => $destination->id])), 'site_id');
        $this->assertSame($before, $injury->fresh()->getAttributes());
        $this->assertSame(2, WorkplaceInjuryCommandReceipt::count());
        $this->assertSame(2, WorkplaceInjurySourceVersion::count());
        $this->approveSite($this->factual, $destination);
        $receipt = $this->source()->update($injury, $this->manager, $this->command($injury, ['site_id' => $destination->id]));
        $after = $injury->fresh();
        $this->assertSame(3, $receipt['version']);
        $this->assertSame($before['coordinator_user_id'], $after->getRawOriginal('coordinator_user_id'));
        $this->assertSame($before['coordinator_snapshot'], $after->getRawOriginal('coordinator_snapshot'));
        $this->assertSame('2026-10-20', $after->next_review_date->toDateString());
    }

    public function test_transfer_tuple_is_complete_positive_real_date_and_only_for_a_genuine_site_correction(): void
    {
        $injury = $this->injury();
        foreach ([
            ['coordinator_user_id' => $this->factual->id],
            ['reason' => 'Incomplete transfer'],
            ['coordinator_user_id' => $this->factual->id, 'reason' => 'Missing review date'],
        ] as $facts) {
            $this->validation(fn () => $this->source()->update($injury, $this->manager, $this->command($injury, $facts)), 'coordinator_user_id');
        }
        $tuple = ['coordinator_user_id' => $this->factual->id, 'reason' => 'Explicit correction', 'next_review_date' => '2026-10-24'];
        $this->validation(fn () => $this->source()->update($injury, $this->manager,
            $this->command($injury, [...$tuple, 'coordinator_user_id' => null])), 'coordinator_user_id');
        $this->validation(fn () => $this->source()->update($injury, $this->manager,
            $this->command($injury, [...$tuple, 'next_review_date' => '2026-02-30'])), 'next_review_date');
        $this->validation(fn () => $this->source()->update($injury, $this->manager,
            $this->command($injury, [...$tuple, 'site_id' => $this->site->id])), 'site_id');
        $this->assertSame(1, $injury->fresh()->source_revision);
        $this->assertNull($injury->fresh()->coordinator_user_id);
        $this->assertSame(1, WorkplaceInjuryCommandReceipt::count());
    }

    public function test_atomic_site_correction_rejects_stale_draft_typed_children_and_changed_payload_replay(): void
    {
        $injury = $this->injury();
        $destination = \App\Models\Site::factory()->create();
        $this->approveSite($this->manager, $destination);
        $this->approveSite($this->worker, $destination);
        $owner = $this->staff(['hazards.view'], $destination);
        $payload = $this->command($injury, ['site_id' => $destination->id, 'coordinator_user_id' => $owner->id,
            'reason' => 'Explicit destination correction', 'next_review_date' => '2026-10-24']);
        $this->source()->update($injury, $this->manager, $this->command($injury, ['description' => 'New current fact']));
        $this->validation(fn () => $this->source()->update($injury, $this->manager, $payload), 'expected_version');
        $acceptedPayload = [...$payload, 'expected_version' => 2];
        $this->source()->update($injury, $this->manager, $acceptedPayload);
        $this->validation(fn () => $this->source()->update($injury, $this->manager,
            [...$acceptedPayload, 'reason' => 'Altered accepted reason']), 'idempotency_key');
        $this->assertSame(3, $injury->fresh()->source_revision);
        $bound = $this->injury();
        $capacity = $this->recovery()->recordCapacity($bound, $this->manager, $this->capacityPayload($bound));
        $this->validation(fn () => $this->source()->update($bound, $this->manager, $this->command($bound,
            array_diff_key($payload, array_flip(['expected_version', 'idempotency_key'])))), 'site_id');
        DB::table('work_capacity_assessments')->where('id', $capacity['source_id'])->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $this->validation(fn () => $this->source()->update($bound, $this->manager, $this->command($bound,
            array_diff_key($payload, array_flip(['expected_version', 'idempotency_key'])))), 'site_id');
        $this->assertSame($this->site->id, (int) $bound->fresh()->site_id);
        $this->assertNull($bound->fresh()->coordinator_user_id);
    }

    public function test_atomic_site_correction_audit_refusal_restores_owner_site_journey_and_history(): void
    {
        $injury = $this->injury();
        $destination = \App\Models\Site::factory()->create();
        $this->approveSite($this->manager, $destination);
        $this->approveSite($this->worker, $destination);
        $owner = $this->staff(['hazards.view'], $destination);
        $before = $injury->fresh()->getAttributes();
        $counts = [WorkplaceInjurySourceVersion::count(), WorkplaceInjuryCommandReceipt::count(), AuditLog::count()];
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::setEventDispatcher(clone $dispatcher);
        AuditLog::creating(fn (AuditLog $audit) => $audit->action === 'health_safety.injury.update' ? false : null);
        try {
            $this->source()->update($injury, $this->manager, $this->command($injury, ['site_id' => $destination->id,
                'coordinator_user_id' => $owner->id, 'reason' => 'Must be atomically retained', 'next_review_date' => '2026-10-24']));
            $this->fail('Audit refusal must not accept a Site or ownership correction.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('audit', $error->getMessage());
        } finally { AuditLog::setEventDispatcher($dispatcher); }
        $this->assertSame($before, $injury->fresh()->getAttributes());
        $this->assertSame($counts, [WorkplaceInjurySourceVersion::count(), WorkplaceInjuryCommandReceipt::count(), AuditLog::count()]);
        $this->assertDatabaseHas('hs_events', ['source_id' => $injury->id, 'site_id' => $this->site->id, 'staff_id' => $this->worker->id]);
    }

    public function test_atomic_site_correction_storage_failure_cannot_retain_partial_owner_or_acceptance(): void
    {
        $injury = $this->injury();
        $destination = \App\Models\Site::factory()->create();
        $this->approveSite($this->manager, $destination);
        $this->approveSite($this->worker, $destination);
        $owner = $this->staff(['hazards.view'], $destination);
        $before = $injury->fresh()->getAttributes();
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        $connection->setEventDispatcher(clone $dispatcher);
        $connection->listen(function ($event) {
            if (preg_match('/^update [`"]?workplace_injuries[`"]? set/i', $event->sql)) {
                throw new \RuntimeException('Refused injury correction storage.');
            }
        });
        try {
            $this->source()->update($injury, $this->manager, $this->command($injury, ['site_id' => $destination->id,
                'coordinator_user_id' => $owner->id, 'reason' => 'No partial save', 'next_review_date' => '2026-10-24']));
            $this->fail('Storage refusal must roll back the proposed correction.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Refused injury correction storage.', $error->getMessage());
        } finally { $connection->setEventDispatcher($dispatcher); }
        $this->assertSame($before, $injury->fresh()->getAttributes());
        $this->assertSame(1, WorkplaceInjurySourceVersion::count());
        $this->assertSame(1, WorkplaceInjuryCommandReceipt::count());
        $this->assertSame(0, AuditLog::where('action', 'health_safety.injury.update')->count());
    }
}
