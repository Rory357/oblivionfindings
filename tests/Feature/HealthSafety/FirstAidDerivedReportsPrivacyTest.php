<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\FirstAidRecord;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRiskAssessment;
use App\Models\HsRiskAssessmentAttachment;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Clients\ClientProfileSectionAccess;
use App\Services\HealthSafety\HsComplianceExportService;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\HealthSafety\HsGovernanceService;
use App\Services\HealthSafety\HsModuleSummaryService;
use App\Services\HealthSafety\HsRiskAssessmentService;
use Database\Seeders\GovernancePermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FirstAidDerivedReportsPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, GovernancePermissionsSeeder::class]);
        $this->travelTo(Carbon::parse('2026-10-08T01:00:00Z'));
        $this->site = Site::factory()->create();
        $this->viewer = User::factory()->frontlineWorker()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $this->viewer->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);
        foreach (['hazards.view' => true, 'hazards.manage' => true, 'governance.view' => true, 'healthSafety.viewAllSites' => false,
            'clients.viewAny' => false, 'clients.viewAssigned' => true, 'clinical.accessAllSites' => false, 'sites.viewAll' => false] as $permission => $allowed) {
            $this->grant($permission, $allowed);
        }
    }

    public function test_actual_governance_reports_counts_and_narratives_intersect_primary_treatment_read(): void
    {
        $control = $this->work($this->event(), 'Public control');
        $client = $this->client();
        $private = $this->work($this->event($this->source($client)), 'Private clinical marker');
        $this->actingAs($this->viewer);
        foreach (['worksafe-register', 'investigation-outcomes', 'corrective-action-traceability', 'risk-assessment-register'] as $report) {
            $response = $this->getJson('/health-safety/reports/'.$report)->assertOk()->assertJsonPath('item_count', 1);
            $this->assertStringNotContainsString('Private clinical marker', $response->getContent());
            $this->assertStringNotContainsString($private['event']->reference_number, $response->getContent());
        }
        $board = $this->getJson('/health-safety/reports/board-summary')->assertOk();
        $board->assertJsonPath('event_summary.total_period', 1)->assertJsonPath('corrective_action_summary.open', 1)
            ->assertJsonPath('investigation_summary.completed_with_findings', 1)->assertJsonPath('risk_posture.total_active', 1);
        $client->supportWorkers()->attach($this->viewer->id);
        foreach (['worksafe-register', 'investigation-outcomes', 'corrective-action-traceability', 'risk-assessment-register'] as $report) {
            $this->getJson('/health-safety/reports/'.$report)->assertOk()->assertJsonPath('item_count', 2);
        }
        $this->getJson('/health-safety/reports/investigation-outcomes')->assertOk()->assertJsonFragment(['findings_summary' => 'Private clinical marker']);
        $this->assertDatabaseHas('hs_corrective_actions', ['id' => $control['action']->id, 'status' => 'open']);
    }

    public function test_reused_report_services_and_actual_routes_recheck_assignment_permission_and_staff_site(): void
    {
        $client = $this->client();
        $client->supportWorkers()->attach($this->viewer->id);
        $work = $this->work($this->event($this->source($client)), 'Revoked clinical marker');
        $service = app(HsGovernanceService::class);
        $this->viewer->load('permissionOverrides', 'hrEmployeeProfile', 'assignedClients');
        $this->assertSame(1, $service->getRiskPosture($this->viewer)['total_active']);
        $client->supportWorkers()->detach($this->viewer->id);
        $this->assertSame(0, $service->getRiskPosture($this->viewer)['total_active']);
        $this->actingAs($this->viewer)->getJson('/health-safety/reports/risk-assessment-register')->assertOk()->assertJsonPath('item_count', 0);
        $client->supportWorkers()->attach($this->viewer->id);
        $this->assertSame(1, $service->getRiskPosture($this->viewer)['total_active']);
        $this->grant('clients.viewAssigned', false);
        $this->assertSame(0, $service->getRiskPosture($this->viewer)['total_active']);
        $this->grant('clients.viewAssigned', true);
        $this->viewer->hrEmployeeProfile->forceFill(['primary_site_id' => Site::factory()->create()->id])->save();
        $this->assertSame(0, $service->getRiskPosture($this->viewer)['total_active']);
        $this->assertDatabaseHas('hs_risk_assessments', ['id' => $work['risk']->id, 'status' => 'active']);
    }

    public function test_malformed_exact_treatment_event_tuples_do_not_contribute_even_with_site_and_client_authority(): void
    {
        $client = $this->client();
        $client->supportWorkers()->attach($this->viewer->id);
        $source = $this->source($client);
        $foreignSite = Site::factory()->create();
        $this->viewer->hrEmployeeProfile->update(['secondary_site_ids' => [$foreignSite->id]]);
        $other = $this->client();
        $other->supportWorkers()->attach($this->viewer->id);
        $events = [
            $this->event($source, ['source_id' => 999999999]),
            $this->event($source, ['event_category' => 'incident']),
            $this->event($source, ['site_id' => $foreignSite->id]),
            $this->event($source, ['client_id' => $other->id]),
            $this->event($source, ['staff_id' => $this->viewer->id]),
        ];
        foreach ($events as $event) {
            $this->work($event, 'Malformed private marker');
        }
        $this->actingAs($this->viewer)->getJson('/health-safety/reports/board-summary')->assertOk()
            ->assertJsonPath('event_summary.total_period', 0)->assertJsonPath('risk_posture.total_active', 0)
            ->assertJsonPath('corrective_action_summary.open', 0)->assertJsonPath('investigation_summary.completed_with_findings', 0);
        $this->assertSame(5, HsEvent::whereIn('id', array_map(fn ($event) => $event->id, $events))->count());
    }

    public function test_source_event_filters_fail_closed_without_exposing_denied_risk_records(): void
    {
        $work = $this->work($this->event($this->source($this->client())), 'Filter-private marker');
        $this->actingAs($this->viewer)->getJson('/health-safety/reports/risk-assessment-register?hs_event_id='.$work['event']->id)->assertNotFound();
        $this->get('/health-safety/risk-assessments?hs_event_id='.$work['event']->id)->assertNotFound();
        $this->get('/health-safety/risk-assessments?assessment='.$work['risk']->id)->assertForbidden();
        $this->assertDatabaseHas('hs_risk_assessments', ['id' => $work['risk']->id]);
    }

    public function test_actual_risk_list_direct_evidence_and_revocation_share_primary_treatment_read(): void
    {
        Storage::fake('private');
        $client = $this->client();
        $work = $this->work($this->event($this->source($client)), 'Private risk narrative');
        $control = $this->work($this->event(), 'Public risk narrative');
        $attachment = HsRiskAssessmentAttachment::create(['hs_risk_assessment_id' => $work['risk']->id, 'uploaded_by' => $this->viewer->id, 'disk' => 'private', 'path' => 'risk/private.txt', 'original_name' => 'private.txt', 'mime' => 'text/plain', 'size' => 18]);
        Storage::disk('private')->put($attachment->path, 'Private risk bytes');
        $url = '/health-safety/risk-assessments/'.$work['risk']->id;
        $this->actingAs($this->viewer)->get('/health-safety/risk-assessments')->assertOk()->assertInertia(fn ($page) => $page->where('assessments.total', 1)->where('assessments.data.0.id', $control['risk']->id)->where('tabCounts.active', 1));
        $this->getJson($url)->assertForbidden();
        $this->get($url.'/attachments/'.$attachment->id.'/download')->assertForbidden();
        $client->supportWorkers()->attach($this->viewer->id);
        $this->getJson($url)->assertOk()->assertJsonPath('detail.id', $work['risk']->id);
        $this->assertSame('Private risk bytes', $this->get($url.'/attachments/'.$attachment->id.'/download')->assertOk()->streamedContent());
        $this->viewer->load('permissionOverrides', 'hrEmployeeProfile', 'assignedClients');
        $client->supportWorkers()->detach($this->viewer->id);
        $this->getJson($url)->assertForbidden();
        $this->get($url.'/attachments/'.$attachment->id.'/download')->assertForbidden();
        Storage::disk('private')->assertExists($attachment->path);
    }

    public function test_human_risk_commands_cannot_copy_or_change_denied_treatment_provenance(): void
    {
        $client = $this->client();
        $event = $this->event($this->source($client));
        $data = ['assessable_type' => Site::class, 'assessable_id' => $this->site->id, 'hs_event_id' => $event->id,
            'title' => 'Private human risk', 'risk_description' => 'Private human narrative', 'likelihood' => 2, 'consequence' => 2];
        $service = app(HsRiskAssessmentService::class);
        $before = HsRiskAssessment::count();
        $this->denied(fn () => $service->create($data, $this->viewer));
        $this->assertSame($before, HsRiskAssessment::count());
        $this->actingAs($this->viewer)->post('/health-safety/risk-assessments', ['attach_type' => 'event', 'attach_id' => $event->id, 'title' => $data['title'], 'likelihood' => 2, 'consequence' => 2])->assertForbidden();
        $client->supportWorkers()->attach($this->viewer->id);
        $risk = $service->create($data, $this->viewer);
        $this->assertSame($event->id, $risk->hs_event_id);
        $auditCount = AuditLog::count();
        $client->supportWorkers()->detach($this->viewer->id);
        $this->denied(fn () => $service->activate($risk, [], $this->viewer));
        $this->actingAs($this->viewer)->post('/health-safety/risk-assessments/'.$risk->id.'/activate')->assertForbidden();
        $this->put('/health-safety/risk-assessments/'.$risk->id, ['title' => '', 'attach_type' => 'invalid'])->assertForbidden();
        $this->post('/health-safety/risk-assessments/'.$risk->id.'/supersede', ['title' => '', 'attach_type' => 'invalid'])->assertForbidden();
        $this->assertSame('draft', $risk->fresh()->status);
        $this->assertSame($auditCount, AuditLog::count());
    }

    public function test_module_context_summaries_preserve_non_treatment_rows_and_omit_denied_copied_work(): void
    {
        $client = $this->client();
        $control = $this->work($this->event(null, ['client_id' => $client->id, 'staff_id' => $this->viewer->id]), 'Public context');
        $private = $this->work($this->event($this->source($client)), 'Private context');
        foreach ([$control, $private] as $work) {
            $work['risk']->forceFill(['assessable_type' => Client::class, 'assessable_id' => $client->id])->saveQuietly();
        }
        $service = app(HsModuleSummaryService::class);
        foreach ([null, $this->viewer] as $viewer) {
            $this->assertSame(1, $service->forClient($client->id, $viewer)['total_events']);
            $this->assertSame(1, $service->forClient($client->id, $viewer)['active_risk_assessments']);
            $this->assertSame(1, $service->forSite($this->site->id, $viewer)['open_events']);
            $this->assertSame(1, $service->forStaff($this->viewer->id, $viewer)['assigned_corrective_actions']);
        }
        $client->supportWorkers()->attach($this->viewer->id);
        $this->assertSame(2, $service->forClient($client->id, $this->viewer)['total_events']);
        $this->assertSame(2, $service->forClient($client->id, $this->viewer)['active_risk_assessments']);
        $this->assertSame(2, $service->forStaff($this->viewer->id, $this->viewer)['assigned_corrective_actions']);
        $client->supportWorkers()->detach($this->viewer->id);
        $this->assertSame(1, $service->forStaff($this->viewer->id, $this->viewer)['led_investigations']);
        $control['event']->deleteQuietly();
        $this->assertSame(1, $service->forStaff($this->viewer->id, $this->viewer)['assigned_corrective_actions']);
        $this->assertSame(1, $service->forStaff($this->viewer->id, $this->viewer)['led_investigations']);
    }

    public function test_both_risk_provenance_columns_are_binding_without_broadening_legacy_canonical_risk_policy(): void
    {
        $event = $this->event($this->source($this->client()));
        $modern = $this->work($event, 'Modern private risk')['risk'];
        $legacy = HsRiskAssessment::factory()->active()->createQuietly(['assessable_type' => HsEvent::class, 'assessable_id' => $event->id, 'hs_event_id' => null]);
        $control = HsRiskAssessment::factory()->forSite($this->site->id)->active()->createQuietly();
        $access = app(HsEventAccessService::class);
        $ids = DB::transaction(fn () => $access->applyFirstAidRiskSourceScope(HsRiskAssessment::query(), $this->viewer)->pluck('id')->all());
        $this->assertSame([$control->id], $ids);
        $this->assertDatabaseHas('hs_risk_assessments', ['id' => $modern->id, 'hs_event_id' => $event->id]);
        $this->assertDatabaseHas('hs_risk_assessments', ['id' => $legacy->id, 'assessable_id' => $event->id]);
        $source = FirstAidRecord::findOrFail($event->source_id);
        $client = Client::findOrFail($source->client_id);
        $client->supportWorkers()->attach($this->viewer->id);
        $this->assertEqualsCanonicalizing([$modern->id, $legacy->id, $control->id], DB::transaction(fn () => $access->applyFirstAidRiskSourceScope(HsRiskAssessment::query(), $this->viewer)->pluck('id')->all()));
    }

    public function test_traceability_cannot_disclose_private_investigation_through_unrelated_action(): void
    {
        $public = $this->work($this->event(), 'Public trace');
        $private = $this->work($this->event($this->source($this->client())), 'Private trace');
        $public['action']->forceFill(['hs_investigation_id' => $private['investigation']->id])->saveQuietly();
        $this->actingAs($this->viewer)->getJson('/health-safety/reports/corrective-action-traceability')->assertOk()
            ->assertJsonPath('item_count', 1)->assertJsonPath('items.0.investigation_reference', null);
        $this->assertDatabaseHas('hs_corrective_actions', ['id' => $public['action']->id, 'hs_investigation_id' => $private['investigation']->id]);
    }

    public function test_actorless_and_explicit_read_denied_governance_omit_treatment_while_non_treatment_control_is_unchanged(): void
    {
        $this->work($this->event(), 'Public unchanged');
        $source = $this->source();
        $this->work($this->event($source), 'Staff treatment private');
        $service = app(HsGovernanceService::class);
        $this->assertSame(0, $service->getRiskPosture()['total_active']);
        $this->assertSame(0, app(HsComplianceExportService::class)->riskAssessmentRegister()['item_count']);
        $this->assertSame(2, $service->getRiskPosture($this->viewer)['total_active']);
        $this->viewer->load('permissionOverrides', 'hrEmployeeProfile');
        $this->grant('hazards.view', false);
        $this->assertSame(1, $service->getRiskPosture($this->viewer)['total_active']);
        $this->assertSame(1, $service->getEventSummary(now()->subDay(), now(), $this->viewer)['total_period']);
        $this->assertFalse($source->fresh()->trashed());
    }

    public function test_actual_client_profile_optional_summary_uses_current_viewer_and_preserves_its_section_gate(): void
    {
        $client = $this->client();
        $client->supportWorkers()->attach($this->viewer->id);
        $this->work($this->event($this->source($client)), 'Authorized client context');
        $headers = $this->inertiaPartialHeaders('operations/clients/show', 'hs_summary');
        $url = '/operations/clients/'.$client->id;
        $this->actingAs($this->viewer)->get($url, $headers)->assertOk()->assertJsonPath('props.hs_summary.open_events', 1);
        $this->viewer->load('permissionOverrides', 'hrEmployeeProfile', 'assignedClients');
        $this->grant('hazards.view', false);
        $deniedViewer = $this->viewer->fresh();
        $this->assertFalse(app(ClientProfileSectionAccess::class)->for($deniedViewer, $client)['first_aid']);
        // Inertia partial requests retain a requested, omitted prop as a null placeholder.
        $this->actingAs($deniedViewer)->get($url, $headers)->assertOk()
            ->assertJsonPath('props.hs_summary', null)->assertJsonMissingPath('props.hs_summary.open_events')
            ->assertDontSee('Authorized client context');
        $this->grant('hazards.view', true);
        $this->actingAs($this->viewer->fresh())->get($url, $headers)->assertOk()->assertJsonPath('props.hs_summary.open_events', 1);
        $client->supportWorkers()->detach($this->viewer->id);
        $this->get($url, $headers)->assertForbidden();
    }

    public function test_application_site_authority_preserves_standalone_risk_without_granting_primary_client_read(): void
    {
        $this->grant('healthSafety.viewAllSites', true);
        $private = $this->work($this->event($this->source($this->client())), 'Global-site private risk');
        $standalone = HsRiskAssessment::factory()->active()->createQuietly(['assessable_type' => null, 'assessable_id' => null, 'hs_event_id' => null, 'title' => 'Public standalone control']);
        $this->work($this->event(), 'Public site control');
        $this->actingAs($this->viewer)->get('/health-safety/risk-assessments')->assertOk()
            ->assertInertia(fn ($page) => $page->where('assessments.total', 2)->where('tabCounts.active', 2));
        $this->getJson('/health-safety/risk-assessments/'.$standalone->id)->assertOk();
        $this->getJson('/health-safety/risk-assessments/'.$private['risk']->id)->assertForbidden();
        $this->getJson('/health-safety/reports/risk-assessment-register')->assertOk()->assertJsonPath('item_count', 2);
        $this->assertSame(2, app(HsGovernanceService::class)->getRiskPosture($this->viewer)['total_active']);
    }

    public function test_actual_readable_client_profile_omits_other_private_treatment_risk_without_aborting_whole_profile(): void
    {
        $client = $this->client();
        $client->supportWorkers()->attach($this->viewer->id);
        $primary = $this->client();
        $event = $this->event($this->source($primary));
        $private = HsRiskAssessment::factory()->forClient($client->id)->active()->createQuietly(['hs_event_id' => $event->id, 'title' => 'Private copied treatment risk']);
        $public = HsRiskAssessment::factory()->forClient($client->id)->active()->createQuietly(['title' => 'Public Client risk']);
        $headers = $this->inertiaPartialHeaders('operations/clients/show', 'hs_risk_assessments');
        $url = '/operations/clients/'.$client->id;
        $this->actingAs($this->viewer)->get($url, $headers)->assertOk()->assertJsonCount(1, 'props.hs_risk_assessments')
            ->assertJsonPath('props.hs_risk_assessments.0.id', $public->id);
        $primary->supportWorkers()->attach($this->viewer->id);
        $this->get($url, $headers)->assertOk()->assertJsonCount(2, 'props.hs_risk_assessments');
        $this->viewer->load('hrEmployeeProfile', 'permissionOverrides', 'assignedClients');
        $primary->supportWorkers()->detach($this->viewer->id);
        $this->get($url, $headers)->assertOk()->assertJsonCount(1, 'props.hs_risk_assessments');
        $this->assertDatabaseHas('hs_risk_assessments', ['id' => $private->id, 'assessable_id' => $client->id, 'hs_event_id' => $event->id]);
    }

    public function test_primary_risk_reattachment_retains_binding_treatment_history_and_rejects_incompatible_or_revoked_source(): void
    {
        $primary = $this->client();
        $primary->supportWorkers()->attach($this->viewer->id);
        $destination = $this->client();
        $destination->supportWorkers()->attach($this->viewer->id);
        $event = $this->event($this->source($primary));
        $service = app(HsRiskAssessmentService::class);
        $risk = $service->create(['assessable_type' => Site::class, 'assessable_id' => $this->site->id, 'hs_event_id' => $event->id,
            'title' => 'Binding private risk', 'risk_description' => 'Retained treatment narrative', 'likelihood' => 2, 'consequence' => 2], $this->viewer);
        $payload = ['attach_type' => 'client', 'attach_id' => $destination->id, 'title' => 'Binding private risk',
            'risk_description' => 'Retained treatment narrative', 'likelihood' => 2, 'consequence' => 2];
        $url = '/health-safety/risk-assessments/'.$risk->id;
        $this->actingAs($this->viewer)->put($url, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('hs_risk_assessments', ['id' => $risk->id, 'assessable_type' => Client::class,
            'assessable_id' => $destination->id, 'hs_event_id' => $event->id, 'risk_description' => 'Retained treatment narrative']);
        $this->getJson($url)->assertOk();
        $otherSite = Site::factory()->create();
        $foreignClient = Client::factory()->create(['site_id' => $otherSite->id]);
        $this->viewer->hrEmployeeProfile->update(['secondary_site_ids' => [$otherSite->id]]);
        $before = $risk->fresh()->getAttributes();
        $audits = AuditLog::count();
        $this->denied(fn () => $service->updateDraft($risk, ['assessable_type' => Client::class, 'assessable_id' => $foreignClient->id,
            'hs_event_id' => null, 'title' => 'Wrong destination', 'likelihood' => 2, 'consequence' => 2], $this->viewer));
        $this->assertSame($before, $risk->fresh()->getAttributes());
        $this->assertSame($audits, AuditLog::count());
        $primary->supportWorkers()->detach($this->viewer->id);
        $this->getJson($url)->assertForbidden();
        $this->put($url, $payload)->assertForbidden();
        $this->denied(fn () => $service->updateDraft($risk, ['assessable_type' => Site::class, 'assessable_id' => $this->site->id,
            'hs_event_id' => null, 'title' => 'Washed source', 'likelihood' => 2, 'consequence' => 2], $this->viewer));
        $this->assertSame($before, $risk->fresh()->getAttributes());
        $this->assertSame($audits, AuditLog::count());

        $publicEvent = $this->event();
        $public = HsRiskAssessment::factory()->forSite($this->site->id)->createQuietly(['hs_event_id' => $publicEvent->id]);
        $service->updateDraft($public, ['assessable_type' => Client::class, 'assessable_id' => $destination->id,
            'hs_event_id' => null, 'title' => 'Unrelated source keeps existing reattachment semantics', 'likelihood' => 2, 'consequence' => 2], $this->viewer);
        $this->assertNull($public->fresh()->hs_event_id);
        $this->assertSame($destination->id, $public->fresh()->assessable_id);
    }

    private function client(): Client
    {
        return Client::factory()->create(['site_id' => $this->site->id]);
    }

    private function source(?Client $client = null): FirstAidRecord
    {
        return FirstAidRecord::factory()->createQuietly(['site_id' => $this->site->id, 'first_aider_id' => $this->viewer->id,
            'created_by' => $this->viewer->id, 'treated_person_type' => $client ? 'client' : 'staff', 'treated_person_id' => null,
            'client_id' => $client?->id, 'treatment_date' => now(), 'injury_illness_description' => 'Recorded treatment source']);
    }

    private function event(?FirstAidRecord $source = null, array $fields = []): HsEvent
    {
        return HsEvent::factory()->worksafeNotifiable($this->viewer)->createQuietly(['site_id' => $this->site->id,
            'created_by' => $this->viewer->id, 'reported_at' => now(), 'source_type' => $source ? FirstAidRecord::class : HsEvent::class,
            ...($source ? ['source_id' => $source->id, 'event_category' => 'injury', 'client_id' => $source->client_id, 'staff_id' => $source->treated_person_id] : []), ...$fields]);
    }

    private function work(HsEvent $event, string $marker): array
    {
        $investigation = HsInvestigation::factory()->completed()->createQuietly(['hs_event_id' => $event->id, 'lead_investigator_id' => $this->viewer->id, 'findings_summary' => $marker]);
        $action = HsCorrectiveAction::factory()->createQuietly(['hs_event_id' => $event->id, 'hs_investigation_id' => $investigation->id, 'assigned_to_user_id' => $this->viewer->id, 'title' => $marker]);
        $risk = HsRiskAssessment::factory()->forSite($this->site->id)->active()->createQuietly(['hs_event_id' => $event->id, 'title' => $marker, 'risk_description' => $marker]);

        return compact('event', 'investigation', 'action', 'risk');
    }

    private function denied(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected current source denial.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    private function grant(string $key, bool $allowed): void
    {
        $this->viewer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->firstOrFail()->id => ['allowed' => $allowed]]);
    }
}
