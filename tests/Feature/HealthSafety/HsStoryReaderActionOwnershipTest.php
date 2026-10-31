<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsCorrectiveActionOwnershipService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Answer 23 (Stephan, 1 Nov 2026): on a safety review that comes from an incident, a team lead who reads only the story
 * (Q1) may own an action, start it and finish it — and nothing more: not someone else's action, not on restricted
 * incidents, never checking an action, never the review itself.
 */
class HsStoryReaderActionOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->lead = $this->roleUser('team_lead');
    }

    public function test_a_story_reading_team_lead_can_own_start_and_finish_their_action(): void
    {
        $event = $this->incidentReview();
        $action = $this->action($event, $this->lead);
        $this->assertTrue(app(HsCorrectiveActionOwnershipService::class)->eligible($this->lead, $event));

        $base = "/health-safety/events/{$event->id}/corrective-actions/{$action->id}";
        $this->actingAs($this->lead)->post("{$base}/start")->assertSessionHas('success');
        $this->assertSame(HsCorrectiveAction::STATUS_IN_PROGRESS, $action->fresh()->status);
        $this->actingAs($this->lead)->post("{$base}/complete", ['completion_notes' => 'Grab rail fitted beside the shower.'])->assertSessionHas('success');
        $this->assertSame(HsCorrectiveAction::STATUS_COMPLETED, $action->fresh()->status);
        $this->assertSame($this->lead->id, $action->fresh()->completed_by_user_id);
    }

    public function test_never_someone_elses_action_and_never_a_check(): void
    {
        $event = $this->incidentReview();
        $theirs = $this->action($event, $this->roleUser('health_safety_officer'));
        $base = "/health-safety/events/{$event->id}/corrective-actions/{$theirs->id}";

        $this->actingAs($this->lead)->post("{$base}/start")->assertNotFound();
        $this->assertSame(HsCorrectiveAction::STATUS_OPEN, $theirs->fresh()->status);

        $theirs->forceFill(['status' => HsCorrectiveAction::STATUS_COMPLETED, 'completed_at' => now(), 'completion_notes' => 'Done.'])->save();
        $this->actingAs($this->lead)
            ->post("{$base}/verify", ['evidence_reviewed' => true, 'effectiveness_confirmed' => true])
            ->assertNotFound();
        $this->assertSame(HsCorrectiveAction::STATUS_COMPLETED, $theirs->fresh()->status);
    }

    public function test_never_on_a_restricted_incident(): void
    {
        $event = $this->incidentReview(['is_hr_confidential' => true]);
        $action = $this->action($event, $this->lead);
        $this->assertFalse(app(HsCorrectiveActionOwnershipService::class)->eligible($this->lead, $event));

        $this->actingAs($this->lead)
            ->post("/health-safety/events/{$event->id}/corrective-actions/{$action->id}/start")
            ->assertNotFound();
        $this->assertSame(HsCorrectiveAction::STATUS_OPEN, $action->fresh()->status);
    }

    private function incidentReview(array $incident = []): HsEvent
    {
        $event = HsEvent::factory()->create(['site_id' => $this->site->id, 'status' => HsEvent::STATUS_CORRECTIVE_ACTION]);
        $record = ClientIncident::factory()->submitted()->atSite($this->site)->create([
            'client_id' => Client::factory()->create(['site_id' => $this->site->id])->id,
            'type' => 'fall',
            'hs_event_id' => $event->id,
            ...$incident,
        ]);
        $event->forceFill(['source_type' => ClientIncident::class, 'source_id' => $record->id])->save();

        return $event->fresh();
    }

    private function action(HsEvent $event, User $owner): HsCorrectiveAction
    {
        $creator = User::factory()->create();

        return HsCorrectiveAction::factory()->create([
            'hs_event_id' => $event->id,
            'status' => HsCorrectiveAction::STATUS_OPEN,
            'priority' => HsCorrectiveAction::PRIORITY_MEDIUM,
            'due_date' => now()->addWeek()->toDateString(),
            'assigned_to_user_id' => $owner->id,
            'created_by' => $creator->id,
        ]);
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
