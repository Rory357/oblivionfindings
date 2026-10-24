<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsRiskAssessment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\RiskAssessmentWorkspaceConnectionsService;
use App\Support\HealthSafety\RiskAssessmentPresenter;
use App\Support\HealthSafety\RiskAssessmentReturn;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HsRiskAssessmentWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->firstOrFail());
        $this->site = Site::factory()->create(['type' => 'house']);
        // H&S data needs current staff (Stephan, 25 Oct): the admin works at the Site.
        \App\Domain\Hr\Models\HrEmployeeProfile::factory()->create(['user_id' => $this->admin->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'start_date' => '2020-01-01', 'end_date' => null, 'is_active' => true]);
    }

    public function test_show_content_negotiates_permanent_page_and_preserves_json_detail(): void
    {
        $assessment = $this->risk();
        $origin = '/health-safety/risk-assessments?tab=drafts&site_id='.$this->site->id.'&page=2';
        $this->actingAs($this->admin)->get(RiskAssessmentReturn::href($assessment->id, $origin))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('health-safety/risk-assessments/show')->where('detail.id', $assessment->id)
            ->where('return_to', $origin)->has('pickers')->has('detail.history')->has('detail.connections'));
        $this->getJson('/health-safety/risk-assessments/'.$assessment->id)->assertOk()
            ->assertJsonPath('detail.id', $assessment->id)->assertJsonPath('detail.can.edit', true);
    }

    public function test_legacy_query_redirects_with_sanitized_context_and_denies_foreign_record(): void
    {
        $assessment = $this->risk();
        $viewer = $this->reader(['hazards.view']);
        $origin = '/health-safety/risk-assessments?tab=drafts&site_id='.$this->site->id.'&search=mat';
        $this->actingAs($viewer)->get($origin.'&assessment='.$assessment->id.'&action=archive')
            ->assertRedirect(RiskAssessmentReturn::href($assessment->id, $origin));
        $foreign = $this->risk(['assessable_id' => Site::factory()->create()->id]);
        $this->get('/health-safety/risk-assessments?assessment='.$foreign->id)->assertForbidden();
        $this->getJson('/health-safety/risk-assessments/'.$foreign->id)->assertForbidden();
        $this->get(RiskAssessmentReturn::href($assessment->id, 'https://other.example'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('return_to', RiskAssessmentReturn::REGISTER));
    }

    public function test_terminal_details_preserve_unknown_risk_and_disable_mutation_commands(): void
    {
        $this->actingAs($this->admin);
        foreach (['superseded', 'archived'] as $status) {
            $assessment = $this->risk(['status' => $status, 'residual_likelihood' => null,
                'residual_consequence' => null, 'residual_risk_score' => null, 'residual_risk_level' => null,
                'risk_acceptable' => null, 'review_frequency_days' => null]);
            $detail = RiskAssessmentPresenter::detail($assessment, true);
            foreach (['edit', 'activate', 'mark_for_review', 'record_residual', 'complete_review', 'supersede', 'archive', 'add_evidence', 'remove_evidence'] as $command) {
                $this->assertFalse($detail['can'][$command], $command);
            }
            foreach (['residual_likelihood', 'residual_consequence', 'risk_acceptable', 'review_frequency_days'] as $field) {
                $this->assertNull($detail['form'][$field], $field);
            }
            $this->assertSame(['kind' => 'none', 'days' => null], $detail['review_state']);
        }
    }

    public function test_history_retains_recorded_actor_and_snapshots_without_invented_owner_or_private_metadata(): void
    {
        $writer = $this->reader(['hazards.view']);
        $this->actingAs($this->admin);
        $assessment = $this->risk(['assessed_by_user_id' => $this->admin->id, 'last_review_note' => 'Latest review',
            'additional_controls' => 'Later control', 'residual_risk_score' => 16]);
        $audit = AuditLog::create(['auditable_type' => HsRiskAssessment::class, 'auditable_id' => $assessment->id,
            'user_id' => $writer->id, 'action' => 'risk_assessment.residual_recorded',
            'meta' => ['note' => 'First review', 'snapshot' => ['additional_controls' => 'Original control',
                'residual_risk_score' => 2, 'risk_acceptable' => false, 'path' => 'private/secret.pdf'],
                'request' => ['ip_address' => '192.0.2.1'], 'owner_user_id' => $this->admin->id]]);
        $unknown = AuditLog::create(['auditable_type' => HsRiskAssessment::class, 'auditable_id' => $assessment->id,
            'user_id' => null, 'action' => 'risk_assessment.status_changed', 'meta' => ['to' => 'under_review']]);
        $detail = RiskAssessmentPresenter::detail($assessment, true);
        $history = collect($detail['history'])->keyBy('id');
        $this->assertSame($writer->id, $history['audit-'.$audit->id]['actor']['id']);
        $this->assertSame('First review', $history['audit-'.$audit->id]['note']);
        $this->assertEquals(['additional_controls' => 'Original control', 'residual_risk_score' => 2, 'risk_acceptable' => false], $history['audit-'.$audit->id]['snapshot']);
        $this->assertNull($history['audit-'.$unknown->id]['actor']);
        $this->assertSame([], $history['audit-'.$unknown->id]['snapshot']);
        $this->assertNull($history['audit-'.$unknown->id]['note']);
        $this->assertArrayNotHasKey('owner', $detail);
        $this->assertStringNotContainsString('private/secret.pdf', json_encode($detail['history']));
        $this->assertStringNotContainsString('192.0.2.1', json_encode($detail['history']));
    }

    public function test_legacy_history_is_identified_and_never_filled_from_later_current_state(): void
    {
        $this->actingAs($this->admin);
        $assessment = $this->risk(['additional_controls' => 'Latest controls', 'last_review_note' => 'Latest note']);
        $audit = AuditLog::create(['auditable_type' => HsRiskAssessment::class, 'auditable_id' => $assessment->id,
            'action' => 'hsriskassessment.update', 'user_id' => null,
            'meta' => ['after' => ['status' => 'under_review', 'last_review_note' => 'Old truncated note…',
                'superseded_by_id' => 999, 'path' => 'private/legacy.pdf']]]);
        $entry = collect(RiskAssessmentPresenter::detail($assessment, true)['history'])->firstWhere('id', 'audit-'.$audit->id);
        $this->assertTrue($entry['legacy']);
        $this->assertSame('Old truncated note…', $entry['note']);
        $this->assertArrayNotHasKey('additional_controls', $entry['snapshot']);
        $this->assertArrayNotHasKey('superseded_by_id', $entry['snapshot']);
        $this->assertNull($entry['actor']);
    }

    public function test_versions_reauthorize_destination_and_reject_changed_or_forged_source_context(): void
    {
        $this->actingAs($this->admin);
        $next = $this->risk();
        $old = $this->risk(['status' => 'superseded', 'superseded_by_id' => $next->id]);
        $viewer = $this->reader(['hazards.view']);
        $service = app(RiskAssessmentWorkspaceConnectionsService::class);
        $this->assertSame($next->id, $service->forViewer($old, $viewer)['versions']['next']['id']);
        $this->assertSame([$old->id], array_column($service->forViewer($next, $viewer)['versions']['previous'], 'id'));
        $next->updateQuietly(['assessable_id' => Site::factory()->create()->id]);
        $this->actingAs($viewer);
        $detail = RiskAssessmentPresenter::detail($old, false);
        $this->assertNull($detail['superseded_by']);
        $this->assertNull($detail['superseded_by_id']);
        $this->assertNull($service->forViewer($old, $this->admin)['versions']['next']);
        $old->updateQuietly(['superseded_by_id' => $old->id]);
        $this->assertNull($service->forViewer($old->fresh(), $this->admin)['versions']['next']);
    }

    public function test_linked_review_actions_and_tasks_retain_independent_state_and_canonical_due_day(): void
    {
        $event = HsEvent::factory()->create(['site_id' => $this->site->id, 'status' => 'monitoring', 'owner_user_id' => $this->admin->id]);
        $assessment = $this->risk(['hs_event_id' => $event->id, 'status' => 'under_review', 'risk_acceptable' => false]);
        $action = HsCorrectiveAction::factory()->createQuietly(['hs_event_id' => $event->id, 'status' => 'completed',
            'assigned_to_user_id' => $this->admin->id, 'due_date' => '2026-10-15']);
        $origin = '/health-safety/risk-assessments?tab=all&site_id='.$this->site->id;
        $connections = app(RiskAssessmentWorkspaceConnectionsService::class)->forViewer($assessment, $this->admin, $origin);
        $this->assertSame($event->id, $connections['safety_review']['id']);
        $this->assertSame($action->id, $connections['corrective_actions'][0]['id']);
        $this->assertSame('2026-10-15', $connections['corrective_actions'][0]['due_date']);
        $this->assertSame('Awaiting independent verification', $connections['corrective_actions'][0]['display_state']);
        $this->assertSame(['hs_event-'.$event->id, 'corrective_action-'.$action->id], array_column($connections['tasks'], 'id'));
        $expectedReturn = RiskAssessmentReturn::REGISTER.'/'.$assessment->id.'?section=connections&return_to='.rawurlencode($origin);
        foreach ([$connections['safety_review'], ...$connections['corrective_actions'], ...$connections['tasks']] as $work) {
            parse_str(parse_url($work['href'], PHP_URL_QUERY) ?? '', $workQuery);
            $this->assertSame($expectedReturn, $workQuery['return_to'] ?? null);
        }
        $this->actingAs($this->admin)->get($connections['safety_review']['href'])->assertOk()
            ->assertInertia(fn (Assert $page) => $page->url($connections['safety_review']['href']));
        $this->get($connections['corrective_actions'][0]['href'])->assertOk()
            ->assertInertia(fn (Assert $page) => $page->url($connections['corrective_actions'][0]['href']));
        $this->assertSame('under_review', $assessment->fresh()->status);
        $this->assertFalse($assessment->fresh()->risk_acceptable);
        $this->assertSame('completed', $action->fresh()->status);
        $event->updateQuietly(['site_id' => Site::factory()->create()->id]);
        $hidden = app(RiskAssessmentWorkspaceConnectionsService::class)->forViewer($assessment, $this->admin);
        $this->assertNull($hidden['safety_review']);
        $this->assertSame([], $hidden['corrective_actions']);
        $this->assertSame([], $hidden['tasks']);
    }

    public function test_source_navigation_requires_actual_destination_capability_and_permission_revocation_hides_connections(): void
    {
        $assessment = $this->risk();
        $viewer = $this->reader(['hazards.view']);
        $service = app(RiskAssessmentWorkspaceConnectionsService::class);
        $this->assertNull($service->forViewer($assessment, $viewer)['source']);
        $source = $service->forViewer($assessment, $this->admin)['source'];
        $this->assertSame('/sites/'.$this->site->id.'?tab=risk_assessments', $source['href']);
        $this->actingAs($this->admin)->get($source['href'])->assertOk();
        $viewer->permissionOverrides()->detach();
        $this->assertNull($service->forViewer($assessment, $viewer->fresh())['source']);
    }

    public function test_review_state_uses_nz_calendar_for_today_soon_and_overdue_without_changing_date_values(): void
    {
        $this->actingAs($this->admin);
        Carbon::setTestNow(Carbon::parse('2026-10-07 20:00:00', 'UTC'));
        try {
            foreach (['2026-10-07' => 'overdue', '2026-10-08' => 'today', '2026-10-09' => 'soon', '2026-12-01' => 'ok'] as $day => $kind) {
                $assessment = $this->risk(['status' => 'under_review', 'review_due_at' => $day]);
                $row = RiskAssessmentPresenter::row($assessment);
                $this->assertSame($day, $row['review_due_at']);
                $this->assertSame($kind, $row['review_state']['kind']);
                $this->assertSame(in_array($kind, ['today', 'overdue'], true), $row['is_due_for_review']);
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_complete_review_gate_explains_unrecorded_approval_and_residual_decision(): void
    {
        $this->actingAs($this->admin);
        $assessment = $this->risk(['status' => 'under_review', 'approved_at' => null, 'approved_by_user_id' => null,
            'residual_likelihood' => null, 'residual_consequence' => null, 'risk_acceptable' => null]);
        $detail = RiskAssessmentPresenter::detail($assessment, true);
        $this->assertFalse($detail['can']['complete_review']);
        $this->assertCount(3, $detail['review_completion_gate']['reasons']);
        $assessment->updateQuietly(['approved_at' => now(), 'approved_by_user_id' => $this->admin->id,
            'residual_likelihood' => 2, 'residual_consequence' => 3, 'risk_acceptable' => false]);
        $detail = RiskAssessmentPresenter::detail($assessment->fresh(), true);
        $this->assertTrue($detail['can']['complete_review']);
        $this->assertSame(['eligible' => true, 'reasons' => []], $detail['review_completion_gate']);
    }

    public function test_single_record_connections_do_not_silently_truncate_versions_or_review_actions(): void
    {
        $event = HsEvent::factory()->create(['site_id' => $this->site->id]);
        $assessment = $this->risk(['hs_event_id' => $event->id]);
        for ($index = 0; $index < 21; $index++) {
            $this->risk(['hs_event_id' => $event->id, 'status' => 'superseded', 'superseded_by_id' => $assessment->id]);
        }
        for ($index = 0; $index < 51; $index++) {
            HsCorrectiveAction::factory()->createQuietly(['hs_event_id' => $event->id]);
        }
        $connections = app(RiskAssessmentWorkspaceConnectionsService::class)->forViewer($assessment, $this->admin);
        $this->assertCount(21, $connections['versions']['previous']);
        $this->assertCount(51, $connections['corrective_actions']);
        $this->assertCount(52, $connections['tasks']);
    }

    public function test_pickers_reauthorize_current_sites_and_include_older_allowed_events_without_private_narrative(): void
    {
        $viewer = $this->reader(['hazards.view']);
        $otherSite = Site::factory()->create();
        HsEvent::factory()->count(51)->create(['site_id' => $this->site->id, 'acceptance_notes' => 'Private review narrative']);
        HsEvent::factory()->create(['site_id' => $otherSite->id]);
        HsEvent::factory()->create(['site_id' => null]);
        $pickers = RiskAssessmentPresenter::siteScopedPickers([$this->site->id, $otherSite->id], $viewer);
        $this->assertSame([$this->site->id], $pickers['sites']->pluck('id')->all());
        $this->assertCount(51, $pickers['events']);
        $this->assertStringNotContainsString('Private review narrative', json_encode($pickers));
        $this->assertSame([], RiskAssessmentPresenter::siteScopedPickers([$this->site->id], $this->reader([]))['events']->all());
    }

    public function test_client_source_requires_client_profile_access_and_uses_the_real_risk_tab(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $assessment = HsRiskAssessment::factory()->forClient($client->id)->create();
        $service = app(RiskAssessmentWorkspaceConnectionsService::class);
        $this->assertNull($service->forViewer($assessment, $this->reader(['hazards.view']))['source']);
        $viewer = $this->reader(['hazards.view', 'clients.viewAny']);
        $source = $service->forViewer($assessment, $viewer)['source'];
        $this->assertSame($client->id, $source['id']);
        $this->assertSame('/operations/clients/'.$client->id.'?tab=risk_management', $source['href']);
        $this->actingAs($viewer)->get($source['href'])->assertOk();
    }

    public function test_evidence_history_exposes_only_safe_names_and_semantic_fields(): void
    {
        $this->actingAs($this->admin);
        $assessment = $this->risk();
        $audit = AuditLog::create(['auditable_type' => HsRiskAssessment::class, 'auditable_id' => $assessment->id,
            'user_id' => $this->admin->id, 'action' => 'risk_assessment.evidence_added',
            'meta' => ['names' => ['private/risk/photo.png', 'C:\\fakepath\\proof.pdf'],
                'path' => 'private/file-secret.pdf', 'disk' => 'private', 'attachment_id' => 222,
                'snapshot' => ['path' => 'private/file-secret.pdf', 'disk' => 'private', 'status' => 'draft']]]);
        $entry = collect(RiskAssessmentPresenter::detail($assessment, true)['history'])->firstWhere('id', 'audit-'.$audit->id);
        $this->assertSame(['photo.png', 'proof.pdf'], $entry['snapshot']['names']);
        $this->assertSame('draft', $entry['snapshot']['status']);
        $this->assertArrayNotHasKey('path', $entry['snapshot']);
        $this->assertArrayNotHasKey('disk', $entry['snapshot']);
        $this->assertStringNotContainsString('private/', json_encode($entry));
    }

    public function test_semantic_creation_supersedes_duplicate_legacy_creation_milestone(): void
    {
        $this->actingAs($this->admin);
        $assessment = $this->risk();
        AuditLog::create(['auditable_type' => HsRiskAssessment::class, 'auditable_id' => $assessment->id,
            'user_id' => $this->admin->id, 'action' => 'risk_assessment.created', 'meta' => ['snapshot' => ['status' => 'draft']]]);
        $history = RiskAssessmentPresenter::detail($assessment, true)['history'];
        $this->assertCount(1, collect($history)->where('title', 'Assessment created'));
        $this->assertSame('risk_assessment.created', collect($history)->firstWhere('title', 'Assessment created')['action']);
    }

    public function test_picker_intersects_requested_sites_with_current_membership_after_warming_and_restore(): void
    {
        $viewer = $this->reader(['hazards.view']);
        $original = HsEvent::factory()->createQuietly(['site_id' => $this->site->id]);
        $destination = Site::factory()->create(['type' => 'house']);
        $current = HsEvent::factory()->createQuietly(['site_id' => $destination->id]);
        $viewer->load('hrEmployeeProfile', 'permissionOverrides');
        $this->assertSame([$original->id], RiskAssessmentPresenter::siteScopedPickers([$this->site->id, $destination->id], $viewer)['events']->pluck('id')->all());
        $viewer->hrEmployeeProfile()->update(['primary_site_id' => $destination->id, 'secondary_site_ids' => []]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $empty = RiskAssessmentPresenter::siteScopedPickers([$this->site->id], $viewer);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame([], $empty['events']->all());
        $this->assertSame([], $empty['clients']->all());
        $this->assertDoesNotMatchRegularExpression('/\bfrom\s+[`"]?(?:hs_events|first_aid_records|restraint_events|workplace_injuries)[`"]?\b/i', implode(' ', array_column($queries, 'query')));
        $this->assertSame([$current->id], RiskAssessmentPresenter::siteScopedPickers([$this->site->id, $destination->id], $viewer)['events']->pluck('id')->all());
        $viewer->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->site->id]]);
        $this->assertEqualsCanonicalizing([$original->id, $current->id], RiskAssessmentPresenter::siteScopedPickers([$this->site->id, $destination->id], $viewer)['events']->pluck('id')->all());
    }

    public function test_connection_module_revocation_overrides_loaded_grants_before_source_discovery(): void
    {
        $viewer = $this->reader(['hazards.view']);
        $event = HsEvent::factory()->createQuietly(['site_id' => $this->site->id]);
        $assessment = $this->risk(['hs_event_id' => $event->id]);
        $service = app(RiskAssessmentWorkspaceConnectionsService::class);
        $this->assertSame($event->id, $service->forViewer($assessment, $viewer)['safety_review']['id']);
        $viewer->load('roles.permissions', 'permissionOverrides');
        $permission = Permission::where('key', 'hazards.view')->sole();
        $viewer->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $hidden = $service->forViewer($assessment, $viewer);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertNull($hidden['safety_review']);
        $this->assertSame([], $hidden['tasks']);
        $this->assertDoesNotMatchRegularExpression('/\bfrom\s+[`"]?(?:hs_events|first_aid_records|restraint_events|workplace_injuries)[`"]?\b/i', implode(' ', array_column($queries, 'query')));
        $viewer->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        $this->assertSame($event->id, $service->forViewer($assessment, $viewer)['safety_review']['id']);
    }

    public function test_next_version_uses_fresh_record_context_instead_of_a_loaded_supersession_pointer(): void
    {
        $viewer = $this->reader(['hazards.view']);
        $original = $this->risk(['status' => HsRiskAssessment::STATUS_SUPERSEDED]);
        $next = $this->risk();
        $loaded = $original->fresh();
        HsRiskAssessment::whereKey($original->id)->update(['superseded_by_id' => $next->id]);
        $service = app(RiskAssessmentWorkspaceConnectionsService::class);
        $this->assertSame($next->id, $service->nextForViewer($loaded, $viewer)['id']);
        $foreign = $this->risk(['assessable_id' => Site::factory()->create()->id]);
        HsRiskAssessment::whereKey($original->id)->update(['superseded_by_id' => $foreign->id]);
        $this->assertNull($service->nextForViewer($loaded, $viewer));
        $this->assertNull($service->forViewer($loaded, $viewer)['versions']['next']);
    }
    public function test_site_destination_rechecks_type_grants_independently_of_the_readable_risk_record(): void
    {
        $assessment = $this->risk();
        $viewer = $this->reader(['hazards.view', 'sites.viewAny', 'sites.type.house.view']);
        $service = app(RiskAssessmentWorkspaceConnectionsService::class);
        $this->assertSame($this->site->id, $service->forViewer($assessment, $viewer)['source']['id']);
        $viewer->load('permissionOverrides');
        $viewer->permissionOverrides()->syncWithoutDetaching([
            Permission::where('key', 'sites.type.house.view')->sole()->id => ['allowed' => false],
            Permission::where('key', 'sites.type.facility.view')->sole()->id => ['allowed' => true],
        ]);
        $connections = $service->forViewer($assessment, $viewer);
        $this->assertNull($connections['source']);
        $this->assertSame([], $connections['tasks']);
        $viewer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'sites.type.house.view')->sole()->id => ['allowed' => true]]);
        $this->assertSame($this->site->id, $service->forViewer($assessment, $viewer)['source']['id']);
    }

    public function test_initial_risk_key_union_preserves_route_links_and_non_alert_tasks_without_cr_grant(): void
    {
        $event = HsEvent::factory()->createQuietly(['site_id' => $this->site->id,
            'event_category' => HsEvent::CATEGORY_HAZARD]);
        $assessment = $this->risk(['hs_event_id' => $event->id]);
        $viewer = $this->reader(['hazards.view']);
        $response = $this->actingAs($viewer)->get('/health-safety/risk-assessments/'.$assessment->id)->assertOk();
        $connections = data_get($response->viewData('page'), 'props.detail.connections');
        $this->assertSame(['hs_event-'.$event->id], array_column($connections['tasks'], 'id'));
        $this->assertSame($event->id, $connections['safety_review']['id']);
        $this->get($connections['safety_review']['href'])->assertOk();
        $this->assertFalse($viewer->canDo('controlRoom.alerts.view'));
        $ordinary = $this->risk(['hs_event_id' => null]);
        $this->getJson('/health-safety/risk-assessments/'.$ordinary->id)->assertOk()
            ->assertJsonPath('detail.connections.tasks', [])->assertJsonPath('detail.connections.safety_review', null);
    }

    public function test_initial_risk_preparation_does_not_grant_private_incident_source_or_foreign_site(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $incident = \App\Models\ClientIncident::withoutEvents(fn () => \App\Models\ClientIncident::factory()->submitted()->create([
            'client_id' => $client->id, 'site_id' => $this->site->id, 'is_hr_confidential' => true,
            'title' => 'PRIVATE-INCIDENT-KEY-PREP']));
        $event = HsEvent::factory()->createQuietly(['site_id' => $this->site->id,
            'source_type' => \App\Models\ClientIncident::class, 'source_id' => $incident->id,
            'event_category' => HsEvent::CATEGORY_INCIDENT]);
        $assessment = $this->risk(['hs_event_id' => $event->id, 'title' => 'PRIVATE-RISK-KEY-PREP']);
        $viewer = $this->reader(['hazards.view']);
        $this->actingAs($viewer)->get('/health-safety/risk-assessments/'.$assessment->id)->assertForbidden()
            ->assertDontSee('PRIVATE-INCIDENT-KEY-PREP')->assertDontSee('PRIVATE-RISK-KEY-PREP');
        $this->assertFalse($viewer->canDo('incidents.viewAny'));
        $foreign = $this->risk(['assessable_id' => Site::factory()->create(['type' => 'house'])->id]);
        $this->getJson('/health-safety/risk-assessments/'.$foreign->id)->assertForbidden();
    }

    private function risk(array $attributes = []): HsRiskAssessment
    {
        return HsRiskAssessment::factory()->forSite($this->site->id)->create($attributes);
    }

    private function reader(array $permissions): User
    {
        $viewer = User::factory()->create(['approved_at' => now()]);
        $ids = Permission::whereIn('key', $permissions)->pluck('id');
        $viewer->permissionOverrides()->sync($ids->mapWithKeys(fn ($id) => [$id => ['allowed' => true]]));
        HrEmployeeProfile::factory()->create(['user_id' => $viewer->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => []]);

        return $viewer;
    }
}
