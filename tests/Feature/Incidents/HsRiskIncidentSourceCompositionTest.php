<?php

namespace Tests\Feature\Incidents;

use App\Http\Controllers\HealthSafety\HsRiskAssessmentController;
use App\Models\AuditLog;
use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Models\HsRiskAssessment;
use App\Models\HsRiskAssessmentAttachment;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HsRiskAssessmentService;
use App\Services\HealthSafety\RiskAssessmentWorkspaceConnectionsService;
use App\Services\Sites\Profile\SiteProfileSafetyPresenter;
use App\Support\HealthSafety\RiskAssessmentPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NotInThisBuild;

/** Whole risk boundaries; runtime acceptance and query ceiling remain unverified. */
final class HsRiskIncidentSourceCompositionTest extends IncidentIndependenceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        foreach ([$this->manager, $this->lead] as $viewer) {
            foreach (['hazards.view', 'hazards.manage', 'governance.view', 'clients.viewAny'] as $key) {
                $this->allow($viewer, $key);
            }
        }
    }

    #[DataProvider('bindings')]
    public function test_register_detail_picker_and_commands_preserve_readable_incident_assessments_and_deny_involvement_before_body(string $binding): void
    {
        $root = ClientIncident::findOrFail($this->accept($this->command())['incident_id']);
        $event = $this->event($root);
        $risk = $this->risk($event, $binding);
        $ordinary = $this->risk(null);
        $this->actingAs($this->manager)->getJson('/health-safety/risk-assessments/'.$risk->id)
            ->assertOk()->assertJsonPath('detail.title', 'private-risk-marker')
            ->assertJsonPath('detail.form.attach_type', $binding === 'dual' ? 'site' : 'event');
        $this->actingAs($this->manager)->put('/health-safety/risk-assessments/'.$risk->id,
            [...$this->draft('private-risk-marker'), 'attach_type' => $binding === 'dual' ? 'site' : 'event', 'attach_id' => $binding === 'dual' ? $this->site->id : $event->id])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('hs_risk_assessments', ['id' => $risk->id,
            'assessable_type' => $risk->assessable_type, 'assessable_id' => $risk->assessable_id, 'hs_event_id' => $risk->hs_event_id]);
        $state = (object) ['privateBodies' => 0];
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        HsRiskAssessment::retrieved(function (HsRiskAssessment $row) use ($state, $risk): void {
            if ((int) $row->id === (int) $risk->id && array_key_exists('risk_description', $row->getAttributes())) { $state->privateBodies++; }
        });
        try {
            $this->actingAs($this->lead)->getJson('/health-safety/risk-assessments/'.$risk->id)->assertForbidden();
            $this->actingAs($this->lead)->get('/health-safety/risk-assessments')->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->component('health-safety/risk-assessments/index')
                    ->where('tabCounts.all', 1)->has('assessments.data', 1)
                    ->where('assessments.data.0.id', $ordinary->id)->where('source_scope', 'readable_subset')->where('source_completeness', 'unknown'));
            $this->actingAs($this->lead)->post('/health-safety/risk-assessments/'.$risk->id.'/activate', [])->assertForbidden();
            $this->denial(fn () => app(HsRiskAssessmentService::class)->activate($risk, [], $this->lead), 404);
            $this->assertSame(0, $state->privateBodies, 'Denied entry points must not hydrate the original risk body.');
        } finally { Model::setEventDispatcher($dispatcher); }
        $pickers = RiskAssessmentPresenter::materialise($this->manager, null, fn ($current, $reads, $context) =>
            RiskAssessmentPresenter::pickerRowsInContext($current, [$this->site->id], $context));
        $this->assertContains($event->id, array_column($pickers['events'], 'id'));
        $denied = RiskAssessmentPresenter::materialise($this->lead, null, fn ($current, $reads, $context) =>
            RiskAssessmentPresenter::pickerRowsInContext($current, [$this->site->id], $context));
        $this->assertNotContains($event->id, array_column($denied['events'], 'id'));
        $service = app(HsRiskAssessmentService::class);
        $this->assertSame('active', $service->activate($risk, [], $this->manager)->status);
        $this->assertSame('under_review', $service->markForReview($risk, $this->manager)->status);
        $service->updateResidualRisk($risk, 1, 2, true, $this->manager);
        $this->assertSame('active', $service->completeReview($risk, ['review_note' => 'Controls checked', 'review_due_at' => now()->addMonth()->toDateString()], $this->manager)->status);
        $successor = $service->supersede($risk, $this->draft('successor-marker'), $this->manager);
        $this->assertSame($risk->getRawOriginal('assessable_type'), $successor->assessable_type);
        $this->assertSame($risk->getRawOriginal('assessable_id'), $successor->assessable_id);
        $this->assertSame($risk->getRawOriginal('hs_event_id'), $successor->hs_event_id);
        $this->assertSame('archived', $service->archive($successor, $this->manager)->status);
    }

    public function test_ordinary_site_assessment_creation_edit_and_lifecycle_remain_available(): void
    {
        $service = app(HsRiskAssessmentService::class);
        $risk = $service->create([...$this->draft('ordinary-created'), 'assessable_type' => Site::class, 'assessable_id' => $this->site->id], $this->manager);
        $service->updateDraft($risk, [...$this->draft('ordinary-edited'), 'assessable_type' => Site::class, 'assessable_id' => $this->site->id], $this->manager);
        $this->assertSame('ordinary-edited', RiskAssessmentPresenter::detail($risk, true, $this->manager)['title']);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $risk->id, 'action' => 'risk_assessment.draft_updated']);
        $this->assertSame('active', $service->activate($risk, [], $this->manager)->status);
        $this->assertSame('archived', $service->archive($risk, $this->manager)->status);
    }

    public function test_source_creation_validation_retains_readable_incident_and_rejects_blocked_event(): void
    {
        $root = ClientIncident::findOrFail($this->accept($this->command())['incident_id']);
        $event = $this->event($root);
        $input = [...$this->draft('http-created'), 'attach_type' => 'event', 'attach_id' => $event->id];
        $this->actingAs($this->manager)->post('/health-safety/risk-assessments', $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('hs_risk_assessments', ['title' => 'http-created', 'hs_event_id' => $event->id]);
        $this->actingAs($this->lead)->post('/health-safety/risk-assessments', [...$input, 'title' => 'blocked-created'])->assertSessionHasErrors('attach_id');
        $this->assertDatabaseMissing('hs_risk_assessments', ['title' => 'blocked-created']);
        $this->denial(fn () => app(HsRiskAssessmentService::class)->create([...$this->draft('blocked-direct'), 'hs_event_id' => $event->id], $this->lead));
        $this->assertDatabaseMissing('hs_risk_assessments', ['title' => 'blocked-direct']);
    }

    public function test_modern_and_legacy_binding_site_conflict_is_denied_before_body(): void
    {
        $event = $this->event($this->incident(['client_id' => $this->client->id]));
        $foreign = Site::factory()->create(['is_active' => true]);
        // Standalone foreign event: hs_events.source_type/source_id are NOT NULL, so it keeps the factory's own HsEvent source.
        $foreignEvent = HsEvent::factory()->createQuietly(['site_id' => $foreign->id, 'created_by' => $this->manager->id]);
        $risk = $this->risk($event, 'modern');
        DB::table('hs_risk_assessments')->where('id', $risk->id)->update(['hs_event_id' => $foreignEvent->id]);
        $this->allow($this->manager, 'healthSafety.viewAllSites');
        $this->denial(fn () => RiskAssessmentPresenter::row($risk, $this->manager), 404);
        $this->denial(fn () => app(HsRiskAssessmentService::class)->activate($risk, [], $this->manager), 404);
        $this->assertSame('draft', $risk->fresh()->status);
    }

    public function test_modern_incident_provenance_cannot_be_removed_from_retained_private_narrative(): void
    {
        $event = $this->event($this->incident(['client_id' => $this->client->id]));
        $risk = $this->risk($event, 'modern');
        $this->validation(fn () => app(HsRiskAssessmentService::class)->updateDraft($risk,
            [...$this->draft('reattached'), 'assessable_type' => Site::class, 'assessable_id' => $this->site->id], $this->manager), 'attach_id');
        $this->assertDatabaseHas('hs_risk_assessments', ['id' => $risk->id, 'assessable_type' => HsEvent::class, 'assessable_id' => $event->id, 'title' => 'private-risk-marker']);
    }

    public function test_actual_command_update_retains_original_source_on_late_permission_withdrawal_and_rolls_back_history(): void
    {
        $event = $this->event($this->incident(['client_id' => $this->client->id]));
        $risk = $this->risk($event);
        $before = AuditLog::where('auditable_type', HsRiskAssessment::class)->where('auditable_id', $risk->id)->count();
        $state = (object) ['armed' => true, 'fired' => false];
        DB::connection()->beforeExecuting(function (string $sql) use ($state): void {
            if (! $state->armed || ! str_starts_with($sql, 'update ' . chr(96) . 'hs_risk_assessments' . chr(96))) { return; }
            $state->armed = false; $state->fired = true;
            $this->allow($this->manager, 'incidents.viewAny', false); $this->allow($this->manager, 'incidents.viewAssigned', false);
        });
        try {
            $this->denial(fn () => app(HsRiskAssessmentService::class)->activate($risk, [], $this->manager));
            $this->assertTrue($state->fired);
            $this->assertSame('draft', $risk->fresh()->status);
            $this->assertSame($before, AuditLog::where('auditable_type', HsRiskAssessment::class)->where('auditable_id', $risk->id)->count());
        } finally { $state->armed = false; }
    }

    public function test_late_history_source_withdrawal_withholds_the_complete_already_hydrated_detail(): void
    {
        $event = $this->event($this->incident(['client_id' => $this->client->id]));
        $risk = $this->risk($event);
        $state = (object) ['armed' => true, 'fired' => false];
        DB::connection()->beforeExecuting(function (string $sql) use ($state): void {
            if (! $state->armed || ! str_starts_with($sql, 'select ') || ! str_contains($sql, 'from '.chr(96).'audit_logs'.chr(96))) { return; }
            $state->armed = false; $state->fired = true;
            $this->allow($this->manager, 'incidents.viewAny', false); $this->allow($this->manager, 'incidents.viewAssigned', false);
        });
        try {
            $this->denial(fn () => RiskAssessmentPresenter::detail($risk, true, $this->manager), 404);
            $this->assertTrue($state->fired);
        } finally { $state->armed = false; }
        $this->allow($this->manager, 'incidents.viewAny');
        $this->assertSame('private-risk-marker', RiskAssessmentPresenter::detail($risk, true, $this->manager)['title']);
    }

    #[DataProvider('dateChanges')]
    public function test_actual_eager_body_compilation_retains_original_timezone_and_day(string $change): void
    {
        $event = $this->event($this->incident(['client_id' => $this->client->id]));
        $risk = $this->risk($event, 'modern');
        $state = (object) ['armed' => true, 'fired' => false, 'privateBodies' => 0];
        $scopes = HsEvent::getAllGlobalScopes(); $dispatcher = Model::getEventDispatcher();
        Model::setEventDispatcher(clone $dispatcher);
        $zone = config('app.worker_timezone');
        HsEvent::retrieved(function (HsEvent $row) use ($state): void { if ($state->fired && array_key_exists('closure_summary', $row->getAttributes())) { $state->privateBodies++; } });
        HsEvent::addGlobalScope('risk-eager-date-test', function (Builder $query) use ($state, $change): void {
            $query->getQuery()->beforeQuery(function (QueryBuilder $actual) use ($state, $change): void {
                if (! $state->armed || ! in_array('*', $actual->columns ?? [], true)) { return; }
                $state->armed = false; $state->fired = true;
                if ($change === 'timezone') { config(['app.worker_timezone' => 'UTC']); }
                else { $this->travelTo(now()->addDay()); }
            });
        });
        try {
            RiskAssessmentPresenter::detail($risk, true, $this->manager);
            $this->fail('Original calendar evidence must survive actual eager SELECT compilation.');
        } catch (LogicException $error) {
            $this->assertTrue($state->fired); $this->assertNotSame('', $error->getMessage()); $this->assertSame(0, $state->privateBodies);
        } finally {
            $state->armed = false; config(['app.worker_timezone' => $zone]);
            $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-12T03:00:00Z')->utc());
            HsEvent::setAllGlobalScopes($scopes); Model::setEventDispatcher($dispatcher);
        }
    }

    public function test_supplied_expired_context_and_original_guarded_query_cannot_be_revived_by_another_read(): void
    {
        $event = $this->event($this->incident(['client_id' => $this->client->id])); $risk = $this->risk($event);
        $escaped = null; $query = null;
        RiskAssessmentPresenter::materialise($this->manager, null, function ($current, $reads, $context) use ($risk, &$escaped, &$query): array {
            $escaped = $context; $query = RiskAssessmentPresenter::guardEagerQuery(HsRiskAssessment::whereKey($risk->id), $current, $context);
            return $query->get()->map(fn ($row) => ['id' => $row->id])->all();
        });
        foreach ([
            fn () => RiskAssessmentPresenter::row($risk, $this->manager, $escaped),
            fn () => HealthSafetyReadContext::within($this->manager, RiskAssessmentPresenter::readPermissions(), fn () => $query->get()->all()),
            fn () => app(HsRiskAssessmentService::class)->activate($risk, [], $this->manager, $escaped),
        ] as $operation) {
            try { $operation(); $this->fail('Expired original authority must not be revived.'); }
            catch (LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
        }
        $this->assertSame('draft', $risk->fresh()->status);
    }

    public function test_attachment_http_download_and_delete_are_parent_source_gated_and_preserve_readable_bytes(): void
    {
        // The lead is named as involved in this independently handled incident, so its H&S work stays closed to them.
        // (Without incident read alone, H&S staff at the Site still work the event: Stephan, 25 Oct, "event yes, incident no".)
        Storage::fake('private'); $event = $this->event(ClientIncident::findOrFail($this->accept($this->command())['incident_id']));
        $risk = $this->risk($event);
        $attachment = $this->attachment($risk);
        $url = '/health-safety/risk-assessments/'.$risk->id.'/attachments/'.$attachment->id;
        $response = $this->actingAs($this->manager)->get($url.'/download')->assertOk();
        $this->assertSame('private-evidence-bytes', $response->streamedContent());
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->actingAs($this->lead)->get($url.'/download')->assertNotFound();
        $this->actingAs($this->lead)->delete($url)->assertForbidden();
        $this->assertDatabaseHas('hs_risk_assessment_attachments', ['id' => $attachment->id, 'deleted_at' => null]);
        $this->actingAs($this->manager)->delete($url)->assertRedirect()->assertSessionHas('success', 'Evidence removed.');
        $this->assertSoftDeleted('hs_risk_assessment_attachments', ['id' => $attachment->id]);
    }

    public function test_attachment_deleting_veto_preserves_metadata_file_and_semantic_history(): void
    {
        Storage::fake('private'); $risk = $this->risk(null); $attachment = $this->attachment($risk);
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        HsRiskAssessmentAttachment::deleting(fn () => false);
        try {
            $this->expectException(LogicException::class);
            try {
                app(HsRiskAssessmentService::class)->command($risk, $this->manager, function ($locked, $actor, $reads, $context, $source, $parent) use ($attachment): void {
                    $current = RiskAssessmentPresenter::guardChildQuery(HsRiskAssessmentAttachment::whereKey($attachment->id), 'hs_risk_assessment_attachments.hs_risk_assessment_id', $parent, $context)->firstOrFail();
                    app(HsRiskAssessmentService::class)->audit('risk_assessment.evidence_removed', $locked, $actor);
                    app(HsRiskAssessmentService::class)->deleteAttachment($current, $parent, $context);
                });
            } finally {
                $this->assertDatabaseHas('hs_risk_assessment_attachments', ['id' => $attachment->id, 'deleted_at' => null]);
                $this->assertDatabaseMissing('audit_logs', ['auditable_type' => HsRiskAssessment::class, 'auditable_id' => $risk->id, 'action' => 'risk_assessment.evidence_removed']);
                Storage::disk('private')->assertExists($attachment->path);
            }
        } finally { Model::setEventDispatcher($dispatcher); }
    }

    public function test_site_panel_actual_boundary_and_versions_exclude_private_source_before_risk_body(): void
    {
        $root = ClientIncident::findOrFail($this->accept($this->command())['incident_id']);
        $private = $this->risk($this->event($root), 'dual'); $ordinary = $this->risk(null);
        $state = (object) ['privateBodies' => 0]; $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        HsRiskAssessment::retrieved(function (HsRiskAssessment $row) use ($state, $private): void {
            if ((int) $row->id === (int) $private->id && array_key_exists('title', $row->getAttributes())) { $state->privateBodies++; }
        });
        try {
            $panel = app(SiteProfileSafetyPresenter::class)->riskAssessments($this->lead, $this->site);
            $this->assertSame([$ordinary->id], array_column($panel['assessments'], 'id'));
            $this->assertSame(0, $state->privateBodies);
            $connections = app(RiskAssessmentWorkspaceConnectionsService::class)->forViewer($private, $this->lead);
            $this->assertNull($connections['safety_review']); $this->assertSame([], $connections['versions']['previous']);
            $this->assertSame(0, $state->privateBodies);
        } finally { Model::setEventDispatcher($dispatcher); }
        $panel = app(SiteProfileSafetyPresenter::class)->riskAssessments($this->manager, $this->site);
        $this->assertEqualsCanonicalizing([$private->id, $ordinary->id], array_column($panel['assessments'], 'id'));
    }

    public function test_http_risk_and_attachment_parameters_are_raw_ids_before_private_model_hydration(): void
    {
        foreach (['show', 'update', 'activate', 'markForReview', 'completeReview', 'updateResidual', 'supersede', 'archive', 'uploadAttachment', 'downloadAttachment', 'destroyAttachment'] as $method) {
            foreach ((new \ReflectionMethod(HsRiskAssessmentController::class, $method))->getParameters() as $parameter) {
                if (in_array($parameter->getName(), ['assessment', 'attachment'], true)) {
                    $this->assertNull($parameter->getType(), $method.' must admit the bounded identifier before private model binding.');
                }
            }
        }
    }

    #[DataProvider('eagerBodies')]
    public function test_original_actual_eager_query_cannot_escape_its_context(string $model, string $field): void
    {
        $event = $this->event($this->incident(['client_id' => $this->client->id])); $risk = $this->risk($event, 'modern');
        $state = (object) ['armed' => true, 'captured' => false, 'expired' => false, 'bodySql' => 0]; $escaped = null;
        $scopes = $model::getAllGlobalScopes();
        $model::addGlobalScope('risk-eager-lifetime-test', function (Builder $query) use ($state, &$escaped, $field): void {
            $query->getQuery()->beforeQuery(function (QueryBuilder $actual) use ($state, &$escaped, $field): void {
                if (! $state->armed) { return; }
                $body = in_array('*', $actual->columns ?? [], true) || in_array($field, $actual->columns ?? [], true);
                $parent = false;
                foreach ($actual->wheres ?? [] as $where) {
                    if (($where['type'] ?? null) === 'In' && ($where['values'][0] ?? null) instanceof \Illuminate\Database\Query\Expression) { $parent = true; }
                }
                if (! $body || ! $parent) { return; }
                $state->armed = false; $state->captured = true; $escaped = clone $actual;
            });
        });
        DB::connection()->beforeExecuting(function (string $sql) use ($model, $state): void {
            if ($state->expired && str_starts_with($sql, 'select ') && str_contains($sql, 'from '.chr(96).(new $model)->getTable().chr(96))) { $state->bodySql++; }
        });
        try {
            $this->assertSame('private-risk-marker', RiskAssessmentPresenter::detail($risk, true, $this->manager)['title']);
            $this->assertTrue($state->captured);
            try {
                // Count from inside the later read: opening it locks and checks the viewer's own users row, which is not
                // the escaped query (that matters for the author-name data set).
                HealthSafetyReadContext::within($this->manager, RiskAssessmentPresenter::readPermissions(), function () use ($state, $escaped) {
                    $state->expired = true;
                    return $escaped->get()->all();
                });
                $this->fail('A later context cannot revive original eager source evidence.');
            } catch (LogicException $error) {
                $this->assertNotSame('', $error->getMessage()); $this->assertSame(0, $state->bodySql);
            }
        } finally { $state->armed = false; $state->expired = false; $model::setAllGlobalScopes($scopes); }
    }

    public function test_attachment_actual_delete_sql_denies_late_source_withdrawal_and_keeps_file_and_history(): void
    {
        Storage::fake('private'); $event = $this->event($this->incident(['client_id' => $this->client->id])); $risk = $this->risk($event);
        $attachment = $this->attachment($risk); $state = (object) ['armed' => true, 'fired' => false];
        DB::connection()->beforeExecuting(function (string $sql) use ($state): void {
            if (! $state->armed || ! str_starts_with($sql, 'update '.chr(96).'hs_risk_assessment_attachments'.chr(96))) { return; }
            $state->armed = false; $state->fired = true;
            $this->allow($this->manager, 'incidents.viewAny', false); $this->allow($this->manager, 'incidents.viewAssigned', false);
        });
        try {
            $this->denial(fn () => app(HsRiskAssessmentService::class)->command($risk, $this->manager,
                function ($locked, $actor, $reads, $context, $source, $parent) use ($attachment): void {
                    $current = RiskAssessmentPresenter::guardChildQuery(HsRiskAssessmentAttachment::whereKey($attachment->id), 'hs_risk_assessment_attachments.hs_risk_assessment_id', $parent, $context)->firstOrFail();
                    app(HsRiskAssessmentService::class)->audit('risk_assessment.evidence_removed', $locked, $actor);
                    app(HsRiskAssessmentService::class)->deleteAttachment($current, $parent, $context);
                }));
            $this->assertTrue($state->fired);
            $this->assertDatabaseHas('hs_risk_assessment_attachments', ['id' => $attachment->id, 'deleted_at' => null]);
            $this->assertDatabaseMissing('audit_logs', ['auditable_type' => HsRiskAssessment::class, 'auditable_id' => $risk->id, 'action' => 'risk_assessment.evidence_removed']);
            Storage::disk('private')->assertExists($attachment->path);
        } finally { $state->armed = false; }
    }

    public function test_attachment_upload_veto_rolls_back_body_audit_and_newly_stored_file(): void
    {
        Storage::fake('private'); $risk = $this->risk(null);
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        HsRiskAssessmentAttachment::saving(fn () => false);
        try {
            $this->withoutExceptionHandling();
            try {
                $this->actingAs($this->manager)->post('/health-safety/risk-assessments/'.$risk->id.'/attachments',
                    ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('proof.txt', 'synthetic proof')]);
                $this->fail('Evidence persistence veto must fail before a successful receipt.');
            } catch (LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
            $this->assertDatabaseMissing('hs_risk_assessment_attachments', ['hs_risk_assessment_id' => $risk->id]);
            $this->assertDatabaseMissing('audit_logs', ['auditable_type' => HsRiskAssessment::class, 'auditable_id' => $risk->id, 'action' => 'risk_assessment.evidence_added']);
            $this->assertSame([], Storage::disk('private')->allFiles());
        } finally { Model::setEventDispatcher($dispatcher); }
    }

    public function test_client_http_risk_panel_materialises_only_readable_rows_before_private_risk_body(): void
    {
        $root = ClientIncident::findOrFail($this->accept($this->command())['incident_id']);
        $private = $this->risk($this->event($root), 'dual'); $ordinary = $this->risk(null);
        DB::table('hs_risk_assessments')->whereIn('id', [$private->id, $ordinary->id])
            ->update(['assessable_type' => \App\Models\Client::class, 'assessable_id' => $this->client->id]);
        $state = (object) ['privateBodies' => 0]; $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        HsRiskAssessment::retrieved(function (HsRiskAssessment $row) use ($state, $private): void {
            if ((int) $row->id === (int) $private->id && array_key_exists('risk_description', $row->getAttributes())) { $state->privateBodies++; }
        });
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'operations/clients/show',
            'X-Inertia-Partial-Data' => 'hs_risk_assessments,ra_pickers'];
        try {
            $this->actingAs($this->lead)->get('/operations/clients/'.$this->client->id.'?tab=risk_management', $headers)
                ->assertOk()->assertJsonPath('component', 'operations/clients/show')
                ->assertJsonCount(1, 'props.hs_risk_assessments')->assertJsonPath('props.hs_risk_assessments.0.id', $ordinary->id);
            $this->assertSame(0, $state->privateBodies);
        } finally { Model::setEventDispatcher($dispatcher); }
        $this->actingAs($this->manager)->get('/operations/clients/'.$this->client->id.'?tab=risk_management', $headers)
            ->assertOk()->assertJsonPath('component', 'operations/clients/show')->assertJsonCount(2, 'props.hs_risk_assessments');
    }

    public function test_actual_first_risk_body_select_rechecks_original_source_before_private_hydration(): void
    {
        $event = $this->event($this->incident(['client_id' => $this->client->id])); $risk = $this->risk($event);
        $state = (object) ['armed' => true, 'fired' => false, 'privateBodies' => 0];
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        HsRiskAssessment::retrieved(function (HsRiskAssessment $row) use ($risk, $state): void {
            if ((int) $row->id === (int) $risk->id && array_key_exists('title', $row->getAttributes())) { $state->privateBodies++; }
        });
        DB::connection()->beforeExecuting(function (string $sql) use ($state): void {
            if (! $state->armed || ! str_starts_with($sql, 'select * from '.chr(96).'hs_risk_assessments'.chr(96))) { return; }
            $state->armed = false; $state->fired = true;
            $this->allow($this->manager, 'incidents.viewAny', false); $this->allow($this->manager, 'incidents.viewAssigned', false);
        });
        try {
            $this->denial(fn () => RiskAssessmentPresenter::detail($risk, true, $this->manager), 404);
            $this->assertTrue($state->fired); $this->assertSame(0, $state->privateBodies);
        } finally { $state->armed = false; Model::setEventDispatcher($dispatcher); }
    }

    public function test_row_reload_retains_original_caller_filter_and_tuple_before_reassociated_body(): void
    {
        $risk = $this->risk(null); $otherSite = Site::factory()->create(['is_active' => true]);
        $this->allow($this->manager, 'healthSafety.viewAllSites');
        $state = (object) ['armed' => true, 'fired' => false, 'replacementBodies' => 0];
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        HsRiskAssessment::retrieved(function (HsRiskAssessment $row) use ($risk, $otherSite, $state): void {
            if ((int) $row->id !== (int) $risk->id || ! array_key_exists('title', $row->getAttributes())) { return; }
            if ($row->title === 'late-reassociated-private-body') { $state->replacementBodies++; }
            if (! $state->armed) { return; }
            $state->armed = false; $state->fired = true;
            DB::table('hs_risk_assessments')->where('id', $risk->id)
                ->update(['assessable_id' => $otherSite->id, 'title' => 'late-reassociated-private-body']);
        });
        try {
            $this->denial(fn () => RiskAssessmentPresenter::materialise($this->manager, null, function ($current, $reads, $context): array {
                $original = RiskAssessmentPresenter::guardEagerQuery(HsRiskAssessment::forAssessable(Site::class, $this->site->id), $current, $context);
                $rows = $original->get();
                return $rows->map(fn (HsRiskAssessment $row) => RiskAssessmentPresenter::row($row, $current, $context, $original))->all();
            }), 404);
            $this->assertTrue($state->fired); $this->assertSame(0, $state->replacementBodies);
        } finally { $state->armed = false; Model::setEventDispatcher($dispatcher); }
    }

    /** Open whole-page gate: Root owns wrapping later Client assembly in the original final barrier. */
    public function test_client_final_page_withholds_earlier_risk_panel_after_late_picker_source_withdrawal(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::UNMET_CHECK, 'a permission withdrawn during the client page\'s later picker read, after the H&S risk panel was read in the same request, is not re-checked against that panel before the page returns (the client page code is Codex\'s own); the next request applies it.', 're-prove the client page\'s H&S risk panel at the end of the request.'));
        $event =$this->event($this->incident(['client_id' => $this->client->id])); $risk = $this->risk($event, 'dual');
        DB::table('hs_risk_assessments')->where('id', $risk->id)->update(['assessable_type' => \App\Models\Client::class, 'assessable_id' => $this->client->id]);
        $state = (object) ['armed' => true, 'fired' => false, 'earlierRiskBodies' => 0];
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        HsRiskAssessment::retrieved(function (HsRiskAssessment $row) use ($risk, $state): void {
            if ((int) $row->id === (int) $risk->id && array_key_exists('risk_description', $row->getAttributes())) { $state->earlierRiskBodies++; }
        });
        DB::connection()->beforeExecuting(function (string $sql) use ($state): void {
            if (! $state->armed || $state->earlierRiskBodies === 0
                || ! str_starts_with($sql, 'select '.chr(96).'id'.chr(96).', '.chr(96).'name'.chr(96).' from '.chr(96).'sites'.chr(96))) { return; }
            $state->armed = false; $state->fired = true;
            // Withdraw the H&S read the risk panel depends on. (Incident read alone no longer decides whether an
            // incident-linked H&S event and its risks show: Stephan, 25 Oct, "event yes, incident no".)
            $this->allow($this->manager, 'hazards.view', false);
        });
        try {
            $this->actingAs($this->manager)->get('/operations/clients/'.$this->client->id.'?tab=risk_management',
                ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'operations/clients/show',
                    'X-Inertia-Partial-Data' => 'hs_risk_assessments,ra_pickers'])->assertNotFound();
            $this->assertTrue($state->fired, 'Withdraw source access during actual later picker SQL after the private panel was read.');
            $this->assertGreaterThan(0, $state->earlierRiskBodies);
        } finally { $state->armed = false; Model::setEventDispatcher($dispatcher); }
    }

    public static function eagerBodies(): array
    {
        return ['modern event body' => [HsEvent::class, 'closure_summary'], 'assessment author name' => [User::class, 'name']];
    }

    public static function bindings(): array { return ['legacy' => ['legacy'], 'modern' => ['modern'], 'dual Site/event' => ['dual']]; }
    public static function dateChanges(): array { return ['timezone' => ['timezone'], 'day' => ['day']]; }

    private function event(ClientIncident $root): HsEvent
    {
        $category = $root->type === 'near_miss' ? HsEvent::CATEGORY_NEAR_MISS : HsEvent::CATEGORY_INCIDENT;
        $event = HsEvent::factory()->createQuietly(['source_type' => ClientIncident::class, 'source_id' => $root->id,
            'event_category' => $category, 'idempotency_key' => HsEvent::buildIdempotencyKey(ClientIncident::class, $root->id, $category),
            'site_id' => $root->site_id, 'client_id' => $root->client_id, 'control_room_alert_id' => null,
            'created_by' => $this->manager->id, 'closure_summary' => 'private-source-marker']);
        DB::table('client_incidents')->where('id', $root->id)->update(['hs_event_id' => $event->id]);
        return $event;
    }

    private function risk(?HsEvent $event, string $binding = 'legacy'): HsRiskAssessment
    {
        return HsRiskAssessment::factory()->createQuietly([...$this->draft($event ? 'private-risk-marker' : 'ordinary-risk-marker'),
            'assessed_by_user_id' => $this->manager->id, 'created_by' => $this->manager->id,
            'assessable_type' => $binding === 'modern' ? HsEvent::class : (($event === null || $binding === 'dual') ? Site::class : null),
            'assessable_id' => $binding === 'modern' ? $event?->id : (($event === null || $binding === 'dual') ? $this->site->id : null),
            'hs_event_id' => $binding === 'modern' ? null : $event?->id]);
    }

    private function draft(string $title): array
    {
        return ['title' => $title, 'risk_description' => $title.' narrative', 'likelihood' => 2, 'consequence' => 3,
            'existing_controls' => 'Recorded control', 'additional_controls' => 'Follow-up control', 'review_frequency_days' => 30];
    }

    private function attachment(HsRiskAssessment $risk): HsRiskAssessmentAttachment
    {
        Storage::disk('private')->put('risk-proof/evidence.txt', 'private-evidence-bytes');
        return HsRiskAssessmentAttachment::create(['hs_risk_assessment_id' => $risk->id, 'uploaded_by' => $this->manager->id,
            'disk' => 'private', 'path' => 'risk-proof/evidence.txt', 'original_name' => 'evidence.txt', 'mime' => 'text/plain', 'size' => 22]);
    }
}
