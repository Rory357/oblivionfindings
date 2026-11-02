<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Q1 + answer 23 (G3 board M5b): a team lead who reads only an incident's story sees the safety review's story view —
 * the steps, their own actions, the reporter's two paragraphs and how close it is to closing — and nothing else. The
 * view never counts as reading the review: everyone else keeps the normal page or a 404, and nothing new opens up.
 */
class HsStoryReviewPageTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->withoutMiddleware(HandleInertiaRequests::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->lead = $this->roleUser('team_lead', $this->site);
    }

    public function test_a_story_reader_gets_the_story_view_with_only_their_own_actions(): void
    {
        [$event] = $this->incidentReview();
        $mine = $this->action($event, $this->lead, 'Fit a grab rail beside the shower');
        $this->action($event, $this->roleUser('health_safety_officer', $this->site), 'Retrain night staff on wet floors');
        HsInvestigation::factory()->create(['hs_event_id' => $event->id, 'findings_summary' => 'Secret finding about the night roster.']);

        $review = null;
        $this->actingAs($this->lead)->get("/health-safety/events/{$event->id}")
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$review): void {
                $page->component('health-safety/events/story')->missing('detail');
                $review = $page->toArray()['props']['review'];
            });

        $this->assertSame('Tane slipped on the wet bathroom floor and grabbed the towel rail.', $review['story']['what_happened']);
        $this->assertSame('Checked he was all right and dried the floor.', $review['story']['did_straight_away']);
        $this->assertSame([$mine->id], array_column($review['actions'], 'id'));
        // Steps count every action, but never name anyone else's.
        $this->assertSame('0 of 2 done', collect($review['steps'])->firstWhere('key', 'implemented')['meta']);

        // Nothing from the investigation, other people's actions, the incident beyond the story, or the client.
        $json = json_encode($review);
        foreach (['Secret finding', 'Retrain night staff', 'witness', 'investigations', 'worksafe_reason', 'client'] as $absent) {
            $this->assertStringNotContainsStringIgnoringCase($absent, $json, "The story view must not carry {$absent}.");
        }
        $this->assertSame([
            'id', 'reference_number', 'title', 'status_label', 'severity_label', 'category_label', 'from_label', 'site_name',
            'owner_name', 'steps', 'story', 'actions', 'activity', 'closure', 'worksafe_with',
        ], array_keys($review));
    }

    public function test_the_story_view_widens_nothing_for_the_reader(): void
    {
        [$event] = $this->incidentReview();
        $this->action($event, $this->lead, 'Fit a grab rail beside the shower');

        // Still no History, no owner change, no close, no WorkSafe decision.
        $this->actingAs($this->lead)->getJson("/health-safety/events/{$event->id}/activity")->assertNotFound();
        $this->actingAs($this->lead)
            ->post("/health-safety/events/{$event->id}/owner", ['owner_user_id' => $this->lead->id])
            ->assertNotFound();
        $closing = $this->actingAs($this->lead)
            ->post("/health-safety/events/{$event->id}/close", ['closure_summary' => 'Trying to close it from the story view.']);
        $this->assertContains($closing->getStatusCode(), [403, 404]);
        $this->assertNull($event->fresh()->owner_user_id);
        $this->assertNotSame(HsEvent::STATUS_CLOSED, $event->fresh()->status);
    }

    public function test_restricted_draft_other_site_and_keyless_readers_get_a_404(): void
    {
        [$confidential] = $this->incidentReview(['is_hr_confidential' => true]);
        [$independent] = $this->incidentReview(['independent_handling_requested' => true]);
        [$draft] = $this->incidentReview(['status' => 'draft']);
        [$elsewhere] = $this->incidentReview([], Site::factory()->create(['is_active' => true]));
        foreach ([$confidential, $independent, $draft, $elsewhere] as $event) {
            $this->actingAs($this->lead)->get("/health-safety/events/{$event->id}")->assertNotFound();
        }

        [$event] = $this->incidentReview();
        $worker = $this->roleUser('support_worker', $this->site);
        $this->assertContains($this->actingAs($worker)->get("/health-safety/events/{$event->id}")->getStatusCode(), [403, 404]);
    }

    public function test_a_review_the_reader_can_open_normally_keeps_the_normal_page(): void
    {
        // The story view only ever stands in for a 404: the same team lead opening a hazard review at their Site (which
        // they read the normal way) gets the normal page.
        $hazard = HsEvent::factory()->create([
            'site_id' => $this->site->id, 'event_category' => HsEvent::CATEGORY_HAZARD, 'client_id' => null,
            'status' => HsEvent::STATUS_OPEN, 'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]);

        $this->actingAs($this->lead)->get("/health-safety/events/{$hazard->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('health-safety/events/show')->has('detail')->missing('review'));
    }

    /** @return array{0: HsEvent, 1: ClientIncident} */
    private function incidentReview(array $incident = [], ?Site $site = null): array
    {
        $site ??= $this->site;
        $event = HsEvent::factory()->create(['site_id' => $site->id, 'owner_user_id' => null, 'status' => HsEvent::STATUS_CORRECTIVE_ACTION]);
        $record = ClientIncident::factory()->submitted()->atSite($site)->create([
            'client_id' => Client::factory()->create(['site_id' => $site->id])->id,
            'type' => 'fall',
            'description' => 'Tane slipped on the wet bathroom floor and grabbed the towel rail.',
            'immediate_action_taken' => 'Checked he was all right and dried the floor.',
            'witnesses' => 'A witness saw it from the hallway.',
            'hs_event_id' => $event->id,
            ...$incident,
        ]);
        $event->forceFill(['source_type' => ClientIncident::class, 'source_id' => $record->id])->save();

        return [$event->fresh(), $record->fresh()];
    }

    private function action(HsEvent $event, User $owner, string $title): HsCorrectiveAction
    {
        return HsCorrectiveAction::factory()->create([
            'hs_event_id' => $event->id,
            'title' => $title,
            'status' => HsCorrectiveAction::STATUS_OPEN,
            'priority' => HsCorrectiveAction::PRIORITY_MEDIUM,
            'due_date' => now()->addWeek()->toDateString(),
            'assigned_to_user_id' => $owner->id,
            'created_by' => User::factory()->create()->id,
        ]);
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
