<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\RestraintCommandReceipt;
use App\Models\RestraintEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class RestraintCommandLifecycleTest extends RestraintEventCommandTestCase
{
    public function test_capture_has_minimal_receipt_exact_replay_and_unknown_plan_does_not_become_approved(): void
    {
        $input = $this->capture();
        $first = $this->service()->create($this->actor, $input);
        $replay = $this->service()->create($this->actor, $input);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($first['event_id'], $replay['event_id']);
        $this->assertArrayNotHasKey('restraint_description', $first);
        $row = RestraintEvent::findOrFail($first['event_id']);
        $this->assertNotSame('verified', $row->plan_authorization_state);
        $this->assertTrue($row->within_support_plan);
        $this->assertNull($row->behaviour_support_plan_version_id);
        $this->assertDatabaseCount('restraint_events', 1);
        $this->assertDatabaseCount('restraint_command_receipts', 1);
        $this->assertSame(60, $row->duration_minutes);
    }

    public function test_exact_retry_rejects_corrupt_acceptance_or_audit_without_new_source_receipt_or_audit(): void
    {
        $input = $this->capture();
        $accepted = $this->service()->create($this->actor, $input);
        $receipt = RestraintCommandReceipt::where('idempotency_key', $input['idempotency_key'])->sole();
        $audit = AuditLog::findOrFail($receipt->audit_log_id);
        $auditCount = AuditLog::count();
        DB::table('restraint_command_receipts')->where('id', $receipt->id)->update(['accepted_result' => json_encode([...$receipt->accepted_result, 'accepted' => false])]);
        $this->invalid(fn () => $this->service()->create($this->actor, $input), 'idempotency_key');
        DB::table('restraint_command_receipts')->where('id', $receipt->id)->update(['accepted_result' => json_encode($receipt->accepted_result)]);
        $audit->updateQuietly(['action' => 'restraints.event.review']);
        $this->invalid(fn () => $this->service()->create($this->actor, $input), 'idempotency_key');
        $this->assertDatabaseCount('restraint_events', 1);
        $this->assertDatabaseCount('restraint_command_receipts', 1);
        $this->assertSame($auditCount, AuditLog::count());
        $this->assertSame(1, RestraintEvent::findOrFail($accepted['event_id'])->lock_version);
    }

    public function test_exact_command_retry_uses_retained_audit_and_minimal_whitelist_after_later_source_version(): void
    {
        $row = $this->event();
        $input = $this->command($row, ['person_response' => 'Actual correction', 'reason' => 'Actual later evidence']);
        $accepted = $this->service()->correct($row, $this->actor, $input);
        $receipt = RestraintCommandReceipt::where('idempotency_key', $input['idempotency_key'])->sole();
        $this->service()->correct($row, $this->actor, $this->command($row, ['post_incident_support' => 'Later actual support', 'reason' => 'More factual evidence']));
        DB::table('restraint_command_receipts')->where('id', $receipt->id)->update(['accepted_result' => json_encode([...$receipt->accepted_result, 'actor_snapshot' => ['name' => 'Private copied actor'], 'restraint_description' => 'Injected source narrative'])]);
        $auditCount = AuditLog::count();
        $replayed = $this->service()->correct($row, $this->actor, $input);
        $this->assertTrue($replayed['replayed']);
        $this->assertSame($accepted['version'], $replayed['version']);
        $this->assertSame($accepted['source_revision'], $replayed['source_revision']);
        $this->assertSame(3, $row->fresh()->lock_version);
        $this->assertArrayNotHasKey('actor_snapshot', $replayed);
        $this->assertArrayNotHasKey('restraint_description', $replayed);
        $this->assertSame($auditCount, AuditLog::count());
        $this->assertDatabaseCount('restraint_command_receipts', 3);
    }

    public function test_global_uuid_cannot_be_reused_for_actor_payload_verb_or_plan(): void
    {
        $input = $this->capture();
        $this->service()->create($this->actor, $input);
        $this->invalid(fn () => $this->service()->create($this->actor, [...$input, 'severity' => 'critical']), 'idempotency_key');
        $other = $this->staff(['restraints.create', 'clients.viewAny']);
        $this->invalid(fn () => $this->service()->create($other, $input), 'idempotency_key');
        $row = RestraintEvent::firstOrFail();
        $this->invalid(fn () => $this->service()->correct($row, $this->actor, $this->command($row, ['idempotency_key' => $input['idempotency_key'], 'reason' => 'Correction'])), 'idempotency_key');
        RestraintCommandReceipt::query()->insert(['source_type' => 'plan', 'source_id' => 999, 'user_id' => $this->actor->id, 'audit_log_id' => RestraintCommandReceipt::first()->audit_log_id, 'idempotency_key' => $key = (string) Str::uuid(), 'command' => 'create', 'fingerprint' => str_repeat('a', 64), 'accepted_result' => '{}', 'created_at' => now()]);
        $this->invalid(fn () => $this->service()->create($this->actor, $this->capture(['idempotency_key' => $key])), 'idempotency_key');
    }

    public function test_correction_requires_version_and_keeps_history_tuple_and_review_boundary(): void
    {
        $row = $this->event();
        $input = $this->command($row, ['reason' => 'Later factual discovery', 'injury_occurred' => true, 'injury_details' => 'Actual injury']);
        $accepted = $this->service()->correct($row, $this->actor, $input);
        $this->assertSame(2, $accepted['version']);
        $this->assertSame(2, $row->fresh()->source_revision);
        $this->assertTrue($this->service()->correct($row, $this->actor, $input)['replayed']);
        $this->invalid(fn () => $this->service()->correct($row, $this->actor, [...$input, 'idempotency_key' => (string) Str::uuid()]), 'expected_version');
        $this->invalid(fn () => $this->service()->correct($row, $this->actor, $this->command($row, ['client_id' => $row->client_id + 1000, 'reason' => 'Not a transfer'])), 'client_id');
        $this->assertSame($this->client->id, $row->fresh()->recorded_client_id);
        $this->assertTrue($row->fresh()->needsReview());
    }

    public function test_audit_or_suppressed_receipt_rolls_back_all_source_and_delivery_rows(): void
    {
        RestraintCommandReceipt::creating(fn () => false);
        try {
            $this->service()->create($this->actor, $this->capture());
            $this->fail('Suppressed acceptance must fail.');
        } catch (LogicException) {
            $this->assertDatabaseCount('restraint_events', 0);
            $this->assertDatabaseCount('restraint_source_deliveries', 0);
        } finally {
            RestraintCommandReceipt::flushEventListeners();
            RestraintCommandReceipt::clearBootedModels();
        }
        AuditLog::creating(fn () => false);
        try {
            $this->service()->create($this->actor, $this->capture());
            $this->fail('Suppressed semantic audit must fail.');
        } catch (LogicException) {
            $this->assertDatabaseCount('restraint_events', 0);
            $this->assertDatabaseCount('restraint_command_receipts', 0);
        } finally {
            AuditLog::flushEventListeners();
            AuditLog::clearBootedModels();
        }
    }

    public function test_offset_dst_overnight_duration_and_actual_date_validation(): void
    {
        $row = $this->event(['started_at' => '2026-09-27T01:30:00+12:00', 'ended_at' => '2026-09-27T03:30:00+13:00', 'duration_minutes' => 60]);
        $this->assertSame(60, $row->duration_minutes);
        $this->invalid(fn () => $this->service()->create($this->actor, $this->capture(['started_at' => '2026-02-30T12:00:00+13:00'])), 'started_at');
        $this->invalid(fn () => $this->service()->create($this->actor, $this->capture(['started_at' => '2026-09-27T01:30:00'])), 'started_at');
        $this->invalid(fn () => $this->service()->create($this->actor, $this->capture(['ended_at' => now()->addHour()->toIso8601String()])), 'ended_at');
        $this->invalid(fn () => $this->service()->create($this->actor, $this->capture(['duration_minutes' => 1])), 'duration_minutes');
        $ongoing = $this->event(['ended_at' => null]);
        $this->assertNull($ongoing->duration_minutes);
    }

    public function test_strict_envelope_rejects_boolean_id_and_unknown_state_fields(): void
    {
        $this->invalid(fn () => $this->service()->create($this->actor, $this->capture(['client_id' => true])), 'client_id');
        $this->invalid(fn () => $this->service()->create($this->actor, $this->capture(['reviewed_at' => now()->toIso8601String()])), 'command');
        $this->invalid(fn () => $this->service()->create($this->actor, $this->capture(['idempotency_key' => 'urn:uuid:'.Str::uuid()])), 'idempotency_key');
    }

    public function test_redirect_confirmation_reconstructs_only_exact_actor_receipt_and_rechecks_current_source_authority(): void
    {
        $input = $this->capture();
        $accepted = $this->service()->create($this->actor, $input);
        $forgedFlash = [...$accepted, 'version' => 999, 'restraint_description' => 'Forged private narrative', 'accepted' => false];
        $confirmation = $this->service()->confirmationForViewer($this->actor, $forgedFlash);
        $this->assertTrue($confirmation['accepted']);
        $this->assertSame(1, $confirmation['version']);
        $this->assertArrayNotHasKey('restraint_description', $confirmation);
        $this->assertNull($this->service()->confirmationForViewer($this->actor, [...$accepted, 'idempotency_key' => 'urn:uuid:'.$input['idempotency_key']]));
        $other = $this->staff(['restraints.create', 'clients.viewAny']);
        $this->assertNull($this->service()->confirmationForViewer($other, $accepted));
        $this->actor->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->revoke($this->actor, 'restraints.create');
        $this->revoke($this->actor, 'restraints.manage');
        $this->assertNull($this->service()->confirmationForViewer($this->actor, $accepted));
        $this->assertSame(1, RestraintCommandReceipt::where('source_type', 'event')->count());
    }
}
