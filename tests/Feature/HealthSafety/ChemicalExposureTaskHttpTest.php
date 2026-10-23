<?php

namespace Tests\Feature\HealthSafety;

use App\Models\ChemicalExposureFollowup;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\TaskWatcher;
use App\Services\Tasks\TaskAggregator;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/** Actual registered Tasks/My Day HTTP consumers; no route/provider/service doubles. UNAPPLIED dependencies / UNRUN. */
final class ChemicalExposureTaskHttpTest extends ChemicalExposureTaskReadTestCase
{
    private function origin(): string { return '/tasks?sources=chemical_exposure_followup&assigned=me'; }
    private function drawer(int $id): string { return '/tasks/detail?source=chemical_exposure_followup&id='.$id; }

    public function test_actual_tasks_and_my_day_render_current_named_work_you_and_blocked_destination_without_private_prose(): void
    {
        [$root, $receiver, $child] = $this->assignedWork();
        $id = 'chemical_exposure_followup-'.$child->id;
        $this->actingAs($receiver)->get($this->origin())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('tasks/index')->where('pagination.total', 1)->has('items', 1)->where('items.0.id', $id)
            ->where('items.0.assignee', ['id' => $receiver->id, 'name' => 'You'])->where('items.0.restricted', true)
            ->where('items.0.link', null)->where('items.0.description', null)->where('items.0.client', null)->where('items.0.site', null)
            ->where('items.0.actionHelp', fn ($value) => is_string($value) && $value !== ''));
        $this->get('/my-day')->assertOk()->assertInertia(fn (Assert $page) => $page->component('my-day/index')
            ->where('myTasks.items', fn ($rows) => collect($rows)->contains(fn ($row) => $row['id'] === $id && $row['link'] === null
                && $row['title'] === 'Assigned follow-up')));
        $this->assertSame(2, $root->fresh()->source_revision); $this->assertNull($child->fresh()->completed_at);
    }

    public function test_actual_private_drawer_has_no_audit_actors_watchers_generic_commands_or_false_open_capability(): void
    {
        [$root, $receiver, $child] = $this->assignedWork();
        TaskWatcher::create(['source' => 'chemical_exposure_followup', 'item_id' => $child->id, 'user_id' => $this->manager->id]);
        $response = $this->actingAs($receiver)->getJson($this->drawer($child->id))->assertOk()
            ->assertJsonPath('item.assignee', ['id' => $receiver->id, 'name' => 'You'])
            ->assertJsonPath('timeline', [])->assertJsonPath('watchers', [])->assertJsonPath('watchersHidden', true)
            ->assertJsonPath('canAssign', false)->assertJsonPath('canSplit', false)->assertJsonPath('canOpen', false)->assertJsonPath('canWatch', false);
        $this->assertStringNotContainsString('PRIVATE-', $response->getContent());
        $this->post('/tasks/chemical_exposure_followup/'.$child->id.'/assign', ['assignee_id' => $this->manager->id])->assertStatus(400);
        $this->post('/tasks/chemical_exposure_followup/'.$child->id.'/split', ['title' => 'Generic child attempt'])->assertStatus(400);
        $this->post('/tasks/chemical_exposure_followup/'.$child->id.'/watch', ['watching' => true])->assertNotFound();
        $this->assertSame($receiver->id, $child->fresh()->assigned_to); $this->assertNull($child->fresh()->completed_at);
    }

    public function test_real_roles_and_site_withdrawal_restore_all_actual_task_origins_without_unrelated_module_denial(): void
    {
        [$root, $receiver, $child] = $this->assignedWork();
        $receiver->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->actingAs($receiver);
        foreach (['hr.wellbeing.view', 'hazards.view'] as $key) {
            $this->allow($receiver, $key, false);
            $this->get($this->origin())->assertOk()->assertInertia(fn (Assert $page) => $page->has('items', 0)->where('pagination.total', 0));
            $this->getJson($this->drawer($child->id))->assertNotFound();
            $this->get('/my-day')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('myTasks.items', fn ($rows) => ! collect($rows)->contains('id', 'chemical_exposure_followup-'.$child->id)));
            $this->allow($receiver, $key);
            $this->get($this->origin())->assertOk()->assertInertia(fn (Assert $page) => $page->has('items', 1));
        }
        $receiver->hrEmployeeProfile()->update(['primary_site_id' => $this->foreignSite->id]);
        $this->get($this->origin())->assertOk()->assertInertia(fn (Assert $page) => $page->has('items', 0));
        $this->getJson($this->drawer($child->id))->assertNotFound();
        $receiver->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
        $this->getJson($this->drawer($child->id))->assertOk();
    }

    public function test_actual_sealed_neighbour_does_not_turn_tasks_or_my_day_into_an_unavailable_or_empty_private_feed(): void
    {
        [$root, $receiver, $child] = $this->assignedWork(); [$sealed, $legacy] = $this->sealedLegacy($receiver);
        $this->actingAs($receiver)->get($this->origin())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('pagination.total', 1)->where('items.0.id', 'chemical_exposure_followup-'.$child->id));
        $this->get('/my-day')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('myTasks.items', fn ($rows) => collect($rows)->contains('id', 'chemical_exposure_followup-'.$child->id))
            ->where('data_unavailable', fn ($sections) => ! in_array('Other tasks', (array) $sections, true)));
        $this->getJson($this->drawer($legacy->id))->assertNotFound();
    }

    public function test_search_csv_and_lookup_never_copy_private_body_worker_names_or_a_source_reference(): void
    {
        [$root, $receiver, $child] = $this->assignedWork();
        $this->actingAs($receiver)->get($this->origin().'&q=PRIVATE-TASK-NAMED-INSTRUCTION')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('pagination.total', 0)->has('items', 0));
        $this->get($this->origin().'&q=Assigned%20follow-up')->assertOk()->assertInertia(fn (Assert $page) => $page->has('items', 1));
        $csv = $this->get($this->origin().'&format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Assigned follow-up', $csv); $this->assertStringContainsString('You', $csv);
        $this->assertStringNotContainsString('PRIVATE-', $csv); $this->assertStringNotContainsString($this->worker->name, $csv);
        $this->getJson('/tasks/lookup?q=EXP-'.$root->id)->assertOk()->assertJsonPath('match', null);
        $this->getJson($this->drawer($child->id))->assertOk()->assertJsonPath('item.ref', null);
    }

    public function test_actual_source_completion_and_transfer_update_list_exact_drawer_and_my_day_without_generic_completion(): void
    {
        [$root, $receiver, $child] = $this->assignedWork(); $next = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']);
        $this->exposureService()->reassignFollowup($root, $child->id, $this->manager, $this->privateCommand($root,
            ['assigned_to' => $next->id, 'reason' => 'Actual approved source handover']));
        $this->actingAs($receiver)->getJson($this->drawer($child->id))->assertNotFound();
        $this->actingAs($next)->getJson($this->drawer($child->id))->assertOk()->assertJsonPath('item.assignee', ['id' => $next->id, 'name' => 'You']);
        $this->exposureService()->completeFollowup($root, $child->id, $next, $this->privateCommand($root,
            ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'PRIVATE-RETAINED-COMPLETION']));
        $this->get($this->origin())->assertOk()->assertInertia(fn (Assert $page) => $page->has('items', 0));
        $this->get($this->origin().'&done=1')->assertOk()->assertInertia(fn (Assert $page) => $page->has('items', 1)->where('items.0.bucket', 'done'));
        $this->getJson($this->drawer($child->id))->assertOk()->assertJsonPath('item.bucket', 'done');
        $this->get('/my-day')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('myTasks.items', fn ($rows) => ! collect($rows)->contains('id', 'chemical_exposure_followup-'.$child->id)));
    }

    public function test_original_client_source_read_withdrawal_and_foreign_private_recipient_are_real_http_direct_object_denials(): void
    {
        $incident = $this->linkedIncident(); [$root, $receiver, $child] = $this->assignedWork($incident);
        $other = $this->staff(['hazards.view', 'hr.wellbeing.view', 'incidents.viewAny', 'clients.viewAny']);
        $this->actingAs($other)->getJson($this->drawer($child->id))->assertNotFound();
        $this->actingAs($receiver); $this->allow($receiver, 'clients.viewAny', false);
        $this->get($this->origin())->assertOk()->assertInertia(fn (Assert $page) => $page->has('items', 0));
        $this->getJson($this->drawer($child->id))->assertNotFound();
        $this->allow($receiver, 'clients.viewAny'); $this->getJson($this->drawer($child->id))->assertOk();
        $incident->update(['site_id' => $this->foreignSite->id]); $this->getJson($this->drawer($child->id))->assertNotFound();
    }

    public function test_actual_twenty_seven_child_feed_counts_and_drawer_do_not_infer_focused_private_get_readiness(): void
    {
        [$root, $receiver, $first] = $this->assignedWork();
        for ($i = 0; $i < 26; $i++) { $this->assignedWork(null, $receiver, $root); }
        $this->actingAs($receiver)->get($this->origin().'&page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('pagination.total', 27)->where('pagination.page', 1)->has('items', 27));
        $this->getJson($this->drawer($first->id))->assertOk()->assertJsonPath('item.id', 'chemical_exposure_followup-'.$first->id)
            ->assertJsonPath('item.link', null)->assertJsonPath('canOpen', false);
        $this->get('/my-day')->assertOk()->assertInertia(fn (Assert $page) => $page->where('myTasks.total', 27)->has('myTasks.items', 8));
        // Guarded source GET+exact page26 focus/return regression is gate_runner's separate full transport suite.
    }

    public function test_unrelated_actual_incident_task_remains_readable_when_private_source_family_is_revoked(): void
    {
        [$root, $receiver, $child] = $this->assignedWork(); $this->allow($receiver, 'incidents.viewAny');
        $incident = $this->linkedIncident();
        $ordinary = $incident->followups()->create(['assigned_to_user_id' => $receiver->id, 'notes' => 'Ordinary source work', 'due_at' => now()->addDay()]);
        $this->actingAs($receiver); $this->allow($receiver, 'hr.wellbeing.view', false);
        $this->get('/tasks?sources=followup,chemical_exposure_followup&assigned=me')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('items', fn ($rows) => collect($rows)->contains('id', 'followup-'.$ordinary->id)
                && ! collect($rows)->contains('id', 'chemical_exposure_followup-'.$child->id)));
        $this->getJson('/tasks/detail?source=followup&id='.$ordinary->id)->assertOk();
        $this->getJson($this->drawer($child->id))->assertNotFound();
    }
}
