<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\FirstAidFollowup;
use App\Models\FirstAidRecord;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\HsEventAccessService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FirstAidAccessPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $viewer;

    private User $aider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        config()->set('app.worker_timezone', 'Pacific/Auckland');
        $this->travelTo(Carbon::parse('2026-10-07T12:30:00Z'));
        $this->site = Site::factory()->create();
        $this->viewer = $this->staff($this->site, ['hazards.view', 'hazards.manage']);
        $this->aider = $this->staff($this->site);
        $this->aider->hrEmployeeProfile->update(['is_first_aider' => true]);
    }

    private function staff(Site $site, array $permissions = []): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'start_date' => '2020-01-01', 'end_date' => null, 'is_active' => true]);
        $this->grant($user, $permissions);

        return $user;
    }

    private function grant(User $user, array $permissions, bool $allowed = true): void
    {
        foreach (Permission::whereIn('key', $permissions)->get() as $permission) {
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => $allowed]]);
        }
    }

    private function source(array $fields = []): FirstAidRecord
    {
        return FirstAidRecord::factory()->createQuietly(array_merge(['site_id' => $this->site->id, 'first_aider_id' => $this->aider->id, 'treated_person_type' => 'staff', 'client_id' => null, 'treated_person_id' => null, 'created_by' => $this->viewer->id], $fields));
    }

    private function access(): FirstAidAccessService
    {
        return app(FirstAidAccessService::class);
    }

    private function denied(callable $operation, int $status = 403): void
    {
        try {
            $operation();
            $this->fail('Expected current treatment source authority denial.');
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    private function ids(User $viewer, bool $archived = false): array
    {
        return $this->access()->withCurrentViewer($viewer, fn ($current, $reads, $sites) => $this->access()->readableQuery($current, $reads, $sites, $archived)->orderBy('id')->pluck('id')->all());
    }

    public function test_primary_client_subject_requires_actual_client_read_and_current_assignment_not_only_first_aid_permission(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $staff = $this->source();
        $clientRecord = $this->source(['treated_person_type' => 'client', 'client_id' => $client->id]);
        $this->assertSame([$staff->id], $this->ids($this->viewer));
        $this->denied(fn () => $this->access()->recordForViewer($clientRecord, $this->viewer));
        $this->grant($this->viewer, ['clients.viewAssigned']);
        $client->supportWorkers()->attach($this->viewer->id);
        $this->assertSame([$staff->id, $clientRecord->id], $this->ids($this->viewer));
        $this->assertSame($clientRecord->id, $this->access()->recordForViewer($clientRecord, $this->viewer)->id);
        $this->viewer->load('permissionOverrides', 'hrEmployeeProfile');
        $client->supportWorkers()->detach($this->viewer->id);
        $this->assertSame([$staff->id], $this->ids($this->viewer));
        $this->denied(fn () => $this->access()->recordForViewer($clientRecord, $this->viewer));
        $this->assertDatabaseHas('first_aid_records', ['id' => $clientRecord->id, 'client_id' => $client->id]);
    }

    public function test_unknown_and_contradictory_subjects_are_excluded_from_all_read_population_without_erasing_history(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $foreign = Site::factory()->create();
        $otherClient = Client::factory()->create(['site_id' => $foreign->id]);
        $this->grant($this->viewer, ['clients.viewAny']);
        $valid = $this->source();
        $rows = [
            $this->source(['treated_person_type' => 'client']),
            $this->source(['treated_person_type' => 'client', 'client_id' => $otherClient->id]),
            $this->source(['treated_person_type' => 'staff', 'client_id' => $client->id]),
            $this->source(['treated_person_type' => 'visitor', 'treated_person_id' => $this->aider->id]),
        ];
        $this->assertSame([$valid->id], $this->ids($this->viewer));
        foreach ($rows as $row) {
            $this->denied(fn () => $this->access()->recordForViewer($row, $this->viewer));
            $this->assertDatabaseHas('first_aid_records', ['id' => $row->id]);
        }
    }

    public function test_recorded_unlinked_staff_and_historical_staff_aider_are_readable_without_current_eligibility_or_hr_profile_grant(): void
    {
        $subject = $this->staff($this->site);
        $record = $this->source(['treated_person_id' => $subject->id]);
        $unlinked = $this->source(['treated_person_name' => 'Recorded free staff name']);
        $unknown = $this->source(['treated_person_name' => '']);
        $subject->hrEmployeeProfile->update(['is_active' => false]);
        $this->aider->hrEmployeeProfile->update(['end_date' => '2026-10-07', 'is_first_aider' => false]);
        $this->assertFalse($this->viewer->canDo('hr.employees.viewAny'));
        $this->assertSame([$record->id, $unlinked->id, $unknown->id], $this->ids($this->viewer));
        $this->assertNull($this->access()->recordForViewer($unknown, $this->viewer)->treatedPerson);
        $this->assertTrue(app(HsEventAccessService::class)->canView($this->review($unknown), $this->viewer));
        $this->assertDatabaseHas('first_aid_records', ['id' => $unknown->id, 'treated_person_name' => '', 'treated_person_id' => null]);
        $this->assertSame($subject->id, $this->access()->recordForViewer($record, $this->viewer)->treatedPerson->id);
        $options = $this->access()->pickerOptions($this->viewer, $record);
        $this->assertNotContains($subject->id, array_column($options['staff'], 'id'));
        $this->assertNotContains($this->aider->id, array_column($options['first_aiders'], 'id'));
        $this->access()->withCurrentViewer($this->viewer, fn ($current, $reads) => $this->denied(fn () => $this->access()->eligibleStaff($this->aider->id, $this->site->id, $reads, true)));
    }

    public function test_create_only_actor_can_capture_and_edit_under_fresh_source_but_cannot_get_record_or_existing_prefill(): void
    {
        $actor = $this->staff($this->site, ['hazards.create']);
        $this->grant($actor, ['hazards.view', 'hazards.manage'], false);
        $record = $this->source();
        $this->access()->withCurrentViewer($actor, function ($current, $reads, $sites) use ($record): void {
            $this->access()->assertCommand($record, $current, $reads, $sites, 'create');
            $this->access()->assertCommand($record, $current, $reads, $sites, 'update');
            $this->denied(fn () => $this->access()->assertCommand($record, $current, $reads, $sites, 'add_followup'));
        });
        $this->assertSame([], $this->ids($actor));
        $this->denied(fn () => $this->access()->recordForViewer($record, $actor));
        $this->denied(fn () => $this->access()->pickerOptions($actor, $record));
        $this->assertSame([$this->site->id], array_column($this->access()->pickerOptions($actor)['sites'], 'id'));
        $this->grant($actor, ['hazards.create'], false);
        $this->access()->withCurrentViewer($actor, fn ($current, $reads, $sites) => $this->denied(fn () => $this->access()->assertCommand($record, $current, $reads, $sites, 'update')));
    }

    #[DataProvider('portalIdentities')]
    public function test_portal_identity_cannot_become_first_aid_reader_or_assignee_through_lingering_hr_and_overrides(string $identity, bool $rbac): void
    {
        $actor = $this->staff($this->site, ['hazards.view', 'hazards.manage', 'healthSafety.viewAllSites']);
        if ($rbac) {
            $actor->roles()->syncWithoutDetaching([Role::firstOrCreate(['name' => $identity], ['label' => $identity, 'type' => 'system', 'level' => 0])->id]);
        } else {
            $actor->update(['role' => $identity]);
        }
        $this->denied(fn () => $this->access()->recordForViewer($this->source(), $actor));
        $this->access()->withCurrentViewer($this->viewer, fn ($current, $reads) => $this->denied(fn () => $this->access()->eligibleStaff($actor->id, $this->site->id, $reads, false, true)));
    }

    public static function portalIdentities(): array
    {
        return [['client', false], ['next_of_kin', false], ['external_clinician', false], ['client', true], ['next_of_kin', true], ['external_clinician', true]];
    }

    public function test_nz_employment_dates_and_fresh_site_revocation_apply_to_direct_records_and_directory_choices(): void
    {
        $record = $this->source();
        $this->viewer->hrEmployeeProfile->update(['start_date' => '2026-10-08', 'end_date' => '2026-10-08']);
        $this->assertSame($record->id, $this->access()->recordForViewer($record, $this->viewer)->id);
        $this->viewer->load('hrEmployeeProfile');
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['end_date' => '2026-10-07']);
        $this->denied(fn () => $this->access()->recordForViewer($record, $this->viewer));
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['end_date' => null, 'primary_site_id' => Site::factory()->create()->id]);
        $this->denied(fn () => $this->access()->recordForViewer($record, $this->viewer));
        $this->grant($this->viewer, ['healthSafety.viewAllSites']);
        $this->assertSame($record->id, $this->access()->recordForViewer($record, $this->viewer)->id);
        $this->site->update(['is_active' => false]);
        $this->denied(fn () => $this->access()->recordForViewer($record, $this->viewer));
    }

    public function test_exact_named_executor_can_complete_only_own_live_child_under_genuine_parent_read(): void
    {
        $record = $this->source();
        $worker = $this->staff($this->site, ['hazards.view']);
        $other = $this->staff($this->site, ['hazards.view']);
        $followup = FirstAidFollowup::createQuietly(['first_aid_record_id' => $record->id, 'assigned_to_user_id' => $worker->id, 'notes' => 'Recorded requested work', 'created_by' => $this->viewer->id]);
        $this->assertSame(['edit' => false, 'complete' => true], $this->access()->followupCan($record, $followup, $worker));
        $this->assertSame(['edit' => false, 'complete' => false], $this->access()->followupCan($record, $followup, $other));
        $wrong = $this->source();
        $this->assertSame(['edit' => false, 'complete' => false], $this->access()->followupCan($wrong, $followup, $worker));
        $this->grant($worker, ['hazards.view'], false);
        $this->assertSame(['edit' => false, 'complete' => false], $this->access()->followupCan($record, $followup, $worker));
        $record->deleteQuietly();
        $this->assertSame(['edit' => false, 'complete' => false], $this->access()->followupCan($record, $followup, $this->viewer));
        $this->assertNotContains($record->id, $this->ids($this->viewer));
        $this->assertContains($record->id, $this->ids($this->viewer, true));
        $can = $this->access()->commandCan($record, $this->viewer);
        $this->assertTrue($can['view']);
        foreach (['create', 'edit', 'archive', 'add_followup', 'add_evidence', 'remove_evidence', 'link_incident', 'create_incident'] as $flag) {
            $this->assertFalse($can[$flag]);
        }
    }

    public function test_incident_disclosure_requires_exact_client_site_and_independent_incident_authority(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $this->grant($this->viewer, ['clients.viewAny']);
        $record = $this->source(['treated_person_type' => 'client', 'client_id' => $client->id]);
        $incident = ClientIncident::factory()->createQuietly(['site_id' => $this->site->id, 'client_id' => $client->id, 'reported_by' => $this->viewer->id]);
        $this->access()->withCurrentViewer($this->viewer, fn ($current, $reads) => $this->denied(fn () => $this->access()->assertIncident($record, $incident, $current, $reads, false)));
        $this->grant($this->viewer, ['incidents.viewAny', 'incidents.update']);
        $this->access()->withCurrentViewer($this->viewer, function ($current, $reads) use ($record, $incident): void {
            $this->access()->assertIncident($record, $incident, $current, $reads, true);
            $this->assertSame($incident->id, $record->relatedIncident->id);
        });
        $incident->forceFill(['is_hr_confidential' => true])->saveQuietly();
        $this->access()->withCurrentViewer($this->viewer, fn ($current, $reads) => $this->denied(fn () => $this->access()->assertIncident($record, $incident, $current, $reads, false)));
        $incident->forceFill(['is_hr_confidential' => false, 'site_id' => Site::factory()->create()->id])->saveQuietly();
        $this->access()->withCurrentViewer($this->viewer, fn ($current, $reads) => $this->denied(fn () => $this->access()->assertIncident($record, $incident, $current, $reads, false)));
        $this->assertSame($record->id, $this->access()->recordForViewer($record, $this->viewer)->id);
    }

    public function test_new_first_aider_requires_current_flag_site_and_employment_without_rewriting_historical_attribution(): void
    {
        $record = $this->source();
        $worker = $this->staff($this->site);
        $foreign = $this->staff(Site::factory()->create());
        $foreign->hrEmployeeProfile->update(['is_first_aider' => true]);
        $this->access()->withCurrentViewer($this->viewer, function ($current, $reads) use ($worker, $foreign): void {
            $this->assertSame($this->aider->id, $this->access()->eligibleStaff($this->aider->id, $this->site->id, $reads, true)->id);
            $this->denied(fn () => $this->access()->eligibleStaff($worker->id, $this->site->id, $reads, true));
            $this->denied(fn () => $this->access()->eligibleStaff($foreign->id, $this->site->id, $reads, true));
        });
        $this->aider->hrEmployeeProfile->update(['is_first_aider' => false]);
        $this->access()->withCurrentViewer($this->viewer, fn ($current, $reads) => $this->denied(fn () => $this->access()->eligibleStaff($this->aider->id, $this->site->id, $reads, true)));
        $this->assertSame($this->aider->id, $this->access()->recordForViewer($record, $this->viewer)->first_aider_id);
    }

    public function test_followup_assignment_options_require_parent_client_read_for_actual_named_executor(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $this->grant($this->viewer, ['clients.viewAny']);
        $worker = $this->staff($this->site, ['hazards.view', 'clients.viewAssigned']);
        $record = $this->source(['treated_person_type' => 'client', 'client_id' => $client->id]);
        $this->assertNotContains($worker->id, array_column($this->access()->pickerOptions($this->viewer, $record)['assignees'], 'id'));
        $client->supportWorkers()->attach($worker->id);
        $this->assertContains($worker->id, array_column($this->access()->pickerOptions($this->viewer, $record)['assignees'], 'id'));
        $worker->load('permissionOverrides');
        $client->supportWorkers()->detach($worker->id);
        $this->assertNotContains($worker->id, array_column($this->access()->pickerOptions($this->viewer, $record)['assignees'], 'id'));
    }

    public function test_cached_read_permission_revocation_hides_source_without_expanding_or_removing_actual_capture_authority(): void
    {
        $record = $this->source();
        $this->assertSame($record->id, $this->access()->recordForViewer($record, $this->viewer)->id);
        $this->viewer->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $this->grant($this->viewer, ['hazards.view'], false);
        $this->assertSame([], $this->ids($this->viewer));
        $this->denied(fn () => $this->access()->recordForViewer($record, $this->viewer));
        $this->denied(fn () => $this->access()->pickerOptions($this->viewer, $record));
        $this->access()->withCurrentViewer($this->viewer, function ($current, $reads, $sites) use ($record): void {
            $this->access()->assertCommand($record, $current, $reads, $sites, 'update');
            $this->assertFalse($current->canDo('hazards.view'));
            $this->assertTrue($current->canDo('hazards.manage'));
        });
        $this->assertDatabaseHas('first_aid_records', ['id' => $record->id, 'treated_person_name' => $record->treated_person_name]);
    }

    public function test_inactive_and_archived_site_choices_are_removed_after_current_scope_recheck(): void
    {
        $active = Site::factory()->create();
        $inactive = Site::factory()->create();
        $archived = Site::factory()->create();
        $this->viewer->hrEmployeeProfile->update(['secondary_site_ids' => [$active->id, $inactive->id, $archived->id]]);
        $this->viewer->load('hrEmployeeProfile');
        $inactive->update(['is_active' => false]);
        $archived->update(['archived' => true, 'archived_at' => now()]);
        $ids = array_column($this->access()->pickerOptions($this->viewer)['sites'], 'id');
        $this->assertContains($this->site->id, $ids);
        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
        $this->assertNotContains($archived->id, $ids);
        $this->denied(fn () => $this->access()->pickerOptions($this->viewer, null, $inactive->id));
        $this->denied(fn () => $this->access()->pickerOptions($this->viewer, null, $archived->id));
    }

    public function test_picker_population_is_full_current_and_site_specific_without_treatment_or_hr_narrative(): void
    {
        for ($i = 0; $i < 105; $i++) {
            $this->staff($this->site);
        }
        $foreign = $this->staff(Site::factory()->create(), ['hazards.view']);
        $options = $this->access()->pickerOptions($this->viewer, null, $this->site->id);
        $this->assertCount(107, $options['staff']);
        $this->assertSame([$this->aider->id], array_column($options['first_aiders'], 'id'));
        $this->assertNotContains($foreign->id, array_column($options['staff'], 'id'));
        foreach ($options['staff'] as $person) {
            $this->assertSame(['id', 'name', 'site_id'], array_keys($person));
        }
        $this->assertSame([], $options['clients']);
        $this->assertSame([], $options['incidents']);
    }

    private function review(FirstAidRecord $record, array $fields = []): HsEvent
    {
        return HsEvent::factory()->createQuietly(array_merge([
            'source_type' => $record->getMorphClass(), 'source_id' => $record->id, 'site_id' => $record->site_id,
            'event_category' => HsEvent::CATEGORY_INJURY, 'client_id' => $record->client_id,
            'staff_id' => $record->treated_person_type === 'staff' ? $record->treated_person_id : null,
            'status' => HsEvent::STATUS_OPEN, 'created_by' => $this->viewer->id,
        ], $fields));
    }

    public function test_treatment_review_population_search_count_and_direct_ids_require_current_primary_subject_read(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $record = $this->source(['treated_person_type' => 'client', 'client_id' => $client->id]);
        $private = $this->review($record);
        $unrelated = HsEvent::factory()->createQuietly(['site_id' => $this->site->id, 'created_by' => $this->viewer->id, 'status' => HsEvent::STATUS_OPEN]);
        $this->actingAs($this->viewer);
        $this->get('/health-safety/events?tab=all')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('events.total', 1)->where('events.data.0.id', $unrelated->id)->where('tabCounts.all', 1));
        $this->get('/health-safety/events?q='.urlencode($private->reference_number))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('events.total', 0)->where('tabCounts.all', 0));
        $this->get('/health-safety/events/'.$private->id)->assertNotFound();
        $access = app(HsEventAccessService::class);
        $this->assertFalse($access->canView($private, $this->viewer));
        $this->assertTrue($access->canView($unrelated, $this->viewer));

        $this->grant($this->viewer, ['clients.viewAssigned']);
        $client->supportWorkers()->attach($this->viewer->id);
        $this->get('/health-safety/events?q='.urlencode($private->reference_number))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('events.total', 1)->where('events.data.0.id', $private->id)->where('tabCounts.all', 1));
        $this->get('/health-safety/events/'.$private->id)->assertOk();
        $this->assertTrue($access->canView($private, $this->viewer));
        $this->viewer->load('permissionOverrides');
        $client->supportWorkers()->detach($this->viewer->id);
        $this->get('/health-safety/events/'.$private->id)->assertNotFound();
        $this->assertFalse($access->canView($private, $this->viewer));
        $this->assertDatabaseHas('hs_events', ['id' => $private->id, 'source_id' => $record->id]);
    }

    public function test_treatment_review_requires_exact_category_site_and_subject_tuple_including_archived_source(): void
    {
        $record = $this->source(['treated_person_id' => $this->aider->id]);
        $good = $this->review($record);
        $otherSite = Site::factory()->create();
        $this->viewer->hrEmployeeProfile->update(['secondary_site_ids' => [$otherSite->id]]);
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $invalid = [
            $this->review($record, ['source_type' => FirstAidRecord::class, 'source_id' => 999999999]),
            $this->review($record, ['event_category' => HsEvent::CATEGORY_INCIDENT]),
            $this->review($record, ['site_id' => $otherSite->id]),
            $this->review($record, ['client_id' => $client->id]),
            $this->review($record, ['staff_id' => null]),
            $this->review($record, ['staff_id' => $this->viewer->id]),
        ];
        $record->deleteQuietly();
        $access = app(HsEventAccessService::class);
        $this->assertTrue($access->canView($good, $this->viewer));
        foreach ($invalid as $event) {
            $this->assertFalse($access->canView($event, $this->viewer));
            $this->actingAs($this->viewer)->get('/health-safety/events/'.$event->id)->assertNotFound();
            $this->assertDatabaseHas('hs_events', ['id' => $event->id]);
        }
        $this->assertSame([$good->id], $access->applyReadableScope(HsEvent::query(), $this->viewer)->orderBy('id')->pluck('id')->all());
    }

    public function test_treatment_review_denies_stale_staff_identity_without_changing_other_source_policy(): void
    {
        $review = $this->review($this->source());
        $other = HsEvent::factory()->createQuietly(['site_id' => $this->site->id, 'created_by' => $this->viewer->id]);
        $this->viewer->load('hrEmployeeProfile', 'permissionOverrides');
        $this->viewer->hrEmployeeProfile->update(['end_date' => '2026-10-07']);
        $query = app(HsEventAccessService::class)->applyFirstAidSourceScope(HsEvent::query(), $this->viewer);
        $this->assertSame([$other->id], $query->orderBy('id')->pluck('id')->all());
        $this->assertDatabaseHas('hs_events', ['id' => $review->id]);
    }

    public function test_new_capture_incident_picker_requires_selected_client_site_and_exact_current_link_authority_without_treatment_prefill(): void
    {
        $actor = $this->staff($this->site, ['hazards.manage', 'clients.viewAssigned', 'incidents.viewAssigned', 'incidents.update']);
        $this->grant($actor, ['hazards.view', 'incidents.viewAny'], false);
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $client->supportWorkers()->attach($actor->id);
        $good = ClientIncident::factory()->createQuietly(['client_id' => $client->id, 'site_id' => $this->site->id, 'reported_by' => $actor->id, 'status' => 'draft']);
        $closed = ClientIncident::factory()->createQuietly(['client_id' => $client->id, 'site_id' => $this->site->id, 'reported_by' => $actor->id, 'status' => 'closed']);
        $otherReporter = ClientIncident::factory()->createQuietly(['client_id' => $client->id, 'site_id' => $this->site->id, 'reported_by' => $this->viewer->id, 'status' => 'draft']);
        $confidential = ClientIncident::factory()->createQuietly(['client_id' => $client->id, 'site_id' => $this->site->id, 'reported_by' => $actor->id, 'status' => 'draft', 'is_hr_confidential' => true]);
        $wrongSite = Site::factory()->create();
        $actor->hrEmployeeProfile->update(['secondary_site_ids' => [$wrongSite->id]]);
        $poisoned = ClientIncident::factory()->createQuietly(['client_id' => $client->id, 'site_id' => $wrongSite->id, 'reported_by' => $actor->id, 'status' => 'draft']);
        $this->assertSame([], $this->access()->pickerOptions($actor, null, $this->site->id)['incidents']);
        $options = $this->access()->pickerOptions($actor, null, $this->site->id, $client->id);
        $this->assertSame([$good->id], array_column($options['incidents'], 'id'));
        $this->assertSame('/incidents/'.$good->id, $options['incidents'][0]['href']);
        foreach ([$closed, $otherReporter, $confidential, $poisoned] as $excluded) {
            $this->assertNotContains($excluded->id, array_column($options['incidents'], 'id'));
        }
        $this->denied(fn () => $this->access()->pickerOptions($actor, null, null, $client->id));
        $this->denied(fn () => $this->access()->pickerOptions($actor, null, $wrongSite->id, $client->id));
        $this->denied(fn () => $this->access()->pickerOptions($actor, $this->source(), $this->site->id, $client->id));
        $actor->load('permissionOverrides', 'hrEmployeeProfile');
        $this->grant($actor, ['incidents.update'], false);
        $this->assertSame([], $this->access()->pickerOptions($actor, null, $this->site->id, $client->id)['incidents']);
        $client->supportWorkers()->detach($actor->id);
        $this->denied(fn () => $this->access()->pickerOptions($actor, null, $this->site->id, $client->id));
        $this->assertDatabaseHas('client_incidents', ['id' => $good->id, 'client_id' => $client->id]);
    }

    public function test_archive_capability_reads_exact_open_source_work_without_trusting_loaded_children(): void
    {
        $record = $this->source();
        $other = $this->source();
        FirstAidFollowup::create(['first_aid_record_id' => $other->id, 'notes' => 'Other source still needs work', 'created_by' => $this->viewer->id]);
        FirstAidFollowup::create(['first_aid_record_id' => $record->id, 'notes' => 'Completed historical work', 'created_by' => $this->viewer->id, 'completed_at' => now()->subMinute()]);
        $this->assertTrue($this->access()->commandCan($record, $this->viewer)['archive']);

        $record->load('followups');
        $open = FirstAidFollowup::create(['first_aid_record_id' => $record->id, 'notes' => 'Record actual follow-through before archive', 'created_by' => $this->viewer->id]);
        $this->assertFalse($this->access()->commandCan($record, $this->viewer)['archive']);
        $this->assertTrue($this->access()->commandCan($record, $this->viewer)['add_followup']);
        $open->forceFill(['completed_at' => now()])->save();
        $this->assertTrue($this->access()->commandCan($record, $this->viewer)['archive']);

        $record->delete();
        $this->assertFalse($this->access()->commandCan($record, $this->viewer)['archive']);
        $this->viewer->load('hrEmployeeProfile', 'permissionOverrides');
        $this->viewer->hrEmployeeProfile->update(['primary_site_id' => null, 'secondary_site_ids' => []]);
        $this->assertFalse($this->access()->commandCan($other, $this->viewer)['archive']);
        $this->assertNull($open->fresh()->completed_by_user_id);
        $this->assertFalse($other->fresh()->trashed());
    }
}
