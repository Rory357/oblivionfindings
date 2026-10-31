<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/** Real /my-day partial responses. Canonical private Tasks registration proposal is a prerequisite; every case UNRUN. */
final class MyDayAssignedWorkPrivacyTest extends ChemicalExposureTaskReadTestCase
{
    private ?string $assetVersion = null;
    private function full(User $viewer): TestResponse
    {
        $response = $this->actingAs($viewer)->get('/my-day')->assertOk();
        $this->assetVersion = (string) ($response->viewData('page')['version'] ?? '');
        return $response;
    }
    private function partial(User $viewer, string $data = 'auth', array $extra = []): TestResponse
    {
        if ($this->assetVersion === null) { $this->full($viewer); }
        return $this->actingAs($viewer)->get('/my-day', [
            'X-Inertia' => 'true', 'Accept' => 'application/json', 'X-Inertia-Version' => $this->assetVersion,
            'X-Inertia-Partial-Component' => 'my-day/index', 'X-Inertia-Partial-Data' => $data, ...$extra,
        ])->assertOk();
    }
    private function bound(TestResponse $response, User $viewer, int $total): TestResponse
    {
        return $response->assertJsonPath('props.myTasks.viewer_id', (int) $viewer->id)
            ->assertJsonPath('props.myTasks.total', $total);
    }
    public function test_full_page_binds_real_assigned_private_work_to_current_viewer_without_copied_medical_prose(): void
    {
        [, $receiver, $child] = $this->assignedWork(); $page = $this->full($receiver)->viewData('page')['props'];
        $this->assertSame($receiver->id, $page['myTasks']['viewer_id']); $this->assertSame(1, $page['myTasks']['total']);
        $this->assertSame('chemical_exposure_followup-'.$child->id, $page['myTasks']['items'][0]['id']);
        $this->assertSame('Assigned follow-up', $page['myTasks']['items'][0]['title']);
        $this->assertStringNotContainsString('PRIVATE-TASK', json_encode($page['myTasks'], JSON_THROW_ON_ERROR));
        foreach (['clock', 'timesheets', 'medications_due', 'active_shift'] as $key) { $this->assertArrayHasKey($key, $page); }
    }
    public function test_auth_only_partial_always_replaces_warmed_private_rows_and_count_after_each_real_read_grant_revoke_restore(): void
    {
        [, $receiver, $child] = $this->assignedWork(); $this->full($receiver);
        $receiver->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
        foreach (['hazards.view', 'hr.wellbeing.view'] as $key) {
            $this->allow($receiver, $key, false);
            $response = $this->bound($this->partial($receiver), $receiver, 0)->assertJsonPath('props.myTasks.items', []);
            $response->assertJsonMissingPath('props.shifts')->assertJsonMissingPath('props.medications_due');
            $this->assertArrayHasKey('data_unavailable', $response->json('props'));
            $this->allow($receiver, $key);
            $this->bound($this->partial($receiver), $receiver, 1)->assertJsonPath('props.myTasks.items.0.id', 'chemical_exposure_followup-'.$child->id);
        }
    }
    public function test_cached_shared_capability_is_not_private_row_authority_on_actual_auth_only_partial(): void
    {
        [, $receiver] = $this->assignedWork(); $baseline = $this->full($receiver)->viewData('page')['props'];
        $this->assertTrue($baseline['auth']['can']['hr']['wellbeing']['view']);
        $this->allow($receiver, 'hr.wellbeing.view', false); $fresh = $receiver->fresh();
        $this->bound($this->partial($fresh), $fresh, 0)->assertJsonPath('props.auth.user.id', $receiver->id)
            ->assertJsonPath('props.auth.can.hr.wellbeing.view', true)->assertJsonPath('props.auth.can.hazards.view', true);
        // Existing 300-second shared-map cache stays historical UI evidence; canonical current source rows are already denied.
        // No cache flush, alternative grant or middleware rewrite hides this actual dependency.
        $this->allow($receiver, 'hr.wellbeing.view'); $fresh = $receiver->fresh();
        $this->bound($this->partial($fresh), $fresh, 1);
    }
    public function test_auth_only_account_switch_returns_only_actual_new_viewer_work_and_identity_not_previous_deadlines(): void
    {
        [, $first, $old] = $this->assignedWork(); [, $second, $current] = $this->assignedWork();
        $oldPage = $this->full($first)->viewData('page')['props']['myTasks'];
        $this->assertSame('chemical_exposure_followup-'.$old->id, $oldPage['items'][0]['id']);
        $response = $this->bound($this->partial($second), $second, 1)->assertJsonPath('props.auth.user.id', $second->id)
            ->assertJsonPath('props.myTasks.items.0.id', 'chemical_exposure_followup-'.$current->id);
        $this->assertNotContains('chemical_exposure_followup-'.$old->id, array_column($response->json('props.myTasks.items'), 'id'));
    }
    public function test_my_tasks_only_partial_carries_current_viewer_binding_when_auth_is_not_requested(): void
    {
        [, $first] = $this->assignedWork(); [, $second, $child] = $this->assignedWork(); $this->full($first);
        $this->bound($this->partial($second, 'myTasks'), $second, 1)->assertJsonMissingPath('props.auth')
            ->assertJsonPath('props.myTasks.items.0.id', 'chemical_exposure_followup-'.$child->id);
        // The frontend compares this ID to retained auth; this response does not infer or refresh another account's authority.
    }
    public function test_partial_except_cannot_remove_new_assigned_work_or_its_fresh_unavailable_metadata(): void
    {
        [, $receiver, $child] = $this->assignedWork(); $this->full($receiver);
        $response = $this->bound($this->partial($receiver, 'auth', ['X-Inertia-Partial-Except' => 'myTasks,data_unavailable']), $receiver, 1);
        $response->assertJsonPath('props.myTasks.items.0.id', 'chemical_exposure_followup-'.$child->id);
        $this->assertArrayHasKey('data_unavailable', $response->json('props'));
    }
    public function test_current_site_withdrawal_and_restore_replaces_private_partial_count_despite_loaded_profile(): void
    {
        [, $receiver, $child] = $this->assignedWork(); $this->full($receiver); $receiver->load('hrEmployeeProfile');
        HrEmployeeProfile::where('user_id', $receiver->id)->update(['primary_site_id' => $this->foreignSite->id]);
        $this->bound($this->partial($receiver), $receiver, 0)->assertJsonPath('props.myTasks.items', []);
        HrEmployeeProfile::where('user_id', $receiver->id)->update(['primary_site_id' => $this->site->id]);
        $this->bound($this->partial($receiver), $receiver, 1)->assertJsonPath('props.myTasks.items.0.id', 'chemical_exposure_followup-'.$child->id);
    }
    public function test_original_client_permission_withdrawal_and_restore_updates_actual_private_partial_without_self_fallback(): void
    {
        $incident = $this->linkedIncident(); [, $receiver, $child] = $this->assignedWork($incident); $this->full($receiver);
        $receiver->load(['roles.permissions', 'permissionOverrides']); $this->allow($receiver, 'clients.viewAny', false);
        $this->bound($this->partial($receiver), $receiver, 0)->assertJsonPath('props.myTasks.items', []);
        $this->allow($receiver, 'clients.viewAny');
        $this->bound($this->partial($receiver), $receiver, 1)->assertJsonPath('props.myTasks.items.0.id', 'chemical_exposure_followup-'.$child->id);
    }
    public function test_actual_source_completion_replaces_cached_private_deadline_and_count_on_auth_only_partial(): void
    {
        [$root, $receiver, $child] = $this->assignedWork(); $this->full($receiver);
        $this->exposureService()->completeFollowup($root, $child->id, $receiver, $this->privateCommand($root,
            ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'PRIVATE-CURRENT-COMPLETION']));
        $response = $this->bound($this->partial($receiver), $receiver, 0)->assertJsonPath('props.myTasks.items', []);
        $this->assertNotContains('Other tasks', $response->json('props.data_unavailable'));
        $this->assertNotNull($child->fresh()->completed_at);
    }
    public function test_actual_named_transfer_replaces_both_accounts_private_partial_rows_without_generic_write(): void
    {
        [$root, $first, $child] = $this->assignedWork(); $second = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']); $this->full($first);
        $this->exposureService()->reassignFollowup($root, $child->id, $this->manager, $this->privateCommand($root,
            ['assigned_to' => $second->id, 'reason' => 'Actual source-approved transfer']));
        $this->bound($this->partial($first), $first, 0)->assertJsonPath('props.myTasks.items', []);
        $this->bound($this->partial($second), $second, 1)->assertJsonPath('props.myTasks.items.0.id', 'chemical_exposure_followup-'.$child->id);
        $this->assertSame($second->id, $child->fresh()->assigned_to); $this->assertNull($child->fresh()->completed_at);
    }
    public function test_actual_assigned_query_failure_is_fresh_unavailable_not_an_empty_available_or_other_account_feed(): void
    {
        [, $receiver] = $this->assignedWork(); $this->full($receiver); $active = true; $intercepted = 0;
        DB::connection()->beforeExecuting(function (string $query) use (&$active, &$intercepted) {
            if ($active && str_contains($query, 'chemical_exposure_followups')) {
                $intercepted++; throw new \RuntimeException('Injected assigned-work database failure.');
            }
        });
        try { $response = $this->bound($this->partial($receiver), $receiver, 0)->assertJsonPath('props.myTasks.items', []); }
        finally { $active = false; }
        $this->assertGreaterThan(0, $intercepted); $this->assertContains('Other tasks', $response->json('props.data_unavailable'));
        // A real query failure tests failure transport only; it is not a mock/private authority certificate.
    }
    public function test_private_revocation_keeps_real_unrelated_incident_work_in_same_always_partial_feed(): void
    {
        [, $receiver, $private] = $this->assignedWork(); $incident = $this->linkedIncident(); $this->allow($receiver, 'incidents.viewAny');
        $ordinary = $incident->followups()->create(['assigned_to_user_id' => $receiver->id, 'notes' => 'Independent incident work', 'due_at' => now()->addDay()]);
        $this->full($receiver); $this->allow($receiver, 'hr.wellbeing.view', false);
        $response = $this->bound($this->partial($receiver), $receiver, 1);
        $this->assertContains('followup-'.$ordinary->id, array_column($response->json('props.myTasks.items'), 'id'));
        $this->assertNotContains('chemical_exposure_followup-'.$private->id, array_column($response->json('props.myTasks.items'), 'id'));
        $this->assertNotContains('Other tasks', $response->json('props.data_unavailable'));
    }
    public function test_component_mismatch_keeps_actual_full_my_day_payload_and_viewer_binding(): void
    {
        [, $receiver] = $this->assignedWork(); $this->full($receiver);
        $response = $this->bound($this->partial($receiver, 'auth', ['X-Inertia-Partial-Component' => 'another/component']), $receiver, 1);
        $response->assertJsonPath('component', 'my-day/index');
        foreach (['clock', 'timesheets', 'medications_due', 'shifts', 'stats'] as $key) { $this->assertArrayHasKey($key, $response->json('props')); }
    }
    public function test_guest_my_day_route_does_not_disclose_source_rows_or_assigned_counts(): void
    {
        $this->assignedWork();
        [, $queries] = $this->capturedQueries(fn () => $this->get('/my-day')->assertRedirect('/login'));
        foreach ($queries as $query) { $this->assertStringNotContainsString('chemical_exposure_followups', $query['query']); }
    }
    public function test_actual_auth_partial_preserves_eight_row_preview_and_current_materialised_feed_count(): void
    {
        [$root, $receiver, $first] = $this->assignedWork();
        for ($i = 0; $i < 26; $i++) { $this->assignedWork(null, $receiver, $root); }
        $this->full($receiver); $this->bound($this->partial($receiver), $receiver, 27)->assertJsonCount(8, 'props.myTasks.items');
        $this->exposureService()->completeFollowup($root, $first->id, $receiver, $this->privateCommand($root,
            ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'Actual single source completion']));
        $this->bound($this->partial($receiver), $receiver, 26)->assertJsonCount(8, 'props.myTasks.items');
        // The total is the actual materialised provider feed before card slicing, not an invented uncapped organisation total.
    }
}
