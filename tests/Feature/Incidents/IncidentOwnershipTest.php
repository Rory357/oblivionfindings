<?php

namespace Tests\Feature\Incidents;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\DispatchIncidentOwnerDelivery;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\IncidentOwnerChange;
use App\Models\IncidentOwnerDelivery;
use App\Models\Permission;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\User;
use App\Services\Incidents\IncidentOwnerNotifications;
use App\Services\Incidents\IncidentOwnershipService;
use App\Services\Tasks\Providers\ClientIncidentProvider;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class IncidentOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Hold transport until each scenario explicitly exercises delivery,
        // failure, revocation or recovery after the assignment commits.
        Bus::fake([DispatchIncidentOwnerDelivery::class]);
    }

    private function staff(Site $site, array $keys): User
    {
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'coordinator']);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => []]);
        foreach ($keys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'incidents', 'module' => 'Compliance']);
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }

        return $user;
    }

    private function scenario(): array
    {
        $site = Site::factory()->create();
        $actor = $this->staff($site, ['incidents.viewAny', 'incidents.owner.assign']);
        $owner = $this->staff($site, ['incidents.viewAny', 'incidents.owner.hold']);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'site_id' => $site->id, 'client_id' => Client::factory()->create(['site_id' => $site->id])->id,
            'status' => 'submitted', 'reported_by' => $actor->id, 'investigation_assigned_to' => $actor->id,
        ]));

        return [$site, $actor, $owner, $incident];
    }

    private function command(User $owner, int $revision = 0, ?string $uuid = null, ?string $reason = null): array
    {
        return ['owner_user_id' => $owner->id, 'expected_revision' => $revision, 'request_uuid' => $uuid ?? (string) Str::uuid(), 'reason' => $reason];
    }

    public function test_assignment_is_canonical_across_detail_register_and_tasks_without_changing_reporter_or_investigator(): void
    {
        [, $actor, $owner, $incident] = $this->scenario();
        $this->actingAs($actor)->post('/incidents/'.$incident->id.'/owner', $this->command($owner))->assertRedirect()->assertSessionHasNoErrors();
        $incident->refresh();
        $this->assertSame($owner->id, $incident->incident_owner_user_id);
        $this->assertSame($actor->id, $incident->reported_by);
        $this->assertSame($actor->id, $incident->investigation_assigned_to);
        $this->get('/incidents/'.$incident->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.ownership.owner.id', $owner->id)->where('detail.ownership.revision', 1)->where('detail.ownership.state', 'assigned'));
        $this->get('/incidents')->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.data.0.ownership.owner.id', $owner->id));
        $task = app(ClientIncidentProvider::class)->authorizedTasks($actor, ['id' => $incident->id])[0];
        $this->assertSame($owner->id, $task->assignee['id']);
        $this->assertSame('/incidents/'.$incident->id, $task->link);
        $this->assertDatabaseHas('incident_owner_deliveries', ['change_id' => $incident->ownerChanges()->first()->id, 'status' => 'pending']);
    }

    public function test_duplicate_request_replays_once_and_changed_payload_cannot_reuse_its_identity(): void
    {
        [$site, $actor, $owner, $incident] = $this->scenario();
        $uuid = (string) Str::uuid();
        $data = $this->command($owner, uuid: $uuid);
        $this->actingAs($actor)->post('/incidents/'.$incident->id.'/owner', $data)->assertSessionHasNoErrors();
        $this->post('/incidents/'.$incident->id.'/owner', $data)->assertSessionHasNoErrors();
        $this->assertSame(1, IncidentOwnerChange::count());
        $this->assertSame(1, IncidentOwnerDelivery::count());
        $other = $this->staff($site, ['incidents.viewAny', 'incidents.owner.hold']);
        $this->post('/incidents/'.$incident->id.'/owner', $this->command($other, uuid: $uuid))->assertSessionHasErrors('request_uuid');
        $this->assertSame($owner->id, $incident->fresh()->incident_owner_user_id);
    }

    public function test_reassignment_requires_reason_and_expected_revision_and_preserves_full_history(): void
    {
        [$site, $actor, $owner, $incident] = $this->scenario();
        $this->actingAs($actor)->post('/incidents/'.$incident->id.'/owner', $this->command($owner))->assertSessionHasNoErrors();
        $replacement = $this->staff($site, ['incidents.viewAny', 'incidents.owner.hold']);
        $this->post('/incidents/'.$incident->id.'/owner', $this->command($replacement, 0, reason: 'Cover changed.'))->assertSessionHasErrors('expected_revision');
        $this->post('/incidents/'.$incident->id.'/owner', $this->command($replacement, 1))->assertSessionHasErrors('reason');
        $reason = str_repeat('Agreed cover and outstanding responsibilities. ', 25);
        $this->post('/incidents/'.$incident->id.'/owner', $this->command($replacement, 1, reason: $reason))->assertSessionHasNoErrors();
        $this->assertSame($replacement->id, $incident->fresh()->incident_owner_user_id);
        $latest = $incident->ownerChanges()->first();
        $this->assertSame(trim($reason), $latest->reason);
        $this->assertSame($owner->name, $latest->previous_owner_name);
        $this->assertSame(2, $latest->revision);
        $this->assertSame(3, IncidentOwnerDelivery::count());
    }

    public function test_picker_and_commit_exclude_foreign_inactive_and_external_accounts(): void
    {
        [, $actor, $owner, $incident] = $this->scenario();
        $foreign = $this->staff(Site::factory()->create(), ['incidents.viewAny', 'incidents.owner.hold']);
        $owner->update(['approved_at' => null]);
        $this->actingAs($actor)->getJson('/incidents/'.$incident->id.'/owners/options')->assertOk()->assertExactJson(['owners' => []]);
        foreach ([$owner, $foreign] as $invalid) {
            $this->post('/incidents/'.$incident->id.'/owner', $this->command($invalid))->assertSessionHasErrors('owner_user_id');
        }
        $foreign->forceFill(['external_clinical_account' => true])->save();
        $this->post('/incidents/'.$incident->id.'/owner', $this->command($foreign))->assertSessionHasErrors('owner_user_id');
        $this->assertSame(0, IncidentOwnerChange::count());
    }

    public function test_assignment_rechecks_revoked_actor_permission_even_when_request_user_has_loaded_grants(): void
    {
        [, $actor, $owner, $incident] = $this->scenario();
        $actor->load(['roles.permissions', 'permissionOverrides']);
        $permission = Permission::where('key', 'incidents.owner.assign')->firstOrFail();
        DB::table('permission_user')->where('user_id', $actor->id)->where('permission_id', $permission->id)->update(['allowed' => false]);
        $this->actingAs($actor)->post('/incidents/'.$incident->id.'/owner', $this->command($owner))->assertForbidden();
        $this->assertSame(0, IncidentOwnerChange::count());
    }

    public function test_owner_losing_site_access_is_flagged_for_replacement_and_ownership_does_not_grant_read_access(): void
    {
        [, $actor, $owner, $incident] = $this->scenario();
        $this->actingAs($actor)->post('/incidents/'.$incident->id.'/owner', $this->command($owner))->assertSessionHasNoErrors();
        $owner->hrEmployeeProfile()->update(['primary_site_id' => Site::factory()->create()->id]);
        $this->get('/incidents/'.$incident->id)->assertInertia(fn (Assert $page) => $page->where('detail.ownership.state', 'replacement_needed')->where('detail.ownership.owner.name', $owner->name));
        // Outside the owner's current Site scope the record is concealed (404), as the detail read decides.
        $this->actingAs($owner->fresh())->get('/incidents/'.$incident->id)->assertNotFound();
        $this->assertSame($owner->id, $incident->fresh()->incident_owner_user_id);
        $this->assertNull(app(ClientIncidentProvider::class)->authorizedTasks($actor->fresh(), ['id' => $incident->id])[0]->assignee);
    }

    public function test_restricted_incident_requires_specialist_ownership_and_declared_conflict_excludes_even_a_specialist(): void
    {
        [$site, $actor, $owner, $incident] = $this->scenario();
        $incident->update(['type' => 'safeguarding']);
        $this->actingAs($actor)->post('/incidents/'.$incident->id.'/owner', $this->command($owner))->assertSessionHasErrors('owner_user_id');
        $specialist = $this->staff($site, ['incidents.viewAny', 'incidents.owner.hold', 'incidents.owner.holdRestricted']);
        DB::table('incident_owner_exclusions')->insert(['incident_id' => $incident->id, 'user_id' => $specialist->id, 'recorded_by' => $actor->id, 'reason_code' => 'declared_conflict', 'created_at' => now(), 'updated_at' => now()]);
        $this->post('/incidents/'.$incident->id.'/owner', $this->command($specialist))->assertSessionHasErrors('owner_user_id');
        $independent = $this->staff($site, ['incidents.viewAny', 'incidents.owner.hold', 'incidents.owner.holdRestricted']);
        $this->post('/incidents/'.$incident->id.'/owner', $this->command($independent))->assertSessionHasNoErrors();
        $this->assertSame($independent->id, $incident->fresh()->incident_owner_user_id);
    }

    public function test_a_staff_member_named_as_alleged_perpetrator_cannot_own_the_linked_incident(): void
    {
        [$site, $actor, , $incident] = $this->scenario();
        $specialist = $this->staff($site, ['incidents.viewAny', 'incidents.owner.hold', 'incidents.owner.holdRestricted']);
        SafeguardingConcern::withoutEvents(fn () => SafeguardingConcern::factory()->create([
            'reference_number' => 'SG-TEST-OWNER', 'related_incident_id' => $incident->id, 'site_id' => $site->id,
            'subject_type' => Client::class, 'subject_id' => $incident->client_id,
            'concern_type' => 'abuse', 'severity' => 'high', 'description' => 'Synthetic concern',
            'reported_at' => now(), 'reported_by_user_id' => $actor->id, 'status' => 'reported',
            'alleged_perpetrator_type' => User::class, 'alleged_perpetrator_id' => $specialist->id,
        ]));
        $this->actingAs($actor)->post('/incidents/'.$incident->id.'/owner', $this->command($specialist))->assertSessionHasErrors('owner_user_id');
        $this->assertSame(0, IncidentOwnerChange::count());
    }

    public function test_closed_record_cannot_be_reassigned_but_its_original_receipt_is_repeat_safe(): void
    {
        [$site, $actor, $owner, $incident] = $this->scenario();
        $data = $this->command($owner);
        $this->actingAs($actor)->post('/incidents/'.$incident->id.'/owner', $data)->assertSessionHasNoErrors();
        $incident->update(['status' => 'closed']);
        $replacement = $this->staff($site, ['incidents.viewAny', 'incidents.owner.hold']);
        $this->post('/incidents/'.$incident->id.'/owner', $this->command($replacement, 1, reason: 'Cover'))->assertForbidden();
        $this->post('/incidents/'.$incident->id.'/owner', $data)->assertSessionHasNoErrors();
        $this->assertSame(1, IncidentOwnerChange::count());
        $this->assertSame('historical', app(IncidentOwnershipService::class)->present($actor, $incident->fresh())['state']);
    }

    public function test_notification_failure_does_not_undo_assignment_and_retry_delivers_once(): void
    {
        [, $actor, $owner, $incident] = $this->scenario();
        $this->actingAs($actor)->post('/incidents/'.$incident->id.'/owner', $this->command($owner))->assertSessionHasNoErrors();
        $delivery = IncidentOwnerDelivery::firstOrFail();
        $original = app(Dispatcher::class);
        $failing = Mockery::mock(Dispatcher::class);
        $failing->shouldReceive('send')->once()->andThrow(new RuntimeException('Synthetic transport failure'));
        app()->instance(Dispatcher::class, $failing);
        try {
            app(IncidentOwnerNotifications::class)->deliver($delivery->id);
            $this->fail('Expected the synthetic delivery failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic transport failure', $exception->getMessage());
        } finally {
            app()->instance(Dispatcher::class, $original);
        }
        $this->assertSame($owner->id, $incident->fresh()->incident_owner_user_id);
        $this->assertSame('failed', $delivery->fresh()->status);
        app(IncidentOwnerNotifications::class)->deliver($delivery->id);
        app(IncidentOwnerNotifications::class)->deliver($delivery->id);
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame(1, DB::table('notifications')->where('id', $delivery->id)->count());
        $this->assertSame(1, IncidentOwnerChange::count());
    }

    public function test_revoked_recipient_is_not_notified(): void
    {
        [, $actor, $owner, $incident] = $this->scenario();
        $this->actingAs($actor)->post('/incidents/'.$incident->id.'/owner', $this->command($owner))->assertSessionHasNoErrors();
        $delivery = IncidentOwnerDelivery::firstOrFail();
        $owner->update(['approved_at' => null]);
        app(IncidentOwnerNotifications::class)->deliver($delivery->id);
        $this->assertSame('skipped', $delivery->fresh()->status);
        $this->assertSame(0, DB::table('notifications')->where('id', $delivery->id)->count());
    }

    public function test_legacy_investigator_is_not_inferred_as_incident_owner(): void
    {
        [, $actor, , $incident] = $this->scenario();
        $summary = app(IncidentOwnershipService::class)->present($actor, $incident);
        $this->assertSame('unassigned', $summary['state']);
        $this->assertNull($summary['owner']);
        $this->assertSame([], $summary['history']);
        $this->assertSame(0, $summary['revision']);
    }

    public function test_superseded_incoming_notice_is_skipped_even_if_the_same_person_becomes_owner_again(): void
    {
        [$site, $actor, $owner, $incident] = $this->scenario();
        $replacement = $this->staff($site, ['incidents.viewAny', 'incidents.owner.hold']);
        $this->actingAs($actor)->post('/incidents/'.$incident->id.'/owner', $this->command($owner))->assertSessionHasNoErrors();
        $firstNotice = IncidentOwnerDelivery::firstOrFail();
        $this->post('/incidents/'.$incident->id.'/owner', $this->command($replacement, 1, reason: 'Agreed cover.'))->assertSessionHasNoErrors();
        $this->post('/incidents/'.$incident->id.'/owner', $this->command($owner, 2, reason: 'Cover finished.'))->assertSessionHasNoErrors();
        app(IncidentOwnerNotifications::class)->deliver($firstNotice->id);
        $this->assertSame('skipped', $firstNotice->fresh()->status);
        $this->assertSame(0, DB::table('notifications')->where('id', $firstNotice->id)->count());
    }

    public function test_unknown_and_ineligible_owner_ids_have_the_same_safe_validation_response(): void
    {
        [, $actor, $owner, $incident] = $this->scenario();
        $owner->update(['approved_at' => null]);
        $invalid = $this->actingAs($actor)->postJson('/incidents/'.$incident->id.'/owner', $this->command($owner))
            ->assertUnprocessable()->json('errors.owner_user_id');
        $missing = $this->postJson('/incidents/'.$incident->id.'/owner', [
            ...$this->command($owner), 'owner_user_id' => 999999999,
        ])->assertUnprocessable()->json('errors.owner_user_id');
        $this->assertSame($invalid, $missing);
        $this->assertSame(0, IncidentOwnerChange::count());
    }

    public function test_foreign_incident_cannot_be_queried_or_assigned_through_owner_endpoints(): void
    {
        [, $actor, $owner, $incident] = $this->scenario();
        $incident->update(['site_id' => Site::factory()->create()->id]);
        $this->actingAs($actor)->getJson('/incidents/'.$incident->id.'/owners/options')->assertForbidden();
        $this->post('/incidents/'.$incident->id.'/owner', $this->command($owner))->assertForbidden();
        $this->assertSame(0, IncidentOwnerChange::count());
    }
}
