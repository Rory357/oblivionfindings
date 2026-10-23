<?php

namespace Tests\Feature\HealthSafety;

use App\Models\ClientIncident;
use App\Models\ClientIncidentAttachment;
use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsRiskAssessment;
use App\Models\IncidentFollowup;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\HealthSafety\HsEventService;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\RestraintAccessService;
use App\Services\Incidents\IncidentIndependencePolicySettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Incidents\IncidentIndependenceTestCase;

/** Complete native source/detail suite UNRUN. It does not certify bulk readers, CR transport, copied byte provenance or query budget. */
final class IncidentHsEventSourceDetailTest extends IncidentIndependenceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach ([$this->manager, $this->lead] as $viewer) { $this->allow($viewer, 'hazards.view'); }
    }

    private function within(User $viewer, callable $operation): mixed
    {
        return HealthSafetyReadContext::within($viewer, array_unique([
            ...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS,
        ]), $operation);
    }

    /** Real retained intake and original HSE producer; no latest-link inference or fabricated declaration receipt. */
    private function source(bool $independent = false, bool $direct = true): array
    {
        $incident = $independent
            ? ClientIncident::findOrFail($this->accept($this->command())['incident_id']) : $this->incident();
        DB::table('client_incidents')->where('id', $incident->id)->update([
            'type' => 'injury', 'description' => 'Private original incident narrative.', 'immediate_action_taken' => 'Private original immediate controls.',
            'witnesses' => 'Private original witness.',
        ]);
        $incident = $incident->fresh();
        $event = app(HsEventService::class)->recordEvent([
            'source' => $incident, 'event_category' => HsEvent::CATEGORY_INCIDENT, 'severity' => HsEvent::SEVERITY_HIGH,
            'site_id' => $incident->site_id, 'client_id' => $incident->client_id, 'created_by' => $this->manager->id,
            'occurred_at' => now()->subMinute(),
        ]);
        $this->assertInstanceOf(HsEvent::class, $event);
        // A synthetic copied field tests source confidentiality, not a byte-version provenance certificate.
        DB::table('hs_events')->where('id', $event->id)->update(['closure_summary' => 'Private copied HSE marker.']);
        if ($direct) { DB::table('client_incidents')->where('id', $incident->id)->update(['hs_event_id' => $event->id]); }
        $attachment = ClientIncidentAttachment::withoutEvents(fn () => ClientIncidentAttachment::create([
            'incident_id' => $incident->id, 'uploaded_by' => $this->manager->id, 'disk' => 'private',
            'path' => 'incident-fixture/private-source.txt', 'original_name' => 'Private original attachment.txt',
            'mime' => 'text/plain', 'mime_type' => 'text/plain', 'size' => 31,
        ]));
        $followup = IncidentFollowup::withoutEvents(fn () => IncidentFollowup::create([
            'client_incident_id' => $incident->id, 'created_by' => $this->manager->id, 'assigned_to_user_id' => $this->manager->id,
            'notes' => 'Private original followup instructions.', 'due_at' => now()->addDay(),
        ]));
        return ['incident' => $incident->fresh(), 'event' => $event->fresh(), 'attachment' => $attachment, 'followup' => $followup];
    }

    /** Counts actual private model hydration; metadata-only admission queries are deliberately not body reads. */
    private function observeBodies(array $fixture, callable $operation, ?string $mutateTable = null, ?callable $mutate = null): object
    {
        $state = (object) ['event' => 0, 'incident' => 0, 'attachment' => 0, 'followup' => 0, 'armed' => $mutate !== null, 'fired' => false];
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        foreach ([HsEvent::class => ['event', 'closure_summary'], ClientIncident::class => ['incident', 'description'],
            ClientIncidentAttachment::class => ['attachment', 'original_name'], IncidentFollowup::class => ['followup', 'notes']] as $class => [$key, $field]) {
            $id = $fixture[$key]->id;
            $class::retrieved(function ($row) use ($state, $key, $field, $id): void {
                if ((int) $row->id === (int) $id && array_key_exists($field, $row->getAttributes())) { $state->{$key}++; }
            });
        }
        if ($mutate !== null) {
            DB::connection()->beforeExecuting(function ($sql) use ($state, $mutateTable, $mutate): void {
                if (! $state->armed || ! preg_match('/^select (.*?) from `([^`]+)` /s', $sql, $match) || $match[2] !== $mutateTable) { return; }
                if (in_array($mutateTable, ['hs_events', 'client_incidents'], true) && ! str_contains($match[1], '*')) { return; }
                $state->armed = false; $state->fired = true; $mutate();
            });
        }
        try { $operation(); }
        finally { $state->armed = false; Model::setEventDispatcher($dispatcher); }
        return $state;
    }

    private function detail(array $fixture, ?User $viewer = null)
    {
        return $this->actingAs($viewer ?? $this->manager)->get('/health-safety/events/'.$fixture['event']->id);
    }

    public function test_actual_detail_reads_the_original_incident_and_guarded_children_without_storage_paths(): void
    {
        $fixture = $this->source();
        $state = $this->observeBodies($fixture, fn () => $this->detail($fixture)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('detail.description', 'Private original incident narrative.')
                ->where('detail.handover_summary.immediate_controls', 'Private original immediate controls.')
                ->where('detail.handover_summary.witnesses', 'Private original witness.')
                ->where('detail.attachments.0.name', 'Private original attachment.txt')
                ->where('detail.incident_followups.0.notes', 'Private original followup instructions.')
                ->missing('detail.attachments.0.path')->missing('detail.attachments.0.disk')));
        $this->assertGreaterThan(0, $state->event); $this->assertGreaterThan(0, $state->incident);
        $this->assertGreaterThan(0, $state->attachment); $this->assertGreaterThan(0, $state->followup);
    }

    public function test_named_independence_denies_hse_and_incident_children_before_body_while_unrelated_event_survives(): void
    {
        $fixture = $this->source(true); $healthy = $this->source();
        $state = $this->observeBodies($fixture, fn () => $this->detail($fixture, $this->lead)->assertNotFound()
            ->assertDontSee('Private original incident narrative.')->assertDontSee('Private copied HSE marker.'));
        $this->assertSame([0, 0, 0, 0], [$state->event, $state->incident, $state->attachment, $state->followup]);
        $this->detail($healthy, $this->lead)->assertOk();
    }

    public function test_later_policy_and_source_closure_never_release_original_named_independence(): void
    {
        $fixture = $this->source(true);
        app(IncidentIndependencePolicySettings::class)->save($this->manager, ['mode' => 'assignment_only', 'expected_policy_revision' => 0,
            'request_uuid' => (string) Str::uuid(), 'reason' => 'Different future original incidents only.']);
        DB::table('client_incidents')->where('id', $fixture['incident']->id)->update(['status' => 'closed', 'closed_at' => now()]);
        $this->detail($fixture, $this->lead)->assertNotFound(); $this->detail($fixture)->assertOk();
    }

    public function test_current_permission_withdrawal_and_restore_uses_fresh_evidence_despite_warmed_actor(): void
    {
        $fixture = $this->source(); $warm = $this->manager->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->allow($this->manager, 'incidents.viewAny', false);
        $state = $this->observeBodies($fixture, fn () => $this->detail($fixture, $warm)->assertNotFound());
        $this->assertSame([0, 0, 0, 0], [$state->event, $state->incident, $state->attachment, $state->followup]);
        $this->allow($this->manager, 'incidents.viewAny', true); $this->detail($fixture, $warm)->assertOk();
    }

    public function test_current_site_withdrawal_preserves_original_source_site_and_can_restore(): void
    {
        $fixture = $this->source(); $foreign = Site::factory()->create(['is_active' => true]);
        DB::table('hr_employee_profiles')->where('user_id', $this->manager->id)->update(['primary_site_id' => $foreign->id]);
        $this->detail($fixture)->assertNotFound();
        DB::table('hr_employee_profiles')->where('user_id', $this->manager->id)->update(['primary_site_id' => $this->site->id]);
        $this->detail($fixture)->assertOk();
    }

    public function test_established_exact_typed_legacy_source_without_direct_incident_fk_remains_readable(): void
    {
        $fixture = $this->source(direct: false);
        $this->assertNull($fixture['incident']->hs_event_id);
        $this->detail($fixture)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('detail.source.url', '/incidents/'.$fixture['incident']->id)
            ->where('detail.description', 'Private original incident narrative.'));
    }

    public function test_competing_direct_incident_link_and_wrong_original_client_are_quarantined_without_body(): void
    {
        $fixture = $this->source(); $other = $this->incident();
        DB::table('client_incidents')->where('id', $other->id)->update(['hs_event_id' => $fixture['event']->id]);
        $state = $this->observeBodies($fixture, fn () => $this->detail($fixture)->assertNotFound());
        $this->assertSame(0, $state->event); $this->assertSame(0, $state->incident);
        DB::table('client_incidents')->where('id', $other->id)->update(['hs_event_id' => null]);
        DB::table('client_incidents')->where('id', $fixture['incident']->id)->update(['client_id' => $other->client_id]);
        $this->detail($fixture)->assertNotFound();
        DB::table('client_incidents')->where('id', $fixture['incident']->id)->update(['client_id' => $fixture['incident']->client_id]);
        $this->detail($fixture)->assertOk();
    }

    public static function malformedCompetingSourceClaims(): array
    {
        return ['leading tab' => ["\t".ClientIncident::class], 'trailing CR' => [ClientIncident::class."\r"],
            'trailing LF' => [ClientIncident::class."\n"]];
    }

    #[DataProvider('malformedCompetingSourceClaims')]
    public function test_actual_detail_denies_a_duplicate_malformed_legacy_source_claim_before_private_body(string $type): void
    {
        $fixture = $this->source(direct: false);
        $duplicate = HsEvent::withoutEvents(fn () => HsEvent::factory()->create([
            'source_type' => $type, 'source_id' => $fixture['incident']->id,
            'event_category' => $fixture['event']->event_category,
            'idempotency_key' => hash('sha256', 'competing-malformed-claim-'.$type.'-'.$fixture['incident']->id),
            'site_id' => $this->site->id, 'client_id' => $fixture['incident']->client_id,
        ]));
        $state = $this->observeBodies($fixture, fn () => $this->detail($fixture)->assertNotFound());
        $this->assertSame([0, 0, 0, 0], [$state->event, $state->incident, $state->attachment, $state->followup]);
        DB::table('hs_events')->where('id', $duplicate->id)->delete();
        $this->detail($fixture)->assertOk();
    }

    public static function damagedSourceMetadata(): array
    {
        return ['case' => ['source_type', strtolower(ClientIncident::class)], 'trailing bytes' => ['source_type', ClientIncident::class.' '],
            'tab and newline bytes' => ['source_type', "\t".ClientIncident::class."\r\n"],
            'wrong category' => ['event_category', HsEvent::CATEGORY_NEAR_MISS], 'wrong original key' => ['idempotency_key', str_repeat('0', 64)]];
    }

    #[DataProvider('damagedSourceMetadata')]
    public function test_changed_typed_source_category_or_original_key_is_not_a_new_original(string $field, string $value): void
    {
        $fixture = $this->source(direct: false);
        DB::table('hs_events')->where('id', $fixture['event']->id)->update([$field => $value]);
        $state = $this->observeBodies($fixture, fn () => $this->detail($fixture)->assertNotFound());
        $this->assertSame(0, $state->event); $this->assertSame(0, $state->incident);
    }

    public static function lateSourceChanges(): array
    {
        return ['read grant' => ['grant'], 'HR source privacy' => ['privacy'], 'original client' => ['client'],
            'relabeled source' => ['source'], 'competing link' => ['competing'], 'independence declaration' => ['independence']];
    }

    #[DataProvider('lateSourceChanges')]
    public function test_actual_hse_body_select_rejects_late_original_graph_change_before_body(string $change): void
    {
        $fixture = $this->source(true); $other = $this->incident();
        $state = $this->observeBodies($fixture, fn () => $this->detail($fixture)->assertNotFound(), 'hs_events', function () use ($change, $fixture, $other): void {
            match ($change) {
                'grant' => $this->allow($this->manager, 'incidents.viewAny', false),
                'privacy' => DB::table('client_incidents')->where('id', $fixture['incident']->id)->update(['is_hr_confidential' => true]),
                'client' => DB::table('client_incidents')->where('id', $fixture['incident']->id)->update(['client_id' => $other->client_id]),
                'source' => DB::table('hs_events')->where('id', $fixture['event']->id)->update(['source_type' => HsEvent::class]),
                'competing' => DB::table('client_incidents')->where('id', $other->id)->update(['hs_event_id' => $fixture['event']->id]),
                'independence' => DB::table('incident_independent_handling_declarations')->where('incident_id', $fixture['incident']->id)->update(['handling' => json_encode(['requested' => false])]),
            };
        });
        $this->assertTrue($state->fired); $this->assertSame([0, 0, 0, 0], [$state->event, $state->incident, $state->attachment, $state->followup]);
    }

    public function test_actual_incident_body_select_rejects_late_withdrawal_and_withholds_whole_detail(): void
    {
        $fixture = $this->source();
        $state = $this->observeBodies($fixture, fn () => $this->detail($fixture)->assertNotFound(), 'client_incidents',
            fn () => $this->allow($this->manager, 'incidents.viewAny', false));
        $this->assertTrue($state->fired); $this->assertSame(0, $state->incident);
        $this->assertSame(0, $state->attachment); $this->assertSame(0, $state->followup);
    }

    public function test_actual_attachment_select_rejects_late_withdrawal_before_name_or_followup_body(): void
    {
        $fixture = $this->source();
        $state = $this->observeBodies($fixture, fn () => $this->detail($fixture)->assertNotFound(), 'client_incident_attachments',
            fn () => $this->allow($this->manager, 'incidents.viewAny', false));
        $this->assertTrue($state->fired); $this->assertSame(0, $state->attachment); $this->assertSame(0, $state->followup);
    }

    public function test_count_search_and_modern_and_legacy_children_use_the_same_original_source_denial(): void
    {
        $fixture = $this->source(true); $healthy = $this->source();
        $action = HsCorrectiveAction::factory()->create(['hs_event_id' => $fixture['event']->id]);
        $risk = HsRiskAssessment::factory()->forSite($this->site->id)->create(['hs_event_id' => $fixture['event']->id]);
        $legacy = HsRiskAssessment::factory()->create(['hs_event_id' => null, 'assessable_type' => HsEvent::class, 'assessable_id' => $fixture['event']->id]);
        $this->within($this->lead, function ($context) use ($fixture, $healthy, $action, $risk, $legacy): array {
            $access = app(HsEventAccessService::class);
            $events = $access->applyReadableScope(HsEvent::query()->whereIn('id', [$fixture['event']->id, $healthy['event']->id]), $this->lead, $context);
            $this->assertSame(1, (clone $events)->count()); $this->assertSame([$healthy['event']->id], (clone $events)->pluck('id')->all());
            $this->assertSame(0, $access->applyFirstAidChildSourceScope(HsCorrectiveAction::query()->whereKey($action->id), 'hs_corrective_actions.hs_event_id', $this->lead, $context)->count());
            $this->assertSame(0, $access->applyFirstAidRiskSourceScope(HsRiskAssessment::query()->whereIn('id', [$risk->id, $legacy->id]), $this->lead, $context)->count());
            return [];
        });
    }

    public function test_same_context_reuses_original_predicate_after_source_reassociation_instead_of_rebaselining(): void
    {
        $fixture = $this->source(); $other = $this->incident();
        $this->within($this->manager, function ($context) use ($fixture, $other): array {
            $parent = $context->incidentQueryForEvent($fixture['event']);
            $this->assertTrue((clone $parent)->exists());
            DB::table('client_incidents')->where('id', $fixture['incident']->id)->update(['client_id' => $other->client_id]);
            $this->assertNull($context->incidentQueryForEvent($fixture['event'])->first(['id', 'description']));
            $this->assertSame(0, $context->applyIncidentSourceScope(ClientIncident::query()->whereKey($fixture['incident']->id))->count());
            return [];
        });
    }

    public function test_expired_original_predicate_cannot_be_embedded_in_another_body_reader(): void
    {
        $fixture = $this->source(); $retained = null;
        $this->within($this->manager, function ($context) use ($fixture, &$retained): array { $retained = $context->incidentQueryForEvent($fixture['event']); return []; });
        try {
            HsEvent::query()->whereKey($fixture['event']->id)->whereExists((clone $retained)->selectRaw('1')->toBase())->first();
            $this->fail('Expired original source predicate must refuse nested compilation.');
        } catch (LogicException $failure) { $this->assertStringContainsString('transaction scope', $failure->getMessage()); }
    }

    public function test_actual_followup_select_rejects_late_withdrawal_before_instructions(): void
    {
        $fixture = $this->source();
        $state = $this->observeBodies($fixture, fn () => $this->detail($fixture)->assertNotFound(), 'incident_followups',
            fn () => $this->allow($this->manager, 'incidents.viewAny', false));
        $this->assertTrue($state->fired); $this->assertSame(0, $state->followup);
    }

    public function test_unrelated_hse_keeps_ordinary_authority_when_incident_permission_is_withdrawn(): void
    {
        $fixture = $this->source();
        $ordinary = HsEvent::factory()->create(['site_id' => $this->site->id, 'source_type' => null, 'source_id' => null]);
        $this->allow($this->manager, 'incidents.viewAny', false);
        $this->within($this->manager, function ($context) use ($fixture, $ordinary): array {
            $query = app(HsEventAccessService::class)->applyReadableScope(HsEvent::query()->whereIn('id', [$fixture['event']->id, $ordinary->id]), $this->manager, $context);
            $this->assertSame([$ordinary->id], $query->pluck('id')->all());
            return [];
        });
    }

    public function test_original_child_and_risk_predicates_refuse_nested_compilation_after_read_lifetime(): void
    {
        $fixture = $this->source(); $retained = [];
        $this->within($this->manager, function ($context) use ($fixture, &$retained): array {
            $access = app(HsEventAccessService::class);
            $retained[] = $access->applyFirstAidChildSourceScope(HsCorrectiveAction::query()->where('hs_event_id', $fixture['event']->id), 'hs_corrective_actions.hs_event_id', $this->manager, $context);
            $retained[] = $access->applyFirstAidRiskSourceScope(HsRiskAssessment::query()->where('hs_event_id', $fixture['event']->id), $this->manager, $context);
            return [];
        });
        foreach ($retained as $query) {
            try {
                HsEvent::query()->whereExists((clone $query)->selectRaw('1')->toBase())->toSql();
                $this->fail('Nested child and risk predicates must retain original read lifetime.');
            } catch (LogicException $failure) {
                $this->assertStringContainsString('transaction scope', $failure->getMessage());
            }
        }
    }

    public function test_actual_child_and_risk_selects_reconsume_original_resolved_timezone(): void
    {
        $fixture = $this->source();
        $originalWorker = config('app.worker_timezone'); $originalApp = config('app.timezone');
        config(['app.worker_timezone' => null, 'app.timezone' => 'Pacific/Auckland']);
        try {
            $this->within($this->manager, function ($context) use ($fixture): array {
                $access = app(HsEventAccessService::class);
                $queries = [
                    $access->applyFirstAidChildSourceScope(HsCorrectiveAction::query()->where('hs_event_id', $fixture['event']->id), 'hs_corrective_actions.hs_event_id', $this->manager, $context),
                    $access->applyFirstAidRiskSourceScope(HsRiskAssessment::query()->where('hs_event_id', $fixture['event']->id), $this->manager, $context),
                ];
                config(['app.timezone' => 'UTC']);
                foreach ($queries as $query) {
                    try {
                        $query->get();
                        $this->fail('Actual derived SELECT must reconsume the original resolved timezone.');
                    } catch (LogicException $failure) {
                        $this->assertStringContainsString('source date evidence changed', $failure->getMessage());
                    }
                }
                return [];
            });
        } finally {
            config(['app.worker_timezone' => $originalWorker, 'app.timezone' => $originalApp]);
        }
    }

    public function test_incident_child_actual_select_reconsumes_the_original_parent_compiler_guard(): void
    {
        $fixture = $this->source();
        $worker = config('app.worker_timezone'); $fallback = config('app.timezone');
        config(['app.worker_timezone' => '', 'app.timezone' => 'Pacific/Auckland']);
        try {
            $this->within($this->manager, function ($context) use ($fixture): array {
                $parent = $context->incidentQueryForEvent($fixture['event']);
                return $context->consumeSourceViewer([], function ($actor, $reads) use ($parent, $fixture): array {
                    $child = app(\App\Services\Incidents\IncidentHsEventSourceReadService::class)->childQueryInReads(
                        ClientIncidentAttachment::query()->whereKey($fixture['attachment']->id), 'incident_id', $parent, $reads);
                    $child->getQuery()->beforeQuery(fn () => config(['app.timezone' => 'UTC']));
                    try {
                        $child->first(['id', 'original_name']);
                        $this->fail('Original parent compiler guard must run on the actual child SELECT.');
                    } catch (LogicException $failure) {
                        $this->assertStringContainsString('source date evidence changed', $failure->getMessage());
                    }
                    return [];
                });
            });
        } finally { config(['app.worker_timezone' => $worker, 'app.timezone' => $fallback]); }
    }

    public function test_lingering_overrides_on_an_external_account_never_admit_original_source(): void
    {
        $fixture = $this->source();
        DB::table('users')->where('id', $this->manager->id)->update(['external_clinical_account' => true]);
        $state = $this->observeBodies($fixture, fn () => $this->detail($fixture)->assertForbidden());
        $this->assertSame([0, 0, 0, 0], [$state->event, $state->incident, $state->attachment, $state->followup]);
    }

    public function test_established_leading_slash_legacy_source_contract_keeps_exact_original_key(): void
    {
        $fixture = $this->source(direct: false);
        DB::table('hs_events')->where('id', $fixture['event']->id)->update(['source_type' => '\\'.ClientIncident::class]);
        $this->detail($fixture)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('detail.description', 'Private original incident narrative.'));
    }

    public function test_same_live_read_reuses_original_common_evidence_without_recapturing_a_withdrawn_grant(): void
    {
        $first = $this->source(); $second = $this->source();
        $this->within($this->manager, function ($context) use ($first, $second): array {
            DB::flushQueryLog(); DB::enableQueryLog();
            try {
                $this->assertTrue($context->incidentQueryForEvent($first['event'])->exists());
                $this->assertTrue($context->incidentQueryForEvent($second['event'])->exists());
                $firstGuard = $context->incidentQueryForEvent($first['event']);
                $definitionReads = collect(DB::getQueryLog())->filter(fn ($query) => preg_match('/^select `id`, `key` from `permissions` where `key` in /', $query['query']))->count();
                $this->assertSame(1, $definitionReads, 'Common evidence is observed once within this original read, not once per source.');
                $this->allow($this->manager, 'incidents.viewAny', false);
                $this->assertFalse((clone $firstGuard)->exists());
                $this->assertFalse($context->incidentQueryForEvent($second['event'])->exists());
                $this->assertSame(0, $context->applyIncidentSourceScope(ClientIncident::query()->whereIn('id', [$first['incident']->id, $second['incident']->id]))->count());
                $this->allow($this->manager, 'incidents.viewAny', true);
                $this->assertTrue((clone $firstGuard)->exists());
            } finally { DB::disableQueryLog(); }
            return [];
        });
    }

    public function test_incident_authority_does_not_grant_operational_alert_body_or_link(): void
    {
        $fixture = $this->source();
        $alert = ControlRoomAlert::withoutEvents(fn () => ControlRoomAlert::factory()->create(['site_id' => $this->site->id,
            'client_id' => $fixture['incident']->client_id, 'context' => ['incident_id' => $fixture['incident']->id], 'notes' => 'Independent private operational alert marker.']));
        DB::table('client_incidents')->where('id', $fixture['incident']->id)->update(['control_room_alert_id' => $alert->id]);
        DB::table('hs_events')->where('id', $fixture['event']->id)->update(['control_room_alert_id' => $alert->id]);
        $this->allow($this->manager, 'controlRoom.alerts.view', false); $this->allow($this->manager, 'controlRoom.alerts.manage', false);
        $this->detail($fixture)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('detail.description', 'Private original incident narrative.')->where('detail.control_room_alert', null))
            ->assertDontSee('Independent private operational alert marker.');
    }
}
