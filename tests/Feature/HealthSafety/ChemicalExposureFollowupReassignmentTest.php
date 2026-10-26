<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ChemicalExposureCommandReceipt;
use App\Models\ChemicalExposureFollowup;
use App\Models\ChemicalExposureSourceDelivery;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Services\HealthSafety\ChemicalExposureDeliveryService;
use App\Services\HealthSafety\ChemicalExposureFollowupDestinationService;
use App\Services\HealthSafety\ChemicalExposureWorkspaceService;
use App\Services\Tasks\Providers\ChemicalExposureFollowupProvider;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Actual source commands/durable database destination. All executable proof remains UNRUN until coordinator admission. */
final class ChemicalExposureFollowupReassignmentTest extends ChemicalExposureCommandTestCase
{
    protected bool $physicalCommits = true;
    private function receiver() { return $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']); }
    private function assigned(): array
    {
        $root = $this->exposure(); $old = $this->receiver(); $new = $this->receiver();
        $receipt = $this->exposureService()->assignFollowup($root, $this->manager, $this->privateCommand($root,
            ['assigned_to' => $old->id, 'instruction' => 'PRIVATE-ORIGINAL-INSTRUCTION', 'due_at' => '2026-10-13T01:00:00Z']));
        return [$root, $old, $new, ChemicalExposureFollowup::findOrFail($receipt['source_id']), $receipt];
    }
    private function transfer($root, $child, $new, array $facts = [], $actor = null): array
    {
        return $this->exposureService()->reassignFollowup($root, $child->id, $actor ?? $this->manager,
            $this->privateCommand($root, ['assigned_to' => $new->id, 'reason' => 'Actual reviewed responsibility transfer', ...$facts]));
    }
    private function attempt($receipt): ChemicalExposureSourceDelivery
    { return ChemicalExposureSourceDelivery::where('kind', 'followup')->where('source_version_id', $receipt['source_version_id'])->sole(); }

    public function test_new_assignment_requires_actual_completion_permission_without_granting_it_to_private_readers(): void
    {
        $root = $this->exposure(); $reader = $this->staff(['hazards.view', 'hr.wellbeing.view']); $this->allow($reader, 'hazards.manage', false);
        $this->denial(fn () => $this->exposureService()->assignFollowup($root, $this->manager, $this->privateCommand($root,
            ['assigned_to' => $reader->id, 'instruction' => 'Actual task', 'due_at' => '2026-10-13T01:00:00Z'])));
        $this->assertSame(0, ChemicalExposureFollowup::count()); $this->assertSame(1, $root->fresh()->source_revision);
        $this->assertFalse($reader->fresh()->canDo('hazards.manage'));
    }
    public function test_transfer_preserves_original_content_recorder_and_immutable_history_with_one_successor_revision(): void
    {
        [$root, $old, $new, $child, $original] = $this->assigned(); $initial = $child->getRawOriginal(); $v1 = ChemicalExposureSourceVersion::findOrFail($original['source_version_id']);
        $oldBytes = $v1->getRawOriginal('content'); $receipt = $this->transfer($root, $child, $new);
        $this->assertSame('reassign_followup', $receipt['command']); $this->assertTrue($receipt['changed']); $this->assertCount(12, $receipt);
        $this->assertSame(2, $receipt['expected_version']); $this->assertSame(3, $receipt['version']); $this->assertSame($child->id, $receipt['source_id']);
        $after = $child->fresh(); $this->assertSame($new->id, $after->assigned_to); $this->assertSame($new->name, $after->assignee_snapshot['name']);
        foreach (['instruction', 'due_at', 'recorded_by', 'created_at'] as $key) { $this->assertSame($initial[$key], $after->getRawOriginal($key)); }
        $this->assertSame($oldBytes, $v1->fresh()->getRawOriginal('content'));
        $version = ChemicalExposureSourceVersion::findOrFail($receipt['source_version_id']);
        $this->assertSame($old->id, $version->content['source_before']['assigned_to']); $this->assertSame($new->id, $version->content['source_after']['assigned_to']);
        $this->assertSame('Actual reviewed responsibility transfer', $version->content['facts']['reason']);
        $this->assertSame($this->manager->id, $version->actor_id); $this->assertSame(3, $root->fresh()->source_revision);
        $this->assertStringNotContainsString('PRIVATE-', json_encode($receipt, JSON_THROW_ON_ERROR));
    }
    public function test_old_pending_delivery_is_superseded_in_same_commit_and_successor_has_exact_new_receiver(): void
    {
        [$root, $old, $new, $child, $original] = $this->assigned(); $pending = $this->attempt($original); $membership = $pending->membership;
        $receipt = $this->transfer($root, $child, $new); $successor = $this->attempt($receipt);
        $this->assertSame('superseded', $pending->fresh()->state); $this->assertSame($membership, $pending->fresh()->membership);
        $this->assertSame($old->id, $pending->fresh()->recipient_id); $this->assertSame(0, $pending->fresh()->attempts);
        $this->assertSame('pending', $successor->state); $this->assertSame($new->id, $successor->recipient_id);
        $this->assertSame(3, $successor->membership['source_revision']); $this->assertSame($receipt['source_version_id'], $successor->source_version_id);
        $called = false; $dto = app(ChemicalExposureDeliveryService::class)->deliverForViewer($this->manager, $pending->id,
            function () use (&$called) { $called = true; throw new \LogicException('Old receiver must not be dispatched.'); });
        $this->assertFalse($called); $this->assertSame('superseded', $dto['state']);
    }
    public function test_successor_dispatch_is_actual_new_inbox_and_source_tasks_move_to_new_receiver_only(): void
    {
        [$root, $old, $new, $child, $original] = $this->assigned(); $receipt = $this->transfer($root, $child, $new);
        $dto = app(ChemicalExposureFollowupDestinationService::class)->deliverForViewer($this->manager, $this->attempt($receipt)->id);
        $this->assertSame('inbox_present', $dto['destination_confirmation']); $this->assertSame([$new->id], DatabaseNotification::pluck('notifiable_id')->map(fn ($id) => (int) $id)->all());
        $provider = app(ChemicalExposureFollowupProvider::class); $this->assertSame([], $provider->authorizedTasks($old)); $this->assertCount(1, $provider->authorizedTasks($new));
        $this->assertSame(3, $root->fresh()->source_revision); $this->assertSame('not_attempted', $dto['external_delivery']);
    }
    public function test_retained_delivered_old_inbox_is_not_rewritten_and_new_delivery_is_independently_confirmed(): void
    {
        [$root, $old, $new, $child, $original] = $this->assigned(); $destination = app(ChemicalExposureFollowupDestinationService::class);
        $destination->deliverForViewer($this->manager, $this->attempt($original)->id); $n = DatabaseNotification::sole(); $data = $n->getRawOriginal();
        $receipt = $this->transfer($root, $child, $new); $this->assertSame($data, $n->fresh()->getRawOriginal());
        $this->assertSame('delivered', $this->attempt($original)->state); $destination->deliverForViewer($this->manager, $this->attempt($receipt)->id);
        $this->assertSame(2, DatabaseNotification::count()); $this->assertSame($data, $n->fresh()->getRawOriginal());
    }
    public function test_old_receiver_cannot_complete_after_transfer_but_new_receiver_uses_existing_completion_authority(): void
    {
        [$root, $old, $new, $child] = $this->assigned(); $this->transfer($root, $child, $new);
        $input = $this->privateCommand($root, ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'Actual completed follow-up']);
        $this->denial(fn () => $this->exposureService()->completeFollowup($root, $child->id, $old, $input));
        $this->assertNull($child->fresh()->completed_at); $done = $this->exposureService()->completeFollowup($root, $child->id, $new, $input);
        $this->assertSame('complete_followup', $done['command']); $this->assertSame($new->id, $child->fresh()->completed_by); $this->assertSame(4, $done['version']);
    }
    public function test_completion_fact_cannot_predate_new_accepted_assignment_even_when_original_creation_was_earlier(): void
    {
        [$root, $old, $new, $child] = $this->assigned(); $this->travelTo(Carbon::parse('2026-10-12T01:10:00Z')); $this->transfer($root, $child, $new);
        $this->validation(fn () => $this->exposureService()->completeFollowup($root, $child->id, $new,
            $this->privateCommand($root, ['completed_at' => '2026-10-12T01:05:00Z', 'completion_notes' => 'Attempted earlier completion'])), 'completed_at');
        $this->assertNull($child->fresh()->completed_at); $this->assertSame(3, $root->fresh()->source_revision);
    }
    public function test_completed_followup_refuses_transfer_without_changing_history_or_recorder(): void
    {
        [$root, $old, $new, $child] = $this->assigned(); $this->exposureService()->completeFollowup($root, $child->id, $old,
            $this->privateCommand($root, ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'Actual completion']));
        $before = $child->fresh()->getRawOriginal(); $this->validation(fn () => $this->transfer($root, $child, $new), 'followup_id');
        $this->assertSame($before, $child->fresh()->getRawOriginal()); $this->assertSame(3, $root->fresh()->source_revision);
    }
    public function test_transfer_requires_reason_distinct_receiver_and_exact_bounded_payload(): void
    {
        [$root, $old, $new, $child] = $this->assigned();
        foreach (['', ' ', str_repeat('x', 2001)] as $reason) { $this->validation(fn () => $this->transfer($root, $child, $new, ['reason' => $reason]), 'reason'); }
        $this->validation(fn () => $this->transfer($root, $child, $old), 'assigned_to');
        $this->validation(fn () => $this->transfer($root, $child, $new, ['due_at' => '2026-10-14T01:00:00Z']), 'command');
        $this->validation(fn () => $this->transfer($root, $child, $new, ['assigned_to' => null]), 'assigned_to');
        $this->assertSame(2, $root->fresh()->source_revision); $this->assertSame($old->id, $child->fresh()->assigned_to);
    }
    public function test_current_actor_or_destination_read_manage_site_and_staff_withdrawal_refuses_transfer_atomically(): void
    {
        [$root, $old, $new, $child, $original] = $this->assigned();
        foreach (['hazards.manage', 'hazards.view', 'hr.wellbeing.view'] as $key) {
            $this->allow($new, $key, false); $this->denial(fn () => $this->transfer($root, $child, $new)); $this->allow($new, $key);
            $this->allow($this->manager, $key, false); $this->denial(fn () => $this->transfer($root, $child, $new)); $this->allow($this->manager, $key);
        }
        HrEmployeeProfile::where('user_id', $new->id)->update(['primary_site_id' => $this->foreignSite->id]);
        $this->denial(fn () => $this->transfer($root, $child, $new), 404);
        HrEmployeeProfile::where('user_id', $new->id)->update(['primary_site_id' => $this->site->id, 'is_active' => false]);
        $this->denial(fn () => $this->transfer($root, $child, $new), 404);
        $this->assertSame(2, $root->fresh()->source_revision); $this->assertSame($old->id, $child->fresh()->assigned_to); $this->assertSame('pending', $this->attempt($original)->state);
    }
    public function test_withdrawn_old_receiver_can_be_transferred_without_fabricating_current_old_authority_or_losing_history(): void
    {
        [$root, $old, $new, $child, $original] = $this->assigned(); HrEmployeeProfile::where('user_id', $old->id)->update(['is_active' => false]);
        $history = app(ChemicalExposureWorkspaceService::class)->detail($this->manager, $root->id);
        $this->assertSame($old->id, $history['followups']['items'][0]['assigned_to']['id']); $this->assertTrue($history['followups']['items'][0]['can_reassign']);
        $this->transfer($root, $child, $new); $v = ChemicalExposureSourceVersion::where('exposure_id', $root->id)->where('command', 'reassign_followup')->sole();
        $this->assertSame($old->id, $v->content['source_before']['assigned_to']); $this->assertSame($old->name, $v->content['source_before']['assignee_snapshot']['name']);
        $this->assertSame($new->id, $child->fresh()->assigned_to);
    }
    public function test_exact_retry_and_receipt_recovery_keep_original_transfer_after_later_completion(): void
    {
        [$root, $old, $new, $child] = $this->assigned(); $input = $this->privateCommand($root, ['assigned_to' => $new->id, 'reason' => 'Exact retained transfer']);
        $receipt = $this->exposureService()->reassignFollowup($root, $child->id, $this->manager, $input);
        $this->exposureService()->completeFollowup($root, $child->id, $new, $this->privateCommand($root,
            ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'Actual completed action']));
        $this->assertSame($receipt, $this->exposureService()->reassignFollowup($root, $child->id, $this->manager, $input));
        $this->assertSame($receipt, $this->exposureService()->confirmationForKey($this->manager, strtoupper($input['idempotency_key']), $root->id));
        $this->assertSame(4, $root->fresh()->source_revision); $this->assertSame(1, AuditLog::where('meta->idempotency_key', $input['idempotency_key'])->count());
    }
    public function test_transfer_retry_rechecks_original_accepted_receiver_not_current_successor_assignee(): void
    {
        [$root, $old, $new, $child] = $this->assigned(); $input = $this->privateCommand($root, ['assigned_to' => $new->id, 'reason' => 'First accepted transfer']);
        $receipt = $this->exposureService()->reassignFollowup($root, $child->id, $this->manager, $input); $third = $this->receiver(); $this->transfer($root, $child, $third);
        $this->allow($new, 'hazards.manage', false);
        $this->denial(fn () => $this->exposureService()->reassignFollowup($root, $child->id, $this->manager, $input));
        $this->denial(fn () => $this->exposureService()->confirmationForKey($this->manager, $receipt['idempotency_key'], $root->id));
        $this->assertSame($third->id, $child->fresh()->assigned_to); $this->assertSame(4, $root->fresh()->source_revision);
        $this->allow($new, 'hazards.manage'); $this->assertSame($receipt, $this->exposureService()->confirmationForKey($this->manager, $receipt['idempotency_key'], $root->id));
    }
    public function test_stale_revision_and_uuid_payload_actor_reuse_never_silently_transfer_newer_work(): void
    {
        [$root, $old, $new, $child] = $this->assigned(); $input = $this->privateCommand($root, ['assigned_to' => $new->id, 'reason' => 'Reviewed transfer']);
        $r = $this->exposureService()->reassignFollowup($root, $child->id, $this->manager, $input);
        $this->validation(fn () => $this->transfer($root, $child, $old, ['expected_version' => 2]), 'expected_version');
        $this->validation(fn () => $this->exposureService()->reassignFollowup($root, $child->id, $this->manager, [...$input, 'reason' => 'Changed intent']), 'idempotency_key');
        $other = $this->receiver(); $this->validation(fn () => $this->exposureService()->reassignFollowup($root, $child->id, $other, $input), 'idempotency_key');
        $this->assertSame($r, $this->exposureService()->reassignFollowup($root, $child->id, $this->manager, $input)); $this->assertSame(3, $root->fresh()->source_revision);
    }
    public function test_foreign_parent_followup_is_concealed_before_instruction_or_snapshot_hydration(): void
    {
        [$root, $old, $new, $child] = $this->assigned(); $foreign = $this->exposure(); $loaded = false;
        ChemicalExposureFollowup::retrieved(function ($row) use (&$loaded, $child) { if ($row->id === $child->id && $row->getRawOriginal('instruction') !== null) { $loaded = true; } });
        try { $this->denial(fn () => $this->transfer($foreign, $child, $new), 404); }
        finally { ChemicalExposureFollowup::flushEventListeners(); }
        $this->assertFalse($loaded); $this->assertSame(1, $foreign->fresh()->source_revision); $this->assertSame($old->id, $child->fresh()->assigned_to);
    }
    public function test_child_save_and_strict_audit_veto_roll_back_transfer_history_and_pending_supersession(): void
    {
        [$root, $old, $new, $child, $original] = $this->assigned(); $versions = ChemicalExposureSourceVersion::count(); $outboxes = ChemicalExposureSourceDelivery::count();
        ChemicalExposureFollowup::updating(fn () => false);
        try { $this->integrity(fn () => $this->transfer($root, $child, $new)); } finally { ChemicalExposureFollowup::flushEventListeners(); }
        AuditLog::creating(fn ($row) => $row->action === 'chemical.exposure.reassign_followup' ? false : null);
        try { $this->integrity(fn () => $this->transfer($root, $child, $new)); } finally { AuditLog::flushEventListeners(); }
        $this->assertSame($old->id, $child->fresh()->assigned_to); $this->assertSame(2, $root->fresh()->source_revision); $this->assertSame('pending', $this->attempt($original)->state);
        $this->assertSame($versions, ChemicalExposureSourceVersion::count()); $this->assertSame($outboxes, ChemicalExposureSourceDelivery::count());
        $this->assertSame(0, ChemicalExposureCommandReceipt::where('command', 'reassign_followup')->count());
    }
    public function test_pending_supersession_or_successor_outbox_save_veto_rolls_back_all_source_changes(): void
    {
        [$root, $old, $new, $child, $original] = $this->assigned();
        ChemicalExposureSourceDelivery::updating(fn ($row) => $row->state === 'superseded' ? false : null);
        try { $this->integrity(fn () => $this->transfer($root, $child, $new)); } finally { ChemicalExposureSourceDelivery::flushEventListeners(); }
        ChemicalExposureSourceDelivery::creating(fn ($row) => $row->kind === 'followup' ? false : null);
        try { $this->integrity(fn () => $this->transfer($root, $child, $new)); } finally { ChemicalExposureSourceDelivery::flushEventListeners(); }
        $this->assertSame('pending', $this->attempt($original)->state); $this->assertSame($old->id, $child->fresh()->assigned_to); $this->assertSame(2, $root->fresh()->source_revision);
    }
    public function test_corrupt_original_membership_or_child_snapshot_refuses_transfer_instead_of_certifying_repair(): void
    {
        [$root, $old, $new, $child, $original] = $this->assigned(); $pending = $this->attempt($original); $membership = $pending->getRawOriginal('membership');
        DB::table('chemical_exposure_source_deliveries')->where('id', $pending->id)->update(['recipient_id' => $new->id]);
        $this->integrity(fn () => $this->transfer($root, $child, $new));
        DB::table('chemical_exposure_source_deliveries')->where('id', $pending->id)->update(['recipient_id' => $old->id, 'membership' => $membership]);
        DB::table('chemical_exposure_followups')->where('id', $child->id)->update(['assignee_snapshot' => json_encode(['id' => $old->id, 'name' => 'Forged source attribution'])]);
        $this->integrity(fn () => $this->transfer($root, $child, $new)); $this->assertSame(2, $root->fresh()->source_revision);
    }
    public function test_current_private_readers_can_see_historical_assignment_but_cannot_reassign_or_complete(): void
    {
        [$root, $old, $new, $child] = $this->assigned(); $reader = $this->staff(['hazards.view', 'hr.wellbeing.view']); $this->allow($reader, 'hazards.manage', false);
        $dto = app(ChemicalExposureWorkspaceService::class)->detail($reader, $root->id); $row = $dto['followups']['items'][0];
        $this->assertSame($old->id, $row['assigned_to']['id']); $this->assertFalse($row['can_reassign']); $this->assertFalse($row['can_complete']);
        $this->denial(fn () => $this->transfer($root, $child, $new, [], $reader)); $this->assertSame($old->id, $child->fresh()->assigned_to);
    }
    public function test_prospective_assignment_and_transfer_require_receiver_original_incident_client_authority(): void
    {
        foreach (['incidents.viewAny', 'clients.viewAny'] as $key) { $this->allow($this->manager, $key); }
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create(['client_id' => $client->id,
            'site_id' => $this->site->id, 'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null, 'is_hr_confidential' => false]));
        $root = $this->exposure(['related_incident_id' => $incident->id]); $old = $this->receiver(); $new = $this->receiver();
        $assign = $this->privateCommand($root, ['assigned_to' => $old->id, 'instruction' => 'Actual linked action', 'due_at' => '2026-10-13T01:00:00Z']);
        $this->denial(fn () => $this->exposureService()->assignFollowup($root, $this->manager, $assign), 404);
        foreach (['incidents.viewAny', 'clients.viewAny'] as $key) { $this->allow($old, $key); }
        $r = $this->exposureService()->assignFollowup($root, $this->manager, $assign); $child = ChemicalExposureFollowup::findOrFail($r['source_id']);
        $this->denial(fn () => $this->transfer($root, $child, $new), 404);
        $this->allow($new, 'incidents.viewAny'); $this->denial(fn () => $this->transfer($root, $child, $new));
        $this->allow($new, 'clients.viewAny'); $receipt = $this->transfer($root, $child, $new);
        $this->assertSame(3, $receipt['version']); $this->assertSame($new->id, $child->fresh()->assigned_to);
        $this->allow($new, 'clients.viewAny', false);
        $this->denial(fn () => $this->exposureService()->confirmationForKey($this->manager, $receipt['idempotency_key'], $root->id));
    }
    public function test_picker_excludes_read_only_receiver_and_completed_row_capability_is_false_without_historical_erasure(): void
    {
        [$root, $old, $new, $child] = $this->assigned(); $reader = $this->staff(['hazards.view', 'hr.wellbeing.view']); $this->allow($reader, 'hazards.manage', false);
        $options = app(ChemicalExposureWorkspaceService::class)->commandOptions($this->manager, $root->id);
        $this->assertContains($new->id, array_column($options['receivers']['items'], 'id'));
        $this->assertNotContains($reader->id, array_column($options['receivers']['items'], 'id'));
        $this->allow($new, 'hazards.manage', false);
        $this->assertNotContains($new->id, array_column(app(ChemicalExposureWorkspaceService::class)->commandOptions($this->manager, $root->id)['receivers']['items'], 'id'));
        $this->exposureService()->completeFollowup($root, $child->id, $old, $this->privateCommand($root,
            ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'Actual completed action']));
        $dto = app(ChemicalExposureWorkspaceService::class)->detail($this->manager, $root->id);
        $this->assertFalse($dto['followups']['items'][0]['can_reassign']); $this->assertSame($old->id, $dto['followups']['items'][0]['assigned_to']['id']);
    }
    public function test_genuine_legacy_unknown_assignment_time_is_not_invented_and_real_transfer_establishes_new_timing_only(): void
    {
        $root = $this->exposure(); $old = $this->receiver(); $new = $this->receiver();
        $child = ChemicalExposureFollowup::create(['exposure_id' => $root->id, 'source_schema_version' => 0, 'source_revision' => 0,
            'assigned_to' => $old->id, 'assignee_snapshot' => ['id' => $old->id, 'name' => $old->name], 'instruction' => 'Legacy retained work',
            'due_at' => '2026-10-13 01:00:00', 'recorded_by' => $this->manager->id, 'created_at' => null, 'updated_at' => null]);
        $this->validation(fn () => $this->exposureService()->completeFollowup($root, $child->id, $old,
            $this->privateCommand($root, ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'Actual completion'])), 'completed_at');
        $receipt = $this->transfer($root, $child, $new); $version = ChemicalExposureSourceVersion::findOrFail($receipt['source_version_id']);
        $this->assertSame(0, $version->content['source_before']['source_schema_version']); $this->assertSame(1, $version->content['source_after']['source_schema_version']);
        $this->exposureService()->completeFollowup($root, $child->id, $new, $this->privateCommand($root,
            ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'Actual post-transfer completion']));
        $this->assertSame($new->id, $child->fresh()->completed_by);
    }
    public function test_linked_source_picker_and_assignment_recheck_warmed_receiver_incident_and_client_grants_after_withdrawal_and_restore(): void
    {
        foreach (['incidents.viewAny', 'clients.viewAny'] as $key) { $this->allow($this->manager, $key); }
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create(['client_id' => $client->id,
            'site_id' => $this->site->id, 'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null, 'is_hr_confidential' => false]));
        $root = $this->exposure(['related_incident_id' => $incident->id]); $receiver = $this->receiver();
        foreach (['incidents.viewAny', 'clients.viewAny'] as $key) { $this->allow($receiver, $key); $this->assertTrue($receiver->canDo($key)); }
        $workspace = app(ChemicalExposureWorkspaceService::class);
        $choices = fn () => array_column($workspace->commandOptions($this->manager, $root->id)['receivers']['items'], 'id');
        $this->assertContains($receiver->id, $choices());
        $input = $this->privateCommand($root, ['assigned_to' => $receiver->id, 'instruction' => 'Actual linked follow-up',
            'due_at' => '2026-10-13T01:00:00Z']);
        foreach (['incidents.viewAny' => 404, 'clients.viewAny' => 403] as $key => $status) {
            $this->allow($receiver, $key, false);
            $this->assertNotContains($receiver->id, $choices());
            $this->denial(fn () => $this->exposureService()->assignFollowup($root, $this->manager, $input), $status);
            $this->assertSame(1, $root->fresh()->source_revision); $this->assertSame(0, ChemicalExposureFollowup::count());
            $this->assertSame(0, ChemicalExposureCommandReceipt::where('command', 'assign_followup')->count());
            $this->allow($receiver, $key); $this->assertContains($receiver->id, $choices());
        }
        $receipt = $this->exposureService()->assignFollowup($root, $this->manager, $input);
        $this->assertSame(2, $receipt['version']); $this->assertSame($receiver->id, ChemicalExposureFollowup::findOrFail($receipt['source_id'])->assigned_to);
        $this->assertSame(1, ChemicalExposureSourceDelivery::where('kind', 'followup')->count());
        $this->assertSame(0, DatabaseNotification::count());
    }

}
