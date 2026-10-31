<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\PpeCommandReceipt;
use App\Models\PpeSourceVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PpeCommandReplayTest extends PpeCommandTestCase
{
    public function test_exact_retry_has_one_revision_receipt_and_audit(): void
    {
        $item = $this->item(); $input = $this->command($item, ['location' => 'Recorded rack']);
        $first = $this->service()->updateInventory($item, $this->manager, $input);
        $retry = $this->service()->updateInventory($item, $this->manager, $input);
        $this->assertSame($first, $retry);
        $this->assertSame(2, $item->fresh()->source_revision);
        $this->assertSame(1, PpeCommandReceipt::where('idempotency_key', $input['idempotency_key'])->count());
        $this->assertSame(1, AuditLog::where('action', 'ppe.update_inventory')->where('meta->idempotency_key', $input['idempotency_key'])->count());
    }
    public function test_retained_acceptance_recovers_after_unrelated_later_command_without_changing_draft_version(): void
    {
        $item = $this->item(); $input = $this->command($item, ['brand' => 'Recorded manufacturer']);
        $accepted = $this->service()->updateInventory($item, $this->manager, $input);
        $this->service()->inspect($item, $this->manager, $this->command($item, ['result' => 'pass']));
        $this->assertSame($accepted, $this->service()->confirmationForKey($this->manager, strtoupper($input['idempotency_key'])));
        $this->assertSame($accepted, $this->service()->updateInventory($item, $this->manager, $input));
        $this->assertSame(3, $item->fresh()->source_revision);
    }
    public function test_changed_payload_actor_and_cross_command_key_are_not_replayed(): void
    {
        $item = $this->item(); $input = $this->command($item, ['location' => 'Reviewed rack']);
        $this->service()->updateInventory($item, $this->manager, $input);
        $this->validation(fn () => $this->service()->updateInventory($item, $this->manager, [...$input, 'location' => 'Edited draft']), 'idempotency_key');
        $other = $this->staff(['hazards.view', 'hazards.manage']);
        $this->validation(fn () => $this->service()->updateInventory($item, $other, $input), 'idempotency_key');
        $this->validation(fn () => $this->service()->condemn($item, $this->manager, [...$input, 'reason' => 'Cross command']), 'command');
        $this->assertNull($this->service()->confirmationForKey($other, $input['idempotency_key']));
    }
    public function test_stale_new_intent_is_preserved_not_accepted(): void
    {
        $item = $this->item(); $stale = $this->command($item, ['location' => 'Stale proposal']);
        $this->service()->updateInventory($item, $this->manager, $this->command($item, ['model' => 'Accepted model']));
        $this->validation(fn () => $this->service()->updateInventory($item, $this->manager, $stale), 'expected_version');
        $this->assertNotSame('Stale proposal', $item->fresh()->location);
        $this->assertDatabaseMissing('ppe_command_receipts', ['idempotency_key' => $stale['idempotency_key']]);
    }
    public function test_warmed_permission_revocation_denies_retry_and_recovery_then_restore_recovers(): void
    {
        $item = $this->item(); $input = $this->command($item, ['location' => 'Exact intent']);
        $accepted = $this->service()->updateInventory($item, $this->manager, $input);
        $this->revoke($this->manager, 'hazards.manage');
        $this->denial(fn () => $this->service()->updateInventory($item, $this->manager, $input));
        $this->denial(fn () => $this->service()->confirmationForKey($this->manager, $input['idempotency_key']));
        $permission = \App\Models\Permission::where('key', 'hazards.manage')->sole();
        $this->manager->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        $this->assertSame($accepted, $this->service()->confirmationForKey($this->manager, $input['idempotency_key']));
    }
    public function test_site_or_current_staff_withdrawal_denies_retained_acceptance(): void
    {
        $item = $this->item(); $input = $this->command($item, ['location' => 'Site-bound intent']);
        $this->service()->updateInventory($item, $this->manager, $input);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => \App\Models\Site::factory()->create()->id]);
        $this->denial(fn () => $this->service()->confirmationForKey($this->manager, $input['idempotency_key']), 404);
    }
    public function test_own_ack_needs_no_hazards_grant_has_exact_actor_and_no_other_source_content(): void
    {
        $item = $this->item(); $allocation = $this->issue($item); $input = $this->command($item);
        $receipt = $this->service()->acknowledge($allocation, $this->worker, $input, true);
        $this->assertSame('acknowledge_own', $receipt['command']);
        $this->assertSame($this->worker->id, $allocation->fresh()->acknowledged_by);
        $this->assertSame($receipt, $this->service()->confirmationForKey($this->worker, $input['idempotency_key']));
        $this->assertCount(11, $receipt);
        $this->assertArrayNotHasKey('notes', $receipt);
        $other = $this->staff();
        $this->denial(fn () => $this->service()->acknowledge($allocation, $other, $this->command($item), true), 404);
    }
    public function test_ack_noop_preserves_first_attribution_and_does_not_newly_certify_legacy(): void
    {
        $item = \App\Models\PpeInventory::factory()->allocated()->create(['site_id' => $this->site->id]);
        $allocation = \App\Models\PpeAllocation::factory()->acknowledged()->create(['ppe_inventory_id' => $item->id,
            'user_id' => $this->worker->id, 'acknowledged_by' => $this->manager->id]);
        $at = $allocation->acknowledged_at->toIso8601String();
        $receipt = $this->service()->acknowledge($allocation, $this->worker, $this->command($item), true);
        $this->assertSame(0, $receipt['version']);
        $this->assertNull($receipt['source_version_id']);
        $this->assertSame($this->manager->id, $allocation->fresh()->acknowledged_by);
        $this->assertSame($at, $allocation->fresh()->acknowledged_at->toIso8601String());
        $this->assertSame(0, PpeSourceVersion::where('root_id', $item->id)->where('root_type', 'inventory')->count());
    }
    public function test_corrupt_audit_or_polluted_receipt_cannot_prove_success(): void
    {
        $item = $this->item(); $input = $this->command($item, ['location' => 'Real acceptance']);
        $this->service()->updateInventory($item, $this->manager, $input);
        $receipt = PpeCommandReceipt::where('idempotency_key', $input['idempotency_key'])->sole();
        $original = $receipt->accepted_result;
        DB::table('ppe_command_receipts')->where('id', $receipt->id)->update(['accepted_result' => json_encode([...$original, 'private_note' => 'Forged prose'])]);
        $this->integrity(fn () => $this->service()->confirmationForKey($this->manager, $input['idempotency_key']));
        DB::table('ppe_command_receipts')->where('id', $receipt->id)->update(['accepted_result' => json_encode($original)]);
        DB::table('audit_logs')->where('id', $receipt->audit_log_id)->update(['user_id' => $this->worker->id]);
        $this->integrity(fn () => $this->service()->updateInventory($item, $this->manager, $input));
    }
    public function test_unknown_key_is_not_an_acceptance_and_immutable_receipt_cannot_be_edited(): void
    {
        $this->assertNull($this->service()->confirmationForKey($this->manager, (string) Str::uuid()));
        $item = $this->item(); $receipt = PpeCommandReceipt::where('root_id', $item->id)->where('root_type', 'inventory')->sole();
        $this->integrity(fn () => $receipt->update(['command' => 'dispose']));
    }
    public function test_forged_changed_noop_and_create_revision_linkage_are_rejected_even_with_matching_audit_meta(): void
    {
        $item = $this->item(); $input = $this->command($item, ['location' => 'Actual changed fact']);
        $this->service()->updateInventory($item, $this->manager, $input);
        $row = PpeCommandReceipt::where('idempotency_key', $input['idempotency_key'])->sole();
        $original = $row->accepted_result;
        foreach ([['source_version_id' => null, 'version' => $original['version']],
            ['source_version_id' => null, 'version' => $original['expected_version'] - 1]] as $corruption) {
            $forged = [...$original, ...$corruption];
            DB::table('ppe_command_receipts')->where('id', $row->id)->update(['source_version_id' => $forged['source_version_id'], 'accepted_result' => json_encode($forged)]);
            DB::table('audit_logs')->where('id', $row->audit_log_id)->update(['meta' => json_encode([...$forged, 'fingerprint' => $row->fingerprint])]);
            $this->integrity(fn () => $this->service()->confirmationForKey($this->manager, $input['idempotency_key']));
        }
        $create = PpeCommandReceipt::where('root_type', 'inventory')->where('root_id', $item->id)->where('command', 'create_inventory')->sole();
        $forged = [...$create->accepted_result, 'expected_version' => 0];
        DB::table('ppe_command_receipts')->where('id', $create->id)->update(['expected_version' => 0, 'accepted_result' => json_encode($forged)]);
        DB::table('audit_logs')->where('id', $create->audit_log_id)->update(['meta' => json_encode([...$forged, 'fingerprint' => $create->fingerprint])]);
        $this->integrity(fn () => $this->service()->confirmationForKey($this->manager, $create->idempotency_key));
    }}
