<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\BehaviourSupportPlan;
use App\Models\BehaviourSupportPlanVersion;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\Permission;
use App\Models\RespiteBooking;
use App\Models\RespiteStay;
use App\Models\RestraintEvent;
use App\Models\Site;
use App\Services\HealthSafety\BehaviourSupportApprovalPolicySettings;
use App\Services\HealthSafety\BehaviourSupportPlanApprovalService;
use App\Services\HealthSafety\BehaviourSupportPlanService;
use App\Services\HealthSafety\RestraintRegisterService;
use App\Services\Incidents\IncidentAccessService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** Complete native suite. Source-only candidate until the controller adapter is integrated. */
final class RestraintRegisterServiceTest extends RestraintEventCommandTestCase
{
    private function register(): RestraintRegisterService
    {
        return app(RestraintRegisterService::class);
    }

    private function legacyPlan(array $attributes = []): BehaviourSupportPlan
    {
        return BehaviourSupportPlan::factory()->create(['client_id' => $this->client->id, 'created_at' => now()->subDay(), 'source_schema_version' => null, 'status' => 'active', ...$attributes]);
    }

    private function ids(array $rows): array
    {
        return array_column($rows, 'id');
    }

    public function test_register_pages_sources_counts_revision_review_and_omits_private_details(): void
    {
        $event = $this->event();
        $event->forceFill(['reviewed_at' => now(), 'reviewed_source_revision' => 1, 'source_revision' => 2])->save();
        $plan = $this->legacyPlan(['review_date' => now('Pacific/Auckland')->subDay()->toDateString()]);
        $result = $this->register()->register($this->actor, ['period' => 'all', 'event' => $event->id, 'plan' => $plan->id]);
        $this->assertSame([$event->id], $this->ids($result['events']['data']));
        $this->assertSame(1, $result['tabCounts']['events']['unreviewed']);
        $this->assertSame(1, $result['hero']['badges']['unreviewed']);
        $this->assertTrue($result['events']['data'][0]['needs_review']);
        $this->assertNull($result['detail']);
        $this->assertSame('unknown', $result['plans']['data'][0]['authorization_state']);
        $json = json_encode($result);
        foreach (['Sensitive factual source', 'plan_authorization_snapshot', 'actor_snapshot', 'approver_snapshot', 'policy_snapshot', 'storage_path', 'attachments'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    public function test_every_read_excludes_foreign_moved_and_malformed_sources(): void
    {
        $visible = $this->event();
        $bad = $this->event();
        $bad->forceFill(['recorded_client_id' => $this->client->id + 10000])->save();
        $other = Site::factory()->create(['is_active' => true]);
        $foreignClient = Client::factory()->create(['site_id' => $other->id]);
        RestraintEvent::factory()->create(['client_id' => $foreignClient->id, 'source_schema_version' => null]);
        BehaviourSupportPlan::factory()->create(['client_id' => $foreignClient->id, 'title' => 'Foreign hidden plan']);
        $moved = Client::factory()->create(['site_id' => $this->site->id]);
        $historical = RestraintEvent::factory()->create(['client_id' => $moved->id, 'source_schema_version' => null]);
        $moved->forceFill(['site_id' => $other->id])->save();
        $result = $this->register()->register($this->actor, ['period' => 'all']);
        $this->assertSame([$visible->id], $this->ids($result['events']['data']));
        $this->assertSame(1, $result['tabCounts']['events']['all']);
        $this->assertNotContains($historical->id, $this->ids($result['events']['data']));
        $this->assertStringNotContainsString('Foreign hidden plan', json_encode($result));
        $this->assertSame(1, $this->register()->summary($this->actor, $this->client)['total_events']);
        $rows = [];
        $this->register()->export($this->actor, [], function ($row) use (&$rows) {
            $rows[] = $row;
        });
        $this->assertCount(2, $rows);
        $this->denied(fn () => $this->register()->summary($this->actor, $moved));
    }

    public function test_nz_day_filter_includes_whole_dst_day_and_export_matches(): void
    {
        $inside = $this->event(['started_at' => '2026-09-27T23:59:59+13:00', 'ended_at' => '2026-09-28T00:00:00+13:00']);
        $before = $this->event(['started_at' => '2026-09-26T23:59:59+12:00', 'ended_at' => '2026-09-27T00:00:00+12:00']);
        $after = $this->event(['started_at' => '2026-09-28T00:00:00+13:00', 'ended_at' => '2026-09-28T00:01:00+13:00']);
        $filters = ['from' => '2026-09-27', 'to' => '2026-09-27', 'period' => 'all'];
        $result = $this->register()->register($this->actor, $filters);
        $this->assertSame([$inside->id], $this->ids($result['events']['data']));
        $this->assertNotContains($before->id, $this->ids($result['events']['data']));
        $this->assertNotContains($after->id, $this->ids($result['events']['data']));
        $rows = [];
        $this->register()->export($this->actor, $filters, function ($row) use (&$rows) {
            $rows[] = $row;
        });
        $this->assertCount(2, $rows);
        $this->assertSame('2026-09-27 23:59', $rows[1][5]);
        $this->invalid(fn () => $this->register()->register($this->actor, ['from' => '2026-02-30']), 'from');
    }

    public function test_source_search_filters_tabs_and_pagination_preserve_full_totals(): void
    {
        $first = $this->event(['severity' => 'critical', 'within_support_plan' => false, 'deviation_reason' => 'Immediate factual safety risk', 'injury_occurred' => true, 'injury_details' => 'Actual injury reported', 'restraint_type' => 'physical', 'restraint_description' => 'Exact register search']);
        for ($i = 0; $i < 21; $i++) {
            $this->event(['severity' => 'low', 'restraint_description' => 'Other source']);
        }
        $all = $this->register()->register($this->actor, ['period' => 'all']);
        $this->assertSame(22, $all['events']['total']);
        $this->assertCount(20, $all['events']['data']);
        $second = $this->register()->register($this->actor, ['period' => 'all', 'events_page' => 2]);
        $this->assertCount(2, $second['events']['data']);
        $filtered = $this->register()->register($this->actor, ['period' => 'all', 'q' => 'Exact register search', 'tab' => 'injury', 'severity' => 'critical', 'within_plan' => 'no', 'restraint_type' => 'physical']);
        $this->assertSame([$first->id], $this->ids($filtered['events']['data']));
        $this->assertSame(1, $filtered['tabCounts']['events']['critical']);
    }

    public function test_client_summary_is_five_recent_rows_and_source_safe_legacy_plan_facts(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->event(['started_at' => now()->subDays($i + 1)->toIso8601String(), 'ended_at' => now()->subDays($i + 1)->addMinutes(2)->toIso8601String()]);
        }
        $plan = $this->legacyPlan();
        $result = $this->register()->summary($this->actor, $this->client);
        $this->assertSame(7, $result['total_events']);
        $this->assertCount(5, $result['recent_events']);
        $this->assertSame($plan->id, $result['active_plan']['id']);
        $this->assertNull($result['active_plan']['published_version_id']);
        $this->assertSame('unknown', $result['active_plan']['authorization_state']);
        $this->assertArrayNotHasKey('approved_interventions', $result['active_plan']);
    }

    public function test_capture_only_and_review_only_pick_sources_without_register_permission(): void
    {
        $event = $this->event();
        $capture = $this->staff(['restraints.create', 'clients.viewAny']);
        $review = $this->staff(['restraints.review', 'clients.viewAny']);
        $this->assertSame([$this->client->id], $this->ids($this->register()->pickers($capture)['clients']));
        $this->denied(fn () => $this->register()->register($capture));
        $result = $this->register()->pickers($review, [], $event);
        $this->assertSame([$this->client->id], $this->ids($result['clients']));
        $this->assertSame([$this->site->id], $this->ids($result['sites']));
        $this->assertContains($review->id, $this->ids($result['reviewers']));
        $this->assertNotContains($capture->id, $this->ids($result['reviewers']));
        $this->denied(fn () => $this->register()->register($review));
    }

    public function test_workspace_pickers_do_not_disclose_another_client_incident_or_plan(): void
    {
        $event = $this->event();
        $other = Client::factory()->create(['site_id' => $this->site->id]);
        $ownIncident = ClientIncident::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'status' => 'submitted', 'is_hr_confidential' => false]);
        ClientIncident::factory()->create(['client_id' => $other->id, 'site_id' => $this->site->id, 'status' => 'submitted']);
        $ownPlan = $this->legacyPlan();
        BehaviourSupportPlan::factory()->create(['client_id' => $other->id, 'title' => 'Unrelated source']);
        $result = $this->register()->pickers($this->actor, ['client_id' => $other->id], $event);
        $this->assertSame([$this->client->id], $this->ids($result['clients']));
        $this->assertSame([$ownIncident->id], $this->ids($result['incidents']));
        $this->assertSame([$ownPlan->id], $this->ids($result['plansForPicker']));
        $this->assertStringNotContainsString('Unrelated source', json_encode($result));
        $this->revoke($this->actor, 'incidents.update');
        $this->assertSame([], $this->register()->pickers($this->actor, [], $event)['incidents']);
    }

    public function test_picker_staff_and_owners_use_current_hr_site_and_source_authority(): void
    {
        $event = $this->event();
        $ended = $this->staff(['restraints.review', 'clients.viewAny']);
        $future = $this->staff(['restraints.review', 'clients.viewAny']);
        $unassigned = $this->staff(['restraints.review', 'clients.viewAssigned']);
        HrEmployeeProfile::where('user_id', $ended->id)->update(['end_date' => now('Pacific/Auckland')->subDay()->toDateString()]);
        HrEmployeeProfile::where('user_id', $future->id)->update(['start_date' => now('Pacific/Auckland')->addDay()->toDateString()]);
        $result = $this->register()->pickers($this->actor, [], $event);
        $this->assertNotContains($ended->id, $this->ids($result['staff']));
        $this->assertNotContains($future->id, $this->ids($result['staff']));
        $this->assertNotContains($unassigned->id, $this->ids($result['reviewers']));
        $this->assertSame($result['reviewers'], $result['followupOwners']);
    }

    public function test_fresh_rbac_client_and_hr_revocation_defeat_warm_callers(): void
    {
        $this->event();
        $this->actor->load(['permissionOverrides', 'roles.permissions']);
        $this->assertSame(1, $this->register()->register($this->actor)['events']['total']);
        $this->revoke($this->actor, 'restraints.view');
        $this->denied(fn () => $this->register()->register($this->actor));
        $this->denied(fn () => $this->register()->summary($this->actor, $this->client));
        $wrote = false;
        $this->denied(fn () => $this->register()->export($this->actor, [], function () use (&$wrote) {
            $wrote = true;
        }));
        $this->assertFalse($wrote);
        $this->actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'restraints.view')->sole()->id, ['allowed' => true]);
        $this->revoke($this->actor, 'clients.viewAny');
        $this->assertSame(0, $this->register()->register($this->actor)['events']['total']);
        HrEmployeeProfile::where('user_id', $this->actor->id)->update(['is_active' => false]);
        $this->denied(fn () => $this->register()->pickers($this->actor));
    }

    public function test_linked_incident_requires_its_own_permission_and_hr_privacy(): void
    {
        $incident = ClientIncident::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'is_hr_confidential' => true]);
        $event = $this->event(['related_incident_id' => null]);
        $event->forceFill(['related_incident_id' => $incident->id])->save();
        $row = $this->register()->register($this->actor)['events']['data'][0];
        $this->assertNull($row['related_incident_id']);
        $this->assertFalse($row['flags']['linked_incident']);
        $this->assertSame([], $this->register()->pickers($this->actor, [], $event)['incidents']);
    }

    public function test_readable_incident_relation_still_requires_exact_nullable_stay_tuple(): void
    {
        $booking = RespiteBooking::factory()->create(['client_id' => $this->client->id, 'location_id' => $this->site->id, 'status' => 'confirmed']);
        $stay = RespiteStay::create(['booking_id' => $booking->id, 'client_id' => $this->client->id, 'status' => 'active', 'actual_start' => now()->subDay()]);
        $incident = ClientIncident::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'respite_stay_id' => $stay->id, 'is_hr_confidential' => false]);
        $this->assertTrue(app(IncidentAccessService::class)->canView($this->actor, $incident));
        $event = $this->event();
        $event->forceFill(['related_incident_id' => $incident->id])->save();
        $data = $this->register()->register($this->actor)['events']['data'][0];
        $this->assertNull($data['related_incident_id']);
        $this->assertFalse($data['flags']['linked_incident']);
        $incident->forceFill(['respite_stay_id' => null])->save();
        $matching = $this->register()->register($this->actor)['events']['data'][0];
        $this->assertSame($incident->id, $matching['related_incident_id']);
        $this->assertTrue($matching['flags']['linked_incident']);
    }

    public function test_plan_picker_presents_only_exact_source_asserted_version_ids(): void
    {
        $receipt = app(BehaviourSupportPlanService::class)->create($this->actor, ['client_id' => $this->client->id, 'title' => 'Immutable candidate', 'triggers' => 'Noise', 'de_escalation_strategies' => 'Quiet support', 'approved_interventions' => 'Private content', 'prohibited_interventions' => 'Private limitations', 'developed_at' => '2026-10-01', 'review_date' => '2026-11-30', 'idempotency_key' => (string) Str::uuid()]);
        $plan = BehaviourSupportPlan::findOrFail($receipt['plan_id']);
        $row = $this->register()->pickers($this->actor)['plansForPicker'][0];
        $this->assertSame($plan->current_candidate_version_id, $row['candidate_version_id']);
        $this->assertNull($row['published_version_id']);
        $this->assertSame('unknown', $row['authorization_state']);
        $this->assertStringNotContainsString('Private content', json_encode($row));
        $foreign = Client::factory()->create(['site_id' => $this->site->id]);
        $wrong = BehaviourSupportPlanVersion::create(['behaviour_support_plan_id' => $plan->id, 'sequence' => 99, 'kind' => 'candidate', 'client_id' => $foreign->id, 'site_id' => $this->site->id, 'context_origin' => 'client', 'content' => [], 'content_digest' => str_repeat('a', 64), 'recorded_by' => $this->actor->id, 'actor_snapshot' => [], 'recorded_at' => now(), 'command_key' => (string) Str::uuid()]);
        $plan->forceFill(['current_candidate_version_id' => $wrong->id])->save();
        $this->assertNull($this->register()->pickers($this->actor)['plansForPicker'][0]['candidate_version_id']);
    }

    public function test_current_published_picker_proof_is_exact_and_rechecks_revocation(): void
    {
        $plans = app(BehaviourSupportPlanService::class);
        $receipt = $plans->create($this->actor, ['client_id' => $this->client->id, 'title' => 'Exact published source', 'triggers' => 'Noise', 'de_escalation_strategies' => 'Quiet support', 'approved_interventions' => 'Private content', 'prohibited_interventions' => 'Private limitations', 'developed_at' => '2026-10-01', 'review_date' => '2026-11-30', 'idempotency_key' => (string) Str::uuid()]);
        $plan = BehaviourSupportPlan::findOrFail($receipt['plan_id']);
        $candidate = BehaviourSupportPlanVersion::findOrFail($plan->current_candidate_version_id);
        $policy = app(BehaviourSupportApprovalPolicySettings::class)->snapshot();
        $specialist = $this->staff(['restraints.approve', 'clients.viewAny']);
        $target = ['candidate_version_id' => $candidate->id, 'content_digest' => $candidate->content_digest, 'expected_policy_revision' => $policy['policy_revision']];
        $decision = app(BehaviourSupportPlanApprovalService::class)->decide($plan, $specialist, ['expected_version' => $plan->lock_version, 'idempotency_key' => (string) Str::uuid(), ...$target, 'approval_decision' => 'approve', 'origin' => 'internal_specialist', 'approver_role' => 'Organisation designated specialist', 'approved_at' => now()->subDay()->toIso8601String(), 'effective_from' => now()->subDay()->toIso8601String(), 'basis' => 'Actual plan-specific decision', 'evidence_references' => [], 'activate_requested' => false]);
        $published = $plans->activate($plan, $this->actor, ['expected_version' => $plan->fresh()->lock_version, 'idempotency_key' => (string) Str::uuid(), ...$target]);
        $row = $this->register()->pickers($this->actor)['plansForPicker'][0];
        $this->assertSame($published['published_version_id'], $row['published_version_id']);
        $this->assertSame('verified', $row['authorization_state']);
        $this->assertArrayNotHasKey('policy_snapshot', $row);
        $this->assertArrayNotHasKey('approval_id', $row);
        app(BehaviourSupportPlanApprovalService::class)->decide($plan, $specialist, ['expected_version' => $plan->fresh()->lock_version, 'idempotency_key' => (string) Str::uuid(), ...$target, 'approval_decision' => 'revoke', 'origin' => 'internal_specialist', 'approver_role' => 'Organisation designated specialist', 'approved_at' => now()->toIso8601String(), 'effective_from' => now()->toIso8601String(), 'basis' => 'Actual withdrawal', 'supersedes_approval_id' => $decision['approval_id'], 'evidence_references' => [], 'activate_requested' => false]);
        $this->assertSame('withdrawn', $this->register()->pickers($this->actor)['plansForPicker'][0]['authorization_state']);
    }

    public function test_plan_export_keeps_archived_sources_and_filters_exact_site(): void
    {
        $archived = $this->legacyPlan(['status' => 'archived']);
        $rows = [];
        $this->register()->export($this->actor, ['lens' => 'plans', 'site_id' => $this->site->id], function ($row) use (&$rows) {
            $this->assertGreaterThan(0, DB::transactionLevel());
            $rows[] = $row;
        });
        $this->assertCount(2, $rows);
        $this->assertSame($archived->reference_number, $rows[1][0]);
        $foreign = Site::factory()->create(['is_active' => true]);
        $this->denied(fn () => $this->register()->export($this->actor, ['site_id' => $foreign->id], fn () => null));
    }

    public function test_review_date_today_is_due_not_overdue_in_nz(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10T11:05:00Z'));
        $plan = $this->legacyPlan(['review_date' => '2026-10-11']);
        $result = $this->register()->register($this->actor, ['period' => 'all', 'lens' => 'plans']);
        $this->assertSame('due', $result['plans']['data'][0]['review_state']);
        $this->assertSame(0, $result['hero']['badges']['plans_overdue']);
        $this->assertSame(1, $result['tabCounts']['plans']['review_due']);
        $this->assertSame($plan->id, $result['plans']['data'][0]['id']);
    }

    public function test_http_register_and_client_summary_use_source_safe_adapter(): void
    {
        $event = $this->event();
        $event->forceFill(['reviewed_at' => now(), 'reviewed_source_revision' => 1, 'source_revision' => 2])->save();
        $this->actingAs($this->actor)->get(route('health-safety.restraints.index', ['period' => 'all']))->assertOk()->assertInertia(fn (Assert $page) => $page->component('health-safety/restraints/workspace-index')->where('events.total', 1)->where('events.data.0.needs_review', true)->where('detail', null)->where('tabCounts.events.unreviewed', 1));
        $this->getJson(route('health-safety.restraints.clients.summary', $this->client))->assertOk()->assertJsonPath('total_events', 1)->assertJsonPath('recent_events.0.needs_review', true)->assertJsonMissing(['restraint_description' => 'Sensitive factual source']);
    }

    public function test_http_summary_and_export_reject_foreign_client_filters(): void
    {
        $other = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $other->id]);
        $this->actingAs($this->actor)->getJson(route('health-safety.restraints.clients.summary', $client))->assertForbidden();
        $this->get(route('health-safety.restraints.export', ['client_id' => $client->id]))->assertForbidden();
    }
}
