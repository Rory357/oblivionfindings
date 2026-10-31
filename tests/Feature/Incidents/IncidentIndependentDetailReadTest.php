<?php

namespace Tests\Feature\Incidents;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ClientIncident;
use App\Models\Site;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\Incidents\IncidentRegisterReadService;
use App\Services\Incidents\IncidentIndependencePolicySettings as Policy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Complete native suite UNRUN. HTTP cases require Root's real show/raw-ID/current-route mount; no middleware suppression. */
final class IncidentIndependentDetailReadTest extends IncidentIndependenceTestCase
{
    private function requestFor(User $actor, int $id): Request
    {
        $request = Request::create('/incidents/'.$id, 'GET');
        $request->setUserResolver(fn () => $actor);
        return $request;
    }

    private function read(User $actor, int $id, ?callable $consumer = null): array
    {
        return app(IncidentRegisterReadService::class)->show($this->requestFor($actor, $id), $id,
            $consumer ?? function (Request $request, int $sourceId, CurrentAuthorizationReads $reads): array {
                $source = $reads->query(ClientIncident::query()->whereKey($sourceId))->firstOrFail(['id', 'description']);
                return ['id' => (int) $source->id, 'description' => $source->description];
            });
    }

    private function rejects(callable $operation, int $status): void
    {
        try { $operation(); $this->fail('The actual protected source operation must refuse this request.'); }
        catch (HttpExceptionInterface $error) { $this->assertSame($status, $error->getStatusCode()); }
    }

    private function partial(int $id): \Illuminate\Testing\TestResponse
    {
        return $this->get('/incidents/'.$id, ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'incidents/show',
            'X-Inertia-Partial-Data' => 'auth']);
    }

    public function test_named_strong_denial_is_before_body_callback_and_does_not_affect_healthy_source(): void
    {
        $receipt = $this->accept($this->command()); $called = false;
        $this->rejects(fn () => $this->read($this->lead, $receipt['incident_id'], function () use (&$called): array {
            $called = true; return ['description' => 'Never materialise denied prose'];
        }), 404);
        $this->assertFalse($called);
        $healthy = $this->incident(); $this->assertSame($healthy->id, $this->read($this->lead, $healthy->id)['detail']['id']);
    }

    public function test_assignment_only_preserves_existing_read_authority_without_giving_reporter_read(): void
    {
        app(Policy::class)->save($this->manager, ['mode' => 'assignment_only', 'expected_policy_revision' => 0,
            'request_uuid' => (string) Str::uuid(), 'reason' => 'Explicit policy for later reports.']);
        $receipt = $this->accept($this->command(['expected_policy_revision' => 1]));
        $this->assertSame($receipt['incident_id'], $this->read($this->lead, $receipt['incident_id'])['detail']['id']);
        $this->rejects(fn () => $this->read($this->reporter, $receipt['incident_id']), 403);
    }

    public function test_current_site_scope_denies_foreign_root_before_callback(): void
    {
        $foreign = Site::factory()->create(['is_active' => true]); $root = $this->incident(['site_id' => $foreign->id]); $called = false;
        $this->rejects(fn () => $this->read($this->manager, $root->id, function () use (&$called): array { $called = true; return []; }), 404);
        $this->assertFalse($called);
    }

    public function test_hr_private_source_needs_existing_current_hr_read_grant_and_restores(): void
    {
        $root = $this->incident(['is_hr_confidential' => true]); $this->allow($this->manager, 'hr.cases.view', false);
        $this->rejects(fn () => $this->read($this->manager, $root->id), 404);
        $this->allow($this->manager, 'hr.cases.view');
        $this->assertSame($root->id, $this->read($this->manager, $root->id)['detail']['id']);
    }

    public function test_current_assigned_client_membership_is_not_replaced_by_independence_acceptance(): void
    {
        $root = $this->incident(); $this->allow($this->lead, 'incidents.viewAny', false); $this->allow($this->lead, 'incidents.viewAssigned');
        $root->client->supportWorkers()->attach($this->lead->id);
        $this->assertSame($root->id, $this->read($this->lead, $root->id)['detail']['id']);
        $root->client->supportWorkers()->detach($this->lead->id);
        $this->rejects(fn () => $this->read($this->lead, $root->id), 404);
        $root->client->supportWorkers()->attach($this->lead->id);
        $this->assertSame($root->id, $this->read($this->lead, $root->id)['detail']['id']);
    }

    public function test_retained_strong_policy_is_not_released_by_later_policy_or_closed_state(): void
    {
        $receipt = $this->accept($this->command());
        app(Policy::class)->save($this->manager, ['mode' => 'assignment_only', 'expected_policy_revision' => 0,
            'request_uuid' => (string) Str::uuid(), 'reason' => 'Future reports only.']);
        DB::table('client_incidents')->where('id', $receipt['incident_id'])->update(['status' => 'closed', 'closed_at' => now()]);
        $this->rejects(fn () => $this->read($this->lead, $receipt['incident_id']), 404);
        $this->assertSame($receipt['incident_id'], $this->read($this->manager, $receipt['incident_id'])['detail']['id']);
    }

    public function test_warmed_false_actor_grant_restore_uses_the_complete_existing_projection_vector(): void
    {
        $root = $this->incident();
        foreach (['incidents.viewAny', 'incidents.viewAssigned'] as $key) { $this->allow($this->manager, $key, false); }
        $warm = $this->manager->fresh()->load(['roles.permissions', 'permissionOverrides']);
        $this->rejects(fn () => $this->read($warm, $root->id), 403);
        $this->allow($warm, 'incidents.viewAny'); $this->allow($warm, 'incidents.templates.manage');
        $this->assertFalse($warm->canDo('incidents.viewAny'));
        $dto = $this->read($warm, $root->id, fn ($request) => ['id' => $root->id, 'templates' => $request->user()->canDo('incidents.templates.manage')]);
        $this->assertTrue($dto['detail']['templates']); $this->assertSame($warm->id, $dto['viewer_id']);
    }

    public function test_missing_or_nonpositive_source_never_invokes_detail_callback(): void
    {
        $called = false; $consumer = function () use (&$called): array { $called = true; return []; };
        $missing = $this->incident()->id + 1000000;
        $this->rejects(fn () => $this->read($this->manager, $missing, $consumer), 404);
        $this->rejects(fn () => $this->read($this->manager, 0, $consumer), 404);
        $this->assertFalse($called);
    }

    public function test_null_existing_detail_is_unavailable_not_a_readable_empty_record(): void
    {
        $root = $this->incident(); $this->rejects(fn () => $this->read($this->manager, $root->id, fn () => null), 404);
    }

    public function test_cloned_request_uses_current_actor_and_restores_resolvers_before_handle_expiry(): void
    {
        $root = $this->incident(); $request = $this->requestFor($this->manager, $root->id);
        $original = $request->getUserResolver(); $working = null; $handle = null;
        $dto = app(IncidentRegisterReadService::class)->show($request, $root->id, function ($currentRequest, $id, $reads) use (&$working, &$handle, $request): array {
            $this->assertNotSame($request, $currentRequest); $working = $currentRequest; $handle = $reads; $reads->assertActive();
            return ['id' => $id, 'actor_id' => (int) $currentRequest->user()->id];
        });
        $this->assertSame($original, $request->getUserResolver()); $this->assertSame($original, $working->getUserResolver());
        $this->assertSame($this->manager->id, $dto['viewer_id']); $this->assertSame($dto['viewer_id'], $dto['detail']['actor_id']);
        $this->expectException(LogicException::class); $handle->assertActive();
    }

    public function test_callback_fault_restores_both_request_resolvers_and_invalidates_handle(): void
    {
        $root = $this->incident(); $request = $this->requestFor($this->manager, $root->id);
        $original = $request->getUserResolver(); $working = null; $handle = null;
        try {
            app(IncidentRegisterReadService::class)->show($request, $root->id, function ($currentRequest, $id, $reads) use (&$working, &$handle): array {
                $working = $currentRequest; $handle = $reads; throw new LogicException('Deliberate callback fault.');
            });
            $this->fail('Unexpected successful callback.');
        } catch (LogicException $error) { $this->assertSame('Deliberate callback fault.', $error->getMessage()); }
        $this->assertSame($original, $request->getUserResolver()); $this->assertSame($original, $working->getUserResolver());
        $this->expectException(LogicException::class); $handle->assertActive();
    }

    public function test_raw_model_cannot_escape_in_detail_array(): void
    {
        $root = $this->incident(); $this->expectException(LogicException::class);
        $this->read($this->manager, $root->id, fn () => ['source' => $root]);
    }

    public function test_builder_cannot_escape_in_detail_array(): void
    {
        $root = $this->incident(); $this->expectException(LogicException::class);
        $this->read($this->manager, $root->id, fn () => ['source' => ClientIncident::query()]);
    }

    public function test_callback_cannot_escape_as_detail_authority(): void
    {
        $root = $this->incident(); $this->expectException(LogicException::class);
        $this->read($this->manager, $root->id, fn () => ['authority' => fn () => true]);
    }

    public function test_actual_http_named_denial_and_missing_source_are_404_without_body(): void
    {
        $receipt = $this->accept($this->command()); $this->actingAs($this->lead);
        $response = $this->partial($receipt['incident_id'])->assertNotFound();
        $this->assertStringNotContainsString('Synthetic private source text', $response->getContent());
        $this->partial($receipt['incident_id'] + 1000000)->assertNotFound();
    }

    public function test_actual_http_assignment_only_preserves_normal_read_but_capture_only_remains_forbidden(): void
    {
        app(Policy::class)->save($this->manager, ['mode' => 'assignment_only', 'expected_policy_revision' => 0,
            'request_uuid' => (string) Str::uuid(), 'reason' => 'Future assignment only.']);
        $receipt = $this->accept($this->command(['expected_policy_revision' => 1]));
        $this->actingAs($this->lead)->partial($receipt['incident_id'])->assertOk()->assertJsonPath('props.detail.id', $receipt['incident_id']);
        $this->actingAs($this->reporter)->partial($receipt['incident_id'])->assertForbidden();
    }

    public function test_actual_http_auth_only_partial_replaces_eager_detail_and_has_private_headers(): void
    {
        $root = $this->incident();
        $response = $this->actingAs($this->manager)->partial($root->id)->assertOk()
            ->assertJsonPath('props.viewer_id', $this->manager->id)->assertJsonPath('props.detail.id', $root->id)
            ->assertJsonPath('props.detail.description', $root->description);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    public function test_actual_http_warmed_false_restoration_and_true_revocation_use_real_route_guard(): void
    {
        $root = $this->incident();
        foreach (['incidents.viewAny', 'incidents.viewAssigned'] as $key) { $this->allow($this->manager, $key, false); }
        $warm = $this->manager->fresh()->load(['roles.permissions', 'permissionOverrides']); $this->actingAs($warm);
        $this->partial($root->id)->assertForbidden(); $this->allow($warm, 'incidents.viewAny');
        $this->assertFalse($warm->canDo('incidents.viewAny'));
        $this->partial($root->id)->assertOk()->assertJsonPath('props.viewer_id', $warm->id)->assertJsonPath('props.detail.id', $root->id);
        $this->allow($warm, 'incidents.viewAny', false); $this->partial($root->id)->assertForbidden();
    }

    public function test_actual_http_current_account_switch_has_new_viewer_and_source_scope(): void
    {
        $root = $this->incident();
        $this->actingAs($this->manager)->partial($root->id)->assertOk()->assertJsonPath('props.viewer_id', $this->manager->id);
        $this->actingAs($this->cover)->partial($root->id)->assertOk()->assertJsonPath('props.viewer_id', $this->cover->id);
        $this->actingAs($this->reporter)->partial($root->id)->assertForbidden();
    }

    public function test_actual_http_current_site_and_hr_withdrawal_restore_without_cached_detail(): void
    {
        $root = $this->incident(['is_hr_confidential' => true]); $this->allow($this->manager, 'hr.cases.view');
        $this->actingAs($this->manager)->partial($root->id)->assertOk();
        $this->allow($this->manager, 'hr.cases.view', false); $this->partial($root->id)->assertNotFound();
        $this->allow($this->manager, 'hr.cases.view'); $foreign = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $foreign->id]);
        $this->partial($root->id)->assertNotFound();
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->site->id]);
        $this->partial($root->id)->assertOk()->assertJsonPath('props.detail.id', $root->id);
    }

    public function test_actual_http_raw_invalid_or_overflow_identity_is_concealed_before_source_binding(): void
    {
        $this->actingAs($this->manager)->get('/incidents/0', ['X-Inertia' => 'true'])->assertNotFound();
        $this->get('/incidents/99999999999999999999999999', ['X-Inertia' => 'true'])->assertNotFound();
    }
}
