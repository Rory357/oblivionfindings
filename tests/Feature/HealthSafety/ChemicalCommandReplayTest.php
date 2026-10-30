<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\ChemicalCommandIntent;
use App\Models\ChemicalCommandReceipt;
use App\Models\ChemicalSourceVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ChemicalCommandReplayTest extends ChemicalCommandTestCase
{
    public function test_exact_create_retry_returns_identical_acceptance_without_second_source_or_audit(): void
    {
        $input = ['idempotency_key' => (string) Str::uuid(), 'name' => 'Exact created record', 'physical_form' => 'powder'];
        $first = $this->service()->create($this->manager, $input);
        $this->assertSame($first, $this->service()->create($this->manager, $input));
        $this->assertSame(1, ChemicalCommandIntent::where('idempotency_key', $input['idempotency_key'])->count());
        $this->assertSame(1, AuditLog::where('meta->idempotency_key', $input['idempotency_key'])->count());
    }

    public function test_retry_after_later_accepted_revision_recovers_original_receipt_not_latest_facts(): void
    {
        $root = $this->catalogue(); $input = $this->command($root, ['common_name' => 'First accepted name']);
        $first = $this->service()->update($root, $this->manager, $input);
        $this->service()->update($root, $this->manager, $this->command($root, ['common_name' => 'Later accepted name']));
        $this->assertSame($first, $this->service()->update($root, $this->manager, $input));
        $this->assertSame(2, $first['version']); $this->assertSame(3, $root->fresh()->source_revision);
        $this->assertSame('Later accepted name', $root->fresh()->common_name);
    }

    public function test_uuid_cannot_change_actor_verb_source_payload_or_reviewed_revision(): void
    {
        $root = $this->catalogue(); $other = $this->catalogue(); $input = $this->command($root, ['common_name' => 'Bound fact']);
        $this->service()->update($root, $this->manager, $input);
        $this->validation(fn () => $this->service()->update($root, $this->staff(['hazards.manage']), $input), 'idempotency_key');
        $this->validation(fn () => $this->service()->update($other, $this->manager, $input), 'idempotency_key');
        $this->validation(fn () => $this->service()->update($root, $this->manager, [...$input, 'common_name' => 'Changed fact']), 'idempotency_key');
        $this->validation(fn () => $this->service()->update($root, $this->manager, [...$input, 'expected_version' => 2]), 'idempotency_key');
        $this->validation(fn () => $this->service()->status($root, $this->manager,
            ['idempotency_key' => $input['idempotency_key'], 'expected_version' => 1, 'status' => 'inactive', 'reason' => 'Another verb']), 'idempotency_key');
    }

    public function test_global_uuid_cannot_move_between_catalogue_and_storage_or_parent_revisions(): void
    {
        $parent = $this->catalogue(); $input = $this->command($parent, ['common_name' => 'Bound root']);
        $this->service()->update($parent, $this->manager, $input);
        $this->validation(fn () => $this->service()->createStorage($parent, $this->manager,
            ['idempotency_key' => $input['idempotency_key'], 'parent_expected_version' => 2, 'site_id' => $this->site->id,
                'location_description' => 'Different source family']), 'idempotency_key');
        $storage = $this->storage($parent); $command = $this->storageCommand($parent, $storage, ['storage_notes' => 'Bound child']);
        $first = $this->service()->updateStorage($parent, $storage, $this->manager, $command);
        $this->assertSame($first, $this->service()->updateStorage($parent, $storage, $this->manager, $command));
        $this->validation(fn () => $this->service()->updateStorage($parent, $storage, $this->manager,
            [...$command, 'parent_expected_version' => 1]), 'idempotency_key');
    }

    public function test_warmed_permission_and_site_revocation_deny_exact_retry_before_receipt_sql(): void
    {
        $parent = $this->catalogue(); $row = $this->storage($parent); $input = $this->storageCommand($parent, $row, ['storage_notes' => 'Accepted']);
        $first = $this->service()->updateStorage($parent, $row, $this->manager, $input);
        $this->manager->load('permissionOverrides', 'hrEmployeeProfile'); $this->allow($this->manager, 'hazards.manage', false);
        DB::flushQueryLog(); DB::enableQueryLog();
        try { $this->denial(fn () => $this->service()->updateStorage($parent, $row, $this->manager, $input));
            $sql = implode(' ', array_column(DB::getQueryLog(), 'query')); $this->assertStringNotContainsString('chemical_command_receipts', $sql);
        } finally { DB::disableQueryLog(); }
        $this->allow($this->manager, 'hazards.manage');
        \App\Domain\Hr\Models\HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->foreignSite->id]);
        $this->denial(fn () => $this->service()->updateStorage($parent, $row, $this->manager, $input), 404);
        \App\Domain\Hr\Models\HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->site->id]);
        $this->assertSame($first, $this->service()->updateStorage($parent, $row, $this->manager, $input));
    }

    public function test_recovery_is_actor_source_command_bound_and_uppercase_uuid_is_canonical(): void
    {
        $root = $this->catalogue(); $input = $this->command($root, ['common_name' => 'Recover own acceptance']);
        $r = $this->service()->update($root, $this->manager, $input);
        $this->assertSame($r, $this->service()->confirmationForViewer($this->manager, 'catalogue', $root->id, strtoupper($input['idempotency_key'])));
        $this->assertNull($this->service()->confirmationForViewer($this->staff(['hazards.manage']), 'catalogue', $root->id, $input['idempotency_key']));
        $this->assertNull($this->service()->confirmationForViewer($this->manager, 'catalogue', $this->catalogue()->id, $input['idempotency_key']));
        $this->assertNull($this->service()->confirmationForViewer($this->manager, 'exposure', $root->id, $input['idempotency_key']));
    }

    public function test_corrupt_receipt_identity_semantic_audit_or_retained_version_cannot_echo_success(): void
    {
        foreach (['receipt', 'audit', 'version'] as $damage) {
            $root = $this->catalogue(); $input = $this->command($root, ['common_name' => 'Accepted before damage']);
            $r = $this->service()->update($root, $this->manager, $input);
            $receipt = ChemicalCommandReceipt::where('idempotency_key', $input['idempotency_key'])->sole();
            if ($damage === 'receipt') {
                DB::table('chemical_command_receipts')->where('id', $receipt->id)->update(['root_id' => $root->id + 999]);
            } elseif ($damage === 'audit') {
                DB::table('audit_logs')->where('id', $receipt->audit_log_id)->update(['action' => 'chemical.unrelated']);
            } else {
                DB::table('chemical_source_versions')->where('id', $r['source_version_id'])->update(['content_digest' => str_repeat('f', 64)]);
            }
            $this->integrity(fn () => $this->service()->update($root, $this->manager, $input));
        }
    }

    public function test_whitelist_never_recovers_polluted_receipt_fields(): void
    {
        $root = $this->catalogue(); $input = $this->command($root, ['common_name' => 'Safe acceptance']);
        $r = $this->service()->update($root, $this->manager, $input);
        DB::table('chemical_command_receipts')->where('idempotency_key', $input['idempotency_key'])->update([
            'accepted_result' => json_encode([...$r, 'document_path' => 'private/raw', 'medical_notes' => 'Not public'], JSON_THROW_ON_ERROR)]);
        $this->assertSame($r, $this->service()->confirmationForViewer($this->manager, 'catalogue', $root->id, $input['idempotency_key']));
    }

    public function test_immutable_models_refuse_application_update_or_delete_and_noop_replay_remains_exact(): void
    {
        $root = $this->catalogue(); $input = $this->command($root, ['name' => $root->name]);
        $r = $this->service()->update($root, $this->manager, $input); $this->assertFalse($r['changed']);
        $this->assertSame($r, $this->service()->update($root, $this->manager, $input));
        foreach ([ChemicalCommandIntent::where('idempotency_key', $input['idempotency_key'])->sole(),
            ChemicalCommandReceipt::where('idempotency_key', $input['idempotency_key'])->sole(), ChemicalSourceVersion::firstOrFail()] as $row) {
            $this->integrity(fn () => $row->delete());
            $row->created_at = now()->addDay(); $this->integrity(fn () => $row->save());
        }
    }

    public function test_private_family_uuid_collision_is_generic_and_never_hydrates_its_retained_identity(): void
    {
        $key = (string) Str::uuid();
        DB::table('chemical_command_intents')->insert(['idempotency_key' => $key, 'actor_id' => $this->manager->id,
            'actor_snapshot' => json_encode(['id' => $this->manager->id, 'name' => 'Private retained actor']), 'source_type' => 'exposure',
            'source_id' => 123456, 'parent_id' => 654321, 'command' => 'capture_exposure', 'expected_version' => null,
            'parent_expected_version' => 1, 'fingerprint' => str_repeat('a', 64), 'created_at' => now()->utc()]);
        $hydrated = []; ChemicalCommandIntent::retrieved(function ($row) use (&$hydrated) { $hydrated[] = $row->source_type; });
        DB::flushQueryLog(); DB::enableQueryLog();
        try {
            $this->validation(fn () => $this->service()->create($this->manager,
                ['idempotency_key' => $key, 'name' => 'Cannot steal private UUID', 'physical_form' => 'solid']), 'idempotency_key');
            $this->assertSame([], $hydrated);
            $sql = implode(' ', array_column(DB::getQueryLog(), 'query'));
            $this->assertStringNotContainsString('substance_exposure_records', $sql);
            $this->assertStringNotContainsString('chemical_command_receipts', $sql);
        } finally { DB::disableQueryLog(); ChemicalCommandIntent::flushEventListeners(); }
    }
}
