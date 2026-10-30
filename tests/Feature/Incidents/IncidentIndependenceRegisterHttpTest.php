<?php

namespace Tests\Feature\Incidents;

use App\Models\IncidentFollowup;
use App\Services\Incidents\IncidentIndependencePolicySettings as Policy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Actual mounted register HTTP paths, with auth/verified/current route guard intact. Native execution UNRUN. */
final class IncidentIndependenceRegisterHttpTest extends IncidentIndependenceTestCase
{
    private function partial(array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->get('/incidents?'.http_build_query($query), ['X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'incidents/index', 'X-Inertia-Partial-Data' => 'auth']);
    }

    public function test_named_strong_reader_receives_only_healthy_parent_counts_and_rows_on_actual_partial_route(): void
    {
        $this->accept($this->command()); $healthy = $this->incident(); $this->actingAs($this->lead);
        $response = $this->partial()->assertOk()->assertJsonPath('props.viewer_id', $this->lead->id)
            ->assertJsonPath('props.rows.total', 1)->assertJsonPath('props.rows.data.0.id', $healthy->id)
            ->assertJsonPath('props.tabCounts.all', 1)->assertJsonPath('props.hero.period.open.value', 1);
        $this->assertStringNotContainsString('Synthetic private source text', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_named_strong_reader_cannot_reveal_a_saved_incident_through_register_detail_parameter(): void
    {
        $receipt = $this->accept($this->command()); $this->actingAs($this->lead);
        $response = $this->partial(['incident' => $receipt['incident_id']])->assertOk()
            ->assertJsonPath('props.detail', null)->assertJsonPath('props.rows.total', 0);
        $this->assertStringNotContainsString('Synthetic private source text', $response->getContent());
    }

    public function test_followup_worklist_counts_and_body_use_the_same_admitted_parent_subquery(): void
    {
        $private = $this->accept($this->command()); $healthy = $this->incident();
        $visible = null;
        foreach ([$private['incident_id'], $healthy->id] as $id) {
            $row = IncidentFollowup::withoutEvents(fn () => IncidentFollowup::create(['client_incident_id' => $id,
                'assigned_to_user_id' => $this->lead->id, 'notes' => $id === $private['incident_id'] ? 'RESTRICTED-LINKED-FOLLOWUP' : 'Healthy instruction',
                'due_at' => now()->subHour(), 'created_by' => $this->reporter->id]));
            if ($id === $healthy->id) { $visible = $row->id; }
        }
        $response = $this->actingAs($this->lead)->partial(['tab' => 'followups'])->assertOk()
            ->assertJsonPath('props.rows.total', 1)->assertJsonPath('props.rows.data.0.id', $visible)
            ->assertJsonPath('props.tabCounts.followups', 1)->assertJsonPath('props.hero.attention.followups.overdue', 1);
        $this->assertStringNotContainsString('RESTRICTED-LINKED-FOLLOWUP', $response->getContent());
    }

    public function test_assignment_only_has_no_extra_read_rights_but_does_preserve_normal_register_read(): void
    {
        app(Policy::class)->save($this->manager, ['mode' => 'assignment_only', 'expected_policy_revision' => 0,
            'request_uuid' => (string) Str::uuid(), 'reason' => 'Explicit configurable policy.']);
        $receipt = $this->accept($this->command(['expected_policy_revision' => 1]));
        $this->actingAs($this->lead)->partial()->assertOk()->assertJsonPath('props.rows.data.0.id', $receipt['incident_id']);
        $this->actingAs($this->reporter)->partial()->assertForbidden();
    }

    public function test_policy_change_and_closed_state_do_not_release_an_already_protected_source_on_the_route(): void
    {
        $receipt = $this->accept($this->command());
        app(Policy::class)->save($this->manager, ['mode' => 'assignment_only', 'expected_policy_revision' => 0,
            'request_uuid' => (string) Str::uuid(), 'reason' => 'For future reports only.']);
        DB::table('client_incidents')->where('id', $receipt['incident_id'])->update(['status' => 'closed', 'closed_at' => now()]);
        $this->actingAs($this->lead)->partial(['tab' => 'closed'])->assertOk()->assertJsonPath('props.rows.total', 0)->assertJsonPath('props.tabCounts.closed', 0);
        $this->actingAs($this->manager)->partial(['tab' => 'closed'])->assertOk()->assertJsonPath('props.rows.data.0.id', $receipt['incident_id']);
    }

    public function test_damaged_retained_declaration_does_not_abort_healthy_register_work_or_leak_search_counts(): void
    {
        $receipt = $this->accept($this->command()); $healthy = $this->incident();
        DB::table('incident_independent_handling_declarations')->where('id', $receipt['declaration_id'])->update(['handling' => 'false']);
        $this->actingAs($this->manager)->partial()->assertOk()->assertJsonPath('props.rows.total', 1)->assertJsonPath('props.rows.data.0.id', $healthy->id);
        $response = $this->partial(['q' => 'Synthetic private source text'])->assertOk()->assertJsonPath('props.rows.total', 0)
            ->assertJsonPath('props.tabCounts.all', 0)->assertJsonPath('props.detail', null);
        // The query itself is echoed as a filter; no incident description or occurrence row is returned.
        $this->assertSame([], $response->json('props.rows.data'));
    }
}
