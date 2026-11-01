<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsCorrectiveActionService;
use App\Services\HealthSafety\HsInvestigationCorrectionService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * G3 History tab: the review's activity log in plain words, built from what is recorded, for people who can read the
 * whole review only.
 */
class HsEventActivityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
    }

    public function test_the_activity_log_tells_what_was_done_in_plain_words(): void
    {
        $owner = $this->roleUser('health_safety_officer');
        $checker = $this->roleUser('health_safety_officer');
        $other = $this->roleUser('health_safety_officer');
        $event = HsEvent::factory()->create(['site_id' => $this->site->id, 'owner_user_id' => $owner->id, 'status' => HsEvent::STATUS_CORRECTIVE_ACTION]);
        $service = app(HsCorrectiveActionService::class);

        $this->actingAs($owner);
        $action = $service->createStandalone($event, ['title' => 'Fit grips under every lounge rug', 'assigned_to_user_id' => $owner->id,
            'due_date' => now()->addWeek()->toDateString(), 'priority' => HsCorrectiveAction::PRIORITY_MEDIUM], $owner);
        $service->start($action->fresh());
        $service->complete($action->fresh(), ['completion_notes' => 'Grips fitted.']);
        $this->actingAs($checker);
        $service->verify($action->fresh(), ['evidence_reviewed' => true, 'effectiveness_confirmed' => false, 'verification_notes' => 'Still slides.']);

        $this->actingAs($other)->post("/health-safety/events/{$event->id}/owner", ['owner_user_id' => $checker->id, 'reason' => 'Jordan is on leave until 16 Nov.'])
            ->assertSessionHas('success');

        $investigation = HsInvestigation::factory()->create(['hs_event_id' => $event->id, 'status' => HsInvestigation::STATUS_COMPLETED,
            'lead_investigator_id' => $owner->id, 'submitted_by_id' => $owner->id, 'team_member_ids' => [], 'findings_summary' => 'The rug edge lifts.']);
        $this->actingAs($other);
        app(HsInvestigationCorrectionService::class)->propose($event->fresh(), $investigation, $other, 'The rug has no grip underneath.', 'The check found no grip underneath.');

        $entries = collect($this->actingAs($other)->getJson("/health-safety/events/{$event->id}/activity")->assertOk()->json('entries'));
        $whats = $entries->pluck('what')->all();

        $this->assertContains('added “Fit grips under every lounge rug”', $whats);
        $this->assertContains('started “Fit grips under every lounge rug”', $whats);
        $this->assertContains('marked “Fit grips under every lounge rug” done', $whats);
        $failed = $entries->firstWhere('what', 'checked “Fit grips under every lounge rug”: didn\'t work');
        $this->assertSame(['check', 'failed', $checker->name], [$failed['step'], $failed['kind'], $failed['who']]);
        $owned = $entries->firstWhere('kind', 'owner');
        $this->assertSame('Jordan is on leave until 16 Nov.', $owned['detail']['why']);
        $edit = $entries->firstWhere('kind', 'edit');
        $this->assertSame(['The check found no grip underneath.', 'The rug edge lifts.', 'The rug has no grip underneath.'],
            [$edit['detail']['why'], $edit['detail']['before'], $edit['detail']['after']]);
        $this->assertSame($entries->pluck('at')->sortDesc()->values()->all(), $entries->pluck('at')->all(), 'Newest first.');
    }

    public function test_story_readers_and_people_without_hs_access_get_no_activity_log(): void
    {
        $event = HsEvent::factory()->create(['site_id' => $this->site->id]);
        $incident = ClientIncident::factory()->submitted()->atSite($this->site)->create([
            'client_id' => Client::factory()->create(['site_id' => $this->site->id])->id, 'type' => 'fall', 'hs_event_id' => $event->id,
        ]);
        $event->forceFill(['source_type' => ClientIncident::class, 'source_id' => $incident->id])->save();

        $this->actingAs($this->roleUser('team_lead'))->getJson("/health-safety/events/{$event->id}/activity")->assertNotFound();
        $this->actingAs($this->roleUser('support_worker'))->getJson("/health-safety/events/{$event->id}/activity")->assertForbidden();
    }

    private function roleUser(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $roleName)->firstOrFail()->id]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'position_role' => $roleName, 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true,
        ]);

        return $user->fresh();
    }
}
