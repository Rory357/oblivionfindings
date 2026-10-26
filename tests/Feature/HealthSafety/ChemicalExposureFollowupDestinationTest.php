<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ChemicalExposureFollowup;
use App\Models\ChemicalExposureSourceDelivery;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Notifications\HealthSafety\ChemicalExposureFollowupAssignedNotification;
use App\Services\HealthSafety\ChemicalExposureCommandAccess;
use App\Services\HealthSafety\ChemicalExposureFollowupDestinationService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\Tasks\Providers\ChemicalExposureFollowupProvider;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/** Actual accepted source/assignment and database destination; no simulated delivered callback. */
final class ChemicalExposureFollowupDestinationTest extends ChemicalExposureCommandTestCase
{
    protected bool $physicalCommits = true;
    private function destination(): ChemicalExposureFollowupDestinationService { return app(ChemicalExposureFollowupDestinationService::class); }
    private function assigned(?ClientIncident $incident = null): array
    {
        $receiver = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']);
        if ($incident !== null) { foreach (['incidents.viewAny', 'clients.viewAny'] as $key) { $this->allow($receiver, $key); } }
        $root = $this->exposure(['symptoms' => 'PRIVATE-SYMPTOMS', 'circumstances' => 'PRIVATE-CIRCUMSTANCES',
            'related_incident_id' => $incident?->id]);
        $receipt = $this->exposureService()->assignFollowup($root, $this->manager, $this->privateCommand($root,
            ['assigned_to' => $receiver->id, 'instruction' => 'PRIVATE-INSTRUCTION', 'due_at' => '2026-10-13T01:00:00Z']));
        $row = ChemicalExposureSourceDelivery::where('kind', 'followup')->where('source_version_id', $receipt['source_version_id'])->sole();
        return [$root, $receiver, $row, $receipt];
    }
    private function visible($viewer): array
    {
        return HealthSafetyReadContext::within($viewer, ChemicalExposureCommandAccess::PERMISSIONS,
            fn ($context) => $this->destination()->visibleNotificationIdsInContext($viewer, $context));
    }
    private function notification(): DatabaseNotification
    { return DatabaseNotification::where('type', ChemicalExposureFollowupAssignedNotification::class)->sole(); }

    public function test_assignment_is_pending_until_actual_inbox_persistence_and_confirmation_is_database_only(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::DECIDED, 'Stephan chose to keep follow-up assignment notices off (26 Oct, job 3): the assignee sees the work in /tasks, which applies their current access. No follow-up notice is staged or sent.', 'none unless notices are turned on together with their notification-centre reader.'));
        [$root, $receiver, $row] = $this->assigned();
        $this->assertSame('pending', $row->state); $this->assertSame(0, DatabaseNotification::count());
        $this->assertSame('unconfirmed', $this->destination()->confirmationForViewer($this->manager, $row->id)['destination_confirmation']);
        $result = $this->destination()->deliverForViewer($this->manager, $row->id);
        $this->assertSame('inbox_present', $result['destination_confirmation']); $this->assertSame('not_attempted', $result['external_delivery']);
        $n = $this->notification(); $this->assertSame($receiver->id, (int) $n->notifiable_id);
        $this->assertSame(['kind' => 'private_followup', 'title' => 'Assigned follow-up', 'body' => null, 'url' => null, 'delivery_id' => $row->id], $n->data);
        $serialized = json_encode($n->data, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE', 'worker_id', 'client_id', 'site_id', 'path', 'instruction', 'symptoms', 'idempotency_key'] as $private) { $this->assertStringNotContainsString($private, $serialized); }
        $this->assertSame(2, $root->fresh()->source_revision);
    }
    public function test_lost_response_and_repeat_delivery_reuse_one_exact_destination_without_another_source_revision(): void
    {
        [$root, $receiver, $row] = $this->assigned(); $this->destination()->deliverForViewer($this->manager, $row->id);
        $id = $this->notification()->id;
        $this->assertSame('inbox_present', $this->destination()->confirmationForViewer($receiver, $row->id)['destination_confirmation']);
        $this->destination()->deliverForViewer($this->manager, $row->id);
        $this->assertSame($id, $this->notification()->id); $this->assertSame(1, DatabaseNotification::count());
        $this->assertSame(2, $root->fresh()->source_revision); $this->assertSame(2, $row->fresh()->attempts);
    }
    public function test_notification_save_veto_rolls_back_without_a_delivery_claim_and_exact_retry_remains_possible(): void
    {
        [$root, $receiver, $row] = $this->assigned(); DatabaseNotification::creating(fn () => false);
        try { $this->integrity(fn () => $this->destination()->deliverForViewer($this->manager, $row->id)); }
        finally { DatabaseNotification::flushEventListeners(); }
        $this->assertSame(0, DatabaseNotification::count()); $this->assertSame('pending', $row->fresh()->state);
        $this->assertSame('inbox_present', $this->destination()->deliverForViewer($this->manager, $row->id)['destination_confirmation']);
    }
    public function test_outbox_save_veto_rolls_back_the_actual_inbox_item_then_retry_creates_once(): void
    {
        [$root, $receiver, $row] = $this->assigned(); ChemicalExposureSourceDelivery::updating(fn ($record) => $record->state === 'delivered' ? false : null);
        try { $this->integrity(fn () => $this->destination()->deliverForViewer($this->manager, $row->id)); }
        finally { ChemicalExposureSourceDelivery::flushEventListeners(); }
        $this->assertSame(0, DatabaseNotification::count()); $this->assertSame('pending', $row->fresh()->state);
        $this->assertSame('inbox_present', $this->destination()->deliverForViewer($this->manager, $row->id)['destination_confirmation']);
    }
    public function test_warmed_receiver_private_permissions_withdrawal_denies_send_and_hides_existing_notification_and_task(): void
    {
        [$root, $receiver, $row] = $this->assigned(); $this->destination()->deliverForViewer($this->manager, $row->id);
        $receiver->canDo('hr.wellbeing.view'); $this->allow($receiver, 'hr.wellbeing.view', false);
        $this->denial(fn () => $this->destination()->deliverForViewer($this->manager, $row->id));
        [$ids, $queries] = $this->capturedQueries(fn () => $this->visible($receiver)); $this->assertSame([], $ids);
        foreach ($queries as $query) { $this->assertStringNotContainsString('chemical_exposure', strtolower($query['query'])); }
        $this->assertSame([], app(ChemicalExposureFollowupProvider::class)->authorizedTasks($receiver));
        $this->allow($receiver, 'hr.wellbeing.view'); $this->assertSame([$this->notification()->id], $this->visible($receiver));
    }
    public function test_receiver_site_reassignment_and_employment_withdrawal_refuse_without_rerouting(): void
    {
        [$root, $receiver, $row] = $this->assigned(); HrEmployeeProfile::where('user_id', $receiver->id)->update(['primary_site_id' => $this->foreignSite->id]);
        $this->denial(fn () => $this->destination()->deliverForViewer($this->manager, $row->id), 404); $this->assertSame(0, DatabaseNotification::count());
        HrEmployeeProfile::where('user_id', $receiver->id)->update(['primary_site_id' => $this->site->id, 'is_active' => false]);
        $this->denial(fn () => $this->destination()->deliverForViewer($this->manager, $row->id), 404); $this->assertSame('pending', $row->fresh()->state);
    }
    public function test_sender_private_or_manage_revocation_cannot_dispatch_or_claim_current_confirmation(): void
    {
        [$root, $receiver, $row] = $this->assigned(); $this->allow($this->manager, 'hazards.manage', false);
        $this->denial(fn () => $this->destination()->deliverForViewer($this->manager, $row->id));
        $this->denial(fn () => $this->destination()->confirmationForViewer($this->manager, $row->id), 404);
        $this->assertSame(0, DatabaseNotification::count());
    }
    public function test_original_context_recipient_authority_is_required_even_when_source_sender_can_read(): void
    {
        foreach (['incidents.viewAny', 'clients.viewAny'] as $key) { $this->allow($this->manager, $key); }
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create(['site_id' => $this->site->id, 'client_id' => $client->id,
            'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null, 'is_hr_confidential' => false]));
        [$root, $receiver, $row] = $this->assigned($incident);
        $this->allow($receiver, 'incidents.viewAny', false);
        $this->denial(fn () => $this->destination()->deliverForViewer($this->manager, $row->id), 404);
        $this->assertSame(0, DatabaseNotification::count());
        foreach (['incidents.viewAny', 'clients.viewAny'] as $key) { $this->allow($receiver, $key); }
        $this->destination()->deliverForViewer($this->manager, $row->id);
        $this->allow($receiver, 'clients.viewAny', false);
        $this->assertSame([], $this->visible($receiver));
        $this->denial(fn () => $this->destination()->confirmationForViewer($this->manager, $row->id));
    }
    public function test_genuine_later_source_advance_supersedes_old_delivery_without_resending_old_assignment(): void
    {
        [$root, $receiver, $row] = $this->assigned(); $this->destination()->deliverForViewer($this->manager, $row->id);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root, ['reason' => 'Actual changed circumstance', 'circumstances' => 'Later fact']));
        $result = $this->destination()->deliverForViewer($this->manager, $row->id);
        $this->assertSame('superseded', $result['state']); $this->assertSame('unconfirmed', $result['destination_confirmation']);
        $this->assertSame(1, DatabaseNotification::count()); $this->assertSame([], $this->visible($receiver));
        // Source work remains open and exact, despite an older delivery being superseded.
        $this->assertCount(1, app(ChemicalExposureFollowupProvider::class)->authorizedTasks($receiver));
    }
    public function test_destination_type_recipient_or_payload_tamper_is_never_repaired_by_resend_or_delivered_flag(): void
    {
        [$root, $receiver, $row] = $this->assigned(); $this->destination()->deliverForViewer($this->manager, $row->id);
        $n = $this->notification(); $original = $n->getRawOriginal();
        foreach ([['type' => 'OtherNotification'], ['notifiable_id' => $this->worker->id], ['data' => json_encode([...$n->data, 'body' => 'PRIVATE-INJECTION'])]] as $bad) {
            DB::table('notifications')->where('id', $n->id)->update($bad);
            $this->integrity(fn () => $this->destination()->deliverForViewer($this->manager, $row->id));
            $this->integrity(fn () => $this->destination()->confirmationForViewer($receiver, $row->id));
            DB::table('notifications')->where('id', $n->id)->update($original);
        }
        $this->assertSame(1, DatabaseNotification::count());
    }
    public function test_missing_destination_is_unconfirmed_then_exact_retry_recreates_only_same_identity(): void
    {
        [$root, $receiver, $row] = $this->assigned(); $this->destination()->deliverForViewer($this->manager, $row->id); $id = $this->notification()->id;
        DB::table('notifications')->where('id', $id)->delete();
        $this->assertSame('unconfirmed', $this->destination()->confirmationForViewer($receiver, $row->id)['destination_confirmation']);
        $this->destination()->deliverForViewer($this->manager, $row->id); $this->assertSame($id, $this->notification()->id);
    }
    public function test_notification_read_is_distinct_from_source_completion_and_completion_hides_old_open_item(): void
    {
        [$root, $receiver, $row, $receipt] = $this->assigned(); $this->destination()->deliverForViewer($this->manager, $row->id);
        $n = $this->notification(); $n->markAsRead(); $proof = $this->destination()->confirmationForViewer($receiver, $row->id);
        $this->assertTrue($proof['read']); $this->assertFalse($proof['actioned']);
        $this->exposureService()->completeFollowup($root, $receipt['source_id'], $receiver, $this->privateCommand($root,
            ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'Actual completed action']));
        $this->assertSame([], $this->visible($receiver)); $this->assertSame([], app(ChemicalExposureFollowupProvider::class)->authorizedTasks($receiver));
        $this->assertCount(1, app(ChemicalExposureFollowupProvider::class)->authorizedTasks($receiver, ['include_done' => true]));
    }
    public function test_foreign_current_private_reader_receives_neither_named_task_nor_notification_projection(): void
    {
        [$root, $receiver, $row] = $this->assigned(); $other = $this->staff(['hazards.view', 'hr.wellbeing.view']);
        $this->destination()->deliverForViewer($this->manager, $row->id);
        $this->assertSame([], $this->visible($other)); $this->assertSame([], app(ChemicalExposureFollowupProvider::class)->authorizedTasks($other));
        $this->denial(fn () => $this->destination()->confirmationForViewer($other, $row->id), 404);
    }
    public function test_task_feed_is_actual_source_work_without_medical_prose_or_generic_completion_authority(): void
    {
        [$root, $receiver, $row, $receipt] = $this->assigned(); $items = app(ChemicalExposureFollowupProvider::class)->authorizedTasks($receiver);
        $this->assertCount(1, $items); $dto = $items[0]->toArray();
        $this->assertSame('chemical_exposure_followup-'.$receipt['source_id'], $dto['id']);
        // Codex's provider deliberately gives no link until the follow-up focus route is mounted (logged follow-up).
        $this->assertNull($dto['link']);
        $this->assertNull($dto['description']); $this->assertNull($dto['client']); $this->assertNull($dto['site']); $this->assertTrue($dto['restricted']);
        $this->assertStringNotContainsString('PRIVATE-', json_encode($dto, JSON_THROW_ON_ERROR));
        $this->assertSame(0, DatabaseNotification::count());
    }
    public function test_corrupted_followup_assignment_or_instruction_never_becomes_a_destination_or_task(): void
    {
        [$root, $receiver, $row, $receipt] = $this->assigned();
        DB::table('chemical_exposure_followups')->where('id', $receipt['source_id'])->update(['instruction' => 'Rehashed independent narrative']);
        $this->integrity(fn () => $this->destination()->deliverForViewer($this->manager, $row->id));
        $this->assertSame([], app(ChemicalExposureFollowupProvider::class)->authorizedTasks($receiver)); $this->assertSame(0, DatabaseNotification::count());
    }
    public function test_save_listener_source_replacement_rolls_back_new_destination_and_never_confirms_old_source(): void
    {
        [$root, $receiver, $row] = $this->assigned();
        DatabaseNotification::created(fn () => DB::table('substance_exposure_records')->where('id', $root->id)->update(['symptoms' => 'Unauthorized changed fact']));
        try { $this->integrity(fn () => $this->destination()->deliverForViewer($this->manager, $row->id)); }
        finally { DatabaseNotification::flushEventListeners(); }
        $this->assertSame(0, DatabaseNotification::count()); $this->assertSame('PRIVATE-SYMPTOMS', $root->fresh()->symptoms); $this->assertSame('pending', $row->fresh()->state);
    }
    public function test_contextual_provider_requires_same_viewer_and_complete_prepared_union_without_fallback(): void
    {
        [$root, $receiver] = $this->assigned(); $provider = app(ChemicalExposureFollowupProvider::class);
        HealthSafetyReadContext::within($receiver, ChemicalExposureCommandAccess::PERMISSIONS, function ($context) use ($provider, $receiver) {
            $this->assertCount(1, $provider->authorizedTasksInContext($receiver, [], $context));
            $this->integrity(fn () => $provider->authorizedTasksInContext($this->manager, [], $context)); return null;
        });
        HealthSafetyReadContext::within($receiver, ['hazards.view', 'hr.wellbeing.view', 'healthSafety.viewAllSites'], function ($context) use ($provider, $receiver) {
            $this->integrity(fn () => $provider->authorizedTasksInContext($receiver, [], $context)); return null;
        });
    }
    public function test_immutable_accepted_delivery_membership_cannot_be_reassigned_to_a_different_receiver(): void
    {
        [$root, $receiver, $row, $receipt] = $this->assigned(); $other = $this->staff(['hazards.view', 'hr.wellbeing.view']);
        DB::table('chemical_exposure_followups')->where('id', $receipt['source_id'])->update(['assigned_to' => $other->id]);
        DB::table('chemical_exposure_source_deliveries')->where('id', $row->id)->update(['recipient_id' => $other->id]);
        $this->integrity(fn () => $this->destination()->deliverForViewer($this->manager, $row->id));
        $this->assertSame(0, DatabaseNotification::count());
        $this->assertSame([], app(ChemicalExposureFollowupProvider::class)->authorizedTasks($other));
    }
    public function test_damaged_assignment_does_not_hide_healthy_independent_named_work(): void
    {
        [$root, $receiver, $row, $receipt] = $this->assigned(); $otherRoot = $this->exposure();
        $healthy = $this->exposureService()->assignFollowup($otherRoot, $this->manager, $this->privateCommand($otherRoot,
            ['assigned_to' => $receiver->id, 'instruction' => 'Actual independent work', 'due_at' => '2026-10-13T01:00:00Z']));
        DB::table('chemical_exposure_followups')->where('id', $receipt['source_id'])->update(['instruction' => 'Unaudited replacement']);
        $items = app(ChemicalExposureFollowupProvider::class)->authorizedTasks($receiver);
        $this->assertCount(1, $items); $this->assertSame('chemical_exposure_followup-'.$healthy['source_id'], $items[0]->id);
    }
    public function test_denied_original_site_never_hydrates_full_notification_payload_and_contextual_open_has_no_fallback(): void
    {
        [$root, $receiver, $row] = $this->assigned(); $this->destination()->deliverForViewer($this->manager, $row->id); $id = $this->notification()->id;
        HrEmployeeProfile::where('user_id', $receiver->id)->update(['primary_site_id' => $this->foreignSite->id]);
        [$visible, $queries] = $this->capturedQueries(fn () => $this->visible($receiver)); $this->assertSame([], $visible);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/select\s+\*\s+from\s+[`"]?notifications/i', $query['query']);
        }
        HealthSafetyReadContext::within($receiver, ChemicalExposureCommandAccess::PERMISSIONS, function ($context) use ($receiver, $id) {
            $this->denial(fn () => $this->destination()->projectNotification($receiver, $id, $context), 404); return null;
        });
    }
    public function test_current_notification_projection_has_only_source_proved_canonical_link_without_private_payload(): void
    {
        [$root, $receiver, $row] = $this->assigned(); $this->destination()->deliverForViewer($this->manager, $row->id); $id = $this->notification()->id;
        $dto = HealthSafetyReadContext::within($receiver, ChemicalExposureCommandAccess::PERMISSIONS,
            fn ($context) => $this->destination()->projectNotification($receiver, $id, $context));
        $this->assertSame('/health-safety/chemical-exposures/'.$root->id.'?section=followups', $dto['data']['url']);
        $this->assertStringNotContainsString('PRIVATE-', json_encode($dto, JSON_THROW_ON_ERROR));
        $this->assertSame([$id], $this->visible($receiver));
    }
    public function test_actual_user_preference_and_database_channel_optout_leave_source_pending_and_named_task_open(): void
    {
        [$root, $receiver, $row] = $this->assigned();
        $pref = \App\Models\UserNotificationPreference::create(['user_id' => $receiver->id,
            'key' => ChemicalExposureFollowupDestinationService::EVENT_KEY, 'enabled' => false, 'channel_inapp' => true]);
        $this->validation(fn () => $this->destination()->deliverForViewer($this->manager, $row->id), 'delivery');
        $this->assertSame('pending', $row->fresh()->state); $this->assertSame(0, DatabaseNotification::count());
        $this->assertCount(1, app(ChemicalExposureFollowupProvider::class)->authorizedTasks($receiver));
        $pref->update(['enabled' => true, 'channel_inapp' => false]);
        $this->validation(fn () => $this->destination()->deliverForViewer($this->manager, $row->id), 'delivery');
        $pref->update(['channel_inapp' => true]);
        $this->assertSame('inbox_present', $this->destination()->deliverForViewer($this->manager, $row->id)['destination_confirmation']);
    }
    public function test_actual_role_preferences_and_user_override_preserve_named_recipient_without_manager_fanout(): void
    {
        [$root, $receiver, $row] = $this->assigned();
        $role = \App\Models\Role::where('name', 'support_worker')->sole(); $receiver->roles()->sync([$role->id]);
        \App\Models\RoleNotificationPreference::create(['role_id' => $role->id,
            'key' => ChemicalExposureFollowupDestinationService::EVENT_KEY, 'enabled' => false, 'channel_inapp' => true]);
        $this->validation(fn () => $this->destination()->deliverForViewer($this->manager, $row->id), 'delivery');
        \App\Models\UserNotificationPreference::create(['user_id' => $receiver->id,
            'key' => ChemicalExposureFollowupDestinationService::EVENT_KEY, 'enabled' => true, 'channel_inapp' => true]);
        $this->destination()->deliverForViewer($this->manager, $row->id);
        $this->assertSame([$receiver->id], DatabaseNotification::pluck('notifiable_id')->map(fn ($id) => (int) $id)->all());
    }
    public function test_nested_transaction_refusal_happens_before_destination_or_outbox_sql(): void
    {
        [$root, $receiver, $row] = $this->assigned(); DB::beginTransaction();
        try {
            DB::enableQueryLog(); DB::flushQueryLog();
            $this->integrity(fn () => $this->destination()->deliverForViewer($this->manager, $row->id));
            $this->assertSame([], DB::getQueryLog());
        } finally { DB::disableQueryLog(); DB::rollBack(); }
        $this->assertSame(0, DatabaseNotification::count()); $this->assertSame('pending', $row->fresh()->state);
    }
    public function test_preference_optout_after_confirmed_destination_does_not_duplicate_or_erase_retained_inbox(): void
    {
        [$root, $receiver, $row] = $this->assigned(); $this->destination()->deliverForViewer($this->manager, $row->id); $id = $this->notification()->id;
        \App\Models\UserNotificationPreference::create(['user_id' => $receiver->id,
            'key' => ChemicalExposureFollowupDestinationService::EVENT_KEY, 'enabled' => false, 'channel_inapp' => false]);
        $this->assertSame('inbox_present', $this->destination()->deliverForViewer($this->manager, $row->id)['destination_confirmation']);
        $this->assertSame($id, $this->notification()->id); $this->assertSame(1, DatabaseNotification::count());
        DB::table('notifications')->where('id', $id)->delete();
        $this->validation(fn () => $this->destination()->deliverForViewer($this->manager, $row->id), 'delivery');
        $this->assertSame('unconfirmed', $this->destination()->confirmationForViewer($receiver, $row->id)['destination_confirmation']);
    }
}
