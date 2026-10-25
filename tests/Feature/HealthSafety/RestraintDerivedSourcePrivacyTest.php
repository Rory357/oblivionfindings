<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Clinical\Services\ClinicalDashboardService;
use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\RestraintEvent;
use App\Services\ControlRoom\ControlRoomSourceContentService;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\RestraintAccessService;
use App\Services\Tasks\Providers\HsCorrectiveActionProvider;
use App\Services\Tasks\Providers\HsInvestigationProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\NotInThisBuild;

class RestraintDerivedSourcePrivacyTest extends RestraintEventCommandTestCase
{
    private function hs(RestraintEvent $source, array $overrides = []): HsEvent
    {
        return HsEvent::withoutEvents(fn () => HsEvent::factory()->create(['source_type' => RestraintEvent::class, 'source_id' => $source->id,
            'event_category' => HsEvent::CATEGORY_RESTRAINT, 'site_id' => $source->site_id, 'client_id' => $source->client_id,
            'staff_id' => $source->created_by, 'created_by' => $source->created_by, ...$overrides]));
    }

    private function alert(RestraintEvent $source, array $context = []): ControlRoomAlert
    {
        return ControlRoomAlert::withoutEvents(fn () => ControlRoomAlert::factory()->create(['source' => 'operations', 'alert_type' => 'operations.restraint_event',
            'client_id' => $source->client_id, 'site_id' => $source->site_id, 'notes' => 'Legacy copied sensitive source note',
            'context' => ['restraint_event_id' => $source->id, 'description' => 'Private copied restraint narrative', ...$context]]));
    }

    public function test_hs_destination_grant_never_substitutes_for_private_source_view_and_review_grants_remain_distinct(): void
    {
        $source = $this->event();
        $event = $this->hs($source);
        $access = app(HsEventAccessService::class);
        $reader = $this->staff(['hazards.view', 'clients.viewAny']);
        $reviewer = $this->staff(['hazards.view', 'restraints.review', 'clients.viewAny']);
        $this->assertFalse($access->canView($event, $reader));
        $this->assertFalse($access->canView($event, $reviewer));
        $viewer = $this->staff(['hazards.view', 'restraints.view', 'clients.viewAny']);
        $this->assertTrue($access->canView($event, $viewer));
        $viewer->load('permissionOverrides', 'roles.permissions');
        $this->revoke($viewer, 'hazards.view');
        $this->assertFalse($access->canView($event, $viewer));
    }

    public function test_exact_derived_tuple_and_recorded_stay_are_binding_for_hs_visibility(): void
    {
        $source = $this->event();
        $viewer = $this->staff(['hazards.view', 'restraints.view', 'clients.viewAny']);
        $access = app(HsEventAccessService::class);
        foreach ([['client_id' => null], ['site_id' => null], ['staff_id' => null], ['event_category' => HsEvent::CATEGORY_INJURY]] as $mismatch) {
            $event = $this->hs($source, $mismatch);
            $this->assertFalse($access->canView($event, $viewer));
        }
        $event = $this->hs($source);
        $this->assertTrue($access->canView($event, $viewer));
        $source->updateQuietly(['recorded_stay_id' => 999]);
        $this->assertFalse($access->canView($event, $viewer));
    }

    public function test_context_and_standalone_source_denial_preserve_ordinary_hs_rows_and_child_privacy(): void
    {
        $source = $this->event();
        $event = $this->hs($source);
        $ordinary = HsEvent::withoutEvents(fn () => HsEvent::factory()->create(['site_id' => $this->site->id]));
        $viewer = $this->staff(['hazards.view', 'clients.viewAny']);
        $access = app(HsEventAccessService::class);
        $keys = [...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS];
        $ids = HealthSafetyReadContext::within($viewer, $keys, fn ($context) => $access->applyReadableScope(HsEvent::query(), $viewer, $context)->pluck('id')->all());
        $this->assertContains($ordinary->id, $ids);
        $this->assertNotContains($event->id, $ids);
        $this->assertTrue($access->canView($ordinary, $viewer));
        $guarded = $access->applyFirstAidChildSourceScope(HsEvent::query(), 'hs_events.id', $viewer)->pluck('id')->all();
        $this->assertNotContains($event->id, $guarded);
        $this->assertContains($ordinary->id, $guarded);
    }

    public function test_filtered_ordinary_hs_read_does_not_fetch_unrelated_private_restraint_sources(): void
    {
        $private = $this->hs($this->event());
        $ordinary = HsEvent::withoutEvents(fn () => HsEvent::factory()->create(['site_id' => $this->site->id]));
        $viewer = $this->staff(['hazards.view', 'restraints.view', 'clients.viewAny']);
        $access = app(HsEventAccessService::class);
        $this->assertTrue($access->canView($private, $viewer));
        $keys = [...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS];
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $this->assertTrue($access->canView($ordinary, $viewer));
            $ids = HealthSafetyReadContext::within($viewer, $keys, fn ($context) => $access->applyReadableScope(HsEvent::whereKey($ordinary->id), $viewer, $context)->pluck('id')->all());
            $this->assertSame([$ordinary->id], $ids);
            foreach (DB::getQueryLog() as $entry) {
                $this->assertStringNotContainsString('restraint_events', strtolower($entry['query']));
            }
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_derived_child_tasks_preserve_correlated_source_scope_standalone_context_and_navigation_parity(): void
    {
        $source = $this->event();
        $event = $this->hs($source);
        $viewer = $this->staff(['hazards.view', 'restraints.view', 'clients.viewAny']);
        $investigation = HsInvestigation::withoutEvents(fn () => HsInvestigation::factory()->create(['hs_event_id' => $event->id, 'lead_investigator_id' => $viewer->id]));
        $action = HsCorrectiveAction::withoutEvents(fn () => HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'assigned_to_user_id' => $viewer->id]));
        $keys = [...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS];
        foreach ([[new HsInvestigationProvider, $investigation->id], [new HsCorrectiveActionProvider, $action->id]] as [$provider, $id]) {
            $filters = ['id' => $id];
            $standalone = $provider->authorizedTasks($viewer, $filters);
            $this->assertCount(1, $standalone);
            $contextual = HealthSafetyReadContext::within($viewer, $keys, fn ($context) => $provider->authorizedTasksInContext($viewer, $filters, $context));
            $this->assertEquals($standalone, $contextual);
            $navigation = HealthSafetyReadContext::within($viewer, $keys, fn ($context) => $provider->authorizedNavigationTasksInContext($viewer, $context));
            $this->assertSame(array_column($standalone, 'id'), array_column($navigation, 'id'));
        }
        $viewer->load('permissionOverrides', 'roles.permissions');
        $this->revoke($viewer, 'restraints.view');
        foreach ([new HsInvestigationProvider, new HsCorrectiveActionProvider] as $provider) {
            $this->assertSame([], $provider->authorizedTasks($viewer));
            $this->assertSame([], HealthSafetyReadContext::within($viewer, $keys, fn ($context) => $provider->authorizedTasksInContext($viewer, [], $context)));
            $this->assertSame([], HealthSafetyReadContext::within($viewer, $keys, fn ($context) => $provider->authorizedNavigationTasksInContext($viewer, $context)));
        }
    }

    public function test_cr_source_entry_is_detached_exact_and_fresh_while_operational_content_is_preserved(): void
    {
        $source = $this->event();
        $alert = $this->alert($source, ['source_deliveries' => [['source_revision' => 1, 'delivery_id' => (string) Str::uuid(), 'severity' => 'medium', 'accepted_at' => now()->toIso8601String()]],
            'activity_log' => [['content' => 'Independent operational response note', 'type' => 'note']]]);
        $alert->updateQuietly(['notes' => 'Independent operational notes']);
        $viewer = $this->staff(['controlRoom.alerts.view', 'clients.viewAny']);
        $content = app(ControlRoomSourceContentService::class);
        $entry = $content->restraintForAlert($alert, $viewer);
        $this->assertSame('restricted', $entry['restraint_access']);
        $this->assertNull($entry['restraint_source']);
        DB::enableQueryLog();
        $before = count(DB::getQueryLog());
        $dto = $content->present($alert, $entry, $alert->context);
        $this->assertSame($before, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $this->assertSame('Independent operational notes', $dto['notes']);
        $this->assertSame('Independent operational response note', $dto['context']['activity_log'][0]['content']);
        $this->assertNull($dto['context']['restraint_source']);
        $this->assertStringNotContainsString('Private copied restraint narrative', json_encode($dto));
        $this->assertArrayNotHasKey('source_deliveries', $dto['context']);
        $this->assertArrayNotHasKey('restraint_event_id', $dto['context']);
        $this->assertStringNotContainsString('Independent operational notes', $dto['summary']);
        $audit = $content->audit(['action' => 'control_room.restraint_source_accepted', 'user' => ['id' => 99], 'meta' => ['restraint_event_id' => $source->id, 'delivery_id' => (string) Str::uuid(), 'source_revision' => 1, 'severity' => 'medium', 'new_response' => true]], $entry);
        $this->assertArrayNotHasKey('restraint_event_id', $audit['meta']);
        $this->assertArrayNotHasKey('delivery_id', $audit['meta']);
        $this->assertArrayNotHasKey('source_revision', $audit['meta']);
        $this->assertSame('medium', $audit['meta']['severity']);
        $this->assertNull($audit['user']);
    }

    public function test_legacy_copied_cr_notes_search_and_summary_are_source_guarded_without_closing_response(): void
    {
        $source = $this->event();
        $alert = $this->alert($source);
        $content = app(ControlRoomSourceContentService::class);
        $denied = $this->staff(['controlRoom.alerts.view', 'clients.viewAny']);
        $viewer = $this->staff(['controlRoom.alerts.view', 'restraints.view', 'clients.viewAny']);
        $this->assertNull($content->present($alert, $content->restraintForAlert($alert, $denied), $alert->context)['notes']);
        $read = $content->restraintForAlert($alert, $viewer);
        $this->assertSame('/health-safety/restraints/events/'.$source->id, $read['restraint_source']['href']);
        $this->assertSame($alert->notes, $content->present($alert, $read, $alert->context)['notes']);
        $this->assertFalse($content->applyReadableNotes(ControlRoomAlert::whereKey($alert->id), $denied)->exists());
        $this->assertTrue($content->applyReadableNotes(ControlRoomAlert::whereKey($alert->id), $viewer)->exists());
        $viewer->load('permissionOverrides', 'roles.permissions');
        $this->revoke($viewer, 'restraints.view');
        $this->assertNull($content->restraintForAlert($alert, $viewer)['restraint_source']);
        $snapshot = $content->snapshotSummary(['summary' => 'Old private source summary', 'summary_origin' => ['type' => 'alert']], $alert, []);
        $this->assertStringNotContainsString('Old private source summary', $snapshot['summary']);
        $this->assertDatabaseHas('control_room_alerts', ['id' => $alert->id]);
    }

    public function test_cr_context_consumes_same_active_current_evidence_and_rejects_foreign_source_tuple(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::OTHER_MODULE, 'it needs Codex\'s Control Room source-content and Clinical restraint lens changes, which this build doesn\'t port.', 'port with the Control Room and Clinical work.'));
        $source = $this->event();
        $alert = $this->alert($source);
        $viewer = $this->staff(['restraints.view', 'clients.viewAssigned']);
        $this->client->supportWorkers()->attach($viewer->id);
        $content = app(ControlRoomSourceContentService::class);
        $entry = HealthSafetyReadContext::within($viewer, RestraintAccessService::PERMISSIONS, fn ($context) => $content->restraintForAlert($alert, $viewer, $context));
        $this->assertSame($source->id, $entry['restraint_source']['id']);
        $this->client->supportWorkers()->detach($viewer->id);
        $this->assertNull($content->restraintForAlert($alert, $viewer)['restraint_source']);
        $alert->updateQuietly(['site_id' => null]);
        $this->assertSame('unavailable', $content->restraintForAlert($alert, $this->actor)['restraint_access']);
    }

    public function test_limited_clinical_facts_and_permanent_source_entry_preserve_separate_current_authority(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::OTHER_MODULE, 'it needs Codex\'s Control Room source-content and Clinical restraint lens changes, which this build doesn\'t port.', 'port with the Control Room and Clinical work.'));
        $source = $this->event(['within_support_plan' => false, 'deviation_reason' => 'Actual deviation']);
        $clinical = app(ClinicalDashboardService::class);
        $denied = $this->staff(['clinical.behaviour.viewAny', 'clients.viewAny']);
        $limited = $clinical->getRestraintLens($denied);
        $this->assertSame($source->id, $limited['events'][0]['id']);
        $this->assertNull($limited['events'][0]['href']);
        $this->assertSame(1, $limited['stats']['off_plan']);
        $viewer = $this->staff(['clinical.behaviour.viewAny', 'restraints.view', 'clients.viewAssigned']);
        $this->client->supportWorkers()->attach($viewer->id);
        $dto = $clinical->getRestraintLens($viewer);
        $this->assertSame($source->id, $dto['events'][0]['id']);
        $this->assertSame('/health-safety/restraints/events/'.$source->id, $dto['events'][0]['href']);
        $this->assertSame(1, $dto['stats']['off_plan']);
        $this->assertStringNotContainsString('Sensitive factual source', json_encode($dto));
        $viewer->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $this->client->supportWorkers()->detach($viewer->id);
        $limited = $clinical->getRestraintLens($viewer);
        $this->assertSame($source->id, $limited['events'][0]['id']);
        $this->assertNull($limited['events'][0]['href']);
        $this->assertSame(1, $limited['stats']['off_plan']);
        $this->client->supportWorkers()->attach($viewer->id);
        $this->revoke($viewer, 'clinical.behaviour.viewAny');
        $this->assertSame([], $clinical->getRestraintLens($viewer)['events']);
    }

    public function test_reused_source_hr_and_site_revocation_conceal_all_derived_entries(): void
    {
        $this->markTestSkipped(NotInThisBuild::reason(NotInThisBuild::OTHER_MODULE, 'it needs Codex\'s Control Room source-content and Clinical restraint lens changes, which this build doesn\'t port.', 'port with the Control Room and Clinical work.'));
        $source = $this->event();
        $hs = $this->hs($source);
        $alert = $this->alert($source);
        $viewer = $this->staff(['clinical.behaviour.viewAny', 'hazards.view', 'restraints.view', 'clients.viewAny']);
        $viewer->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $viewer->hrEmployeeProfile()->update(['is_active' => false]);
        $this->assertFalse(app(HsEventAccessService::class)->canView($hs, $viewer));
        $this->assertNull(app(ControlRoomSourceContentService::class)->restraintForAlert($alert, $viewer)['restraint_source']);
        $this->assertSame([], app(ClinicalDashboardService::class)->getRestraintLens($viewer)['events']);
        $viewer->hrEmployeeProfile()->update(['is_active' => true]);
        $this->site->update(['is_active' => false]);
        $this->assertFalse(app(HsEventAccessService::class)->canView($hs, $viewer));
        $this->assertNull(app(ControlRoomSourceContentService::class)->restraintForAlert($alert, $viewer)['restraint_source']);
        $this->assertSame([], app(ClinicalDashboardService::class)->getRestraintLens($viewer)['events']);
    }
}
