<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\FirstAidFollowup;
use App\Models\FirstAidRecord;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\FirstAidSourceProjectionService;
use App\Services\Tasks\Providers\FirstAidFollowupProvider;
use App\Support\HealthSafety\FirstAidReturn;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FirstAidEntryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['type' => 'house']);
        $this->viewer = User::factory()->frontlineWorker()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $this->viewer->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null, 'is_first_aider' => true]);
        foreach (['hazards.view' => true, 'hazards.create' => true, 'hazards.manage' => false, 'healthSafety.viewAllSites' => false,
            'clients.viewAny' => false, 'clients.viewAssigned' => true, 'incidents.viewAny' => false, 'incidents.viewAssigned' => true] as $key => $allowed) {
            $this->grant($key, $allowed);
        }
    }

    public function test_followup_provider_rechecks_primary_client_site_archival_and_current_staff_without_cached_grants(): void
    {
        $client = $this->client(false);
        $private = $this->record(['treated_person_type' => 'client', 'client_id' => $client->id, 'treated_person_name' => 'Private old name']);
        $hidden = $this->followup($private, ['notes' => 'Private clinical followup']);
        $public = $this->record();
        $visible = $this->followup($public);
        $foreign = $this->followup($this->record(['site_id' => Site::factory()->create()->id]));
        $archived = $this->record();
        $this->followup($archived);
        $archived->delete();
        $provider = app(FirstAidFollowupProvider::class);
        $this->assertSame(['first_aid_followup-'.$visible->id], array_column($provider->authorizedTasks($this->viewer), 'id'));
        $client->supportWorkers()->attach($this->viewer->id);
        $this->assertCount(2, $provider->authorizedTasks($this->viewer));
        $client->supportWorkers()->detach($this->viewer->id);
        $this->viewer->load('permissionOverrides', 'hrEmployeeProfile');
        $serialized = json_encode(array_map(fn ($task) => $task->toArray(), $provider->authorizedTasks($this->viewer)));
        $this->assertStringNotContainsString('Private', $serialized);
        $this->assertNull(collect($provider->authorizedTasks($this->viewer, ['id' => $hidden->id]))->first());
        $this->assertSame([], $provider->authorizedTasks($this->viewer, ['id' => $foreign->id]));
        $this->viewer->hrEmployeeProfile->forceFill(['is_active' => false])->save();
        $this->assertSame([], $provider->authorizedTasks($this->viewer));
    }

    public function test_actual_source_task_link_reads_exact_selected_followup_and_filtered_tasks_return(): void
    {
        $record = $this->record();
        $child = $this->followup($record);
        $origin = '/tasks?bucket=open&assigned=me';
        $item = app(FirstAidFollowupProvider::class)->authorizedTasks($this->viewer, ['id' => $child->id, 'return_to' => $origin])[0];
        $this->assertSame(FirstAidReturn::href($record->id, $origin, 'followups', $child->id), $item->link);
        $this->actingAs($this->viewer)->get($item->link)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('health-safety/first-aid/show')->where('detail.id', $record->id)->where('followup', $child->id)->where('section', 'followups')->where('return_to', $origin));
        $this->assertNull($item->dueAt);
        $this->assertFalse($item->isOverdue());
    }

    public function test_actual_my_day_shows_only_current_named_executor_work_and_no_denied_subject(): void
    {
        $record = $this->record();
        $own = $this->followup($record);
        $this->followup($record, ['assigned_to_user_id' => User::factory()->frontlineWorker()->create()->id]);
        $this->followup($record, ['completed_at' => now()]);
        $private = $this->record(['treated_person_type' => 'client', 'client_id' => $this->client(false)->id, 'treated_person_name' => 'Private denied person']);
        $this->followup($private, ['notes' => 'Private denied followup']);
        $this->actingAs($this->viewer)->get('/my-day')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('first_aid_followups', 1)->where('first_aid_followups.0.id', $own->id)
            ->where('first_aid_followups.0.url', FirstAidReturn::href($record->id, '/my-day', 'followups', $own->id))->where('first_aid_confirmation', null));
    }

    public function test_source_profile_lists_have_no_silent_caps_and_retain_authorized_client_treatment_fields(): void
    {
        $client = $this->client(true);
        for ($i = 0; $i < 11; $i++) {
            $this->record(['treated_person_type' => 'client', 'client_id' => $client->id, 'body_part' => 'Hand', 'treatment_given' => 'Dressing']);
        }
        for ($i = 0; $i < 90; $i++) {
            $this->record();
        }
        $this->record(['treated_person_type' => 'client', 'client_id' => $this->client(false)->id, 'treated_person_name' => 'Private unavailable person']);
        $projector = app(FirstAidSourceProjectionService::class);
        $rows = $projector->forSite($this->viewer, $this->site);
        $this->assertCount(101, $rows);
        $this->assertStringNotContainsString('Private unavailable', json_encode($rows));
        $clientRows = $projector->forClient($this->viewer, $client);
        $this->assertCount(11, $clientRows);
        $this->assertSame('Hand', $clientRows[0]['body_part']);
        $this->assertSame('Dressing', $clientRows[0]['treatment_given']);
        parse_str(parse_url($clientRows[0]['href'], PHP_URL_QUERY), $query);
        $this->assertSame('/operations/clients/'.$client->id.'?tab=first_aid', $query['return_to']);
        $this->grant('hazards.view', false);
        $this->assertSame([], $projector->forClient($this->viewer, $client));
        $this->assertSame([], $projector->forSite($this->viewer, $this->site));
    }

    public function test_incident_reciprocal_entries_require_both_independent_records_and_exact_tuple(): void
    {
        $client = $this->client(true);
        $incident = ClientIncident::factory()->create(['client_id' => $client->id, 'site_id' => $this->site->id, 'is_hr_confidential' => false]);
        $record = $this->record(['treated_person_type' => 'client', 'client_id' => $client->id, 'related_incident_id' => $incident->id]);
        $this->record(['treated_person_type' => 'staff', 'related_incident_id' => $incident->id]);
        $projector = app(FirstAidSourceProjectionService::class);
        $rows = $projector->forIncident($this->viewer, $incident);
        $this->assertSame([$record->id], array_column($rows, 'id'));
        parse_str(parse_url($rows[0]['href'], PHP_URL_QUERY), $query);
        $this->assertSame('/incidents/'.$incident->id, $query['return_to']);
        $this->grant('hazards.view', false);
        $this->assertSame([], $projector->forIncident($this->viewer, $incident));
        $this->grant('hazards.view', true);
        $this->grant('incidents.viewAssigned', false);
        $this->assertSame([], $projector->forIncident($this->viewer, $incident));
    }

    public function test_review_breadcrumb_and_actual_event_read_cannot_expose_denied_primary_client_treatment(): void
    {
        $client = $this->client(false);
        $record = $this->record(['treated_person_type' => 'client', 'client_id' => $client->id]);
        $event = $this->event($record);
        $projector = app(FirstAidSourceProjectionService::class);
        $this->assertNull($projector->sourceForReview($event, $this->viewer));
        $this->actingAs($this->viewer)->get('/health-safety/events/'.$event->id)->assertNotFound();
        $client->supportWorkers()->attach($this->viewer->id);
        $this->get('/health-safety/events/'.$event->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('detail.source.type', 'FirstAidRecord')->where('detail.source.id', $record->id)->where('detail.client.id', $client->id));
        $client->supportWorkers()->detach($this->viewer->id);
        $this->get('/health-safety/events/'.$event->id)->assertNotFound();
    }

    public function test_mismatched_review_tuple_cannot_relabel_a_source_and_current_matching_receipt_preserves_selected_child(): void
    {
        $record = $this->record();
        $child = $this->followup($record);
        $event = $this->event($record);
        $back = FirstAidReturn::href($record->id, '/tasks?bucket=open', 'followups', $child->id);
        $this->actingAs($this->viewer)->get('/health-safety/events/'.$event->id.'?return_to='.rawurlencode($back))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('detail.source.url', $back));
        $event->forceFill(['site_id' => Site::factory()->create()->id])->saveQuietly();
        $this->assertNull(app(FirstAidSourceProjectionService::class)->sourceForReview($event, $this->viewer));
    }

    public function test_create_only_capture_returns_exact_confirmation_without_granting_canonical_read_or_outbound_authority(): void
    {
        $this->grant('hazards.view', false);
        $key = (string) Str::uuid();
        $response = $this->actingAs($this->viewer)->postJson('/health-safety/first-aid', ['idempotency_key' => $key, 'site_id' => $this->site->id,
            'treated_person_type' => 'staff', 'treated_person_name' => 'Recorded staff member', 'first_aider_id' => $this->viewer->id,
            'treatment_date' => now()->subMinute()->toIso8601String(), 'injury_illness_type' => 'cut', 'injury_illness_description' => 'Synthetic superficial cut', 'treatment_given' => 'Cleaned and dressed', 'treatment_outcome' => 'returned_to_activity', 'ambulance_called' => false, 'incident_reported' => false]);
        $response->assertSuccessful()->assertJsonPath('status', 'accepted')->assertJsonPath('record_href', null)->assertJsonPath('first_aid_confirmation.idempotency_key', $key)->assertJsonPath('first_aid_confirmation.command', 'create');
        $id = $response->json('first_aid_confirmation.id');
        $this->getJson('/health-safety/first-aid/'.$id)->assertForbidden();
        $proof = $response->json('first_aid_confirmation');
        $this->withSession(['first_aid_confirmation' => $proof])->get('/my-day')->assertOk()->assertInertia(fn (Assert $page) => $page->where('first_aid_confirmation', $proof)->has('first_aid_followups', 0));
    }

    private function grant(string $key, bool $allowed): void
    {
        $this->viewer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->firstOrFail()->id => ['allowed' => $allowed]]);
    }

    private function client(bool $assigned): Client
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        if ($assigned) {
            $client->supportWorkers()->attach($this->viewer->id);
        }

        return $client;
    }

    private function record(array $fields = []): FirstAidRecord
    {
        $record = FirstAidRecord::factory()->create(['site_id' => $this->site->id, 'first_aider_id' => $this->viewer->id, 'created_by' => $this->viewer->id,
            'treated_person_type' => 'staff', 'treated_person_id' => null, 'client_id' => null, 'ambulance_called' => false, 'treatment_outcome' => 'returned_to_activity', ...$fields]);
        $record->forceFill(['lock_version' => 1])->saveQuietly();

        return $record;
    }

    private function followup(FirstAidRecord $record, array $fields = []): FirstAidFollowup
    {
        return FirstAidFollowup::create(['first_aid_record_id' => $record->id, 'notes' => 'Recheck dressing', 'assigned_to_user_id' => $this->viewer->id, 'created_by' => $this->viewer->id, ...$fields]);
    }

    private function event(FirstAidRecord $record): HsEvent
    {
        return HsEvent::factory()->create(['site_id' => $record->site_id, 'source_type' => FirstAidRecord::class, 'source_id' => $record->id,
            'event_category' => HsEvent::CATEGORY_INJURY, 'client_id' => $record->client_id, 'staff_id' => $record->treated_person_id, 'idempotency_key' => (string) Str::uuid()]);
    }
}
