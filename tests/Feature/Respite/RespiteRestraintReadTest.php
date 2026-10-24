<?php

namespace Tests\Feature\Respite;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\BehaviourSupportPlan;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\RespiteBooking;
use App\Models\RespiteStay;
use App\Models\RestraintEvent;
use App\Models\Site;
use App\Services\CurrentAuthorizationReads;
use App\Services\Respite\RespiteRestraintReadService;
use App\Services\Respite\RespiteStayScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Feature\HealthSafety\RestraintEventCommandTestCase;

/** Complete read-entry proof; actual canonical commands produce the private sources. */
final class RespiteRestraintReadTest extends RestraintEventCommandTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = $this->staff(['restraints.view', 'restraints.create', 'restraints.review', 'restraints.manage', 'clients.viewAny', 'incidents.viewAny', 'incidents.update', 'respite.viewAny', 'respite.stays.manage']);
    }

    private function stay(?Client $client = null, ?Site $site = null): RespiteStay
    {
        $client ??= $this->client;
        $booking = RespiteBooking::factory()->create(['client_id' => $client->id, 'location_id' => ($site ?? $this->site)->id, 'status' => 'confirmed', 'start_at' => now()->subDay(), 'end_at' => now()->addDay()]);

        return RespiteStay::create(['booking_id' => $booking->id, 'client_id' => $client->id, 'status' => 'active', 'actual_start' => now()->subDay(), 'created_by' => $this->actor->id]);
    }

    private function recorded(RespiteStay $stay): RestraintEvent
    {
        return RestraintEvent::findOrFail($this->service()->createForStay($this->actor, $stay, $this->capture())['event_id']);
    }

    private function reads(): RespiteRestraintReadService
    {
        return app(RespiteRestraintReadService::class);
    }

    private function noCapabilities(): array
    {
        return ['can' => ['capture' => false, 'view' => false, 'review' => false], 'summary' => null, 'report_href' => null];
    }

    public function test_capture_only_stay_page_has_no_report_link_or_private_summary(): void
    {
        $stay = $this->stay();
        $this->recorded($stay);
        $viewer = $this->staff(['clients.viewAny', 'respite.viewAny', 'respite.stays.manage']);
        // Decision 7: capture is main's respite form on this page; Codex's stay report page is unrouted until the follow-up.
        $response = $this->actingAs($viewer)->get(route('respite.stays.show', $stay))->assertOk()->assertInertia(fn (Assert $page) => $page->component('respite/stays/show')->where('stay.id', $stay->id)->where('restraints.can', ['capture' => true, 'view' => false, 'review' => false])->where('restraints.summary', null)->where('restraints.report_href', null));
        $this->assertStringNotContainsString('Sensitive factual source', $response->getContent());
        $this->assertStringNotContainsString('plan_authorization_snapshot', $response->getContent());
        $this->assertStringNotContainsString('restraint_events', $response->getContent());
    }

    public function test_view_only_stay_summary_keeps_original_safe_shape_and_legacy_approval_unknown(): void
    {
        $stay = $this->stay();
        $event = $this->recorded($stay);
        $plan = BehaviourSupportPlan::factory()->create(['client_id' => $this->client->id, 'status' => 'active']);
        $viewer = $this->staff(['clients.viewAny', 'respite.viewAny', 'restraints.view']);
        $data = $this->reads()->forStay($viewer, $stay);
        $this->assertSame(['capture' => false, 'view' => true, 'review' => false], $data['can']);
        $this->assertNull($data['report_href']);
        $this->assertSame(['active_plan', 'recent_events', 'total_events'], array_keys($data['summary']));
        $this->assertSame(1, $data['summary']['total_events']);
        $this->assertSame($event->id, $data['summary']['recent_events'][0]['id']);
        $this->assertSame($plan->id, $data['summary']['active_plan']['id']);
        $this->assertSame('unknown', $data['summary']['active_plan']['authorization_state']);
        $this->assertNull($data['summary']['active_plan']['published_version_id']);
        $this->assertArrayNotHasKey('restraint_description', $data['summary']['recent_events'][0]);
    }

    public function test_review_only_source_counts_do_not_grant_capture_or_ordinary_summary(): void
    {
        $stay = $this->stay();
        $this->recorded($stay);
        $viewer = $this->staff(['clients.viewAny', 'respite.viewAny', 'restraints.review']);
        $data = $this->reads()->forStay($viewer, $stay);
        $this->assertSame(['capture' => false, 'view' => false, 'review' => true], $data['can']);
        $this->assertNull($data['summary']);
        $this->assertNull($data['report_href']);
        $workspace = $this->reads()->workspace($viewer);
        $this->assertSame(1, $workspace['restraintsAwaitingReview']);
        $this->assertSame(1, $workspace['by_stay'][$stay->id]['unreviewed']);
        $this->assertSame(['sites' => [], 'staff' => [], 'incidents' => [], 'plans' => []], $workspace['pickers']);
    }

    public function test_fixed_stay_report_pickers_require_exact_client_site_stay_and_current_staff(): void
    {
        $stay = $this->stay();
        $otherStay = $this->stay();
        $otherClient = Client::factory()->create(['site_id' => $this->site->id]);
        $own = ClientIncident::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'respite_stay_id' => $stay->id, 'is_hr_confidential' => false]);
        ClientIncident::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'respite_stay_id' => $otherStay->id, 'is_hr_confidential' => false]);
        ClientIncident::factory()->create(['client_id' => $otherClient->id, 'site_id' => $this->site->id, 'respite_stay_id' => null, 'is_hr_confidential' => false]);
        $ownPlan = BehaviourSupportPlan::factory()->create(['client_id' => $this->client->id]);
        BehaviourSupportPlan::factory()->create(['client_id' => $otherClient->id]);
        $inactive = $this->staff(['clients.viewAny']);
        HrEmployeeProfile::where('user_id', $inactive->id)->update(['is_active' => false]);
        $data = $this->reads()->reportContext($this->actor, $stay);
        $this->assertSame([$this->client->id], array_column($data['pickers']['clients'], 'id'));
        $this->assertSame([$this->site->id], array_column($data['pickers']['sites'], 'id'));
        $this->assertSame([$own->id], array_column($data['pickers']['incidents'], 'id'));
        $this->assertSame([$ownPlan->id], array_column($data['pickers']['plansForPicker'], 'id'));
        $this->assertNotContains($inactive->id, array_column($data['pickers']['staff'], 'id'));
        $this->assertSame($stay->id, $data['respite_stay_id']);
        $this->assertSame('/respite/stays/'.$stay->id.'/restraints', $data['store_href']);
    }

    public function test_capture_only_coordinator_posts_main_form_and_does_not_open_generic_or_stay_report(): void
    {
        $stay = $this->stay();
        $viewer = $this->staff(['clients.viewAny', 'respite.viewAny', 'respite.stays.manage']);
        // Stephan (25 Oct): main's respite restraint form and action stay for this merge, so the stay's store
        // endpoint takes main's form payload. Codex's dedicated stay report page (and keyed receipt) is
        // unrouted until the follow-up.
        $this->actingAs($viewer)->getJson('/respite/stays/'.$stay->id.'/restraints/report')->assertNotFound();
        $this->getJson(route('health-safety.restraints.report'))->assertForbidden();
        $this->post(route('respite.stays.restraints.store', $stay), [
            'started_at' => now()->subHour()->toDateTimeString(), 'restraint_type' => 'physical_hold', 'severity' => 'medium',
            'trigger_description' => 'Escalating distress', 'de_escalation_attempted' => 'Calm voice and space offered',
            'restraint_description' => 'Brief guided hold', 'within_support_plan' => false,
            'deviation_reason' => 'No current plan covers this stay',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($stay->id, RestraintEvent::sole()->stay_id);
    }

    public function test_foreign_stay_has_no_restraint_capabilities_or_capture_context(): void
    {
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
        $foreignStay = $this->stay($foreignClient, $foreignSite);
        $this->assertSame($this->noCapabilities(), $this->reads()->forStay($this->actor, $foreignStay));
        $this->refusedContext($foreignStay);
    }

    /** The strict capture context refuses (403/404, or a concealed stay) rather than falling back. */
    private function refusedContext(RespiteStay $stay): void
    {
        try {
            $this->reads()->reportContext($this->actor, $stay);
            $this->fail('The stay capture context must be refused.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertContains($error->getStatusCode(), [403, 404]);
        }
    }

    public function test_warm_actor_permissions_are_fresh_for_stay_links_summary_and_report(): void
    {
        $stay = $this->stay();
        $this->actor->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
        $this->assertTrue($this->reads()->forStay($this->actor, $stay)['can']['capture']);
        foreach (['respite.stays.manage', 'restraints.view', 'restraints.review', 'restraints.manage'] as $key) {
            $this->revoke($this->actor, $key);
        }
        $this->assertSame($this->noCapabilities(), $this->reads()->forStay($this->actor, $stay));
        $this->denied(fn () => $this->reads()->reportContext($this->actor, $stay));
    }

    public function test_current_hr_revocation_conceals_optional_read_but_denies_strict_capture_context(): void
    {
        $stay = $this->stay();
        $this->actor->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
        HrEmployeeProfile::where('user_id', $this->actor->id)->update(['is_active' => false]);
        $this->assertSame($this->noCapabilities(), $this->reads()->forStay($this->actor, $stay));
        $this->denied(fn () => $this->reads()->reportContext($this->actor, $stay));
        $this->assertSame([], $this->reads()->workspace($this->actor)['by_stay']);
    }

    public function test_current_client_move_requires_both_current_and_recorded_sites_then_rechecks_secondary_revocation(): void
    {
        $stay = $this->stay();
        $this->recorded($stay);
        $next = Site::factory()->create(['is_active' => true]);
        $this->client->update(['site_id' => $next->id]);
        $this->assertSame($this->noCapabilities(), $this->reads()->forStay($this->actor, $stay));
        HrEmployeeProfile::where('user_id', $this->actor->id)->update(['secondary_site_ids' => json_encode([$next->id])]);
        $data = $this->reads()->forStay($this->actor, $stay);
        $this->assertTrue($data['can']['view']);
        $this->assertSame(1, $data['summary']['total_events']);
        $this->assertSame($this->site->id, $this->reads()->reportContext($this->actor, $stay)['site_id']);
        HrEmployeeProfile::where('user_id', $this->actor->id)->update(['secondary_site_ids' => '[]']);
        $this->assertSame($this->noCapabilities(), $this->reads()->forStay($this->actor, $stay));
    }

    public function test_workspace_counts_current_readable_revision_once_and_skips_foreign_or_corrupt_sources(): void
    {
        $stay = $this->stay();
        $reviewed = $this->recorded($stay);
        $reviewed->update(['reviewed_at' => now(), 'reviewed_source_revision' => $reviewed->source_revision]);
        $stale = $this->recorded($stay);
        $stale->update(['reviewed_at' => now(), 'reviewed_source_revision' => 0]);
        $corrupt = $this->recorded($stay);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $corrupt->update(['recorded_site_id' => $foreignSite->id]);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
        $foreignStay = $this->stay($foreignClient, $foreignSite);
        $data = $this->reads()->workspace($this->actor);
        $this->assertSame(1, $data['restraintsAwaitingReview']);
        $this->assertSame(2, $data['bspAwaitingLink']);
        $this->assertSame(1, $data['by_stay'][$stay->id]['unreviewed']);
        $this->assertArrayNotHasKey($foreignStay->id, $data['by_stay']);
        $this->assertStringNotContainsString('Sensitive factual source', json_encode($data));
        $this->assertStringNotContainsString('plan_authorization_snapshot', json_encode($data));
    }

    public function test_partial_stay_props_recompute_after_read_revocation_and_preserve_unrelated_stay_payload(): void
    {
        $stay = $this->stay();
        $this->recorded($stay);
        $this->actingAs($this->actor)->get(route('respite.stays.show', $stay))->assertOk()->assertInertia(fn (Assert $page) => $page->where('stay.id', $stay->id)->where('stay.client.id', $this->client->id)->where('restraints.summary.total_events', 1));
        foreach (['restraints.view', 'restraints.review', 'restraints.manage', 'respite.stays.manage'] as $key) {
            $this->revoke($this->actor, $key);
        }
        $response = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'respite/stays/show', 'X-Inertia-Partial-Data' => 'restraints'])->get(route('respite.stays.show', $stay))->assertOk();
        $this->assertSame($this->noCapabilities(), $response->json('props.restraints'));
        $this->assertArrayNotHasKey('stay', $response->json('props'));
        $this->assertStringNotContainsString('Sensitive factual source', $response->getContent());
    }

    public function test_missing_stay_reference_and_archived_site_do_not_escape_optional_adapter(): void
    {
        $missing = new RespiteStay;
        $missing->id = 999999999;
        $this->assertSame($this->noCapabilities(), $this->reads()->forStay($this->actor, $missing));
        $stay = $this->stay();
        $this->site->update(['archived_at' => now()]);
        $this->assertSame($this->noCapabilities(), $this->reads()->forStay($this->actor, $stay));
        $this->refusedContext($stay);
    }

    public function test_current_client_read_permission_revocation_conceals_cached_source_reference(): void
    {
        $stay = $this->stay();
        $this->recorded($stay);
        $this->actor->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
        $this->assertSame(1, $this->reads()->forStay($this->actor, $stay)['summary']['total_events']);
        $this->revoke($this->actor, 'clients.viewAny');
        $this->assertSame($this->noCapabilities(), $this->reads()->forStay($this->actor, $stay));
        $this->assertSame([], $this->reads()->workspace($this->actor)['by_stay']);
    }

    public function test_current_stay_scope_bypasses_warm_site_cache_on_grant_then_secondary_revocation(): void
    {
        $next = Site::factory()->create(['is_active' => true]);
        $stay = $this->stay($this->client, $next);
        $scope = app(RespiteStayScope::class);
        $request = Request::create('/');
        $request->setUserResolver(fn () => $this->actor);
        $this->assertFalse($scope->applyAccessibleStayScope(RespiteStay::query(), $request)->whereKey($stay->id)->exists());
        HrEmployeeProfile::where('user_id', $this->actor->id)->update(['secondary_site_ids' => json_encode([$next->id])]);
        $id = DB::transaction(fn () => CurrentAuthorizationReads::within(fn ($reads) => (int) $scope->resolveAuthorizedStay($request, $stay->id, false, $reads)->id));
        $this->assertSame($stay->id, $id);
        HrEmployeeProfile::where('user_id', $this->actor->id)->update(['secondary_site_ids' => '[]']);
        try {
            DB::transaction(fn () => CurrentAuthorizationReads::within(fn ($reads) => (int) $scope->resolveAuthorizedStay($request, $stay->id, false, $reads)->id));
            $this->fail('Revoked secondary Site must conceal the canonical stay.');
        } catch (ModelNotFoundException $error) {
            $this->assertSame(RespiteStay::class, $error->getModel());
        }
    }

    public function test_canonical_stay_scope_rejects_escaped_current_read_before_source_execution(): void
    {
        $stay = $this->stay();
        $request = Request::create('/');
        $request->setUserResolver(fn () => $this->actor);
        $captured = null;
        DB::transaction(function () use (&$captured, $request, $stay): int {
            return CurrentAuthorizationReads::within(function ($reads) use (&$captured, $request, $stay): int {
                $captured = $reads;

                return (int) app(RespiteStayScope::class)->resolveAuthorizedStay($request, $stay->id, false, $reads)->id;
            });
        });
        try {
            app(RespiteStayScope::class)->resolveAuthorizedHealthSafetyStay($request, $stay->id, false, $captured);
            $this->fail('Disposed current read evidence must not execute source queries.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('transaction scope', $error->getMessage());
        }
    }
}
