<?php

namespace Tests\Feature\HealthSafety;

use App\Models\ChemicalExposureFollowup;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\Client;
use App\Services\HealthSafety\ChemicalExposureCommandAccess;
use App\Services\HealthSafety\ChemicalExposureFollowupDestinationService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\Tasks\Contracts\AssignableTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\SplittableTaskProvider;
use App\Services\Tasks\Providers\ChemicalExposureFollowupProvider;
use App\Services\Tasks\TaskAggregator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Whole source read/proof integration. Companion B/aggregator proposals remain UNAPPLIED; every check UNRUN. */
final class ChemicalExposureTaskPrivacyTest extends ChemicalExposureTaskReadTestCase
{
    private function priorOriginWork(): array
    {
        $this->manager->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        $this->worker->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        $receiver = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view'], $this->foreignSite);
        $receiver->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->site->id]]);
        $incident = $this->linkedIncident();
        foreach (['incidents.viewAny', 'clients.viewAny'] as $key) { $this->allow($this->manager, $key); $this->allow($receiver, $key); }
        $root = $this->exposure(['related_incident_id' => $incident->id, 'medical_attention_sought' => true]);
        $original = (array) DB::table('chemical_exposure_source_versions')->where('exposure_id', $root->id)->where('aggregate_revision', 1)->sole();
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['site_id' => $this->foreignSite->id, 'related_incident_id' => null, 'reason' => 'Correct occurrence Site before any child exists']));
        [$root, , $child] = $this->assignedWork(null, $receiver, $root);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['circumstances' => 'Later accepted facts at Site B', 'reason' => 'Record later known facts']));
        $root = $root->fresh();
        $healthyRoot = $this->exposure(['site_id' => $this->foreignSite->id]);
        [$healthyRoot, , $healthy] = $this->assignedWork(null, $receiver, $healthyRoot);
        $latest = ChemicalExposureSourceVersion::where('exposure_id', $root->id)->orderByDesc('aggregate_revision')->firstOrFail();
        $this->assertSame($this->foreignSite->id, $latest->site_id);
        $this->assertSame($this->foreignSite->id, ChemicalExposureSourceVersion::where('source_type', 'followup')->where('source_id', $child->id)->sole()->site_id);
        $this->assertCount(2, $this->ownTasks()->itemsFor($receiver));
        return [$root, $receiver, $child, $healthy, $incident, $original];
    }

    public function test_default_registry_and_all_three_fresh_origins_include_only_current_private_named_work(): void
    {
        [$root, $receiver, $child] = $this->assignedWork();
        $registry = new TaskAggregator;
        $this->assertInstanceOf(ChemicalExposureFollowupProvider::class, $registry->providerFor('chemical_exposure_followup'));
        $this->assertContains('chemical_exposure_followup', array_column($registry->sourcesFor($receiver), 'key'));
        $items = $registry->itemsFor($receiver, ['sources' => ['chemical_exposure_followup']]);
        $this->assertSame(['chemical_exposure_followup-'.$child->id], array_column($items, 'id'));
        $exact = $registry->findItemFor($receiver, 'chemical_exposure_followup', $child->id);
        $this->assertSame($items[0]->toArray(), $exact?->toArray());
        $this->assertSame(['view' => true, 'badge' => 1, 'degraded' => false], $this->ownTasks()->navigationBadgeFor($receiver));
        $this->assertSame(['id' => $receiver->id, 'name' => 'You'], $exact->assignee);
        $this->assertTrue($exact->restricted); $this->assertNull($exact->description); $this->assertNull($exact->client); $this->assertNull($exact->site);
        $this->assertNull($exact->link); $this->assertNotEmpty($exact->actionHelp);
        $this->assertStringNotContainsString('PRIVATE-', json_encode($exact->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_saved_role_private_withdrawal_and_restoration_are_fresh_for_feed_exact_lookup_source_choices_and_navigation(): void
    {
        [$root, $receiver, $child] = $this->assignedWork(); $aggregator = $this->ownTasks();
        $receiver->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        foreach (['hazards.view', 'hr.wellbeing.view'] as $key) {
            $this->assertCount(1, $aggregator->itemsFor($receiver)); $this->allow($receiver, $key, false);
            [$items, $queries] = $this->capturedQueries(fn () => $aggregator->itemsFor($receiver));
            $this->assertSame([], $items); $this->assertNull($aggregator->findItemFor($receiver, 'chemical_exposure_followup', $child->id));
            $this->assertSame([], $aggregator->sourcesFor($receiver));
            $this->assertSame(['view' => false, 'badge' => 0, 'degraded' => false], $aggregator->navigationBadgeFor($receiver));
            foreach ($queries as $entry) {
                $this->assertStringNotContainsString('substance_exposure_records', strtolower($entry['query']));
                $this->assertStringNotContainsString('chemical_exposure_followups', strtolower($entry['query']));
            }
            $this->allow($receiver, $key); $this->assertCount(1, $aggregator->itemsFor($receiver));
        }
    }

    public function test_current_actor_site_hr_and_approved_site_state_withdraw_and_restore_without_a_new_permission_grant(): void
    {
        [$root, $receiver, $child] = $this->assignedWork(); $aggregator = $this->ownTasks();
        $receiver->load('hrEmployeeProfile');
        foreach ([['primary_site_id' => $this->foreignSite->id], ['is_active' => false]] as $change) {
            $receiver->hrEmployeeProfile()->update($change);
            $this->assertSame([], $aggregator->itemsFor($receiver));
            $this->assertSame(0, $aggregator->navigationBadgeFor($receiver)['badge']);
            $receiver->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id, 'is_active' => true]);
            $this->assertCount(1, $aggregator->itemsFor($receiver));
        }
        $this->site->update(['is_active' => false]); $this->assertSame([], $aggregator->itemsFor($receiver));
        $this->site->update(['is_active' => true]); $this->assertCount(1, $aggregator->itemsFor($receiver));
    }

    public function test_another_private_reader_or_capturer_does_not_own_the_named_task_or_the_you_label(): void
    {
        [$root, $receiver, $child] = $this->assignedWork();
        foreach ([$this->manager, $this->reporter, $this->staff(['hazards.view', 'hr.wellbeing.view'])] as $other) {
            $this->assertSame([], $this->ownTasks()->itemsFor($other));
            $this->assertNull($this->ownTasks()->findItemFor($other, 'chemical_exposure_followup', $child->id));
        }
        DB::table('chemical_exposure_followups')->where('id', $child->id)->update(['assigned_to' => $this->manager->id]);
        $this->assertSame([], $this->ownTasks()->itemsFor($receiver));
        $this->assertSame([], $this->ownTasks()->itemsFor($this->manager), 'External reassignment is not an accepted child revision.');
    }

    public function test_current_original_incident_and_client_permissions_withdraw_and_restore_before_any_task_projection(): void
    {
        $incident = $this->linkedIncident(); [$root, $receiver, $child] = $this->assignedWork($incident);
        $receiver->load('permissionOverrides');
        foreach (['incidents.viewAny', 'clients.viewAny'] as $key) {
            $this->allow($receiver, $key, false); $this->assertSame([], $this->ownTasks()->itemsFor($receiver));
            $this->assertNull($this->ownTasks()->findItemFor($receiver, 'chemical_exposure_followup', $child->id));
            $this->allow($receiver, $key); $this->assertCount(1, $this->ownTasks()->itemsFor($receiver));
        }
        $incident->update(['client_id' => Client::factory()->create(['site_id' => $this->site->id])->id]);
        $this->assertSame([], $this->ownTasks()->itemsFor($receiver));
    }

    public function test_newer_accepted_aggregate_keeps_unchanged_open_child_even_after_its_inbox_delivery_is_superseded(): void
    {
        [$root, $receiver, $child, $delivery] = $this->assignedWork();
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'A separate occurrence fact changed', 'circumstances' => 'Later accepted fact']));
        $superseded = app(ChemicalExposureFollowupDestinationService::class)->deliverForViewer($this->manager, $delivery->id);
        $this->assertSame('superseded', $superseded['state']);
        $this->assertGreaterThan($child->source_revision, $root->fresh()->source_revision);
        $this->assertSame(['chemical_exposure_followup-'.$child->id], array_column($this->ownTasks()->itemsFor($receiver), 'id'));
        $this->assertSame(1, $this->ownTasks()->navigationBadgeFor($receiver)['badge']);
        $this->assertNotNull($this->ownTasks()->findItemFor($receiver, 'chemical_exposure_followup', $child->id));
    }

    public function test_actual_completion_is_source_owned_and_done_exact_lookup_preserves_history_without_open_badge(): void
    {
        [$root, $receiver, $child] = $this->assignedWork();
        $this->exposureService()->completeFollowup($root, $child->id, $receiver, $this->privateCommand($root,
            ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'PRIVATE-ACTUAL-COMPLETION']));
        $this->assertSame([], $this->ownTasks()->itemsFor($receiver)); $this->assertSame(0, $this->ownTasks()->navigationBadgeFor($receiver)['badge']);
        $done = $this->ownTasks()->findItemFor($receiver, 'chemical_exposure_followup', $child->id);
        $this->assertSame('done', $done?->bucket); $this->assertSame('completed', $done?->status);
        $this->assertStringNotContainsString('PRIVATE-', json_encode($done?->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_actual_transfer_retains_child_history_and_only_new_current_named_recipient_sees_you(): void
    {
        [$root, $receiver, $child] = $this->assignedWork();
        $next = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']);
        $this->exposureService()->reassignFollowup($root, $child->id, $this->manager, $this->privateCommand($root,
            ['assigned_to' => $next->id, 'reason' => 'Actual named ownership transfer']));
        $this->assertSame([], $this->ownTasks()->itemsFor($receiver));
        $item = $this->ownTasks()->findItemFor($next, 'chemical_exposure_followup', $child->id);
        $this->assertSame(['id' => $next->id, 'name' => 'You'], $item?->assignee);
        $this->assertSame(2, ChemicalExposureSourceVersion::where('source_type', 'followup')->where('source_id', $child->id)->count());
    }

    public function test_sealed_legacy_origin_is_per_record_unavailable_and_cannot_abort_healthy_feed_or_navigation(): void
    {
        [$root, $receiver, $child] = $this->assignedWork(); [$sealed, $legacyChild] = $this->sealedLegacy($receiver);
        try { $this->proof($sealed, $receiver); $this->fail('Expected original-source validation refusal.'); }
        catch (ValidationException $error) { $this->assertSame(['source'], array_keys($error->errors())); }
        $this->assertSame(['chemical_exposure_followup-'.$child->id], array_column($this->ownTasks()->itemsFor($receiver), 'id'));
        $this->assertSame(['view' => true, 'badge' => 1, 'degraded' => false], $this->ownTasks()->navigationBadgeFor($receiver));
        $this->assertNull($this->ownTasks()->findItemFor($receiver, 'chemical_exposure_followup', $legacyChild->id));
    }

    public function test_post_hydration_child_and_root_mutations_cannot_escape_final_source_collection_statement(): void
    {
        [$root, $receiver, $child] = $this->assignedWork(); [$healthyRoot, , $healthy] = $this->assignedWork(null, $receiver);
        $before = (array) DB::table('chemical_exposure_followups')->where('id', $child->id)->first();
        foreach ([['instruction' => 'FOREIGN-UNACCEPTED-INSTRUCTION'], ['assigned_to' => $this->manager->id], ['source_schema_version' => 0]] as $change) {
            $mutated = false;
            ChemicalExposureFollowup::retrieved(function ($row) use ($child, $change, &$mutated): void {
                if (! $mutated && (int) $row->id === (int) $child->id && array_key_exists('instruction', $row->getAttributes())) {
                    $mutated = true; DB::table('chemical_exposure_followups')->where('id', $child->id)->update($change);
                }
            });
            try {
                $items = $this->ownTasks()->itemsFor($receiver);
                $this->assertTrue($mutated); $this->assertSame(['chemical_exposure_followup-'.$healthy->id], array_column($items, 'id'));
            } finally { ChemicalExposureFollowup::flushEventListeners(); }
            DB::table('chemical_exposure_followups')->where('id', $child->id)->update($before);
        }
        $changed = false;
        ChemicalExposureFollowup::retrieved(function ($row) use ($root, $healthy, &$changed): void {
            if (! $changed && (int) $row->id === (int) $healthy->id && array_key_exists('instruction', $row->getAttributes())) {
                $changed = true; DB::table('substance_exposure_records')->where('id', $root->id)->update(['site_id' => $this->foreignSite->id]);
            }
        });
        try {
            $this->assertSame(['chemical_exposure_followup-'.$healthy->id], array_column($this->ownTasks()->itemsFor($receiver), 'id'));
            $this->assertTrue($changed);
        } finally { ChemicalExposureFollowup::flushEventListeners(); }
        DB::table('substance_exposure_records')->where('id', $root->id)->update(['site_id' => $this->site->id]);
        $this->assertCount(2, $this->ownTasks()->itemsFor($receiver));
    }

    public function test_late_root_private_fact_version_acceptance_and_original_client_changes_deny_only_the_affected_pending_row(): void
    {
        $incident = $this->linkedIncident(); [$root, $receiver, $child] = $this->assignedWork($incident);
        [$healthyRoot, , $healthy] = $this->assignedWork(null, $receiver);
        $version = (array) DB::table('chemical_exposure_source_versions')->where('source_type', 'followup')->where('source_id', $child->id)->first();
        $receipt = (array) DB::table('chemical_exposure_command_receipts')->where('source_version_id', $version['id'])->first();
        $sourceBefore = (array) DB::table('substance_exposure_records')->where('id', $root->id)->first();
        foreach ([['substance_exposure_records', $root->id, ['symptoms' => 'FOREIGN-PRIVATE-FACT'], $sourceBefore],
            ['chemical_exposure_source_versions', $version['id'], ['content_digest' => str_repeat('0', 64)], $version],
            ['chemical_exposure_command_receipts', $receipt['id'], ['actor_id' => $receiver->id], $receipt],
            ['client_incidents', $incident->id, ['client_id' => Client::factory()->create(['site_id' => $this->site->id])->id], ['client_id' => $incident->client_id]]] as [$table, $id, $change, $restore]) {
            $mutated = false;
            ChemicalExposureFollowup::retrieved(function ($row) use ($healthy, $table, $id, $change, &$mutated): void {
                if (! $mutated && (int) $row->id === (int) $healthy->id && array_key_exists('instruction', $row->getAttributes())) {
                    $mutated = true; DB::table($table)->where('id', $id)->update($change);
                }
            });
            try {
                $this->assertSame(['chemical_exposure_followup-'.$healthy->id], array_column($this->ownTasks()->itemsFor($receiver), 'id'));
                $this->assertTrue($mutated);
            } finally { ChemicalExposureFollowup::flushEventListeners(); }
            DB::table($table)->where('id', $id)->update($restore);
        }
        $this->assertCount(2, $this->ownTasks()->itemsFor($receiver));
    }

    public function test_exact_lookup_after_twenty_five_children_is_not_subject_to_source_page_or_my_day_display_caps(): void
    {
        [$root, $receiver, $first] = $this->assignedWork();
        for ($i = 0; $i < 26; $i++) { $this->assignedWork(null, $receiver, $root); }
        $aggregator = $this->ownTasks();
        $this->assertCount(27, $aggregator->itemsFor($receiver)); $this->assertSame(27, $aggregator->navigationBadgeFor($receiver)['badge']);
        $exact = $aggregator->findItemFor($receiver, 'chemical_exposure_followup', $first->id);
        $this->assertSame('chemical_exposure_followup-'.$first->id, $exact?->id); $this->assertNull($exact?->link);
        // Exact protected Workspace focus/page resolution is a separate owning transport prerequisite, never stubbed here.
    }

    public function test_original_reported_site_context_and_acceptance_remain_final_dependencies_after_a_to_b_and_later_b_work(): void
    {
        [$root, $receiver, $child, $healthy, $incident, $original] = $this->priorOriginWork();
        $receipt = (array) DB::table('chemical_exposure_command_receipts')->where('source_version_id', $original['id'])->sole();
        $audit = (array) DB::table('audit_logs')->where('id', $receipt['audit_log_id'])->sole();
        $intent = (array) DB::table('chemical_command_intents')->where('id', $receipt['intent_id'])->sole();
        $client = (array) DB::table('clients')->where('id', $incident->client_id)->sole();
        $changes = [
            ['sites', $this->site->id, ['is_active' => false], ['is_active' => true]],
            ['client_incidents', $incident->id, ['site_id' => $this->foreignSite->id], ['site_id' => $this->site->id]],
            ['clients', $client['id'], ['site_id' => $this->foreignSite->id], ['site_id' => $client['site_id']]],
            ['chemical_exposure_source_versions', $original['id'], ['content_digest' => str_repeat('0', 64)], ['content_digest' => $original['content_digest']]],
            ['chemical_exposure_command_receipts', $receipt['id'], ['actor_id' => $receiver->id], ['actor_id' => $receipt['actor_id']]],
            ['chemical_command_intents', $intent['id'], ['fingerprint' => str_repeat('0', 64)], ['fingerprint' => $intent['fingerprint']]],
            ['audit_logs', $audit['id'], ['action' => 'unrelated.acceptance'], ['action' => $audit['action']]],
        ];
        foreach ($changes as [$table, $id, $change, $restore]) {
            $mutated = false;
            ChemicalExposureFollowup::retrieved(function ($row) use ($healthy, $table, $id, $change, &$mutated): void {
                if (! $mutated && (int) $row->id === (int) $healthy->id && array_key_exists('instruction', $row->getAttributes())) {
                    $mutated = true; DB::table($table)->where('id', $id)->update($change);
                }
            });
            try {
                $rows = $this->ownTasks()->itemsFor($receiver);
                $this->assertTrue($mutated);
                // The affected earlier pending row was fully accumulated BEFORE this mutation.
                // Healthy work at B must not depend on original Site A, so remain truthful here.
                $this->assertSame(['chemical_exposure_followup-'.$healthy->id], array_column($rows, 'id'));
            } finally { ChemicalExposureFollowup::flushEventListeners(); DB::table($table)->where('id', $id)->update($restore); }
            $this->assertCount(2, $this->ownTasks()->itemsFor($receiver));
        }
    }

    public function test_new_reported_origin_or_new_aggregate_version_after_accumulation_cannot_join_the_saved_guard_set(): void
    {
        [$root, $receiver, $child, $healthy] = $this->priorOriginWork();
        $unrelated = $this->exposure(['medical_attention_sought' => false]);
        $version = (array) DB::table('chemical_exposure_source_versions')->where('exposure_id', $unrelated->id)->sole();
        $mutated = false;
        ChemicalExposureFollowup::retrieved(function ($row) use ($healthy, $root, $version, &$mutated): void {
            if (! $mutated && (int) $row->id === (int) $healthy->id && array_key_exists('instruction', $row->getAttributes())) {
                $mutated = true;
                // A real separately accepted version is externally rebound AFTER proof; its body
                // must not be treated as an admitted new reported origin for this pending source.
                DB::table('chemical_exposure_source_versions')->where('id', $version['id'])->update([
                    'exposure_id' => $root->id, 'aggregate_revision' => $root->source_revision + 1]);
            }
        });
        try {
            $this->assertSame(['chemical_exposure_followup-'.$healthy->id], array_column($this->ownTasks()->itemsFor($receiver), 'id'));
            $this->assertTrue($mutated);
        } finally {
            ChemicalExposureFollowup::flushEventListeners();
            DB::table('chemical_exposure_source_versions')->where('id', $version['id'])->update([
                'exposure_id' => $version['exposure_id'], 'aggregate_revision' => $version['aggregate_revision']]);
        }
        $this->assertCount(2, $this->ownTasks()->itemsFor($receiver));
    }

    public function test_foreign_accepted_root_or_child_lineage_rebound_after_last_preflight_is_denied_by_final_feed_and_badge(): void
    {
        [$root, $receiver, $child] = $this->assignedWork();
        [, , $healthy] = $this->assignedWork(null, $receiver);
        [$foreignRoot, $foreignReceiver, $foreign] = $this->assignedWork();
        $foreignVersion = (array) DB::table('chemical_exposure_source_versions')->where('source_type', 'followup')
            ->where('source_id', $foreign->id)->sole();
        $foreignReceipt = (array) DB::table('chemical_exposure_command_receipts')->where('source_version_id', $foreignVersion['id'])->sole();
        $foreignRootVersion = (array) DB::table('chemical_exposure_source_versions')->where('source_type', 'exposure')
            ->where('source_id', $foreignRoot->id)->sole();
        $foreignRootReceipt = (array) DB::table('chemical_exposure_command_receipts')->where('source_version_id', $foreignRootVersion['id'])->sole();
        foreach ([
            ['chemical_exposure_source_versions', $foreignVersion, $child->id],
            ['chemical_exposure_command_receipts', $foreignReceipt, $child->id],
            ['chemical_exposure_source_versions', $foreignRootVersion, $root->id],
            ['chemical_exposure_command_receipts', $foreignRootReceipt, $root->id],
        ] as [$table, $accepted, $targetId]) {
            foreach (['feed', 'navigation'] as $consumer) {
                $mutated = false; $foreignBodies = [];
                ChemicalExposureSourceVersion::retrieved(function ($row) use ($foreignRoot, &$foreignBodies): void {
                    if ((int) $row->exposure_id === (int) $foreignRoot->id && array_key_exists('content', $row->getAttributes())) {
                        $foreignBodies[] = (int) $row->id;
                    }
                });
                ChemicalExposureFollowup::retrieved(function ($row) use ($healthy, $targetId, $table, $accepted, &$mutated): void {
                    if (! $mutated && (int) $row->id === (int) $healthy->id && array_key_exists('instruction', $row->getAttributes())) {
                        // The earlier child's scalar row is already accumulated. Keep its own root,
                        // child, versions and receipts unchanged; add conflicting foreign membership.
                        $mutated = true;
                        DB::table($table)->where('id', $accepted['id'])->update(['source_id' => $targetId]);
                    }
                });
                try {
                    $aggregator = $this->ownTasks();
                    if ($consumer === 'feed') {
                        $this->assertSame(['chemical_exposure_followup-'.$healthy->id], array_column($aggregator->itemsFor($receiver), 'id'));
                    } else {
                        $this->assertSame(['view' => true, 'badge' => 1, 'degraded' => false], $aggregator->navigationBadgeFor($receiver));
                    }
                    $this->assertTrue($mutated);
                    $this->assertSame([], $foreignBodies, 'Final ownership denial must not hydrate the foreign accepted medical version.');
                    $this->assertNull($aggregator->findItemFor($receiver, 'chemical_exposure_followup', $child->id));
                } finally {
                    ChemicalExposureFollowup::flushEventListeners(); ChemicalExposureSourceVersion::flushEventListeners();
                    DB::table($table)->where('id', $accepted['id'])->update(['source_id' => $accepted['source_id']]);
                }
                $this->assertCount(2, $this->ownTasks()->itemsFor($receiver));
                $this->assertSame(2, $this->ownTasks()->navigationBadgeFor($receiver)['badge']);
                $this->assertNotNull($this->ownTasks()->findItemFor($receiver, 'chemical_exposure_followup', $child->id));
                $this->assertSame(['chemical_exposure_followup-'.$foreign->id], array_column($this->ownTasks()->itemsFor($foreignReceiver), 'id'));
            }
        }
    }

    public function test_supplied_context_reuses_current_reads_and_refuses_preparation_actor_and_lifetime(): void
    {
        [$root, $receiver, $child] = $this->assignedWork(); $captured = null;
        $permissions = array_values(array_unique([...\App\Services\HealthSafety\FirstAidAccessService::PERMISSIONS,
            ...\App\Services\HealthSafety\RestraintAccessService::PERMISSIONS, ...\App\Services\HealthSafety\InjuryAccessService::PERMISSIONS,
            ...\App\Services\HealthSafety\SafeWorkProcedureAccessService::PERMISSIONS, ...\App\Services\HealthSafety\EmergencyDrillAccessService::PERMISSIONS,
            ...ChemicalExposureCommandAccess::PERMISSIONS]));
        HealthSafetyReadContext::within($receiver, $permissions, function ($context) use ($receiver, $child, &$captured): void {
            $captured = $context; $level = DB::transactionLevel();
            $this->assertNotNull($this->ownTasks()->findItemFor($receiver, 'chemical_exposure_followup', $child->id, [], $context));
            $this->assertSame(1, $this->ownTasks()->navigationBadgeInContext($receiver, $context)['badge']);
            $this->assertSame($level, DB::transactionLevel());
            $this->integrity(fn () => $this->ownTasks()->findItemFor($this->manager, 'chemical_exposure_followup', $child->id, [], $context));
        });
        $this->integrity(fn () => $this->ownTasks()->findItemFor($receiver, 'chemical_exposure_followup', $child->id, [], $captured));
        HealthSafetyReadContext::within($receiver, ['hazards.view', 'hr.wellbeing.view'], function ($context) use ($receiver, $child): void {
            $this->integrity(fn () => $this->ownTasks()->findItemFor($receiver, 'chemical_exposure_followup', $child->id, [], $context));
        });
    }

    public function test_private_prose_worker_identity_and_generic_action_adapters_are_absent_from_search_and_serialized_task_rows(): void
    {
        [$root, $receiver, $child] = $this->assignedWork(); $aggregator = $this->ownTasks();
        $this->assertSame([], $aggregator->itemsFor($receiver, ['q' => 'PRIVATE-TASK-NAMED-INSTRUCTION']));
        $this->assertCount(1, $aggregator->itemsFor($receiver, ['q' => 'Assigned follow-up']));
        $this->assertCount(1, $aggregator->itemsFor($receiver, ['assigned' => 'me']));
        $this->assertSame([], $aggregator->itemsFor($receiver, ['assigned' => 'unassigned']));
        $provider = $aggregator->providerFor('chemical_exposure_followup');
        $this->assertNotInstanceOf(HasModelClass::class, $provider); $this->assertNotInstanceOf(AssignableTaskProvider::class, $provider);
        $this->assertNotInstanceOf(SplittableTaskProvider::class, $provider);
        $this->allow($receiver, 'hazards.manage', false); $this->assertCount(1, $aggregator->itemsFor($receiver));
        $this->denial(fn () => $this->exposureService()->completeFollowup($root, $child->id, $receiver,
            $this->privateCommand($root, ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'Denied completion'])));
    }

    public function test_supplied_active_read_cannot_silently_switch_to_a_second_physical_connection(): void
    {
        [$root, $receiver] = $this->assignedWork();
        $primary = config('database.default');
        config(['database.connections.chemical_task_foreign' => config('database.connections.'.$primary)]);
        HealthSafetyReadContext::within($receiver, ChemicalExposureCommandAccess::PERMISSIONS, function ($context) use ($receiver, $primary): void {
            config(['database.default' => 'chemical_task_foreign']);
            try {
                $this->integrity(fn () => app(ChemicalExposureFollowupProvider::class)->authorizedTasksInContext($receiver, [], $context));
            } finally { config(['database.default' => $primary]); DB::purge('chemical_task_foreign'); }
        });
    }

    public function test_absolute_due_status_filters_and_navigation_count_match_the_same_permitted_source_work(): void
    {
        [$root, $receiver, $overdue] = $this->assignedWork(null, null, null, ['due_at' => '2026-10-11T23:59:59Z']);
        [$futureRoot, , $future] = $this->assignedWork(null, $receiver, null, ['due_at' => '2026-10-15T02:00:00+13:00']);
        $aggregator = $this->ownTasks();
        $this->assertSame(['chemical_exposure_followup-'.$overdue->id], array_column($aggregator->itemsFor($receiver, ['overdue' => true]), 'id'));
        $this->assertSame(['chemical_exposure_followup-'.$future->id], array_column($aggregator->itemsFor($receiver, ['due' => 'week']), 'id'));
        $items = $aggregator->itemsFor($receiver);
        $this->assertSame('2026-10-14T13:00:00.000000Z', $items[1]->dueAt);
        $this->assertSame(count($items), $aggregator->navigationBadgeFor($receiver)['badge']);
        $this->assertSame([], $aggregator->itemsFor($receiver, ['sources' => ['incident']]));
        $this->assertSame([], $aggregator->itemsFor($receiver, ['bucket' => ['done']]));
    }
}
