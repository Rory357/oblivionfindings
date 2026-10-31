<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Models\Role;
use App\Models\SafeguardingConcern;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\HealthSafety\HsIncidentStoryReadService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Q1 (Stephan, 31 Oct; G3 boards approved 1 Nov 2026): a team lead reads an incident's "What happened" and "What we
 * did straight away" on its safety review at their own Sites — nothing else, and never for HR-confidential,
 * safeguarding, restricted or independently handled incidents. The read never counts as reading the event.
 */
class HsIncidentStoryReadTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->lead = $this->roleUser('team_lead', $this->site);
    }

    public function test_a_team_lead_reads_only_the_story_of_a_submitted_incident_at_their_site(): void
    {
        [$event, $incident] = $this->incidentReview();

        $story = app(HsIncidentStoryReadService::class)->storyFor($this->lead, $event);

        $this->assertSame([
            'incident_id' => $incident->id,
            'what_happened' => 'Tane slipped on the wet bathroom floor and grabbed the towel rail.',
            'did_straight_away' => 'Checked he was all right and dried the floor.',
            'reported_by' => $incident->reporter->name,
        ], $story);
    }

    public function test_hr_confidential_incidents_are_never_read(): void
    {
        [$event] = $this->incidentReview(['is_hr_confidential' => true]);

        $this->assertNull(app(HsIncidentStoryReadService::class)->storyFor($this->lead, $event));
    }

    public function test_independently_handled_incidents_are_never_read(): void
    {
        [$event] = $this->incidentReview(['independent_handling_requested' => true]);

        $this->assertNull(app(HsIncidentStoryReadService::class)->storyFor($this->lead, $event));
    }

    public function test_safeguarding_and_restricted_incidents_are_never_read(): void
    {
        [$safeguarding] = $this->incidentReview(['type' => 'safeguarding']);
        [$withConcern, $concerned] = $this->incidentReview();
        SafeguardingConcern::factory()->create(['related_incident_id' => $concerned->id]);
        [$withExclusion, $excluded] = $this->incidentReview();
        DB::table('incident_owner_exclusions')->insert(['incident_id' => $excluded->id, 'user_id' => User::factory()->create()->id, 'reason_code' => 'involved', 'created_at' => now(), 'updated_at' => now()]);

        foreach ([$safeguarding, $withConcern, $withExclusion] as $event) {
            $this->assertNull(app(HsIncidentStoryReadService::class)->storyFor($this->lead, $event));
        }
    }

    public function test_drafts_and_other_sites_are_never_read(): void
    {
        [$draft] = $this->incidentReview(['status' => 'draft']);
        $elsewhere = Site::factory()->create(['is_active' => true]);
        [$otherSite] = $this->incidentReview([], $elsewhere);

        $this->assertNull(app(HsIncidentStoryReadService::class)->storyFor($this->lead, $draft));
        $this->assertNull(app(HsIncidentStoryReadService::class)->storyFor($this->lead, $otherSite));
    }

    public function test_without_the_key_nobody_reads_the_story_this_way(): void
    {
        [$event] = $this->incidentReview();
        $worker = $this->roleUser('support_worker', $this->site);

        $this->assertNull(app(HsIncidentStoryReadService::class)->storyFor($worker, $event));
    }

    public function test_the_story_read_widens_nothing_else_for_team_leads(): void
    {
        [$event, $incident] = $this->incidentReview();
        $service = app(HsIncidentStoryReadService::class);
        $this->assertTrue($service->canReadStory($this->lead, $event));

        // Team leads hold exactly the two new G3 keys on top of what they had, and still no incident read.
        $this->assertTrue($this->lead->canDo('healthSafety.events.readIncidentStory'));
        $this->assertTrue($this->lead->canDo('healthSafety.actions.ownOnStoryReviews'));
        $this->assertFalse($this->lead->canDo('incidents.viewAny'));
        $this->assertFalse($this->lead->canDo('incidents.viewAssigned'));

        // It never counts as reading the event or the incident.
        $this->assertFalse(app(HsEventAccessService::class)->canReadEvent($this->lead, $event));
        $this->actingAs($this->lead)->get("/incidents/{$incident->id}")->assertStatus(403);
        $decidedBefore = $event->fresh()->worksafe_decided_at;
        $this->actingAs($this->lead)
            ->post("/health-safety/events/{$event->id}/worksafe/decision", ['notifiable' => false, 'reason' => 'Bruise only, first aid only.'])
            ->assertNotFound();
        $this->assertEquals($decidedBefore, $event->fresh()->worksafe_decided_at);
        $this->actingAs($this->lead)
            ->post("/health-safety/events/{$event->id}/accept-handover", ['owner_user_id' => $this->lead->id])
            ->assertNotFound();
        $this->assertNull($event->fresh()->owner_user_id);
    }

    /** @return array{0: HsEvent, 1: ClientIncident} */
    private function incidentReview(array $incident = [], ?Site $site = null): array
    {
        $site ??= $this->site;
        $event = HsEvent::factory()->create(['site_id' => $site->id, 'owner_user_id' => null]);
        $record = ClientIncident::factory()->submitted()->atSite($site)->create([
            'client_id' => Client::factory()->create(['site_id' => $site->id])->id,
            'type' => 'fall',
            'description' => 'Tane slipped on the wet bathroom floor and grabbed the towel rail.',
            'immediate_action_taken' => 'Checked he was all right and dried the floor.',
            'hs_event_id' => $event->id,
            ...$incident,
        ]);
        $event->forceFill(['source_type' => ClientIncident::class, 'source_id' => $record->id])->save();

        return [$event->fresh(), $record->fresh()];
    }

    private function roleUser(string $roleName, Site $site): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $roleName)->firstOrFail()->id]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'position_role' => $roleName, 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true,
        ]);

        return $user->fresh();
    }
}
