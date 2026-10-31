<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsCommittee;
use App\Models\HsCommitteeMeeting;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HealthSafetyReadMaterialisationProof;
use App\Services\HealthSafety\WorkerParticipationInvitationReadService;
use App\Services\HealthSafety\WorkerParticipationRecipientSourceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Whole real My Day route/source consumers; no provider or authorization mocks. Native UNRUN. */
final class WorkerParticipationInvitationReadTest extends RestraintEventCommandTestCase
{
    private HsCommittee $committee;
    private HsCommitteeMeeting $meeting;
    private Site $otherSite;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        $this->actor = $this->staff([]);
        $this->permission('hazards.view', false); $this->permission('healthSafety.viewAllSites', false);
        $this->otherSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->committee = HsCommittee::create(['name' => 'My invited committee', 'site_id' => $this->site->id,
            'status' => 'active', 'meeting_frequency' => 'monthly', 'established_at' => '2026-01-01', 'members' => [$this->actor->id]]);
        $this->meeting = $this->committee->meetings()->create(['scheduled_at' => '2026-10-13 20:30:00', 'location' => 'Meeting room', 'status' => 'scheduled',
            'minutes' => 'Private minutes must not be shared', 'agenda_items' => ['Unshared agenda'],
            'action_items' => [['description' => 'Private assigned action', 'status' => 'open']]]);
        $this->meeting->attendeeUsers()->attach($this->actor->id, ['response' => 'invited', 'attended' => false]);
        $this->actingAs($this->actor);
    }

    public function test_current_own_invitation_has_exact_minimal_json_without_register_or_private_body_grant(): void
    {
        $response = $this->getJson($this->url())->assertOk();
        $response->assertExactJson(['meeting' => ['id' => $this->meeting->id, 'scheduled_at' => '2026-10-13T20:30:00.000000Z',
            'location' => 'Meeting room', 'status' => 'scheduled', 'committee' => ['id' => $this->committee->id, 'name' => 'My invited committee'],
            'site' => ['id' => $this->site->id, 'name' => $this->site->name], 'site_ownership' => 'recorded',
            'attendance' => ['response' => 'invited', 'attended' => false]],
            'context' => ['can_open_register' => false, 'register_href' => null], 'source_viewer_id' => $this->actor->id]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringNotContainsString('Private minutes', $response->getContent());
        $this->assertStringNotContainsString('Unshared agenda', $response->getContent());
        $this->assertStringNotContainsString('Private assigned action', $response->getContent());
    }

    public function test_register_link_is_independent_current_permission_and_actual_register_access(): void
    {
        $this->get('/health-safety/worker-participation')->assertForbidden();
        $this->getJson($this->url())->assertOk()->assertJsonPath('context.can_open_register', false);
        $this->permission('hazards.view', true);
        $this->getJson($this->url())->assertOk()->assertJsonPath('context.can_open_register', true)
            ->assertJsonPath('context.register_href', '/health-safety/worker-participation?tab=meetings&meeting='.$this->meeting->id);
        $this->get('/health-safety/worker-participation?tab=meetings&meeting='.$this->meeting->id, ['X-Inertia' => 'true'])->assertOk();
        $this->permission('hazards.view', false);
        $this->getJson($this->url())->assertOk()->assertJsonPath('context.register_href', null);
    }

    public function test_inertia_auth_only_partial_refreshes_exact_original_viewer_invitation_cluster(): void
    {
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'my-day/meeting-invitation', 'X-Inertia-Partial-Data' => 'auth'];
        $response = $this->get($this->url(), $headers)->assertOk()->assertJsonPath('props.source_viewer_id', $this->actor->id)
            ->assertJsonPath('props.meeting.attendance.response', 'invited')->assertJsonPath('props.context.can_open_register', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->meeting->attendeeUsers()->updateExistingPivot($this->actor->id, ['response' => 'declined', 'attended' => true]);
        $this->get($this->url(), $headers)->assertOk()->assertJsonPath('props.meeting.attendance.response', 'declined')
            ->assertJsonPath('props.meeting.attendance.attended', true);
    }

    public function test_authenticated_account_change_cannot_retain_another_workers_invitation(): void
    {
        $other = $this->staff([]);
        $this->actingAs($other)->getJson($this->url())->assertNotFound();
        $this->meeting->attendeeUsers()->attach($other->id, ['response' => 'accepted']);
        $this->getJson($this->url())->assertOk()->assertJsonPath('source_viewer_id', $other->id)->assertJsonPath('meeting.attendance.response', 'accepted');
        $this->actingAs($this->actor)->getJson($this->url())->assertOk()->assertJsonPath('source_viewer_id', $this->actor->id)->assertJsonPath('meeting.attendance.response', 'invited');
    }

    public function test_unassigned_removed_invitation_and_soft_deleted_sources_read_no_private_body_or_names(): void
    {
        foreach (['unassigned', 'removed', 'meeting_deleted', 'committee_deleted', 'site_archived'] as $state) {
            if ($state === 'unassigned') { $this->committee->update(['site_id' => $this->otherSite->id]); }
            elseif ($state === 'removed') { $this->meeting->attendeeUsers()->detach($this->actor->id); }
            elseif ($state === 'meeting_deleted') { $this->meeting->delete(); }
            elseif ($state === 'committee_deleted') { $this->committee->delete(); }
            else { $this->site->update(['archived_at' => now()]); }
            $this->withoutPrivateHydration(fn () => $this->getJson($this->url())->assertNotFound());
            if ($state === 'unassigned') { $this->committee->update(['site_id' => $this->site->id]); }
            elseif ($state === 'removed') { $this->meeting->attendeeUsers()->attach($this->actor->id); }
            elseif ($state === 'meeting_deleted') { $this->meeting->restore(); }
            elseif ($state === 'committee_deleted') { $this->committee->restore(); }
            else { $this->site->update(['archived_at' => null]); }
            $this->getJson($this->url())->assertOk();
        }
    }

    public static function deniedStaffStates(): array { return [['unapproved'], ['inactive'], ['ended'], ['future'], ['external'], ['client']]; }

    #[DataProvider('deniedStaffStates')]
    public function test_current_approved_internal_staff_is_required_before_body(string $state): void
    {
        if ($state === 'unapproved') { $this->actor->update(['approved_at' => null]); }
        // external_clinical_account is not mass assignable; update() would silently skip it.
        elseif ($state === 'external') { $this->actor->forceFill(['external_clinical_account' => true])->save(); }
        elseif ($state === 'client') { $this->actor->update(['role' => 'client']); }
        else { $this->actor->hrEmployeeProfile->update(match ($state) {
            'inactive' => ['is_active' => false], 'ended' => ['end_date' => '2026-10-01'], default => ['start_date' => '2026-12-01'],
        }); }
        // Main's global web middleware refuses two states before the route runs: an unapproved account is
        // signed out to the login page (EnsureAccountStillApproved), and an external clinical account may open
        // only the clinical portal (EnsureExternalClinicalIsolation, 403). Both still read no private body.
        $this->withoutPrivateHydration(fn () => match ($state) {
            'unapproved' => $this->getJson($this->url())->assertRedirect(route('login')),
            'external' => $this->getJson($this->url())->assertForbidden(),
            default => $this->getJson($this->url())->assertNotFound(),
        });
    }

    public function test_wide_hs_reader_still_requires_own_pivot_and_flags_nullable_ownership(): void
    {
        $this->committee->update(['site_id' => null]);
        $this->getJson($this->url())->assertNotFound();
        $this->permission('healthSafety.viewAllSites', true);
        $this->getJson($this->url())->assertNotFound();
        $this->permission('hazards.view', true);
        $this->getJson($this->url())->assertOk()->assertJsonPath('meeting.site', null)->assertJsonPath('meeting.site_ownership', 'unconfirmed');
        $this->committee->update(['site_id' => $this->otherSite->id]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('meeting.site.id', $this->otherSite->id);
        $this->meeting->attendeeUsers()->detach($this->actor->id);
        $this->getJson($this->url())->assertNotFound();
    }

    public function test_secondary_current_site_assignment_is_preserved(): void
    {
        $this->actor->hrEmployeeProfile->update(['secondary_site_ids' => [$this->otherSite->id]]);
        $this->committee->update(['site_id' => $this->otherSite->id]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('meeting.site.id', $this->otherSite->id);
    }

    public static function lateChanges(): array { return [['source'], ['profile'], ['actor'], ['pivot'], ['site']]; }

    #[DataProvider('lateChanges')]
    public function test_actual_first_location_sql_refuses_original_source_and_recipient_changes(string $kind): void
    {
        $armed = true; $fired = false; $hydrated = 0;
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        HsCommitteeMeeting::retrieved(function ($row) use (&$fired, &$hydrated): void { if ($fired && array_key_exists('location', $row->getAttributes())) { $hydrated++; } });
        DB::connection()->beforeExecuting(function (string $sql) use ($kind, &$armed, &$fired): void {
            if (! $armed || $fired || ! preg_match('/^select\s+(.+?)\s+from\s+`hs_committee_meetings`/is', ltrim($sql), $match) || ! str_contains($match[1], '`location`')) { return; }
            $fired = true;
            match ($kind) {
                'source' => DB::table('hs_committees')->where('id', $this->committee->id)->update(['site_id' => $this->otherSite->id]),
                'profile' => DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['is_active' => false]),
                'actor' => DB::table('users')->where('id', $this->actor->id)->update(['approved_at' => null]),
                'pivot' => DB::table('hs_meeting_attendees')->where('meeting_id', $this->meeting->id)->where('user_id', $this->actor->id)->update(['response' => 'declined']),
                default => DB::table('sites')->where('id', $this->site->id)->update(['archived_at' => now()]),
            };
        });
        try { $this->getJson($this->url())->assertNotFound(); $this->assertTrue($fired); $this->assertSame(0, $hydrated); }
        finally { $armed = false; Model::setEventDispatcher($dispatcher); }
    }

    public function test_original_outer_collector_refuses_source_change_after_detached_invitation_is_built(): void
    {
        $this->refused(fn () => HealthSafetyReadContext::within($this->actor, WorkerParticipationRecipientSourceService::PERMISSIONS,
            fn ($context) => HealthSafetyReadMaterialisationProof::within($context, function () use ($context): array {
                $data = app(WorkerParticipationInvitationReadService::class)->show($this->actor, $this->meeting->id, $context);
                DB::table('hs_committee_meetings')->where('id', $this->meeting->id)->update(['location' => 'Changed after invitation materialisation']);
                return $data;
            })));
    }

    public function test_expired_or_other_actor_context_cannot_be_replaced(): void
    {
        $escaped = null;
        HealthSafetyReadContext::within($this->actor, WorkerParticipationRecipientSourceService::PERMISSIONS, function ($context) use (&$escaped): array { $escaped = $context; return []; });
        try { app(WorkerParticipationInvitationReadService::class)->show($this->actor, $this->meeting->id, $escaped); $this->fail('Expired source context must refuse.'); }
        catch (LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
        $other = $this->staff([]);
        $this->expectException(LogicException::class);
        HealthSafetyReadContext::within($this->actor, WorkerParticipationRecipientSourceService::PERMISSIONS,
            fn ($context) => app(WorkerParticipationInvitationReadService::class)->show($other, $this->meeting->id, $context));
    }

    public function test_raw_nonpositive_overflow_and_js_unsafe_id_is_denied_before_source_sql(): void
    {
        $armed = true; $queries = 0;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$queries): void { if ($armed && preg_match('/^select/i', $sql) && str_contains($sql, 'hs_committee')) { $queries++; } });
        try { foreach (['0', '-1', '9007199254740992', '9999999999999999999999999'] as $id) { $this->getJson('/my-day/safety/meetings/'.$id)->assertNotFound(); } $this->assertSame(0, $queries); }
        finally { $armed = false; }
    }

    private function permission(string $key, bool $allowed): void
    {
        $this->actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => $allowed]]);
        // actingAs() reuses this one User across requests; earlier requests leave its permission relations
        // loaded, and canDo() then answers from that stale copy. A real request always loads a fresh user.
        $this->actor->unsetRelations();
    }
    private function url(): string { return '/my-day/safety/meetings/'.$this->meeting->id; }

    private function withoutPrivateHydration(callable $operation): void
    {
        $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher); $private = 0;
        foreach ([HsCommitteeMeeting::class => 'location', HsCommittee::class => 'name', Site::class => 'name'] as $class => $field) {
            $class::retrieved(function ($row) use (&$private, $field): void { if (array_key_exists($field, $row->getAttributes())) { $private++; } });
        }
        try { $operation(); $this->assertSame(0, $private); }
        finally { Model::setEventDispatcher($dispatcher); }
    }

    private function refused(callable $operation): void
    {
        try { $operation(); $this->fail('Original source refusal was required.'); }
        catch (HttpExceptionInterface $error) { $this->assertSame(404, $error->getStatusCode()); }
    }
}
