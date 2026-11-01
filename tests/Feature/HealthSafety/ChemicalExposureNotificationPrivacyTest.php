<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Governance\Services\BoardPackAccessService;
use App\Models\ChemicalExposureFollowup;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\SubstanceExposureRecord;
use App\Models\UserNotificationPreference;
use App\Notifications\HealthSafety\ChemicalExposureFollowupAssignedNotification;
use App\Services\HealthSafety\ChemicalExposureFollowupDestinationService;
use App\Services\HealthSafety\ChemicalExposureNotificationAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use LogicException;

/** Whole source candidate; common-query/middleware/controllers must adopt the UNAPPLIED patch. UNRUN. */
final class ChemicalExposureNotificationPrivacyTest extends ChemicalExposureNotificationTestCase
{
    private function priorReportedOriginNotices(): array
    {
        $this->manager->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        $this->worker->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        $receiver = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view'], $this->foreignSite);
        $receiver->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->site->id]]);
        foreach (['incidents.viewAny', 'clients.viewAny'] as $key) { $this->allow($this->manager, $key); $this->allow($receiver, $key); }
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create(['site_id' => $this->site->id,
            'client_id' => $client->id, 'reported_by' => $this->manager->id, 'shift_id' => null, 'service_context_id' => null,
            'respite_stay_id' => null, 'is_hr_confidential' => false]));
        $root = $this->exposure(['related_incident_id' => $incident->id, 'medical_attention_sought' => true]);
        $original = (array) DB::table('chemical_exposure_source_versions')->where('exposure_id', $root->id)->where('aggregate_revision', 1)->sole();
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['site_id' => $this->foreignSite->id, 'related_incident_id' => null, 'reason' => 'Correct Site before retaining child work']));
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['circumstances' => 'Later accepted facts at Site B', 'reason' => 'Record actual later facts']));
        $deliver = function ($source) use ($receiver): DatabaseNotification {
            $receipt = $this->exposureService()->assignFollowup($source, $this->manager, $this->privateCommand($source,
                ['assigned_to' => $receiver->id, 'instruction' => 'PRIVATE-ORIGINAL-ACCEPTANCE-FOLLOWUP', 'due_at' => '2026-10-13T01:00:00Z']));
            $delivery = \App\Models\ChemicalExposureSourceDelivery::where('kind', 'followup')->where('source_version_id', $receipt['source_version_id'])->sole();
            app(ChemicalExposureFollowupDestinationService::class)->deliverForViewer($this->manager, $delivery->id);
            return DatabaseNotification::where('type', ChemicalExposureFollowupAssignedNotification::class)
                ->where('notifiable_id', $receiver->id)->where('data->delivery_id', $delivery->id)->sole();
        };
        $notice = $deliver($root);
        $healthyRoot = $this->exposure(['site_id' => $this->foreignSite->id]); $healthy = $deliver($healthyRoot);
        $this->assertEqualsCanonicalizing([$notice->id, $healthy->id], $this->visible($receiver));
        return [$root->fresh(), $receiver, $notice, $healthy, $incident, $original];
    }

    private function refuseAfterDiscovery($receiver, DatabaseNotification $notice, DatabaseNotification $ordinary, callable $mutation): void
    {
        $this->inbox()->materialize($receiver, function ($current, $context) use ($notice, $ordinary, $mutation): null {
            $query = app(BoardPackAccessService::class)->visibleNotificationQuery($current, false, $context);
            $mutation();
            $hydrated = [];
            DatabaseNotification::retrieved(function ($row) use (&$hydrated): void {
                if (array_key_exists('data', $row->getAttributes())) { $hydrated[] = (string) $row->id; }
            });
            try {
                $this->assertSame(1, (clone $query)->count());
                $this->assertSame([$ordinary->id], (clone $query)->get()->pluck('id')->all());
                $this->assertSame([$ordinary->id], $hydrated, 'No private body may hydrate after its source binding changes.');
                $this->assertNull((clone $query)->where('notifications.id', $notice->id)->first());
                $this->assertSame(0, (clone $query)->where('notifications.id', $notice->id)->update(['read_at' => now()]));
            } finally { DatabaseNotification::flushEventListeners(); }
            return null;
        });
        $this->assertNull($notice->fresh()->read_at);
    }

    public function test_original_reported_a_site_context_and_acceptance_are_bound_after_current_b_delivery_discovery(): void
    {
        [$root, $receiver, $notice, $healthy, $incident, $original] = $this->priorReportedOriginNotices();
        $receipt = (array) DB::table('chemical_exposure_command_receipts')->where('source_version_id', $original['id'])->sole();
        $intent = (array) DB::table('chemical_command_intents')->where('id', $receipt['intent_id'])->sole();
        $audit = (array) DB::table('audit_logs')->where('id', $receipt['audit_log_id'])->sole();
        $changes = [
            ['sites', $this->site->id, ['is_active' => false], ['is_active' => true]],
            ['client_incidents', $incident->id, ['site_id' => $this->foreignSite->id], ['site_id' => $this->site->id]],
            ['clients', $incident->client_id, ['site_id' => $this->foreignSite->id], ['site_id' => $this->site->id]],
            ['chemical_exposure_source_versions', $original['id'], ['content_digest' => str_repeat('0', 64)], ['content_digest' => $original['content_digest']]],
            ['chemical_exposure_command_receipts', $receipt['id'], ['actor_id' => $receiver->id], ['actor_id' => $receipt['actor_id']]],
            ['chemical_command_intents', $intent['id'], ['fingerprint' => str_repeat('0', 64)], ['fingerprint' => $intent['fingerprint']]],
            ['audit_logs', $audit['id'], ['action' => 'unrelated.acceptance'], ['action' => $audit['action']]],
        ];
        foreach ($changes as [$table, $id, $change, $restore]) {
            try {
                // Both are actual private notices. The independent healthy B source remains
                // after an earlier A fact/context changes AFTER query discovery but before SQL.
                $this->refuseAfterDiscovery($receiver, $notice, $healthy,
                    fn () => DB::table($table)->where('id', $id)->update($change));
            } finally { DB::table($table)->where('id', $id)->update($restore); }
            $this->assertEqualsCanonicalizing([$notice->id, $healthy->id], $this->visible($receiver));
        }
    }

    public function test_new_reported_origin_after_discovery_cannot_enter_current_notification_membership(): void
    {
        [$root, $receiver, $notice, $healthy] = $this->priorReportedOriginNotices();
        $unrelated = $this->exposure(['medical_attention_sought' => false]);
        $version = (array) DB::table('chemical_exposure_source_versions')->where('exposure_id', $unrelated->id)->sole();
        try {
            $this->refuseAfterDiscovery($receiver, $notice, $healthy, fn () => DB::table('chemical_exposure_source_versions')
                ->where('id', $version['id'])->update(['exposure_id' => $root->id, 'aggregate_revision' => $root->source_revision + 1]));
        } finally {
            DB::table('chemical_exposure_source_versions')->where('id', $version['id'])->update([
                'exposure_id' => $version['exposure_id'], 'aggregate_revision' => $version['aggregate_revision']]);
        }
        $this->assertEqualsCanonicalizing([$notice->id, $healthy->id], $this->visible($receiver));
    }

    public function test_default_common_query_excludes_private_even_for_saved_dual_reader_and_keeps_nullable_ordinary_data(): void
    {
        [$root, $receiver, $notice] = $this->notified();
        $ordinary = $this->ordinary($receiver, ['kind' => null, 'event_key' => null]);
        $receiver->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->assertSame([$ordinary->id], app(BoardPackAccessService::class)->visibleNotificationQuery($receiver)
            ->orderBy('notifications.id')->pluck('notifications.id')->all());
        $this->assertEqualsCanonicalizing([$notice->id, $ordinary->id], $this->visible($receiver));
        $this->assertSame($root->id, $root->fresh()->id);
    }

    public function test_default_marker_exclusion_survives_changed_class_and_exact_event_copy_without_global_delivery_id_guess(): void
    {
        [$root, $receiver, $notice] = $this->notified();
        DB::table('notifications')->where('id', $notice->id)->update(['type' => 'App\\Notifications\\AppEventNotification']);
        $copy = $this->ordinary($receiver, ['event_key' => ChemicalExposureFollowupDestinationService::EVENT_KEY]);
        $unrelated = $this->ordinary($receiver, ['delivery_id' => 7]);
        $this->assertSame([$unrelated->id], $this->visible($receiver));
        $this->assertNotContains($copy->id, app(BoardPackAccessService::class)->visibleNotificationQuery($receiver)->pluck('id')->all());
    }

    public function test_private_grant_denial_has_no_private_source_or_notification_body_hydration_and_preserves_ordinary(): void
    {
        [$root, $receiver, $notice] = $this->notified(); $ordinary = $this->ordinary($receiver);
        $receiver->load('roles.permissions', 'permissionOverrides'); $this->allow($receiver, 'hr.wellbeing.view', false);
        [$ids, $queries] = $this->capturedQueries(fn () => $this->visible($receiver));
        $this->assertSame([$ordinary->id], $ids);
        foreach ($queries as $entry) {
            $sql = strtolower($entry['query']);
            $this->assertStringNotContainsString('substance_exposure_records', $sql);
            $this->assertStringNotContainsString('chemical_exposure_source_', $sql);
        }
        $this->allow($receiver, 'hr.wellbeing.view'); $this->assertContains($notice->id, $this->visible($receiver));
        $this->allow($receiver, 'hazards.view', false); $this->assertSame([$ordinary->id], $this->visible($receiver));
        $this->allow($receiver, 'hazards.view'); $this->assertContains($notice->id, $this->visible($receiver));
    }

    public function test_current_site_and_employment_withdraw_restore_cannot_erase_unrelated_inbox_work(): void
    {
        [$root, $receiver, $notice] = $this->notified(); $ordinary = $this->ordinary($receiver);
        $receiver->load('hrEmployeeProfile');
        $receiver->hrEmployeeProfile()->update(['primary_site_id' => $this->foreignSite->id]);
        $this->assertSame([$ordinary->id], $this->visible($receiver));
        $receiver->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
        $this->site->update(['is_active' => false]); $this->assertSame([$ordinary->id], $this->visible($receiver));
        $this->site->update(['is_active' => true]); $this->assertContains($notice->id, $this->visible($receiver));
        $receiver->hrEmployeeProfile()->update(['is_active' => false]); $this->assertSame([$ordinary->id], $this->visible($receiver));
        $receiver->hrEmployeeProfile()->update(['is_active' => true]); $this->assertContains($notice->id, $this->visible($receiver));
    }

    public function test_other_receivers_and_catalogue_reporter_have_no_notification_identity_or_source_self_grant(): void
    {
        [$root, $receiver, $notice] = $this->notified();
        $other = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']);
        $this->assertSame([], $this->visible($other)); $this->assertSame([], $this->visible($this->reporter));
        $this->expectException(ModelNotFoundException::class); $this->data($other, $notice);
    }

    public function test_materializer_reuses_supplied_union_and_refuses_actor_preparation_lifetime_and_model_escape(): void
    {
        [$root, $receiver, $notice] = $this->notified(); $escaped = null;
        HealthSafetyReadContext::within($receiver, ChemicalExposureNotificationAccessService::PERMISSIONS,
            function ($context) use ($receiver, &$escaped): void {
                $level = DB::transactionLevel();
                $this->inbox()->materialize($receiver, function ($current, $same) use ($context, $level, &$escaped): array {
                    $this->assertSame($context, $same); $this->assertSame($level, DB::transactionLevel());
                    $escaped = app(BoardPackAccessService::class)->visibleNotificationQuery($current, false, $same);
                    return $escaped->pluck('id')->all();
                }, $context);
                $this->integrity(fn () => $this->inbox()->materialize($this->manager, fn () => null, $context));
            });
        $this->integrity(fn () => $escaped->count());
        HealthSafetyReadContext::within($receiver, ['hazards.view', 'hr.wellbeing.view'], function ($context) use ($receiver): void {
            $this->integrity(fn () => $this->inbox()->materialize($receiver, fn () => null, $context));
        });
        $this->integrity(fn () => $this->inbox()->materialize($receiver, fn ($current) => $current));
        $this->integrity(fn () => $this->inbox()->materialize($receiver,
            fn ($current, $context) => app(BoardPackAccessService::class)->visibleNotificationQuery($current, false, $context)));
    }

    public function test_foreign_physical_relation_refuses_before_private_destination_discovery(): void
    {
        [$root, $receiver] = $this->notified();
        config(['database.connections.notification_foreign_fixture' => config('database.connections.'.config('database.default'))]);
        [$result, $queries] = $this->capturedQueries(fn () => $this->inbox()->materialize($receiver, function ($current, $context): null {
            $foreign = clone $current; $foreign->setConnection('notification_foreign_fixture');
            $this->integrity(fn () => $this->inbox()->scope($foreign->notifications(), $current, $context));
            return null;
        }));
        foreach ($queries as $entry) { $this->assertStringNotContainsString('substance_exposure_records', strtolower($entry['query'])); }
    }

    public function test_current_data_replaces_stale_preloaded_body_and_contains_no_medical_worker_or_receipt_fields(): void
    {
        [$root, $receiver, $notice] = $this->notified();
        $notice->data = ['title' => 'PRIVATE-STALE-BODY', 'worker_id' => $this->worker->id, 'idempotency_key' => 'stale'];
        $data = $this->data($receiver, $notice);
        $this->assertSame('Assigned follow-up', $data['title']); $this->assertNull($data['body']);
        $serialized = json_encode($data, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE', 'worker_id', 'client_id', 'site_id', 'source_revision', 'idempotency_key', 'instruction'] as $field) {
            $this->assertStringNotContainsString($field, $serialized);
        }
        $this->assertSame(Route::has('health-safety.chemical-exposures.show')
            ? '/health-safety/chemical-exposures/'.$root->id.'?section=followups' : null, $data['url']);
    }

    public function test_type_and_all_payload_markers_rebound_after_discovery_cannot_become_ordinary_or_read(): void
    {
        [$root, $receiver, $notice] = $this->notified(); $ordinary = $this->ordinary($receiver);
        $revision = $root->fresh()->source_revision;
        $this->inbox()->materialize($receiver, function ($current, $context) use ($notice, $ordinary): null {
            // Actual source/delivery discovery has finished; mutate before the final count/body/update SQL.
            $query = app(BoardPackAccessService::class)->visibleNotificationQuery($current, false, $context);
            DB::table('notifications')->where('id', $notice->id)->update([
                'type' => \App\Notifications\ShiftTaskDueNotification::class,
                'data' => json_encode(['title' => 'PRIVATE-REBOUND-TITLE', 'body' => 'FOREIGN-MEDICAL-BODY', 'module' => 'operations'], JSON_THROW_ON_ERROR),
            ]);
            $hydrated = [];
            DatabaseNotification::retrieved(function ($row) use (&$hydrated): void {
                if (array_key_exists('data', $row->getAttributes())) { $hydrated[] = (string) $row->id; }
            });
            try {
                $this->assertSame(1, (clone $query)->count());
                $rows = (clone $query)->get(['notifications.id', 'notifications.type', 'notifications.data']);
                $this->assertSame([$ordinary->id], $rows->pluck('id')->all());
                $this->assertSame([$ordinary->id], $hydrated, 'Rebound private data must never reach model hydration.');
                $this->assertNull((clone $query)->where('notifications.id', $notice->id)->first());
                $this->assertSame(1, (clone $query)->whereNull('notifications.read_at')->update(['read_at' => now()]));
            } finally { DatabaseNotification::flushEventListeners(); }
            return null;
        });
        $this->assertNull($notice->fresh()->read_at); $this->assertNotNull($ordinary->fresh()->read_at);
        $this->assertSame($revision, $root->fresh()->source_revision);
    }

    public function test_body_and_delivery_payload_mutation_after_discovery_is_rejected_before_body_and_bulk_read_sql(): void
    {
        [$root, $receiver, $notice, $delivery] = $this->notified(); $ordinary = $this->ordinary($receiver);
        [$foreignRoot, $foreignReceiver, $foreignNotice, $foreignDelivery] = $this->notified();
        $original = $notice->data;
        $replacements = [
            [...$original, 'body' => 'FOREIGN-MEDICAL-BODY'],
            [...$original, 'worker_id' => $this->worker->id],
            [...$original, 'url' => '/health-safety/chemical-exposures/999999'],
            [...$original, 'delivery_id' => (string) $delivery->id],
            [...$original, 'delivery_id' => (float) $delivery->id],
            [...$original, 'delivery_id' => $foreignDelivery->id],
            [...$original, 'title' => 'assigned follow-up'],
            ['kind' => 'private_followup', 'title' => 'Assigned follow-up', 'url' => null, 'delivery_id' => $delivery->id],
        ];
        foreach ($replacements as $replacement) {
            DB::table('notifications')->where('id', $notice->id)->update(['data' => json_encode($original, JSON_THROW_ON_ERROR)]);
            $this->inbox()->materialize($receiver, function ($current, $context) use ($notice, $ordinary, $replacement): null {
                $query = app(BoardPackAccessService::class)->visibleNotificationQuery($current, false, $context);
                DB::table('notifications')->where('id', $notice->id)->update([
                    'data' => json_encode($replacement, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                ]);
                $hydrated = [];
                DatabaseNotification::retrieved(function ($row) use (&$hydrated): void {
                    if (array_key_exists('data', $row->getAttributes())) { $hydrated[] = (string) $row->id; }
                });
                try {
                    $this->assertSame(1, (clone $query)->count());
                    $this->assertSame([$ordinary->id], (clone $query)->get()->pluck('id')->all());
                    $this->assertSame([$ordinary->id], $hydrated);
                    $this->assertSame(0, (clone $query)->where('notifications.id', $notice->id)->update(['read_at' => now()]));
                } finally { DatabaseNotification::flushEventListeners(); }
                return null;
            });
            $this->assertNull($notice->fresh()->read_at);
        }
        DB::table('notifications')->where('id', $notice->id)->update(['data' => json_encode($original, JSON_THROW_ON_ERROR)]);
        $this->assertEqualsCanonicalizing([$notice->id, $ordinary->id], $this->visible($receiver));
        $this->assertSame('Assigned follow-up', $this->data($receiver, $notice)['title']);
        $this->assertSame([$foreignNotice->id], $this->visible($foreignReceiver));
    }

    public function test_recipient_rebinding_after_discovery_and_denied_private_marker_rebound_do_not_hydrate_body(): void
    {
        [$root, $receiver, $notice] = $this->notified(); $ordinary = $this->ordinary($receiver);
        $other = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']);
        foreach ([['notifiable_id' => $other->id], ['notifiable_type' => 'App\\Models\\ForeignRecipient']] as $rebind) {
            $this->inbox()->materialize($receiver, function ($current, $context) use ($notice, $ordinary, $rebind): null {
                $query = app(BoardPackAccessService::class)->visibleNotificationQuery($current, false, $context);
                DB::table('notifications')->where('id', $notice->id)->update($rebind);
                $this->assertSame(1, (clone $query)->count());
                $this->assertSame([$ordinary->id], (clone $query)->get()->pluck('id')->all());
                return null;
            });
            DB::table('notifications')->where('id', $notice->id)->update([
                'notifiable_type' => $receiver->getMorphClass(), 'notifiable_id' => $receiver->id,
            ]);
        }
        $this->allow($receiver, 'hr.wellbeing.view', false);
        [$result, $queries] = $this->capturedQueries(fn () => $this->inbox()->materialize($receiver,
            function ($current, $context) use ($notice, $ordinary): array {
                $query = app(BoardPackAccessService::class)->visibleNotificationQuery($current, false, $context);
                DB::table('notifications')->where('id', $notice->id)->update([
                    'type' => \App\Notifications\ShiftTaskDueNotification::class,
                    'data' => json_encode(['title' => 'FOREIGN-MEDICAL-BODY'], JSON_THROW_ON_ERROR),
                ]);
                $this->assertSame(1, (clone $query)->count());
                return $query->get()->pluck('id')->all();
            }));
        $this->assertSame([$ordinary->id], $result);
        foreach ($queries as $entry) { $this->assertStringNotContainsString('substance_exposure_records', strtolower($entry['query'])); }
        $this->assertNull($notice->fresh()->read_at);
    }

    public function test_current_root_tuple_revision_and_unversioned_facts_changed_after_discovery_deny_before_count_body_and_read(): void
    {
        [$root, $receiver, $notice] = $this->notified(); $ordinary = $this->ordinary($receiver);
        $before = (array) DB::table('substance_exposure_records')->where('id', $root->id)->first();
        $mutations = [
            ['site_id' => $this->foreignSite->id], ['user_id' => $this->manager->id],
            ['source_revision' => $root->source_revision + 1], ['source_schema_version' => 0],
            ['deleted_at' => now()], ['symptoms' => 'FOREIGN-UNVERSIONED-MEDICAL-FACT'],
            ['original_context' => json_encode([...$root->original_context, 'site_id' => $this->foreignSite->id], JSON_THROW_ON_ERROR)],
        ];
        foreach ($mutations as $mutation) {
            $this->refuseAfterDiscovery($receiver, $notice, $ordinary,
                fn () => DB::table('substance_exposure_records')->where('id', $root->id)->update($mutation));
            DB::table('substance_exposure_records')->where('id', $root->id)->update($before);
            $this->assertEqualsCanonicalizing([$notice->id, $ordinary->id], $this->visible($receiver));
        }
    }

    public function test_delivery_reparent_version_recipient_actor_and_membership_changes_after_discovery_are_not_authority(): void
    {
        [$root, $receiver, $notice, $delivery] = $this->notified(); $ordinary = $this->ordinary($receiver);
        [$foreignRoot, $foreignReceiver, $foreignNotice, $foreignDelivery] = $this->notified();
        $before = (array) DB::table('chemical_exposure_source_deliveries')->where('id', $delivery->id)->first();
        foreach ([['exposure_id' => $foreignRoot->id], ['source_version_id' => $foreignDelivery->source_version_id],
            ['recipient_id' => $foreignReceiver->id], ['actor_id' => $foreignReceiver->id], ['kind' => 'not_followup'],
            ['membership' => json_encode([...$delivery->membership, 'worker_id' => $foreignRoot->user_id], JSON_THROW_ON_ERROR)]] as $mutation) {
            $this->refuseAfterDiscovery($receiver, $notice, $ordinary,
                fn () => DB::table('chemical_exposure_source_deliveries')->where('id', $delivery->id)->update($mutation));
            DB::table('chemical_exposure_source_deliveries')->where('id', $delivery->id)->update($before);
        }
        $this->assertEqualsCanonicalizing([$notice->id, $ordinary->id], $this->visible($receiver));
        $this->assertSame([$foreignNotice->id], $this->visible($foreignReceiver));
    }

    public function test_followup_assignee_completion_provenance_and_private_fact_changes_after_discovery_are_concealed(): void
    {
        [$root, $receiver, $notice, $delivery, $accepted] = $this->notified(); $ordinary = $this->ordinary($receiver);
        $foreignRoot = $this->exposure();
        $before = (array) DB::table('chemical_exposure_followups')->where('id', $accepted['source_id'])->first();
        foreach ([['exposure_id' => $foreignRoot->id], ['assigned_to' => $this->manager->id], ['completed_at' => now()],
            ['source_schema_version' => 0], ['source_revision' => $root->source_revision + 1],
            ['instruction' => 'FOREIGN-UNVERSIONED-PRIVATE-INSTRUCTION']] as $mutation) {
            $this->refuseAfterDiscovery($receiver, $notice, $ordinary,
                fn () => DB::table('chemical_exposure_followups')->where('id', $accepted['source_id'])->update($mutation));
            DB::table('chemical_exposure_followups')->where('id', $accepted['source_id'])->update($before);
        }
        $this->assertEqualsCanonicalizing([$notice->id, $ordinary->id], $this->visible($receiver));
        $this->assertNull(ChemicalExposureFollowup::findOrFail($accepted['source_id'])->completed_at);
    }

    public function test_version_acceptance_and_original_incident_client_context_mutations_after_discovery_deny_before_payload(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $incident = ClientIncident::factory()->create(['site_id' => $this->site->id, 'client_id' => $client->id,
            'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null, 'reported_by' => $this->manager->id]);
        [$root, $receiver, $notice, $delivery] = $this->notified($incident); $ordinary = $this->ordinary($receiver);
        $version = (array) DB::table('chemical_exposure_source_versions')->where('id', $delivery->source_version_id)->first();
        $receipt = (array) DB::table('chemical_exposure_command_receipts')->where('source_version_id', $delivery->source_version_id)->sole();
        $audit = (array) DB::table('audit_logs')->where('id', $receipt['audit_log_id'])->first();
        $content = json_decode($version['content'], true, flags: JSON_THROW_ON_ERROR);
        foreach ([
            ['chemical_exposure_source_versions', $version['id'], ['worker_id' => $this->manager->id], $version],
            ['chemical_exposure_source_versions', $version['id'], ['content' => json_encode([...$content, 'foreign_copy' => 'FOREIGN-MEDICAL-BODY'], JSON_THROW_ON_ERROR)], $version],
            ['chemical_exposure_command_receipts', $receipt['id'], ['actor_id' => $receiver->id], $receipt],
            ['audit_logs', $audit['id'], ['action' => 'unrelated.semantic.action'], $audit],
            ['client_incidents', $incident->id, ['client_id' => Client::factory()->create(['site_id' => $this->site->id])->id], ['client_id' => $client->id]],
            ['client_incidents', $incident->id, ['site_id' => $this->foreignSite->id], ['site_id' => $this->site->id]],
            ['clients', $client->id, ['site_id' => $this->foreignSite->id], ['site_id' => $this->site->id]],
            ['clients', $client->id, ['deleted_at' => now()], ['deleted_at' => null]],
            ['sites', $this->site->id, ['is_active' => false], ['is_active' => true]],
        ] as [$table, $id, $mutation, $restore]) {
            $this->refuseAfterDiscovery($receiver, $notice, $ordinary, fn () => DB::table($table)->where('id', $id)->update($mutation));
            DB::table($table)->where('id', $id)->update($restore);
            $this->assertContains($notice->id, $this->visible($receiver));
        }
        $receiver->hrEmployeeProfile()->update(['primary_site_id' => $this->foreignSite->id]);
        $this->assertSame([$ordinary->id], $this->visible($receiver));
        $receiver->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
        $this->assertContains($notice->id, $this->visible($receiver));
    }

    public function test_independently_approved_nondefault_site_notice_remains_readable_without_cross_recipient_or_site_grant(): void
    {
        [$ownRoot, $ownReceiver, $ownNotice] = $this->notified();
        $manager = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view'], $this->foreignSite);
        $worker = $this->staff([], $this->foreignSite);
        $receiver = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view'], $this->foreignSite);
        $created = $this->exposureService()->create($this->catalogue()->id, $manager, [
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $worker->id,
            'site_id' => $this->foreignSite->id, 'exposed_at' => '2026-10-12T00:00:00Z', 'exposure_type' => 'skin_contact',
        ]);
        $root = SubstanceExposureRecord::findOrFail($created['root_id']);
        $accepted = $this->exposureService()->assignFollowup($root, $manager, $this->privateCommand($root,
            ['assigned_to' => $receiver->id, 'instruction' => 'Actual nondefault Site work', 'due_at' => '2026-10-14T01:00:00Z']));
        $delivery = \App\Models\ChemicalExposureSourceDelivery::where('kind', 'followup')->where('source_version_id', $accepted['source_version_id'])->sole();
        app(ChemicalExposureFollowupDestinationService::class)->deliverForViewer($manager, $delivery->id);
        $notice = DatabaseNotification::where('type', ChemicalExposureFollowupAssignedNotification::class)
            ->where('notifiable_id', $receiver->id)->where('data->delivery_id', $delivery->id)->sole();
        $this->assertSame([$notice->id], $this->visible($receiver));
        $this->assertSame([$ownNotice->id], $this->visible($ownReceiver));
        $receiver->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
        $this->assertSame([], $this->visible($receiver));
        $receiver->hrEmployeeProfile()->update(['primary_site_id' => $this->foreignSite->id]);
        $this->assertSame([$notice->id], $this->visible($receiver));
        $this->assertSame('Assigned follow-up', $this->data($receiver, $notice)['title']);
    }

    public function test_admission_descriptor_is_detached_proof_only_and_requires_the_supplied_actor_preparation_and_lifetime(): void
    {
        [$root, $receiver, $notice, $delivery] = $this->notified(); $escaped = null;
        HealthSafetyReadContext::within($receiver, ChemicalExposureNotificationAccessService::PERMISSIONS,
            function ($context) use ($root, $receiver, $notice, $delivery, &$escaped): void {
                $escaped = $context;
                $descriptor = app(ChemicalExposureFollowupDestinationService::class)->notificationAdmissionInContext($receiver, $notice->id, $context);
                $this->assertSame($notice->id, $descriptor['notification_id']);
                $this->assertSame($delivery->id, $descriptor['delivery_id']);
                $this->assertSame($root->id, $descriptor['membership']['exposure_id']);
                $this->assertNotEmpty($descriptor['binding_guards']);
                foreach ($descriptor['binding_guards'] as $guard) { $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $guard['fingerprint']); }
                $serialized = json_encode($descriptor, JSON_THROW_ON_ERROR);
                foreach (['PRIVATE-NOTIFICATION', 'symptoms', 'instruction', 'actor_snapshot', 'accepted_result', 'file_path'] as $private) {
                    $this->assertStringNotContainsString($private, $serialized);
                }
                $this->integrity(fn () => app(ChemicalExposureFollowupDestinationService::class)
                    ->notificationAdmissionInContext($this->manager, $notice->id, $context));
            });
        $this->integrity(fn () => app(ChemicalExposureFollowupDestinationService::class)->notificationAdmissionInContext($receiver, $notice->id, $escaped));
        HealthSafetyReadContext::within($receiver, ['hazards.view', 'hr.wellbeing.view'], function ($context) use ($receiver, $notice): void {
            $this->integrity(fn () => app(ChemicalExposureFollowupDestinationService::class)->notificationAdmissionInContext($receiver, $notice->id, $context));
        });
    }

    public function test_new_source_validation_refusal_is_per_notice_and_does_not_abort_a_genuinely_delivered_healthy_neighbour(): void
    {
        [$root, $receiver, $notice] = $this->notified();
        $healthy = $this->exposure();
        $accepted = $this->exposureService()->assignFollowup($healthy, $this->manager, $this->privateCommand($healthy,
            ['assigned_to' => $receiver->id, 'instruction' => 'Actual healthy source work', 'due_at' => '2026-10-14T01:00:00Z']));
        $delivery = \App\Models\ChemicalExposureSourceDelivery::where('kind', 'followup')->where('source_version_id', $accepted['source_version_id'])->sole();
        app(ChemicalExposureFollowupDestinationService::class)->deliverForViewer($this->manager, $delivery->id);
        $other = DatabaseNotification::where('type', ChemicalExposureFollowupAssignedNotification::class)->where('notifiable_id', $receiver->id)
            ->where('data->delivery_id', $delivery->id)->sole();
        DB::table('substance_exposure_records')->where('id', $root->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        try { $this->proof($root, $receiver); $this->fail('Expected source-only provenance refusal.'); }
        catch (\Illuminate\Validation\ValidationException $error) { $this->assertSame(['source'], array_keys($error->errors())); }
        $this->assertSame([$other->id], $this->visible($receiver));
        $this->assertSame('Assigned follow-up', $this->data($receiver, $other)['title']);
        $this->assertNull($notice->fresh()->read_at);
    }

    public function test_genuine_unlinked_legacy_origin_can_receive_typed_named_work_without_inventing_original_client_context(): void
    {
        $receiver = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']);
        $root = SubstanceExposureRecord::create(['hazardous_substance_id' => $this->catalogue()->id, 'user_id' => $this->worker->id,
            'site_id' => $this->site->id, 'exposed_at' => '2026-10-12T00:00:00Z', 'exposure_type' => 'skin_contact',
            'related_incident_id' => null, 'created_by' => $this->manager->id, 'symptoms' => 'PRIVATE-RETAINED-LEGACY-FACT']);
        $accepted = $this->exposureService()->assignFollowup($root, $this->manager, $this->privateCommand($root,
            ['assigned_to' => $receiver->id, 'instruction' => 'New actual work, old occurrence context remains unknown', 'due_at' => '2026-10-14T01:00:00Z']));
        $delivery = \App\Models\ChemicalExposureSourceDelivery::where('kind', 'followup')->where('source_version_id', $accepted['source_version_id'])->sole();
        app(ChemicalExposureFollowupDestinationService::class)->deliverForViewer($this->manager, $delivery->id);
        $notice = DatabaseNotification::where('type', ChemicalExposureFollowupAssignedNotification::class)->where('notifiable_id', $receiver->id)
            ->where('data->delivery_id', $delivery->id)->sole();
        $this->assertNull($root->fresh()->original_context); $this->assertNull($delivery->membership['original_context']);
        $this->assertSame([$notice->id], $this->visible($receiver));
        HealthSafetyReadContext::within($receiver, ChemicalExposureNotificationAccessService::PERMISSIONS, function ($context) use ($receiver, $notice): void {
            $descriptor = app(ChemicalExposureFollowupDestinationService::class)->notificationAdmissionInContext($receiver, $notice->id, $context);
            $this->assertNull($descriptor['membership']['original_context']);
            $this->assertNotContains('incident', array_column($descriptor['binding_guards'], 'family'));
            $this->assertNotContains('client', array_column($descriptor['binding_guards'], 'family'));
        });
        $this->allow($receiver, 'hr.wellbeing.view', false); $this->assertSame([], $this->visible($receiver));
        $this->allow($receiver, 'hr.wellbeing.view'); $this->assertSame([$notice->id], $this->visible($receiver));
    }

    public function test_payload_damage_and_archived_or_rebound_source_are_concealed_without_hiding_healthy_neighbour(): void
    {
        [$root, $receiver, $notice] = $this->notified(); $ordinary = $this->ordinary($receiver);
        $original = $notice->data; $bad = [...$original, 'body' => 'PRIVATE-INJECTED-COPY'];
        DB::table('notifications')->where('id', $notice->id)->update(['data' => json_encode($bad, JSON_THROW_ON_ERROR)]);
        $this->assertSame([$ordinary->id], $this->visible($receiver));
        DB::table('notifications')->where('id', $notice->id)->update(['data' => json_encode($original, JSON_THROW_ON_ERROR)]);
        DB::table('substance_exposure_records')->where('id', $root->id)->update(['site_id' => $this->foreignSite->id]);
        $this->assertSame([$ordinary->id], $this->visible($receiver));
        DB::table('substance_exposure_records')->where('id', $root->id)->update(['site_id' => $this->site->id, 'deleted_at' => now()]);
        $this->assertSame([$ordinary->id], $this->visible($receiver));
    }

    public function test_original_incident_client_withdraw_restore_and_retarget_remain_private_before_notice_payload(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $incident = ClientIncident::factory()->create(['site_id' => $this->site->id, 'client_id' => $client->id,
            'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null, 'reported_by' => $this->manager->id]);
        [$root, $receiver, $notice] = $this->notified($incident); $ordinary = $this->ordinary($receiver);
        $receiver->load('roles.permissions', 'permissionOverrides');
        foreach (['incidents.viewAny', 'clients.viewAny'] as $key) {
            $this->allow($receiver, $key, false); $this->assertSame([$ordinary->id], $this->visible($receiver));
            $this->allow($receiver, $key); $this->assertContains($notice->id, $this->visible($receiver));
        }
        $incident->update(['client_id' => Client::factory()->create(['site_id' => $this->site->id])->id]);
        $this->assertSame([$ordinary->id], $this->visible($receiver));
    }

    public function test_real_transfer_hides_old_recipient_and_admits_only_actual_successor_inbox(): void
    {
        [$root, $receiver, $notice, $delivery, $accepted] = $this->notified();
        $next = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']);
        $result = $this->exposureService()->reassignFollowup($root, $accepted['source_id'], $this->manager,
            $this->privateCommand($root, ['assigned_to' => $next->id, 'reason' => 'Actual handover of named work']));
        $successor = \App\Models\ChemicalExposureSourceDelivery::where('kind', 'followup')->where('source_version_id', $result['source_version_id'])->sole();
        $this->assertSame([], $this->visible($receiver)); $this->assertSame([], $this->visible($next));
        app(ChemicalExposureFollowupDestinationService::class)->deliverForViewer($this->manager, $successor->id);
        $new = DatabaseNotification::where('type', ChemicalExposureFollowupAssignedNotification::class)->where('notifiable_id', $next->id)->sole();
        $this->assertSame([$new->id], $this->visible($next)); $this->assertSame([], $this->visible($receiver));
        $this->assertNull($notice->fresh()->read_at); $this->assertNull(ChemicalExposureFollowup::findOrFail($accepted['source_id'])->completed_at);
    }

    public function test_foreign_accepted_root_or_followup_lineage_rebound_after_discovery_denies_count_body_and_read_only_for_that_notice(): void
    {
        [$root, $receiver, $notice, , $accepted] = $this->notified();
        $healthyRoot = $this->exposure();
        $healthyAcceptance = $this->exposureService()->assignFollowup($healthyRoot, $this->manager, $this->privateCommand($healthyRoot,
            ['assigned_to' => $receiver->id, 'instruction' => 'Healthy independently accepted private work', 'due_at' => '2026-10-14T01:00:00Z']));
        $healthyDelivery = \App\Models\ChemicalExposureSourceDelivery::where('kind', 'followup')
            ->where('source_version_id', $healthyAcceptance['source_version_id'])->sole();
        app(ChemicalExposureFollowupDestinationService::class)->deliverForViewer($this->manager, $healthyDelivery->id);
        $healthy = DatabaseNotification::where('type', ChemicalExposureFollowupAssignedNotification::class)
            ->where('notifiable_id', $receiver->id)->where('data->delivery_id', $healthyDelivery->id)->sole();
        $ordinary = $this->ordinary($receiver);
        [$foreignRoot, $foreignReceiver, $foreignNotice, $foreignDelivery, $foreignAccepted] = $this->notified();
        $foreignVersion = (array) DB::table('chemical_exposure_source_versions')->where('id', $foreignDelivery->source_version_id)->sole();
        $foreignReceipt = (array) DB::table('chemical_exposure_command_receipts')->where('source_version_id', $foreignVersion['id'])->sole();
        $foreignRootVersion = (array) DB::table('chemical_exposure_source_versions')->where('source_type', 'exposure')
            ->where('source_id', $foreignRoot->id)->sole();
        $foreignRootReceipt = (array) DB::table('chemical_exposure_command_receipts')->where('source_version_id', $foreignRootVersion['id'])->sole();
        foreach ([
            ['chemical_exposure_source_versions', $foreignVersion, $accepted['source_id']],
            ['chemical_exposure_command_receipts', $foreignReceipt, $accepted['source_id']],
            ['chemical_exposure_source_versions', $foreignRootVersion, $root->id],
            ['chemical_exposure_command_receipts', $foreignRootReceipt, $root->id],
        ] as [$table, $foreignRow, $targetId]) {
            try {
                $this->inbox()->materialize($receiver, function ($current, $context) use ($targetId, $notice, $healthy, $ordinary, $table, $foreignRow, $foreignRoot): null {
                    $query = app(BoardPackAccessService::class)->visibleNotificationQuery($current, false, $context);
                    // Descriptors, source preflight and body proofs are already complete. Rebind a
                    // genuinely accepted other root's identity without changing any saved guards.
                    DB::table($table)->where('id', $foreignRow['id'])->update(['source_id' => $targetId]);
                    $bodies = []; $foreignBodies = [];
                    DatabaseNotification::retrieved(function ($row) use (&$bodies): void {
                        if (array_key_exists('data', $row->getAttributes())) { $bodies[] = (string) $row->id; }
                    });
                    \App\Models\ChemicalExposureSourceVersion::retrieved(function ($row) use ($foreignRoot, &$foreignBodies): void {
                        if ((int) $row->exposure_id === (int) $foreignRoot->id && array_key_exists('content', $row->getAttributes())) {
                            $foreignBodies[] = (int) $row->id;
                        }
                    });
                    try {
                        $this->assertSame(2, (clone $query)->count());
                        $this->assertEqualsCanonicalizing([$healthy->id, $ordinary->id], (clone $query)->get()->pluck('id')->all());
                        $this->assertEqualsCanonicalizing([$healthy->id, $ordinary->id], $bodies, 'The conflicting notice body must not be selected.');
                        $this->assertNull((clone $query)->where('notifications.id', $notice->id)->first());
                        $this->assertSame(0, (clone $query)->where('notifications.id', $notice->id)->update(['read_at' => now()]));
                        $this->assertSame([], $foreignBodies, 'The final predicate must inspect identity only, never foreign medical version content.');
                    } finally {
                        DatabaseNotification::flushEventListeners(); \App\Models\ChemicalExposureSourceVersion::flushEventListeners();
                    }
                    return null;
                });
                $this->assertNull($notice->fresh()->read_at);
            } finally { DB::table($table)->where('id', $foreignRow['id'])->update(['source_id' => $foreignRow['source_id']]); }
            $this->assertEqualsCanonicalizing([$notice->id, $healthy->id, $ordinary->id], $this->visible($receiver));
            $this->assertSame([$foreignNotice->id], $this->visible($foreignReceiver));
            $this->assertSame('Assigned follow-up', $this->data($receiver, $notice)['title']);
        }
    }

    public function test_reading_is_an_inbox_flag_only_and_suppressed_private_write_is_not_confirmed(): void
    {
        [$root, $receiver, $notice, $delivery, $accepted] = $this->notified();
        $version = $root->fresh()->source_revision;
        $this->inbox()->materialize($receiver, function ($current, $context) use ($notice): void {
            $row = app(BoardPackAccessService::class)->visibleNotificationQuery($current, false, $context)->whereKey($notice->id)->firstOrFail();
            $row->markAsRead(); $this->inbox()->assertReadPersisted($row, $current, $context);
        });
        $this->assertNotNull($notice->fresh()->read_at); $this->assertSame($version, $root->fresh()->source_revision);
        $this->assertNull(ChemicalExposureFollowup::findOrFail($accepted['source_id'])->completed_at);
        DB::table('notifications')->where('id', $notice->id)->update(['read_at' => null]);
        DatabaseNotification::updating(fn () => false);
        try {
            $this->integrity(fn () => $this->inbox()->materialize($receiver, function ($current, $context) use ($notice): void {
                $row = app(BoardPackAccessService::class)->visibleNotificationQuery($current, false, $context)->whereKey($notice->id)->firstOrFail();
                $row->markAsRead(); $this->inbox()->assertReadPersisted($row, $current, $context);
            }));
        } finally { DatabaseNotification::flushEventListeners(); }
        $this->assertNull($notice->fresh()->read_at);
    }

    public function test_inbox_optout_after_accepted_delivery_does_not_erase_saved_notice_or_start_external_delivery(): void
    {
        [$root, $receiver, $notice, $delivery] = $this->notified();
        UserNotificationPreference::create(['user_id' => $receiver->id, 'key' => ChemicalExposureFollowupDestinationService::EVENT_KEY,
            'enabled' => false, 'channel_inapp' => false, 'channel_email' => false, 'channel_push' => false]);
        $this->assertSame([$notice->id], $this->visible($receiver));
        $confirmation = app(ChemicalExposureFollowupDestinationService::class)->confirmationForViewer($receiver, $delivery->id);
        $this->assertSame('inbox_present', $confirmation['destination_confirmation']);
        $this->assertSame('not_attempted', $confirmation['external_delivery']); $this->assertFalse($confirmation['actioned']);
    }

    public function test_unrelated_recipient_without_hr_keeps_ordinary_notifications_and_prepared_flags_are_not_grants(): void
    {
        $user = \App\Models\User::factory()->create(['approved_at' => now(), 'external_clinical_account' => false]);
        $ordinary = $this->ordinary($user);
        $this->assertSame([$ordinary->id], $this->visible($user));
        $this->inbox()->materialize($user, function ($current): null {
            $this->assertFalse($current->canDo('hr.wellbeing.view')); $this->assertFalse($current->canDo('governance.packs.manage'));
            return null;
        });
    }

    public function test_saved_notice_read_does_not_grant_new_assignment_or_completion_after_manage_withdrawal(): void
    {
        [$root, $receiver, $notice, $delivery, $accepted] = $this->notified();
        $this->allow($receiver, 'hazards.manage', false);
        $this->assertSame([$notice->id], $this->visible($receiver), 'Actual destination reader requires current dual read, not a new mutation grant.');
        $this->denial(fn () => $this->exposureService()->completeFollowup($root, $accepted['source_id'], $receiver,
            $this->privateCommand($root, ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'Requested actual completion'])));
        $this->assertNull(ChemicalExposureFollowup::findOrFail($accepted['source_id'])->completed_at);
        $this->assertNull($notice->fresh()->read_at);
    }

    public function test_corrupt_private_notice_does_not_hide_a_genuinely_delivered_healthy_private_neighbour(): void
    {
        [$root, $receiver, $notice] = $this->notified();
        $healthy = $this->exposure();
        $accepted = $this->exposureService()->assignFollowup($healthy, $this->manager, $this->privateCommand($healthy,
            ['assigned_to' => $receiver->id, 'instruction' => 'Another actual source-owned instruction', 'due_at' => '2026-10-14T01:00:00Z']));
        $delivery = \App\Models\ChemicalExposureSourceDelivery::where('kind', 'followup')->where('source_version_id', $accepted['source_version_id'])->sole();
        app(ChemicalExposureFollowupDestinationService::class)->deliverForViewer($this->manager, $delivery->id);
        $other = DatabaseNotification::where('type', ChemicalExposureFollowupAssignedNotification::class)->where('notifiable_id', $receiver->id)
            ->where('data->delivery_id', $delivery->id)->sole();
        DB::table('notifications')->where('id', $notice->id)->update(['data' => json_encode([...$notice->data, 'body' => 'PRIVATE-DAMAGED-NEIGHBOUR'], JSON_THROW_ON_ERROR)]);
        $this->assertSame([$other->id], $this->visible($receiver));
        $this->assertSame('Assigned follow-up', $this->data($receiver, $other)['title']);
        $this->assertNull($notice->fresh()->read_at);
    }
}
