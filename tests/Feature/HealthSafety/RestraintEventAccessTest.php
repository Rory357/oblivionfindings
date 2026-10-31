<?php

namespace Tests\Feature\HealthSafety;

use App\Models\BehaviourSupportPlan;
use App\Models\BehaviourSupportPlanVersion;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\RespiteBooking;
use App\Models\RespiteStay;
use App\Models\RestraintCommandReceipt;
use App\Models\RestraintEvent;
use App\Models\RestraintEventReview;
use App\Models\Site;
use App\Services\HealthSafety\BehaviourSupportApprovalPolicySettings;
use App\Services\HealthSafety\BehaviourSupportPlanApprovalService;
use App\Services\HealthSafety\BehaviourSupportPlanService;
use App\Services\HealthSafety\RestraintSourceProjectionService;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RestraintEventAccessTest extends RestraintEventCommandTestCase
{
    public function test_loaded_actor_rbac_hr_site_and_client_revocation_deny_replay_and_source_detail(): void
    {
        $input = $this->capture();
        $accepted = $this->service()->create($this->actor, $input);
        $this->actor->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->revoke($this->actor, 'restraints.create');
        $this->revoke($this->actor, 'restraints.manage');
        $this->denied(fn () => $this->service()->create($this->actor, $input));
        $this->assertSame(1, RestraintCommandReceipt::count());
        $this->actor->hrEmployeeProfile()->update(['is_active' => false]);
        $this->denied(fn () => app(RestraintSourceProjectionService::class)->event(RestraintEvent::find($accepted['event_id']), $this->actor));
    }

    public function test_typed_source_cannot_be_transferred_and_current_client_assignment_is_independent(): void
    {
        $row = $this->event();
        $viewer = $this->staff(['restraints.view', 'clients.viewAssigned']);
        $this->client->supportWorkers()->attach($viewer->id);
        $projection = app(RestraintSourceProjectionService::class);
        $this->assertSame($row->id, $projection->event($row, $viewer)['event']['id']);
        $this->client->supportWorkers()->detach($viewer->id);
        $this->denied(fn () => $projection->event($row, $viewer));
        $row->forceFill(['recorded_site_id' => $this->site->id + 1000])->saveQuietly();
        $this->denied(fn () => $projection->event($row, $this->actor));
    }

    public function test_capture_only_receipt_does_not_grant_narrative_and_review_only_workspace_retains_operation(): void
    {
        $capturer = $this->staff(['restraints.create', 'clients.viewAny']);
        $receipt = $this->service()->create($capturer, $this->capture());
        $row = RestraintEvent::findOrFail($receipt['event_id']);
        $this->denied(fn () => app(RestraintSourceProjectionService::class)->event($row, $capturer));
        $reviewer = $this->staff(['restraints.review', 'clients.viewAny']);
        $this->assertSame($row->id, app(RestraintSourceProjectionService::class)->event($row, $reviewer, true)['event']['id']);
        $this->denied(fn () => app(RestraintSourceProjectionService::class)->event($row, $reviewer, false));
    }

    public function test_external_portal_future_staff_and_inactive_site_do_not_gain_authority_from_positive_grants(): void
    {
        $row = $this->event();
        $projection = app(RestraintSourceProjectionService::class);
        $external = $this->staff(['restraints.view', 'clients.viewAny']);
        $external->forceFill(['external_clinical_account' => true])->save(); // Not mass-assignable on User.
        $this->denied(fn () => $projection->event($row, $external));
        $portal = $this->staff(['restraints.view', 'clients.viewAny']);
        $portal->update(['role' => 'client']);
        $this->denied(fn () => $projection->event($row, $portal));
        $this->actor->hrEmployeeProfile()->update(['start_date' => now('Pacific/Auckland')->addDay()->toDateString()]);
        $this->denied(fn () => $projection->event($row, $this->actor));
        $this->actor->hrEmployeeProfile()->update(['start_date' => '2020-01-01']);
        $this->site->update(['is_active' => false]);
        $this->denied(fn () => $projection->event($row, $this->actor));
    }

    public function test_projection_retains_factual_history_without_private_command_metadata_or_inactive_directory_identity(): void
    {
        $reviewer = $this->staff(['restraints.review', 'clients.viewAny']);
        $row = $this->event(['staff_involved' => [$reviewer->id]]);
        $this->service()->review($row, $this->actor, $this->command($row, ['reviewed_by' => $reviewer->id, 'reviewed_at' => now()->subMinutes(5)->toIso8601String(), 'review_notes' => 'Retained actual review']));
        $projection = app(RestraintSourceProjectionService::class);
        $current = $projection->event($row, $this->actor);
        $this->assertSame($reviewer->name, $current['event']['reviews'][0]['actual_actor']['name']);
        $reviewer->hrEmployeeProfile()->update(['is_active' => false]);
        $dto = $projection->event($row, $this->actor);
        $this->assertSame('Retained actual review', $dto['event']['reviews'][0]['facts']['review_notes']);
        $this->assertSame('identity_retained', $dto['event']['reviews'][0]['actual_actor']['visibility']);
        $this->assertNull($dto['event']['reviews'][0]['actual_actor']['id']);
        $this->assertNull($dto['event']['reviewed_by']['name']);
        $json = json_encode($dto, JSON_THROW_ON_ERROR);
        foreach (['creation_key', 'creation_fingerprint', 'plan_authorization_snapshot', 'command_key', 'actor_snapshot', 'actual_actor_snapshot', 'initiated_by_user_id', 'membership', 'dedupe_key', 'hs_event_id', 'control_room_alert_id', 'linked_incident_id'] as $private) {
            $this->assertStringNotContainsString('"'.$private.'"', $json);
        }
        $this->assertStringNotContainsString($reviewer->name, $json);
        $this->assertSame($reviewer->name, RestraintEventReview::where('restraint_event_id', $row->id)->sole()->actual_actor_snapshot['name']);
    }

    public function test_event_plan_connections_require_independent_current_parent_and_exact_published_approval_chain(): void
    {
        $plans = app(BehaviourSupportPlanService::class);
        $created = $plans->create($this->actor, ['client_id' => $this->client->id, 'title' => 'Actual support plan', 'developed_at' => now()->subDays(3)->toDateString(), 'review_date' => now()->addMonth()->toDateString(), 'notes' => 'Independent private plan narrative', 'idempotency_key' => (string) Str::uuid()]);
        $plan = BehaviourSupportPlan::findOrFail($created['plan_id']);
        $candidate = BehaviourSupportPlanVersion::findOrFail($plan->current_candidate_version_id);
        $specialist = $this->staff(['restraints.approve', 'clients.viewAny']);
        $target = ['candidate_version_id' => $candidate->id, 'content_digest' => $candidate->content_digest, 'expected_policy_revision' => app(BehaviourSupportApprovalPolicySettings::class)->snapshot()['policy_revision']];
        $approved = app(BehaviourSupportPlanApprovalService::class)->decide($plan, $specialist, ['expected_version' => $plan->fresh()->lock_version, 'idempotency_key' => (string) Str::uuid(), ...$target, 'approval_decision' => 'approve', 'origin' => 'internal_specialist', 'approver_role' => 'Named organisation specialist', 'approved_at' => now()->subDay()->toIso8601String(), 'effective_from' => now()->subDay()->toIso8601String(), 'basis' => 'Actual source-specific approval basis', 'evidence_references' => [], 'activate_requested' => false]);
        $published = $plans->activate($plan, $this->actor, ['expected_version' => $plan->fresh()->lock_version, 'idempotency_key' => (string) Str::uuid(), ...$target]);
        $row = $this->event(['behaviour_support_plan_id' => $plan->id, 'behaviour_support_plan_version_id' => $published['published_version_id']]);
        $projection = app(RestraintSourceProjectionService::class);
        $dto = $projection->event($row, $this->actor);
        $this->assertSame($published['published_version_id'], $dto['connections']['plan_version']['id']);
        $this->assertSame($approved['approval_id'], $dto['connections']['plan_approval']['id']);
        $this->assertSame('verified', $dto['event']['plan_authorization']['state']);
        $this->assertStringNotContainsString('Independent private plan narrative', json_encode($dto));
        $snapshot = $row->fresh()->plan_authorization_snapshot;
        $row->updateQuietly(['plan_authorization_snapshot' => [...$snapshot, 'approval_id' => $approved['approval_id'] + 1000]]);
        $mismatch = $projection->event($row, $this->actor);
        $this->assertNull($mismatch['connections']['plan_version']);
        $this->assertNull($mismatch['connections']['plan_approval']);
        $row->updateQuietly(['plan_authorization_snapshot' => $snapshot]);
        $plan->delete();
        $hidden = $projection->event($row, $this->actor);
        $this->assertSame($row->id, $hidden['event']['id']);
        $this->assertNull($hidden['connections']['plan']);
        $this->assertNull($hidden['connections']['plan_version']);
        $this->assertNull($hidden['connections']['plan_approval']);
        $this->assertArrayNotHasKey('behaviour_support_plan_version_id', $hidden['event']);
    }

    public function test_capture_only_incident_association_preserves_exact_replay_but_never_grants_post_capture_link(): void
    {
        $capturer = $this->staff(['restraints.create', 'clients.viewAny', 'incidents.viewAny', 'incidents.update']);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'reported_by' => $capturer->id, 'status' => 'draft']));
        $input = $this->capture(['related_incident_id' => $incident->id]);
        $accepted = $this->service()->create($capturer, $input);
        $row = RestraintEvent::findOrFail($accepted['event_id']);
        $this->assertSame($incident->id, $row->related_incident_id);
        $this->assertTrue($this->service()->create($capturer, $input)['replayed']);
        $this->assertSame('draft', $incident->fresh()->status);
        $this->assertNull($incident->fresh()->hs_event_id);
        $this->denied(fn () => $this->service()->unlinkIncident($row, $capturer, $this->command($row, ['reason' => 'No review grant'])));
        $this->denied(fn () => $this->service()->linkIncident($row, $capturer, $this->command($row, ['related_incident_id' => $incident->id])));
        $capturer->load('permissionOverrides', 'roles.permissions');
        $this->revoke($capturer, 'incidents.update');
        $this->denied(fn () => $this->service()->create($capturer, $input));
        $this->assertNull($this->service()->confirmationForViewer($capturer, $accepted));
        $this->assertSame(1, RestraintCommandReceipt::where('source_type', 'event')->count());
    }

    public function test_capture_incident_scope_rejects_foreign_client_site_and_closed_incident_without_any_receipt(): void
    {
        $capturer = $this->staff(['restraints.create', 'clients.viewAny', 'incidents.viewAny', 'incidents.update']);
        $otherClient = Client::factory()->create(['site_id' => $this->site->id]);
        $otherSite = Site::factory()->create(['is_active' => true]);
        foreach ([['client_id' => $otherClient->id, 'site_id' => $this->site->id, 'status' => 'draft'], ['client_id' => $this->client->id, 'site_id' => $otherSite->id, 'status' => 'draft'], ['client_id' => $this->client->id, 'site_id' => $this->site->id, 'status' => 'closed']] as $facts) {
            $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([...$facts, 'reported_by' => $capturer->id]));
            $this->denied(fn () => $this->service()->create($capturer, $this->capture(['related_incident_id' => $incident->id])));
        }
        $this->assertDatabaseCount('restraint_events', 0);
        $this->assertDatabaseCount('restraint_command_receipts', 0);
        $this->assertDatabaseCount('restraint_source_deliveries', 0);
    }

    private function incidentStay(): RespiteStay
    {
        $booking = RespiteBooking::factory()->create(['client_id' => $this->client->id, 'location_id' => $this->site->id, 'status' => 'confirmed', 'start_at' => now()->subDay(), 'end_at' => now()->addDay()]);

        return RespiteStay::create(['booking_id' => $booking->id, 'client_id' => $this->client->id, 'status' => 'active', 'actual_start' => now()->subDay()]);
    }

    private function contextNotFound(callable $operation): void
    {
        try {
            $operation();
            $this->fail('An independently readable incident from a different stay must remain concealed.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
    }

    public function test_capture_requires_exact_nullable_incident_stay_even_with_full_incident_authority(): void
    {
        $first = $this->incidentStay();
        $second = $this->incidentStay();
        $coordinator = $this->staff(['respite.stays.manage', 'respite.viewAny', 'clients.viewAny', 'incidents.viewAny', 'incidents.update']);
        foreach ([$second->id, null] as $incidentStay) {
            $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'respite_stay_id' => $incidentStay, 'reported_by' => $coordinator->id, 'status' => 'draft']));
            $this->contextNotFound(fn () => $this->service()->createForStay($coordinator, $first, $this->capture(['related_incident_id' => $incident->id])));
        }
        $foreignStayIncident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'respite_stay_id' => $first->id, 'reported_by' => $this->actor->id, 'status' => 'draft']));
        $this->contextNotFound(fn () => $this->service()->create($this->actor, $this->capture(['related_incident_id' => $foreignStayIncident->id])));
        $this->assertDatabaseCount('restraint_events', 0);
        $this->assertDatabaseCount('restraint_command_receipts', 0);
        $this->assertDatabaseCount('restraint_source_deliveries', 0);
    }

    public function test_capture_retry_receipt_and_private_connection_recheck_current_incident_stay(): void
    {
        $first = $this->incidentStay();
        $second = $this->incidentStay();
        $coordinator = $this->staff(['respite.stays.manage', 'respite.viewAny', 'restraints.view', 'clients.viewAny', 'incidents.viewAny', 'incidents.update']);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'respite_stay_id' => $first->id, 'reported_by' => $coordinator->id, 'status' => 'draft']));
        $input = $this->capture(['related_incident_id' => $incident->id]);
        $accepted = $this->service()->createForStay($coordinator, $first, $input);
        $row = RestraintEvent::findOrFail($accepted['event_id']);
        $this->assertTrue($this->service()->createForStay($coordinator, $first, $input)['replayed']);
        $this->assertSame($incident->id, app(RestraintSourceProjectionService::class)->event($row, $coordinator)['connections']['incident']['id']);
        $incident->updateQuietly(['respite_stay_id' => $second->id]);
        $this->contextNotFound(fn () => $this->service()->createForStay($coordinator, $first, $input));
        $this->assertNull($this->service()->confirmationForViewer($coordinator, $accepted));
        $this->assertNull(app(RestraintSourceProjectionService::class)->event($row, $coordinator)['connections']['incident']);
        $this->assertDatabaseCount('restraint_command_receipts', 1);
        $this->assertSame(1, $row->fresh()->lock_version);
        $this->assertSame('draft', $incident->fresh()->status);
    }

    public function test_post_capture_link_and_exact_retry_cannot_cross_a_current_incident_stay(): void
    {
        $stay = $this->incidentStay();
        $row = $this->event();
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'respite_stay_id' => null, 'reported_by' => $this->actor->id, 'status' => 'draft']));
        $input = $this->command($row, ['related_incident_id' => $incident->id]);
        $accepted = $this->service()->linkIncident($row, $this->actor, $input);
        $this->assertTrue($this->service()->linkIncident($row, $this->actor, $input)['replayed']);
        $incident->updateQuietly(['respite_stay_id' => $stay->id]);
        $this->contextNotFound(fn () => $this->service()->linkIncident($row, $this->actor, $input));
        $this->contextNotFound(fn () => $this->service()->linkIncident($row, $this->actor, $this->command($row, ['related_incident_id' => $incident->id])));
        $this->assertNull($this->service()->confirmationForViewer($this->actor, $accepted));
        $this->assertNull(app(RestraintSourceProjectionService::class)->event($row, $this->actor)['connections']['incident']);
        $this->assertDatabaseCount('restraint_command_receipts', 2);
        $this->assertSame(2, $row->fresh()->lock_version);
        $this->assertNull($incident->fresh()->hs_event_id);
        $this->assertSame('draft', $incident->fresh()->status);
    }

    public function test_source_labels_legacy_staff_text_and_exact_named_followup_actions_remain_truthful(): void
    {
        $row = RestraintEvent::withoutEvents(fn () => RestraintEvent::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'created_by' => $this->actor->id, 'staff_involved' => 'Historical recorded participant']));
        $executor = $this->staff(['restraints.view', 'clients.viewAny']);
        $accepted = $this->service()->addFollowup($row, $this->actor, $this->command($row, ['notes' => 'Actual person-centred follow-up', 'assigned_to_user_id' => $executor->id]));
        $projection = app(RestraintSourceProjectionService::class);
        $executorDto = $projection->event($row, $executor);
        $this->assertSame(trim($this->client->first_name.' '.$this->client->last_name), $executorDto['event']['client']['name']);
        $this->assertSame($this->site->name, $executorDto['event']['site']['name']);
        $this->assertSame(['kind' => 'historical_text', 'text' => 'Historical recorded participant'], $executorDto['event']['staff_involved'][0]);
        $this->assertTrue($executorDto['event']['followups'][0]['can']['complete']);
        $this->assertFalse($executorDto['event']['followups'][0]['can']['reassign']);
        $this->assertFalse($executorDto['event']['followups'][0]['can']['cancel']);
        $managerDto = $projection->event($row, $this->actor);
        $this->assertFalse($managerDto['event']['followups'][0]['can']['complete']);
        $this->assertTrue($managerDto['event']['followups'][0]['can']['reassign']);
        $this->assertTrue($managerDto['event']['followups'][0]['can']['cancel']);
        $this->service()->correct($row, $this->actor, $this->command($row, ['person_response' => 'Later factual response', 'reason' => 'Actual later discovery']));
        $this->assertSame('Historical recorded participant', $row->fresh()->staff_involved);
        $this->service()->completeFollowup($row, $executor, $this->command($row, ['followup_id' => $accepted['followup_id'], 'completed_at' => now()->subMinute()->toIso8601String(), 'outcome' => 'contacted', 'notes' => 'Actual contact']));
        $closedDto = $projection->event($row, $executor);
        $this->assertFalse($closedDto['event']['followups'][0]['can']['complete']);
        $this->assertFalse($closedDto['event']['followups'][0]['can']['reassign']);
        $this->assertFalse($closedDto['event']['followups'][0]['can']['cancel']);
        $this->revoke($executor, 'restraints.view');
        $this->denied(fn () => $projection->event($row, $executor));
    }
}
