<?php

namespace Tests\Feature\Incidents;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\IncidentFollowup;
use App\Models\Site;
use App\Models\User;
use App\Services\Incidents\IncidentRegisterReadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/** Whole native suite UNRUN. Current-staff audience only; source-specific assignment/write eligibility remains separate. */
final class IncidentFollowupStaffReadTest extends IncidentIndependenceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->allow($this->manager, 'incidents.create'); $this->allow($this->manager, 'incidents.followups.manage');
    }

    private function register(User $actor): array
    {
        $request = Request::create('/incidents', 'GET'); $request->setUserResolver(fn () => $actor);
        return app(IncidentRegisterReadService::class)->index($request);
    }

    private function detail(int $id): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/incidents/'.$id, ['X-Inertia' => 'true']);
    }

    private function ids(array $people): array
    {
        $ids = array_map(fn ($person) => (int) $person['id'], $people); sort($ids); return $ids;
    }

    #[DataProvider('noncurrentPeople')]
    public function test_report_and_actual_detail_do_not_offer_inactive_unapproved_future_or_former_staff(array $userChanges, array $profileChanges): void
    {
        $root = $this->incident(); $person = $this->staff([]);
        if ($userChanges !== []) { DB::table('users')->where('id', $person->id)->update($userChanges); }
        if ($profileChanges !== []) { HrEmployeeProfile::where('user_id', $person->id)->update($profileChanges); }
        $this->assertNotContains($person->id, $this->ids($this->register($this->manager)['reportStaff']));
        $this->actingAs($this->manager);
        $this->assertNotContains($person->id, $this->ids($this->detail($root->id)->assertOk()->json('props.detail.assignable_staff')));
    }

    public static function noncurrentPeople(): array
    {
        return ['inactive profile' => [[], ['is_active' => false]], 'unapproved account' => [['approved_at' => null], []],
            'future employment' => [[], ['start_date' => '2026-10-13']], 'ended employment' => [[], ['end_date' => '2026-10-11']]];
    }

    public function test_existing_cross_site_staff_selection_is_preserved_and_not_mistaken_for_incident_ownership(): void
    {
        $root = $this->incident(); $foreign = Site::factory()->create(['is_active' => true]);
        $person = $this->staff([], $foreign);
        $this->assertContains($person->id, $this->ids($this->register($this->manager)['reportStaff']));
        $this->actingAs($this->manager);
        $this->assertContains($person->id, $this->ids($this->detail($root->id)->assertOk()->json('props.detail.assignable_staff')));
        $this->assertNull($root->fresh()->incident_owner_user_id);
    }

    public function test_incident_read_or_hazard_read_alone_does_not_download_assignment_directory(): void
    {
        $root = $this->incident(); $this->allow($this->manager, 'incidents.followups.manage', false);
        $this->allow($this->manager, 'hazards.view'); $this->allow($this->manager, 'compliance.view');
        $this->assertSame([], $this->register($this->manager)['reportStaff']);
        $this->actingAs($this->manager);
        $this->detail($root->id)->assertOk()->assertJsonPath('props.detail.assignable_staff', []);
    }

    public function test_historical_followup_assignee_remains_readable_after_employment_ends_without_becoming_a_current_candidate(): void
    {
        $root = $this->incident(); $person = $this->staff([]);
        IncidentFollowup::withoutEvents(fn () => IncidentFollowup::create(['client_incident_id' => $root->id,
            'assigned_to_user_id' => $person->id, 'created_by' => $this->reporter->id, 'notes' => 'Retained work attribution']));
        HrEmployeeProfile::where('user_id', $person->id)->update(['end_date' => '2026-10-11']);
        $this->actingAs($this->manager); $response = $this->detail($root->id)->assertOk();
        $response->assertJsonPath('props.detail.followups.0.assigned_to', $person->name);
        $this->assertNotContains($person->id, $this->ids($response->json('props.detail.assignable_staff')));
        $this->assertSame($person->id, IncidentFollowup::where('client_incident_id', $root->id)->sole()->assigned_to_user_id);
    }

    public function test_partial_detail_replaces_warmed_assignment_grant_data_after_revoke_and_restore(): void
    {
        $root = $this->incident(); $warm = $this->manager->fresh()->load(['roles.permissions', 'permissionOverrides']);
        $this->actingAs($warm); $this->assertNotEmpty($this->detail($root->id)->assertOk()->json('props.detail.assignable_staff'));
        $this->allow($this->manager, 'incidents.followups.manage', false);
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'incidents/show', 'X-Inertia-Partial-Data' => 'auth'];
        $this->getJson('/incidents/'.$root->id, $headers)->assertOk()->assertJsonPath('props.detail.assignable_staff', []);
        $this->allow($this->manager, 'incidents.followups.manage');
        $this->assertNotEmpty($this->getJson('/incidents/'.$root->id, $headers)->assertOk()->json('props.detail.assignable_staff'));
    }

    public function test_a_person_is_available_on_their_inclusive_start_and_end_date_in_worker_timezone(): void
    {
        $person = $this->staff([]); HrEmployeeProfile::where('user_id', $person->id)->update(['start_date' => '2026-10-12', 'end_date' => '2026-10-12']);
        $this->assertContains($person->id, $this->ids($this->register($this->manager)['reportStaff']));
    }
}
