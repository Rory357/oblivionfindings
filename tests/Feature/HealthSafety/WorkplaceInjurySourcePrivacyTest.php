<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Models\WorkplaceInjuryCommandReceipt;
use Illuminate\Support\Facades\DB;

final class WorkplaceInjurySourcePrivacyTest extends WorkplaceInjurySourceTestCase
{
    public function test_factual_read_omits_private_fields_and_does_not_select_them_or_child_tables(): void
    {
        $injury = $this->injury();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $projection = $this->access()->withCurrentViewer($this->factual, fn ($current, $reads, $sites) =>
            $this->access()->project($injury, $current, $reads, $sites));
        $sql = strtolower(implode("\n", array_column(DB::getQueryLog(), 'query')));
        DB::disableQueryLog();
        $this->assertSame('restricted', $projection['medical_access']);
        foreach (['immediate_treatment', 'medical_treatment_type', 'acc_claim_number', 'notes'] as $field) {
            $this->assertArrayNotHasKey($field, $projection);
        }
        foreach (['immediate_treatment', 'medical_treatment_type', 'acc_claim_number', 'work_capacity_assessments', 'workplace_injury_attachments'] as $private) {
            $this->assertStringNotContainsString($private, $sql);
        }
        $this->assertSame($this->worker->id, $projection['worker']['id']);
        $this->assertSame('Recorded lifting injury.', $projection['description']);
    }

    public function test_shared_context_matches_current_factual_projection_without_private_sql_or_materialisation(): void
    {
        $injury = $this->injury();
        $standalone = $this->access()->withCurrentViewer($this->factual, fn ($current, $reads, $sites) =>
            $this->access()->project($injury, $current, $reads, $sites));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $shared = \App\Services\HealthSafety\HealthSafetyReadContext::within($this->factual,
            \App\Services\HealthSafety\InjuryAccessService::PERMISSIONS, fn ($context) =>
                $this->access()->withReadContext($context, fn ($current, $reads, $sites) =>
                    $this->access()->project($injury, $current, $reads, $sites)));
        $sql = strtolower(implode("\n", array_column(DB::getQueryLog(), 'query')));
        DB::disableQueryLog();
        $this->assertSame($standalone, $shared);
        $this->assertSame('restricted', $shared['medical_access']);
        foreach (['immediate_treatment', 'medical_treatment_type', 'acc_claim_number', 'work_capacity_assessments', 'workplace_injury_attachments'] as $private) {
            $this->assertStringNotContainsString($private, $sql);
        }
    }

    public function test_shared_context_prepared_union_never_grants_read_or_keeps_loaded_withdrawn_authority(): void
    {
        $injury = $this->injury();
        $read = fn ($viewer) => \App\Services\HealthSafety\HealthSafetyReadContext::within($viewer,
            \App\Services\HealthSafety\InjuryAccessService::PERMISSIONS, fn ($context) =>
                $this->access()->withReadContext($context, fn ($current, $reads, $sites) =>
                    $this->access()->project($injury, $current, $reads, $sites)));
        $this->denial(fn () => $read($this->capturer));
        $this->assertSame($injury->id, $read($this->factual)['id']);
        $this->revoke($this->factual, 'hazards.view');
        $this->denial(fn () => $read($this->factual));
    }

    public function test_shared_context_rejects_authority_handle_return_and_expired_context_reuse(): void
    {
        $retained = null;
        \App\Services\HealthSafety\HealthSafetyReadContext::within($this->factual,
            \App\Services\HealthSafety\InjuryAccessService::PERMISSIONS, function ($context) use (&$retained) {
                $retained = $context;
                return $this->access()->withReadContext($context, fn () => ['materialized' => true]);
            });
        try {
            $this->access()->withReadContext($retained, fn () => true);
            $this->fail('The injury adapter must not reuse expired read authority.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('read callback', $error->getMessage());
        }
        $this->expectException(\LogicException::class);
        \App\Services\HealthSafety\HealthSafetyReadContext::within($this->factual,
            \App\Services\HealthSafety\InjuryAccessService::PERMISSIONS, fn ($context) =>
                $this->access()->withReadContext($context, fn ($current) => ['escaped_actor' => $current]));
    }

    public function test_readonly_wellbeing_grant_reads_private_source_but_cannot_write_or_assign(): void
    {
        $injury = $this->injury();
        $projection = $this->access()->withCurrentViewer($this->medicalReader, fn ($current, $reads, $sites) =>
            $this->access()->project($injury, $current, $reads, $sites));
        $this->assertSame('readable', $projection['medical_access']);
        $this->assertSame('PRIVATE-CLAIM', $projection['acc_claim_number']);
        $this->denial(fn () => $this->source()->update($injury, $this->medicalReader, $this->command($injury, ['notes' => 'Changed'])));
        $this->denial(fn () => $this->recovery()->recordCapacity($injury, $this->medicalReader, $this->capacityPayload($injury)));
    }

    public function test_factual_update_preserves_all_omitted_private_values_and_denies_explicit_null_overwrite(): void
    {
        $injury = $this->injury();
        $before = $injury->only(['immediate_treatment', 'medical_treatment_type', 'acc_claim_number', 'notes']);
        $this->source()->update($injury, $this->factual, $this->command($injury, ['description' => 'Factual correction']));
        $this->assertSame($before, $injury->fresh()->only(array_keys($before)));
        $this->denial(fn () => $this->source()->update($injury, $this->factual, $this->command($injury, ['notes' => null])));
        $this->assertSame($before, $injury->fresh()->only(array_keys($before)));
        $version = $injury->sourceVersions()->orderByDesc('aggregate_revision')->firstOrFail();
        $this->assertSame('factual', $version->content['scope']);
        $this->assertArrayNotHasKey('notes', $version->content['after']);
    }

    public function test_private_write_revocation_denies_original_receipt_recovery_and_replay(): void
    {
        $injury = $this->injury();
        $payload = $this->command($injury, ['notes' => 'Actual private correction']);
        $accepted = $this->source()->update($injury, $this->manager, $payload);
        $this->revoke($this->manager, 'hr.wellbeing.view');
        $this->denial(fn () => $this->source()->update($injury, $this->manager, $payload));
        $this->denial(fn () => $this->source()->confirmationForViewer($this->manager, $injury->id, $payload['idempotency_key']));
        $this->assertDatabaseHas('workplace_injury_command_receipts', ['id' => $accepted['receipt_id'], 'requires_medical_access' => true]);
    }

    public function test_historical_worker_is_readable_but_current_staff_is_required_for_new_capture(): void
    {
        $injury = $this->injury();
        HrEmployeeProfile::where('user_id', $this->worker->id)->update(['is_active' => false, 'end_date' => '2026-10-11']);
        $projection = $this->access()->withCurrentViewer($this->medicalReader, fn ($current, $reads, $sites) =>
            $this->access()->project($injury, $current, $reads, $sites));
        $this->assertSame($this->worker->id, $projection['worker']['id']);
        $this->denial(fn () => $this->source()->create($this->capturer, $this->createPayload()));
    }

    public function test_foreign_site_and_external_or_ended_current_actor_never_gain_source_read(): void
    {
        $foreign = Site::factory()->create();
        $worker = $this->staff([], $foreign);
        $injury = WorkplaceInjury::factory()->create(['site_id' => $foreign->id, 'user_id' => $worker->id]);
        $this->denial(fn () => $this->access()->withCurrentViewer($this->manager, fn ($current, $reads, $sites) =>
            $this->access()->project($injury, $current, $reads, $sites)), 404);
        $own = $this->injury();
        User::whereKey($this->manager->id)->update(['external_clinical_account' => true]);
        $this->denial(fn () => $this->access()->withCurrentViewer($this->manager, fn ($current, $reads, $sites) =>
            $this->access()->project($own, $current, $reads, $sites)));
        User::whereKey($this->manager->id)->update(['external_clinical_account' => false]);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => false]);
        $this->denial(fn () => $this->access()->withCurrentViewer($this->manager, fn ($current, $reads, $sites) =>
            $this->access()->project($own, $current, $reads, $sites)));
    }

    public function test_hr_document_requires_independent_grant_exact_historical_employee_and_restricted_manage(): void
    {
        $injury = $this->injury();
        $profile = HrEmployeeProfile::where('user_id', $this->worker->id)->sole();
        $document = HrDocument::factory()->create(['employee_profile_id' => $profile->id, 'is_restricted' => false]);
        $read = fn () => $this->access()->withCurrentViewer($this->medicalReader, fn ($current, $reads, $sites) =>
            ['id' => $this->access()->assertHrDocument($injury, $document->id, $current, $reads, $sites, 'view')->id]);
        $this->denial($read);
        $this->medicalReader->permissionOverrides()->syncWithoutDetaching([
            \App\Models\Permission::where('key', 'hr.documents.view')->sole()->id => ['allowed' => true]]);
        $this->assertSame($document->id, $read()['id']);
        $profile->forceFill(['is_active' => false, 'end_date' => '2026-10-11'])->save();
        $this->assertSame($document->id, $read()['id']);
        $document->forceFill(['is_restricted' => true])->save();
        $this->denial($read, 404);
        $this->medicalReader->permissionOverrides()->syncWithoutDetaching([
            \App\Models\Permission::where('key', 'hr.documents.manage')->sole()->id => ['allowed' => true]]);
        $this->assertSame($document->id, $read()['id']);
        $other = $this->staff();
        $document->forceFill(['employee_profile_id' => HrEmployeeProfile::where('user_id', $other->id)->sole()->id])->save();
        $this->denial($read, 404);
    }

    public function test_current_coordinator_authority_is_rechecked_on_replay(): void
    {
        $injury = $this->injury();
        $payload = $this->command($injury, ['coordinator_user_id' => $this->factual->id, 'next_review_date' => '2026-10-20', 'reason' => 'Named coordination']);
        $this->source()->assign($injury, $this->manager, $payload);
        $this->revoke($this->factual, 'hazards.view');
        $this->denial(fn () => $this->source()->assign($injury, $this->manager, $payload));
        $this->denial(fn () => $this->source()->confirmationForViewer($this->manager, $injury->id, $payload['idempotency_key']));
        $this->assertSame(2, WorkplaceInjuryCommandReceipt::count());
    }
    public function test_atomic_site_transfer_requires_actor_original_source_destination_and_update_authority(): void
    {
        $injury = $this->injury();
        $destination = Site::factory()->create();
        $this->approveSite($this->worker, $destination);
        $owner = $this->staff(['hazards.view'], $destination);
        $payload = $this->command($injury, ['site_id' => $destination->id, 'coordinator_user_id' => $owner->id,
            'reason' => 'Explicit factual Site correction', 'next_review_date' => '2026-10-24']);
        $this->denial(fn () => $this->source()->update($injury, $this->capturer, $payload));
        $this->denial(fn () => $this->source()->update($injury, $this->manager, $payload), 404);
        $destinationOnly = $this->staff(['hazards.manage'], $destination);
        $this->denial(fn () => $this->source()->update($injury, $destinationOnly, $payload), 404);
        $this->approveSite($this->manager, $destination);
        $this->revoke($this->manager, 'hazards.manage');
        $this->denial(fn () => $this->source()->update($injury, $this->manager, $payload));
        $this->assertSame($this->site->id, (int) $injury->fresh()->site_id);
        $this->assertNull($injury->fresh()->coordinator_user_id);
        $this->assertSame(1, WorkplaceInjuryCommandReceipt::count());
    }

    public function test_atomic_site_transfer_checks_named_owner_own_current_read_and_worker_destination_eligibility(): void
    {
        $injury = $this->injury();
        $destination = Site::factory()->create();
        $this->approveSite($this->manager, $destination);
        $owner = $this->staff([], $destination);
        $this->revoke($owner, 'hazards.view');
        $this->revoke($owner, 'hr.wellbeing.view');
        $payload = $this->command($injury, ['site_id' => $destination->id, 'coordinator_user_id' => $owner->id,
            'reason' => 'No inferred permission', 'next_review_date' => '2026-10-24']);
        $this->denial(fn () => $this->source()->update($injury, $this->manager, $payload));
        $owner->permissionOverrides()->syncWithoutDetaching([
            \App\Models\Permission::where('key', 'hazards.view')->sole()->id => ['allowed' => true]]);
        $this->denial(fn () => $this->source()->update($injury, $this->manager, $payload));
        $this->approveSite($this->worker, $destination);
        HrEmployeeProfile::where('user_id', $owner->id)->update(['is_active' => false]);
        $this->denial(fn () => $this->source()->update($injury, $this->manager, $payload));
        $this->assertSame(1, $injury->fresh()->source_revision);
        $this->assertSame($this->site->id, (int) $injury->fresh()->site_id);
        $this->assertNull($injury->fresh()->coordinator_user_id);
    }

    public function test_retained_owner_withdrawal_blocks_only_genuine_site_correction_not_ordinary_fact_edit(): void
    {
        $injury = $this->injury();
        $destination = Site::factory()->create();
        $this->approveSite($this->manager, $destination);
        $this->approveSite($this->worker, $destination);
        $this->approveSite($this->factual, $destination);
        $this->source()->assign($injury, $this->manager, $this->command($injury,
            ['coordinator_user_id' => $this->factual->id, 'reason' => 'Recorded owner', 'next_review_date' => '2026-10-20']));
        $snapshot = $injury->fresh()->coordinator_snapshot;
        $this->revoke($this->factual, 'hazards.view');
        $this->validation(fn () => $this->source()->update($injury, $this->manager,
            $this->command($injury, ['site_id' => $destination->id])), 'site_id');
        $this->source()->update($injury, $this->manager, $this->command($injury, ['description' => 'Ordinary fact correction']));
        $this->assertSame($this->site->id, (int) $injury->fresh()->site_id);
        $this->assertSame($snapshot, $injury->fresh()->coordinator_snapshot);
        $this->assertSame($this->factual->id, (int) $injury->fresh()->coordinator_user_id);
        $this->assertSame(3, $injury->fresh()->source_revision);
    }

    public function test_retained_site_correction_replay_and_recovery_recheck_current_retained_owner(): void
    {
        $injury = $this->injury();
        $destination = Site::factory()->create();
        foreach ([$this->manager, $this->worker, $this->factual] as $actor) {
            $this->approveSite($actor, $destination);
        }
        $this->source()->assign($injury, $this->manager, $this->command($injury,
            ['coordinator_user_id' => $this->factual->id, 'reason' => 'Actual retained owner', 'next_review_date' => '2026-10-20']));
        $payload = $this->command($injury, ['site_id' => $destination->id]);
        $accepted = $this->source()->update($injury, $this->manager, $payload);
        $snapshot = $injury->fresh()->coordinator_snapshot;
        HrEmployeeProfile::where('user_id', $this->factual->id)->update(['secondary_site_ids' => json_encode([])]);
        $this->validation(fn () => $this->source()->update($injury, $this->manager, $payload), 'site_id');
        $this->validation(fn () => $this->source()->confirmationForViewer($this->manager, $injury->id, $payload['idempotency_key']), 'site_id');
        $this->assertSame(3, $injury->fresh()->source_revision);
        $this->assertSame($snapshot, $injury->fresh()->coordinator_snapshot);
        $this->assertSame($this->factual->id, (int) $injury->fresh()->coordinator_user_id);
        $this->assertDatabaseHas('workplace_injury_command_receipts', ['id' => $accepted['receipt_id']]);
        $this->assertSame(3, WorkplaceInjuryCommandReceipt::count());
    }

    public function test_explicit_site_transfer_replay_recovery_and_private_boundary_are_current(): void
    {
        $injury = $this->injury();
        $destination = Site::factory()->create();
        $this->approveSite($this->factual, $destination);
        $this->approveSite($this->worker, $destination);
        $owner = $this->staff(['hazards.view'], $destination);
        $payload = $this->command($injury, ['site_id' => $destination->id, 'coordinator_user_id' => $owner->id,
            'reason' => 'Explicit factual ownership correction', 'next_review_date' => '2026-10-24']);
        $this->denial(fn () => $this->source()->update($injury, $this->factual,
            [...$payload, 'notes' => 'Cannot blindly replace protected history']));
        $this->assertSame(1, $injury->fresh()->source_revision);
        $accepted = $this->source()->update($injury, $this->factual, $payload);
        $this->revoke($owner, 'hazards.view');
        $this->denial(fn () => $this->source()->update($injury, $this->factual, $payload));
        $this->denial(fn () => $this->source()->confirmationForViewer($this->factual, $injury->id, $payload['idempotency_key']));
        $owner->permissionOverrides()->syncWithoutDetaching([
            \App\Models\Permission::where('key', 'hazards.view')->sole()->id => ['allowed' => true]]);
        $this->assertSame($accepted['receipt_id'],
            $this->source()->confirmationForViewer($this->factual, $injury->id, $payload['idempotency_key'])['receipt_id']);
        HrEmployeeProfile::where('user_id', $this->factual->id)->update(['secondary_site_ids' => json_encode([])]);
        $this->denial(fn () => $this->source()->update($injury, $this->factual, $payload), 404);
        $this->denial(fn () => $this->source()->confirmationForViewer($this->factual, $injury->id, $payload['idempotency_key']), 404);
        $this->assertSame('Private enduring narrative', $injury->fresh()->notes);
        $this->assertSame(2, $injury->fresh()->source_revision);
        $this->assertSame(2, WorkplaceInjuryCommandReceipt::count());
    }
}
