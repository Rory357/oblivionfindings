<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ChemicalExposureFollowup;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\SubstanceExposureRecord;
use App\Services\HealthSafety\ChemicalExposureWorkspaceService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/** Full real source/service/HTTP focus suite; reviewed Workspace+existing transport composition required. UNRUN. */
final class ChemicalExposureFollowupFocusTest extends ChemicalExposureCommandTestCase
{
    private const BASE = '/health-safety/chemical-exposures';
    private function workspace(): ChemicalExposureWorkspaceService { return app(ChemicalExposureWorkspaceService::class); }
    private function assignment(SubstanceExposureRecord $root, $receiver = null): ChemicalExposureFollowup
    {
        $receipt = $this->exposureService()->assignFollowup($root, $this->manager, $this->privateCommand($root,
            ['assigned_to' => ($receiver ?? $this->manager)->id, 'instruction' => 'Exact private followup instruction', 'due_at' => '2026-10-13T01:00:00Z']));
        return ChemicalExposureFollowup::findOrFail($receipt['source_id']);
    }
    private function focused(SubstanceExposureRecord $root, ChemicalExposureFollowup $child, array $pages = []): array
    {
        return $this->workspace()->detail($this->manager, $root->id, $pages, $child->id);
    }
    public function test_actual_27_child_descending_register_resolves_oldest_task_child_to_real_page_two(): void
    {
        $root = $this->exposure(['medical_attention_sought' => false]); $children = [];
        for ($i = 0; $i < 27; $i++) { $children[] = $this->assignment($root); }
        $dto = $this->focused($root, $children[0], ['followups_page' => 1]);
        $this->assertSame($children[0]->id, $dto['focused_followup_id']); $this->assertSame('available', $dto['followup_focus_state']);
        $this->assertSame(2, $dto['followups']['page']['current_page']); $this->assertSame(27, $dto['followups']['page']['total']);
        $this->assertSame([$children[1]->id, $children[0]->id], array_column($dto['followups']['items'], 'id'));
        $this->assertSame(28, $root->fresh()->source_revision); $this->assertSame(2, $children[0]->source_revision);
    }
    public function test_unrequested_and_empty_focus_preserves_real_existing_pagination_without_selecting_a_child(): void
    {
        $root = $this->exposure(); $dto = $this->workspace()->detail($this->manager, $root->id, ['followups_page' => 2]);
        $this->assertNull($dto['focused_followup_id']); $this->assertSame('not_requested', $dto['followup_focus_state']);
        $this->assertSame([], $dto['followups']['items']); $this->assertSame(2, $dto['followups']['page']['current_page']);
        $dto = $this->workspace()->detail($this->manager, $root->id, [], 2147483647);
        $this->assertNull($dto['focused_followup_id']); $this->assertSame('unavailable', $dto['followup_focus_state']);
        $this->assertSame(0, $dto['followups']['page']['total']);
    }
    public function test_foreign_root_selector_does_not_hydrate_foreign_child_body_or_return_its_id(): void
    {
        $root = $this->exposure(); $foreign = $this->assignment($this->exposure()); $retrieved = [];
        ChemicalExposureFollowup::retrieved(function ($row) use (&$retrieved) { $retrieved[] = ['id' => $row->id, 'body' => array_key_exists('instruction', $row->getAttributes())]; });
        $dto = $this->workspace()->detail($this->manager, $root->id, [], $foreign->id);
        $this->assertNull($dto['focused_followup_id']); $this->assertSame([], $dto['followups']['items']);
        foreach ($retrieved as $row) { $this->assertFalse($row['id'] === $foreign->id && $row['body']); }
        $this->assertStringNotContainsString('Exact private followup instruction', json_encode($dto, JSON_THROW_ON_ERROR));
    }
    public function test_warmed_private_role_withdrawal_and_restore_do_not_reuse_a_prior_focused_row(): void
    {
        $root = $this->exposure(); $child = $this->assignment($root); $this->assertSame($child->id, $this->focused($root, $child)['focused_followup_id']);
        $this->manager->load(['roles.permissions', 'permissionOverrides']); $this->allow($this->manager, 'hr.wellbeing.view', false);
        [, $queries] = $this->capturedQueries(function () use ($root, $child) { $this->denial(fn () => $this->focused($root, $child)); return null; });
        foreach ($queries as $query) { $this->assertStringNotContainsString('chemical_exposure_followups', $query['query']); }
        $this->allow($this->manager, 'hr.wellbeing.view'); $this->assertSame($child->id, $this->focused($root, $child)['focused_followup_id']);
    }
    public function test_current_site_withdrawal_and_restore_preserve_exact_child_without_own_or_todays_site_fallback(): void
    {
        $root = $this->exposure(); $child = $this->assignment($root);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->foreignSite->id]);
        $this->denial(fn () => $this->focused($root, $child), 404);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->site->id]);
        $this->assertSame($child->id, $this->focused($root, $child)['focused_followup_id']);
    }
    public function test_original_client_withdrawal_blocks_the_whole_private_root_before_focus_child_body(): void
    {
        foreach (['incidents.viewAny', 'clients.viewAny'] as $key) { $this->allow($this->manager, $key); }
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create(['client_id' => $client->id, 'site_id' => $this->site->id,
            'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null, 'is_hr_confidential' => false]));
        $root = $this->exposure(['related_incident_id' => $incident->id]); $child = $this->assignment($root);
        $this->allow($this->manager, 'clients.viewAny', false); $this->denial(fn () => $this->focused($root, $child));
        $this->allow($this->manager, 'clients.viewAny'); $this->assertSame($child->id, $this->focused($root, $child)['focused_followup_id']);
    }
    public function test_actual_transfer_updates_focused_receiver_and_does_not_infer_completion_from_the_link(): void
    {
        $root = $this->exposure(); $child = $this->assignment($root); $other = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']);
        $this->exposureService()->reassignFollowup($root, $child->id, $this->manager, $this->privateCommand($root,
            ['assigned_to' => $other->id, 'reason' => 'Actual transfer']));
        $dto = $this->focused($root, $child); $item = $dto['followups']['items'][0];
        $this->assertSame($child->id, $dto['focused_followup_id']); $this->assertSame($other->id, $item['assigned_to']['id']); $this->assertFalse($item['can_complete']);
        $this->assertSame($this->manager->id, $item['recorded_by']['id']);
    }
    public function test_actual_completion_keeps_historical_focus_but_no_new_completion_or_root_closure_claim(): void
    {
        $root = $this->exposure(); $child = $this->assignment($root);
        $this->exposureService()->completeFollowup($root, $child->id, $this->manager, $this->privateCommand($root,
            ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'Actual retained completion']));
        $dto = $this->focused($root, $child); $this->assertSame($child->id, $dto['focused_followup_id']);
        $this->assertSame('Actual retained completion', $dto['followups']['items'][0]['completion_notes']); $this->assertFalse($dto['followups']['items'][0]['can_complete']);
        $this->assertSame('unconfirmed', $dto['delivery']['state']); $this->assertGreaterThan(0, $dto['history']['page']['total']);
    }
    public function test_retired_exposed_worker_keeps_historical_private_record_and_accepted_followup_identity(): void
    {
        $root = $this->exposure(); $child = $this->assignment($root);
        HrEmployeeProfile::where('user_id', $this->worker->id)->update(['is_active' => false, 'end_date' => '2026-10-11']);
        $this->assertSame($child->id, $this->focused($root, $child)['focused_followup_id']);
        $this->assertSame($this->worker->id, $this->focused($root, $child)['record']['worker']['id']);
    }
    public function test_unversioned_child_body_corruption_has_no_focus_or_private_instruction_placeholder(): void
    {
        $root = $this->exposure(); $child = $this->assignment($root); DB::table('chemical_exposure_followups')->where('id', $child->id)->update(['instruction' => 'DAMAGED-PRIVATE-COPY']);
        $dto = $this->focused($root, $child); $this->assertNull($dto['focused_followup_id']);
        $this->assertSame('repair_required', $dto['followups']['items'][0]['attribution_state']); $this->assertNull($dto['followups']['page']['total']);
        $this->assertStringNotContainsString('DAMAGED-PRIVATE-COPY', json_encode($dto, JSON_THROW_ON_ERROR));
    }
    public function test_actual_child_mutation_after_body_hydration_refuses_a_false_accepted_focus(): void
    {
        $root = $this->exposure(); $child = $this->assignment($root); $changed = false;
        ChemicalExposureFollowup::retrieved(function ($row) use ($child, &$changed) {
            if (! $changed && $row->id === $child->id && array_key_exists('instruction', $row->getAttributes())) {
                $changed = true; DB::table('chemical_exposure_followups')->where('id', $child->id)->update(['instruction' => 'Changed after hydration']);
            }
        });
        $dto = $this->focused($root, $child); $this->assertTrue($changed); $this->assertNull($dto['focused_followup_id']);
        $this->assertStringNotContainsString('Changed after hydration', json_encode($dto, JSON_THROW_ON_ERROR));
    }
    public function test_actual_json_exact_child_deep_link_supplies_focus_from_returned_page_and_no_receipt_or_uuid(): void
    {
        $root = $this->exposure(); $children = []; for ($i = 0; $i < 26; $i++) { $children[] = $this->assignment($root); }
        $response = $this->actingAs($this->manager)->getJson(self::BASE.'/'.$root->id.'?section=followups&followup='.$children[0]->id)->assertOk();
        $response->assertJsonPath('detail.focused_followup_id', $children[0]->id)->assertJsonPath('detail.followups.page.current_page', 2)
            ->assertJsonPath('detail.followups.items.0.id', $children[0]->id)->assertJsonPath('chemical_exposure_confirmation', null);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control')); $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringNotContainsString('idempotency_key', $response->getContent());
    }
    public function test_inertia_partial_detail_uses_same_admitted_current_page_not_a_guessed_query_selector(): void
    {
        $root = $this->exposure(); $child = $this->assignment($root);
        $response = $this->actingAs($this->manager)->get(self::BASE.'/'.$root->id.'?followup='.$child->id, [
            'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'health-safety/chemical-exposure-show', 'X-Inertia-Partial-Data' => 'detail']);
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('health-safety/chemical-exposure-show')
            ->where('detail.focused_followup_id', $child->id)->where('detail.followups.items.0.id', $child->id));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control')); $response->assertHeader('Referrer-Policy', 'no-referrer');
    }
    public function test_transport_invalid_child_identity_refuses_without_source_or_child_sql(): void
    {
        $root = $this->exposure();
        [, $queries] = $this->capturedQueries(fn () => $this->actingAs($this->manager)->getJson(self::BASE.'/'.$root->id.'?followup=0')->assertStatus(422));
        foreach ($queries as $query) { $this->assertStringNotContainsString('chemical_exposure_followups', $query['query']); }
    }
    /** Mutate the actual same-session database immediately before the final single-statement focus proof. */
    private function atFinalFocusProof(callable $mutation): object
    {
        $receipt = (object) ['reached' => false, 'observation' => null];
        DB::connection()->beforeExecuting(function (string $query) use ($mutation, $receipt) {
            if (! $receipt->reached && str_contains($query, 'focus_newer') && str_contains($query, 'CASE WHEN COUNT(*)')) {
                $receipt->reached = true;
                $receipt->observation = $mutation();
            }
        });
        return $receipt;
    }
    public function test_actual_insert_at_final_proof_moves_the_exact_returned_child_to_another_page_and_refuses_focus(): void
    {
        $root = $this->exposure(); $target = $this->assignment($root);
        for ($i = 0; $i < 24; $i++) { $this->assignment($root); }
        $this->assertSame(1, $this->focused($root, $target)['followups']['page']['current_page']);
        $race = $this->atFinalFocusProof(function () use ($root, $target) {
            $inserted = $this->assignment($root);
            return ['inserted_id' => $inserted->id, 'rank' => DB::table('chemical_exposure_followups')
                ->where('exposure_id', $root->id)->where('id', '>', $target->id)->count()];
        });
        $this->integrity(fn () => $this->focused($root, $target));
        $this->assertTrue($race->reached); $this->assertGreaterThan($target->id, $race->observation['inserted_id']);
        $this->assertSame(25, $race->observation['rank']);
        // The failed read callback rolls its nested command back; no assertion pretends that it committed.
        $this->assertSame($target->id, $this->focused($root, $target)['focused_followup_id']);
    }
    public function test_actual_delete_at_final_proof_moves_the_exact_returned_child_off_page_two_and_refuses_focus(): void
    {
        $root = $this->exposure(); $target = $this->assignment($root); $newer = [];
        for ($i = 0; $i < 25; $i++) { $newer[] = $this->assignment($root); }
        $this->assertSame(2, $this->focused($root, $target)['followups']['page']['current_page']);
        $race = $this->atFinalFocusProof(function () use ($root, $target, $newer) {
            DB::table('chemical_exposure_followups')->where('exposure_id', $root->id)->where('id', $newer[0]->id)->delete();
            return DB::table('chemical_exposure_followups')->where('exposure_id', $root->id)->where('id', '>', $target->id)->count();
        });
        $this->integrity(fn () => $this->focused($root, $target));
        $this->assertTrue($race->reached); $this->assertSame(24, $race->observation);
        $this->assertSame($target->id, $this->focused($root, $target)['focused_followup_id']);
        $this->assertSame(2, $this->focused($root, $target)['followups']['page']['current_page']);
    }
    public function test_actual_case_only_instruction_rewrite_after_final_child_proof_is_byte_exactly_refused_then_restored(): void
    {
        $root = $this->exposure(); $child = $this->assignment($root); $original = $child->getRawOriginal('instruction');
        $race = $this->atFinalFocusProof(function () use ($child, $original) {
            DB::table('chemical_exposure_followups')->where('id', $child->id)->update(['instruction' => strtolower($original)]);
            return DB::table('chemical_exposure_followups')->where('id', $child->id)->value('instruction');
        });
        $this->integrity(fn () => $this->focused($root, $child));
        $this->assertTrue($race->reached); $this->assertSame(strtolower($original), $race->observation); $this->assertNotSame($original, $race->observation);
        DB::table('chemical_exposure_followups')->where('id', $child->id)->update(['instruction' => $original]);
        $dto = $this->focused($root, $child); $this->assertSame($child->id, $dto['focused_followup_id']);
        $this->assertSame($original, $dto['followups']['items'][0]['instruction']);
    }
    public function test_actual_trailing_space_instruction_rewrite_after_final_child_proof_is_refused_then_restored(): void
    {
        $root = $this->exposure(); $child = $this->assignment($root); $original = $child->getRawOriginal('instruction');
        $race = $this->atFinalFocusProof(function () use ($child, $original) {
            DB::table('chemical_exposure_followups')->where('id', $child->id)->update(['instruction' => $original.' ']);
            return DB::table('chemical_exposure_followups')->where('id', $child->id)->value('instruction');
        });
        $this->integrity(fn () => $this->focused($root, $child));
        $this->assertTrue($race->reached); $this->assertSame($original.' ', $race->observation);
        DB::table('chemical_exposure_followups')->where('id', $child->id)->update(['instruction' => $original]);
        $dto = $this->focused($root, $child); $this->assertSame($child->id, $dto['focused_followup_id']);
        $this->assertSame($original, $dto['followups']['items'][0]['instruction']);
    }
    public function test_actual_foreign_typed_child_rebound_and_downgraded_cannot_be_read_as_current_root_legacy_work(): void
    {
        $foreignManager = $this->staff(['hazards.view', 'hazards.create', 'hazards.manage', 'hr.wellbeing.view'], $this->foreignSite);
        $foreignWorker = $this->staff([], $this->foreignSite);
        $foreign = SubstanceExposureRecord::findOrFail($this->capture(['site_id' => $this->foreignSite->id,
            'user_id' => $foreignWorker->id], $foreignManager)['root_id']);
        $receipt = $this->exposureService()->assignFollowup($foreign, $foreignManager, $this->privateCommand($foreign,
            ['assigned_to' => $foreignManager->id, 'instruction' => 'SEALED-FOREIGN-FOLLOWUP-BODY', 'due_at' => '2026-10-13T01:00:00Z']));
        $child = ChemicalExposureFollowup::findOrFail($receipt['source_id']); $root = $this->exposure();
        DB::table('chemical_exposure_followups')->where('id', $child->id)->update([
            'exposure_id' => $root->id, 'source_schema_version' => 0, 'source_revision' => 0]);
        $bodies = [];
        ChemicalExposureFollowup::retrieved(function ($row) use ($child, &$bodies) {
            if ($row->id === $child->id && array_key_exists('instruction', $row->getAttributes())) { $bodies[] = $row->id; }
        });
        $dto = $this->workspace()->detail($this->manager, $root->id, [], $child->id);
        $this->assertNull($dto['focused_followup_id']); $this->assertSame('unavailable', $dto['followup_focus_state']);
        $this->assertSame('repair_required', $dto['followups']['items'][0]['attribution_state']);
        $this->assertNull($dto['followups']['page']['total']); $this->assertSame([], $bodies);
        $this->assertStringNotContainsString('SEALED-FOREIGN-FOLLOWUP-BODY', json_encode($dto, JSON_THROW_ON_ERROR));
        // Requires canonical ANY retained kind+child-ID lineage proof AND the Workspace metadata preflight.
        // A root-constrained focus lookup returning null alone does not protect the normal child section.
    }
}
