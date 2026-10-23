<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ChemicalExposureCommandReceipt;
use App\Models\ChemicalExposureFollowup;
use App\Models\SubstanceExposureRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** Literal prospective route composition. UNRUN until the reviewed private patch is adopted by its owner. */
final class ChemicalExposureWorkspaceControllerTest extends ChemicalExposureCommandTestCase
{
    protected bool $physicalCommits = true;

    private const BASE = '/health-safety/chemical-exposures';
    private function input(array $facts = []): array
    {
        return ['idempotency_key' => (string) Str::uuid(), 'user_id' => $this->worker->id, 'site_id' => $this->site->id,
            'exposed_at' => '2026-10-12T00:00:00Z', 'exposure_type' => 'skin_contact', ...$facts];
    }
    private function privateHeaders($response): void
    {
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('Referrer-Policy', 'no-referrer');
    }
    public function test_both_original_capture_aliases_preserve_names_and_use_exact_minimal_persisted_receipts(): void
    {
        $parent = $this->catalogue();
        foreach (['exposure-records', 'exposures'] as $alias) {
            $url = '/health-safety/substances/'.$parent->id.'/'.$alias;
            $this->assertSame($url, route('health-safety.substances.'.$alias.'.store', ['substance' => $parent->id], false));
            $input = $this->input(['symptoms' => 'PRIVATE-CAPTURE-NARRATIVE']);
            $result = $this->actingAs($this->reporter)->postJson($url, $input)->assertOk();
            $receipt = $result->json('chemical_exposure_confirmation');
            $this->assertTrue($receipt['accepted']); $this->assertSame($input['idempotency_key'], $receipt['idempotency_key']);
            $this->assertSame('create_exposure', $receipt['command']); $this->assertSame(1, $receipt['version']);
            $this->assertCount(12, $receipt); $result->assertJsonPath('chemical_exposure_href', null);
            $this->assertStringNotContainsString('PRIVATE-CAPTURE-NARRATIVE', $result->getContent()); $this->privateHeaders($result);
        }
    }
    public function test_lost_create_response_recovers_exact_uppercase_uuid_without_enduring_private_read(): void
    {
        $parent = $this->catalogue(); $r = $this->capture(['symptoms' => 'PRIVATE-LOST-RESPONSE'], $this->reporter, $parent->id);
        $response = $this->actingAs($this->reporter)->getJson(self::BASE.'/capture-confirmation?substance_id='.$parent->id.'&receipt_key='.strtoupper($r['idempotency_key']))->assertOk();
        $this->assertSame($r, $response->json('chemical_exposure_confirmation'));
        $this->assertStringNotContainsString('PRIVATE-LOST-RESPONSE', $response->getContent());
        $this->getJson(self::BASE.'/'.$r['root_id'])->assertForbidden(); $this->privateHeaders($response);
    }
    public function test_receipt_lookup_unknown_actor_wrong_parent_and_noncreate_key_never_discloses_acceptance(): void
    {
        $parent = $this->catalogue(); $other = $this->catalogue(); $r = $this->capture([], $this->reporter, $parent->id);
        $this->actingAs($this->manager)->getJson(self::BASE.'/capture-confirmation?substance_id='.$parent->id.'&receipt_key='.$r['idempotency_key'])->assertOk()->assertJsonPath('chemical_exposure_confirmation', null);
        $this->actingAs($this->reporter)->getJson(self::BASE.'/capture-confirmation?substance_id='.$other->id.'&receipt_key='.$r['idempotency_key'])->assertOk()->assertJsonPath('chemical_exposure_confirmation', null);
        $this->getJson(self::BASE.'/capture-confirmation?substance_id='.$parent->id.'&receipt_key='.Str::uuid())->assertOk()->assertJsonPath('chemical_exposure_confirmation', null);
        $root = $this->exposure(); $review = $this->exposureService()->review($root, $this->manager, $this->privateCommand($root,
            ['reviewed_at' => '2026-10-12T00:30:00Z', 'outcome' => 'recorded', 'notes' => 'Actual recorded review']));
        $this->actingAs($this->manager)->getJson(self::BASE.'/capture-confirmation?substance_id='.$root->hazardous_substance_id.'&receipt_key='.$review['idempotency_key'])->assertOk()->assertJsonPath('chemical_exposure_confirmation', null);
    }
    public function test_receipt_only_capture_rechecks_current_create_hr_and_original_site_authority(): void
    {
        $parent = $this->catalogue(); $r = $this->capture([], $this->reporter, $parent->id);
        $url = self::BASE.'/capture-confirmation?substance_id='.$parent->id.'&receipt_key='.$r['idempotency_key'];
        $this->reporter->canDo('hazards.create'); $this->allow($this->reporter, 'hazards.create', false);
        $this->actingAs($this->reporter)->getJson($url)->assertForbidden();
        $this->allow($this->reporter, 'hazards.create'); HrEmployeeProfile::where('user_id', $this->reporter->id)->update(['primary_site_id' => $this->foreignSite->id]);
        $this->getJson($url)->assertNotFound();
        HrEmployeeProfile::where('user_id', $this->reporter->id)->update(['primary_site_id' => $this->site->id]);
        $this->getJson($url)->assertOk()->assertJsonPath('chemical_exposure_confirmation.root_id', $r['root_id']);
    }
    public function test_explicit_receipt_uuid_is_direct_json_only_never_html_inertia_or_partial_history(): void
    {
        $parent = $this->catalogue(); $r = $this->capture([], $this->manager, $parent->id);
        $url = self::BASE.'/'.$r['root_id'].'?receipt_key='.$r['idempotency_key'];
        $this->actingAs($this->manager)->get($url)->assertNotFound();
        $this->get($url, ['X-Inertia' => 'true', 'Accept' => 'application/json'])->assertNotFound();
        $this->get($url, ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'health-safety/chemical-exposure-show', 'X-Inertia-Partial-Data' => 'chemical_exposure_confirmation'])->assertNotFound();
        $this->getJson($url)->assertOk()->assertJsonPath('chemical_exposure_confirmation.idempotency_key', $r['idempotency_key']);
        $this->getJson(self::BASE.'/'.$r['root_id'].'?receipt_key=not-a-uuid')->assertUnprocessable();
    }
    public function test_html_capture_returns_to_canonical_capture_page_and_reconstructs_hint_without_saved_facts(): void
    {
        $parent = $this->catalogue(); $input = $this->input(['symptoms' => 'PRIVATE-HTML-CAPTURE', 'return_to' => '/health-safety/substances?site_id='.$this->site->id.'&page=2']);
        $response = $this->actingAs($this->reporter)->post('/health-safety/substances/'.$parent->id.'/exposures', $input)->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString(self::BASE.'/capture?substance_id='.$parent->id, $location);
        $this->assertStringNotContainsString($input['idempotency_key'], $location);
        $this->get($location)->assertOk()->assertInertia(fn (Assert $page) => $page->component('health-safety/chemical-exposure-capture')
            ->where('capture.can.read_saved', false)->where('chemical_exposure_confirmation.actor_id', $this->reporter->id)
            ->where('chemical_exposure_href', null)->missing('detail')->etc());
    }
    public function test_forged_session_receipt_hint_is_reconstructed_not_trusted_or_promoted_to_source_access(): void
    {
        $parent = $this->catalogue(); $r = $this->capture([], $this->manager, $parent->id);
        $this->actingAs($this->reporter)->withSession(['chemical_exposure_confirmation_hint' => [
            'idempotency_key' => $r['idempotency_key'], 'root_id' => $r['root_id'], 'accepted' => true, 'symptoms' => 'FORGED-PRIVATE']])
            ->get(self::BASE.'/capture?substance_id='.$parent->id)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('chemical_exposure_confirmation', null)->where('chemical_exposure_href', null)->etc());
    }
    public function test_json_and_inertia_private_pages_preserve_proven_fields_and_no_store_without_a_raw_model(): void
    {
        $root = $this->exposure(['symptoms' => 'Actual protected symptom']);
        $json = $this->actingAs($this->manager)->getJson(self::BASE.'/'.$root->id.'?section=history&history_page=1')->assertOk();
        $json->assertJsonPath('detail.record.symptoms', 'Actual protected symptom')->assertJsonPath('detail.command_context.expected_version', 1);
        $json->assertJsonMissingPath('detail.record.original_context'); $json->assertJsonMissingPath('detail.record.source_schema_version');
        $this->privateHeaders($json);
        $this->get(self::BASE.'/'.$root->id, ['X-Inertia' => 'true', 'Accept' => 'text/html, application/xhtml+xml'])->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('health-safety/chemical-exposure-show')->where('detail.record.id', $root->id)->etc());
    }
    public function test_generic_hs_collection_is_truthfully_restricted_and_detail_has_no_self_private_grant(): void
    {
        $root = $this->exposure(); $this->allow($this->reporter, 'hazards.view');
        $response = $this->actingAs($this->reporter)->getJson(self::BASE)->assertOk()->assertJsonPath('exposure_access', 'restricted')
            ->assertJsonPath('records', null)->assertJsonPath('counts', null)->assertJsonPath('page', null);
        $this->privateHeaders($response); $this->getJson(self::BASE.'/'.$root->id)->assertForbidden();
    }
    public function test_warmed_saved_permission_and_hr_withdrawal_deny_before_source_model_body_and_restore(): void
    {
        $root = $this->exposure(); $this->manager->load(['roles.permissions', 'permissionOverrides']); $this->allow($this->manager, 'hr.wellbeing.view', false);
        $hydrated = false; SubstanceExposureRecord::retrieved(function () use (&$hydrated) { $hydrated = true; });
        try { $this->actingAs($this->manager)->getJson(self::BASE.'/'.$root->id)->assertForbidden(); }
        finally { SubstanceExposureRecord::flushEventListeners(); }
        $this->assertFalse($hydrated); $this->allow($this->manager, 'hr.wellbeing.view');
        $this->getJson(self::BASE.'/'.$root->id)->assertOk();
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => false]);
        $this->getJson(self::BASE.'/'.$root->id)->assertForbidden();
    }
    public function test_foreign_site_raw_route_id_is_denied_before_model_hydration_and_without_private_body(): void
    {
        $root = $this->exposure(['symptoms' => 'PRIVATE-FOREIGN-SITE']);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->foreignSite->id]);
        $hydrated = false; SubstanceExposureRecord::retrieved(function () use (&$hydrated) { $hydrated = true; });
        try { $response = $this->actingAs($this->manager)->getJson(self::BASE.'/'.$root->id)->assertNotFound(); }
        finally { SubstanceExposureRecord::flushEventListeners(); }
        $this->assertFalse($hydrated); $this->assertStringNotContainsString('PRIVATE-FOREIGN-SITE', $response->getContent());
    }
    public function test_correct_review_assign_and_named_complete_use_real_source_envelopes_and_same_aggregate_revision(): void
    {
        $root = $this->exposure(); $this->actingAs($this->manager);
        $this->putJson(self::BASE.'/'.$root->id, $this->privateCommand($root, ['reason' => 'Actual correction', 'symptoms' => 'Corrected fact', '_method' => 'PUT', 'section' => 'overview']))
            ->assertOk()->assertJsonPath('chemical_exposure_confirmation.command', 'correct_exposure')->assertJsonPath('chemical_exposure_confirmation.version', 2);
        $this->postJson(self::BASE.'/'.$root->id.'/reviews', $this->privateCommand($root,
            ['reviewed_at' => '2026-10-12T00:30:00Z', 'outcome' => 'followup_required', 'notes' => 'Actual review']))->assertOk()->assertJsonPath('chemical_exposure_confirmation.version', 3);
        $assigned = $this->postJson(self::BASE.'/'.$root->id.'/followups', $this->privateCommand($root,
            ['assigned_to' => $this->manager->id, 'instruction' => 'Recorded actual followup', 'due_at' => '2026-10-13T00:00:00Z']))->assertOk();
        $id = $assigned->json('chemical_exposure_confirmation.source_id');
        $this->putJson(self::BASE.'/'.$root->id.'/followups/'.$id.'/complete', $this->privateCommand($root,
            ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'Actual completion']))->assertOk()->assertJsonPath('chemical_exposure_confirmation.version', 5);
        $this->assertSame('Corrected fact', $root->fresh()->symptoms); $this->assertNotNull(ChemicalExposureFollowup::findOrFail($id)->completed_at);
    }
    public function test_exact_retry_after_later_change_returns_original_receipt_not_new_revision_and_withdrawal_refuses(): void
    {
        $root = $this->exposure(); $input = $this->privateCommand($root, ['reason' => 'Actual original correction', 'symptoms' => 'Actual original fact']);
        $first = $this->actingAs($this->manager)->putJson(self::BASE.'/'.$root->id, $input)->assertOk()->json('chemical_exposure_confirmation');
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root, ['reason' => 'Later fact', 'circumstances' => 'Later actual fact']));
        $input['idempotency_key'] = strtoupper($input['idempotency_key']);
        $this->assertSame($first, $this->putJson(self::BASE.'/'.$root->id, $input)->assertOk()->json('chemical_exposure_confirmation'));
        $this->assertSame(3, $root->fresh()->source_revision); $this->allow($this->manager, 'hazards.manage', false);
        $this->putJson(self::BASE.'/'.$root->id, $input)->assertForbidden();
    }
    public function test_stale_and_unknown_domain_fields_return_draft_preserving_errors_without_false_receipts(): void
    {
        $root = $this->exposure(); $stale = $this->privateCommand($root, ['reason' => 'Retained draft', 'symptoms' => 'Unaccepted draft']);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root, ['reason' => 'Current fact', 'circumstances' => 'Current']));
        $this->actingAs($this->manager)->putJson(self::BASE.'/'.$root->id, $stale)->assertUnprocessable()->assertJsonValidationErrors('expected_version')->assertJsonMissingPath('chemical_exposure_confirmation');
        $this->putJson(self::BASE.'/'.$root->id, $this->privateCommand($root, ['reason' => 'Wrong domain key', 'client_id' => 123]))->assertUnprocessable()->assertJsonValidationErrors('command');
        $this->assertSame(2, $root->fresh()->source_revision); $this->assertNotSame('Unaccepted draft', $root->fresh()->symptoms);
    }
    public function test_forged_persisted_receipt_identity_or_audit_never_becomes_http_success(): void
    {
        $root = $this->exposure(); $receipt = ChemicalExposureCommandReceipt::where('exposure_id', $root->id)->sole();
        $stored = $receipt->accepted_result; $stored['actor_id'] = $this->reporter->id;
        DB::table('chemical_exposure_command_receipts')->where('id', $receipt->id)->update(['accepted_result' => json_encode($stored, JSON_THROW_ON_ERROR)]);
        $response = $this->actingAs($this->manager)->getJson(self::BASE.'/'.$root->id.'/confirmation?receipt_key='.$receipt->idempotency_key);
        $this->assertFalse($response->isSuccessful()); $this->assertStringNotContainsString('"accepted":true', $response->getContent());
    }
    public function test_options_are_json_only_and_unknown_selector_keys_or_arrays_are_rejected(): void
    {
        $parent = $this->catalogue(); $root = $this->exposure();
        $this->actingAs($this->manager)->get(self::BASE.'/capture-options?substance_id='.$parent->id)->assertNotFound();
        $this->getJson(self::BASE.'/capture-options?substance_id='.$parent->id.'&site_id%5B%5D='.$this->site->id)->assertUnprocessable();
        $this->getJson(self::BASE.'/'.$root->id.'/command-options?client_id=2')->assertUnprocessable();
        $this->getJson(self::BASE.'/'.$root->id.'/command-options?site_id='.$this->site->id)->assertOk()->assertJsonStructure(['sites', 'staff', 'incidents', 'receivers']);
    }
    public function test_delivery_attempt_endpoint_is_metadata_only_json_and_never_confirms_recipient_or_destination(): void
    {
        $root = $this->exposure(); $url = self::BASE.'/'.$root->id.'/delivery-attempts';
        $this->actingAs($this->manager)->get($url)->assertNotFound();
        $this->getJson($url)->assertOk()->assertJsonPath('destination_confirmation', 'not_checked')->assertJsonPath('attempts.0.reported_state', 'pending')
            ->assertJsonMissingPath('attempts.0.recipient_id')->assertJsonMissingPath('attempts.0.hs_event_id')->assertJsonMissingPath('attempts.0.instruction');
    }
    public function test_authentication_and_literal_record_ids_do_not_mount_private_model_binding_or_capture_fallback(): void
    {
        $root = $this->exposure(); $this->getJson(self::BASE.'/'.$root->id)->assertUnauthorized();
        $this->actingAs($this->manager)->getJson(self::BASE.'/not-a-record')->assertNotFound();
        $this->getJson(self::BASE.'/capture?substance_id=0')->assertNotFound();
        $this->getJson(self::BASE.'/'.$root->id.'?history_page=2147483648')->assertUnprocessable();
    }
    public function test_real_positive_child_action_bookmarks_load_only_the_authorised_root_workspace(): void
    {
        $root = $this->exposure(); $r = $this->exposureService()->assignFollowup($root, $this->manager, $this->privateCommand($root,
            ['assigned_to' => $this->manager->id, 'instruction' => 'Actual selected task', 'due_at' => '2026-10-13T00:00:00Z']));
        $this->actingAs($this->manager)->getJson(self::BASE.'/'.$root->id.'?section=followups&action=complete_followup&followup='.$r['source_id'])
            ->assertOk()->assertJsonPath('detail.followups.items.0.id', $r['source_id'])->assertJsonPath('detail.followups.items.0.can_complete', true);
        $this->get(self::BASE.'/'.$root->id.'?section=evidence&action=remove_evidence&evidence=2147483647')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('detail.id', $root->id)->where('detail.attachments.items', [])->etc());
        foreach (['followup=0', 'evidence=2147483648', 'followup%5B%5D=1', 'evidence=false'] as $invalid) {
            $this->getJson(self::BASE.'/'.$root->id.'?'.$invalid)->assertUnprocessable();
        }
    }
    public function test_foreign_child_bookmark_is_not_authority_or_an_arbitrary_child_body_lookup(): void
    {
        $root = $this->exposure(); $other = $this->exposure();
        $foreign = $this->exposureService()->assignFollowup($other, $this->manager, $this->privateCommand($other,
            ['assigned_to' => $this->manager->id, 'instruction' => 'PRIVATE-FOREIGN-BOOKMARK-TASK', 'due_at' => '2026-10-13T00:00:00Z']));
        $hydrated = false;
        ChemicalExposureFollowup::retrieved(function ($row) use ($foreign, &$hydrated) {
            if ($row->id === $foreign['source_id'] && array_key_exists('instruction', $row->getAttributes())) { $hydrated = true; }
        });
        try { $response = $this->actingAs($this->manager)->getJson(self::BASE.'/'.$root->id.'?section=followups&action=complete_followup&followup='.$foreign['source_id'])
            ->assertOk()->assertJsonPath('detail.followups.items', [])->assertJsonPath('detail.focused_followup_id', null)
            ->assertJsonPath('detail.followup_focus_state', 'unavailable'); }
        finally { ChemicalExposureFollowup::flushEventListeners(); }
        $this->assertFalse($hydrated); $this->assertStringNotContainsString('PRIVATE-FOREIGN-BOOKMARK-TASK', $response->getContent());
        $this->putJson(self::BASE.'/'.$root->id.'/followups/'.$foreign['source_id'].'/complete', $this->privateCommand($root,
            ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'Wrong parent']))->assertNotFound();
        $this->assertNull(ChemicalExposureFollowup::findOrFail($foreign['source_id'])->completed_at);
    }
    public function test_literal_prospective_reassignment_route_retains_exact_receipt_and_actual_child_capabilities(): void
    {
        $root = $this->exposure(); $old = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']); $new = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']);
        $r = $this->exposureService()->assignFollowup($root, $this->manager, $this->privateCommand($root,
            ['assigned_to' => $old->id, 'instruction' => 'Actual pending action', 'due_at' => '2026-10-13T01:00:00Z']));
        $url = self::BASE.'/'.$root->id.'/followups/'.$r['source_id'].'/reassign';
        $this->assertSame($url, route('health-safety.chemical-exposures.followups.reassign', ['exposure' => $root->id, 'followup' => $r['source_id']], false));
        $input = $this->privateCommand($root, ['assigned_to' => $new->id, 'reason' => 'Actual reviewed transfer']);
        $response = $this->actingAs($this->manager)->putJson($url, [...$input, 'section' => 'followups', 'action' => 'reassign_followup', 'return_to' => '/tasks'])->assertOk();
        $receipt = $response->json('chemical_exposure_confirmation'); $this->assertSame('reassign_followup', $receipt['command']); $this->assertSame(3, $receipt['version']);
        $this->privateHeaders($response); $this->assertSame($receipt, $this->putJson($url, $input)->assertOk()->json('chemical_exposure_confirmation'));
        $detail = $this->getJson(self::BASE.'/'.$root->id.'?section=followups&action=reassign_followup&followup='.$r['source_id'])->assertOk();
        $detail->assertJsonPath('detail.followups.items.0.can_reassign', true)->assertJsonPath('detail.followups.items.0.assigned_to.id', $new->id);
    }
    public function test_prospective_reassignment_http_denies_foreign_child_and_read_only_actor_and_preserves_stale_draft(): void
    {
        $root = $this->exposure(); $otherRoot = $this->exposure(); $new = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']);
        $r = $this->exposureService()->assignFollowup($root, $this->manager, $this->privateCommand($root,
            ['assigned_to' => $this->manager->id, 'instruction' => 'Actual pending action', 'due_at' => '2026-10-13T01:00:00Z']));
        $url = self::BASE.'/'.$root->id.'/followups/'.$r['source_id'].'/reassign'; $input = $this->privateCommand($root, ['assigned_to' => $new->id, 'reason' => 'Retained draft reason']);
        $reader = $this->staff(['hazards.view', 'hr.wellbeing.view']); $this->allow($reader, 'hazards.manage', false);
        $this->actingAs($reader)->putJson($url, $input)->assertForbidden();
        $this->actingAs($this->manager)->putJson(self::BASE.'/'.$otherRoot->id.'/followups/'.$r['source_id'].'/reassign', $this->privateCommand($otherRoot,
            ['assigned_to' => $new->id, 'reason' => 'Wrong parent']))->assertNotFound();
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root, ['reason' => 'Actual intervening fact', 'circumstances' => 'Later source fact']));
        $this->putJson($url, $input)->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->assertSame($this->manager->id, ChemicalExposureFollowup::findOrFail($r['source_id'])->assigned_to);
    }
    public function test_capture_only_options_use_current_site_staff_without_saved_exposure_queries_or_self_read(): void
    {
        $parent = $this->catalogue(); $root = $this->exposure(['symptoms' => 'PRIVATE-SAVED-NEIGHBOR']);
        $this->actingAs($this->reporter);
        [$response, $queries] = $this->capturedQueries(fn () => $this->getJson(
            self::BASE.'/capture-options?substance_id='.$parent->id.'&site_id='.$this->site->id)->assertOk());
        $response->assertJsonPath('can.create', true)->assertJsonPath('can.read_saved', false);
        $this->privateHeaders($response);
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('substance_exposure_records', $query['query']);
            $this->assertStringNotContainsString('chemical_exposure_source_versions', $query['query']);
        }
        $this->assertStringNotContainsString('PRIVATE-SAVED-NEIGHBOR', $response->getContent());
        $this->getJson(self::BASE.'/'.$root->id)->assertForbidden();
        $this->getJson(self::BASE.'/capture-options?substance_id='.$parent->id.'&site_id='.$this->foreignSite->id)->assertNotFound();
        HrEmployeeProfile::where('user_id', $this->reporter->id)->update(['is_active' => false]);
        $this->getJson(self::BASE.'/capture-options?substance_id='.$parent->id.'&site_id='.$this->site->id)->assertForbidden();
        HrEmployeeProfile::where('user_id', $this->reporter->id)->update(['is_active' => true]);
        $this->getJson(self::BASE.'/capture-options?substance_id='.$parent->id.'&site_id='.$this->site->id)->assertOk();
    }

    public function test_private_read_without_capture_grant_has_independent_index_detail_and_refuses_capture_choices(): void
    {
        $root = $this->exposure(); $reader = $this->staff(['hazards.view', 'hr.wellbeing.view']);
        foreach (['hazards.create', 'hazards.manage'] as $key) { $this->allow($reader, $key, false); }
        $this->actingAs($reader)->getJson(self::BASE)->assertOk()->assertJsonPath('exposure_access', 'readable')
            ->assertJsonPath('can.capture', false);
        $this->getJson(self::BASE.'/'.$root->id)->assertOk()->assertJsonPath('detail.record.id', $root->id)
            ->assertJsonPath('detail.can.correct', false)->assertJsonPath('detail.can.assign_followup', false);
        $this->getJson(self::BASE.'/capture?substance_id='.$root->hazardous_substance_id)->assertForbidden();
        $this->getJson(self::BASE.'/capture-options?substance_id='.$root->hazardous_substance_id)->assertForbidden();
    }

    public function test_index_auth_only_partial_replaces_private_rows_counts_filters_on_warmed_withdrawal_and_restore(): void
    {
        $root = $this->exposure(['symptoms' => 'PRIVATE-RETAINED-INDEX']);
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'health-safety/chemical-exposure-index',
            'X-Inertia-Partial-Data' => 'auth'];
        $this->actingAs($this->manager);
        $response = $this->get(self::BASE, $headers)->assertOk();
        $this->assertContains($root->id, array_column($response->json('props.records'), 'id'));
        $this->manager->load(['roles.permissions', 'permissionOverrides']);
        $this->allow($this->manager, 'hr.wellbeing.view', false);
        $response = $this->get(self::BASE, $headers)->assertOk();
        $response->assertJsonPath('props.exposure_access', 'restricted')->assertJsonPath('props.records', null)
            ->assertJsonPath('props.counts', null)->assertJsonPath('props.filters', null)->assertJsonPath('props.page', null);
        $this->privateHeaders($response);
        $this->assertStringNotContainsString('PRIVATE-RETAINED-INDEX', $response->getContent());
        $this->getJson('/health-safety/substances')->assertOk(); // Ordinary catalogue read remains independent.
        $this->allow($this->manager, 'hr.wellbeing.view');
        $response = $this->get(self::BASE, $headers)->assertOk();
        $this->assertContains($root->id, array_column($response->json('props.records'), 'id'));
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->foreignSite->id]);
        $response = $this->get(self::BASE, $headers)->assertOk();
        $this->assertNotContains($root->id, array_column($response->json('props.records'), 'id'));
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->site->id]);
        $this->assertContains($root->id, array_column($this->get(self::BASE, $headers)->assertOk()->json('props.records'), 'id'));
    }

    public function test_read_only_exact_focus_uses_returned_child_and_does_not_grant_completion_or_private_command(): void
    {
        $root = $this->exposure();
        $receipt = $this->exposureService()->assignFollowup($root, $this->manager, $this->privateCommand($root,
            ['assigned_to' => $this->manager->id, 'instruction' => 'Actual focused manager work', 'due_at' => '2026-10-13T00:00:00Z']));
        $reader = $this->staff(['hazards.view', 'hr.wellbeing.view']); $this->allow($reader, 'hazards.manage', false);
        $response = $this->actingAs($reader)->getJson(self::BASE.'/'.$root->id.'?section=followups&followup='.$receipt['source_id'])->assertOk();
        $response->assertJsonPath('detail.focused_followup_id', $receipt['source_id'])
            ->assertJsonPath('detail.followup_focus_state', 'available')->assertJsonPath('detail.followups.items.0.id', $receipt['source_id'])
            ->assertJsonPath('detail.followups.items.0.can_complete', false);
        $this->putJson(self::BASE.'/'.$root->id.'/followups/'.$receipt['source_id'].'/complete', $this->privateCommand($root,
            ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'No inferred authority']))->assertForbidden();
        $this->assertNull(ChemicalExposureFollowup::findOrFail($receipt['source_id'])->completed_at);
    }

    public function test_actual_download_controller_denies_wrong_parent_before_file_body_and_restores_current_private_scope(): void
    {
        \Illuminate\Support\Facades\Storage::fake('private');
        $bytes = "%PDF-1.4\nActual canonical HTTP evidence\n%%EOF";
        $root = $this->exposure(); $other = $this->exposure();
        $accepted = $this->evidenceService()->upload($root, $this->manager,
            \Illuminate\Http\UploadedFile::fake()->createWithContent('private-proof.pdf', $bytes),
            $this->privateCommand($root, ['description' => 'PRIVATE-FILE-DESCRIPTION']));
        $file = \App\Models\ChemicalExposureEvidence::findOrFail($accepted['source_id']);
        $body = [];
        \App\Models\ChemicalExposureEvidence::retrieved(function ($row) use ($file, &$body): void {
            if ($row->id === $file->id && array_key_exists('path', $row->getAttributes())) { $body[] = $row->id; }
        });
        try {
            $this->actingAs($this->manager);
            $response = $this->get(self::BASE.'/'.$other->id.'/evidence/'.$file->id.'/download')->assertNotFound();
            $this->assertSame([], $body); $this->privateHeaders($response);
            $this->manager->load(['roles.permissions', 'permissionOverrides']);
            $this->allow($this->manager, 'hr.wellbeing.view', false);
            $this->getJson(self::BASE.'/'.$root->id.'/evidence/'.$file->id.'/download')->assertForbidden();
            $this->assertSame([], $body);
            $this->allow($this->manager, 'hr.wellbeing.view');
            $response = $this->get(self::BASE.'/'.$root->id.'/evidence/'.$file->id.'/download')->assertOk()->assertStreamedContent($bytes);
            $this->privateHeaders($response);
            $this->getJson(self::BASE.'/'.$root->id)->assertOk()->assertJsonMissingPath('detail.attachments.items.0.path')
                ->assertJsonMissingPath('detail.attachments.items.0.byte_proof')->assertJsonMissingPath('detail.attachments.items.0.sha256');
        } finally { \App\Models\ChemicalExposureEvidence::flushEventListeners(); }
        \Illuminate\Support\Facades\Storage::disk('private')->assertExists($file->path);
    }

    public function test_invalid_raw_route_ids_and_private_validation_failures_keep_no_store_without_private_hydration(): void
    {
        $root = $this->exposure(); $this->actingAs($this->manager);
        foreach ([self::BASE.'/2147483648', self::BASE.'/999999999999999999999999999999',
            self::BASE.'/'.$root->id.'/evidence/2147483648/download'] as $url) {
            [, $queries] = $this->capturedQueries(function () use ($url): void {
                $response = $this->getJson($url)->assertNotFound(); $this->privateHeaders($response);
            });
            foreach ($queries as $query) {
                $this->assertStringNotContainsString('chemical_exposure_evidence', $query['query']);
            }
        }
        $response = $this->getJson(self::BASE.'/'.$root->id.'?followup%5B%5D=1')->assertUnprocessable();
        $this->privateHeaders($response);
    }

}
