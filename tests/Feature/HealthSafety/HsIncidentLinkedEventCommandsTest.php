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
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Most H&S events come from incident reports. Their source rule (incident read) is only applied inside the H&S read
 * context; without it the ported scope refuses them outright, which hid them from both registers and refused every
 * command on them. These cases keep both registers and the event commands on that read.
 */
final class HsIncidentLinkedEventCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_both_registers_list_incident_linked_events_and_their_actions_for_an_incident_reader(): void
    {
        [$site, $officer] = $this->officerAtSite();
        $event = $this->incidentLinkedEvent($site, $officer);
        $action = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id]);
        $this->assertTrue($officer->canDo('incidents.viewAny'));

        $this->actingAs($officer)->get('/health-safety/events')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tabCounts.all', 1)
                ->where('events.data.0.id', $event->id)
                ->where('hero.attention.handover_due', 1));

        $this->actingAs($officer)->get('/health-safety/corrective-actions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tabCounts.all', 1)
                ->where('actions.data.0.id', $action->id));

        $this->actingAs($officer)->get('/health-safety/corrective-actions?event='.$event->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('detail.id', $event->id));
    }

    public function test_event_commands_on_an_incident_linked_event_work_for_an_incident_reader(): void
    {
        [$site, $officer] = $this->officerAtSite();
        $event = $this->incidentLinkedEvent($site, $officer);

        $this->actingAs($officer)->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/accept-handover", ['acceptance_notes' => 'Reviewed and accepted.'])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame(HsEvent::HANDOVER_ACCEPTED, $event->fresh()->handover_status);

        $this->actingAs($officer)->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/worksafe/decision", [
                'notifiable' => false,
                'reason' => 'A minor bruise treated on site; no notifiable injury or illness.',
                'source' => 'manual',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertNotNull($event->fresh()->worksafe_decided_at);

        $this->actingAs($officer)->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/investigations", [
                'methodology' => '5_whys',
                'lead_investigator_id' => $officer->id,
            ])
            ->assertSessionHas('success');
        $this->assertSame(1, HsInvestigation::query()->where('hs_event_id', $event->id)->count());

        $this->actingAs($officer)->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/corrective-actions", [
                'title' => 'Repair the back step',
                'priority' => 'high',
                'assigned_to_user_id' => $officer->id,
                'due_date' => now()->addDays(14)->toDateString(),
            ])
            ->assertSessionHas('success');
        $this->assertSame(1, HsCorrectiveAction::query()->where('hs_event_id', $event->id)->count());
    }

    public function test_an_unknown_event_is_still_not_found_for_commands(): void
    {
        [, $officer] = $this->officerAtSite();

        $this->actingAs($officer)
            ->post('/health-safety/events/999999/accept-handover', ['acceptance_notes' => 'No such event.'])
            ->assertNotFound();
        $this->actingAs($officer)
            ->post('/health-safety/events/999999/worksafe/decision', ['notifiable' => false, 'reason' => 'No such event here.', 'source' => 'manual'])
            ->assertNotFound();
    }

    /** @return array{0: Site, 1: User} */
    private function officerAtSite(): array
    {
        $site = Site::factory()->create(['is_active' => true]);
        $officer = User::factory()->create(['role' => 'health_safety_officer', 'approved_at' => now()]);
        $officer->roles()->attach(Role::query()->where('name', 'health_safety_officer')->sole());
        HrEmployeeProfile::factory()->create([
            'user_id' => $officer->id,
            'position_role' => 'health_safety_officer',
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return [$site, $officer->fresh()];
    }

    private function incidentLinkedEvent(Site $site, User $reporter): HsEvent
    {
        $client = Client::factory()->create(['site_id' => $site->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->atSite($site)->submitted()->create([
            'client_id' => $client->id,
            'reported_by' => $reporter->id,
            'submitted_at' => now(),
            'description' => 'A resident slipped on the back step.',
            'immediate_action_taken' => 'Checked the resident and dried the step.',
        ]));
        $event = HsEvent::factory()->forClientIncident($incident)->awaitingHandoverAcceptance($reporter)->worksafeUndecided()->create();
        $incident->updateQuietly(['hs_event_id' => $event->id]);

        return $event->fresh();
    }
}
