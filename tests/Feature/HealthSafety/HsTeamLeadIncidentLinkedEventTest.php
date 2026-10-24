<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientIncidentAttachment;
use App\Models\HsEvent;
use App\Models\IncidentFollowup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Stephan, 25 Oct ("event yes, incident no"): team leads have H&S manage but no incident read. They keep seeing and
 * acting on incident-linked H&S events at their Sites, as on main, with no incident narrative, files or link. Only
 * people who can read the incident see those. The incident's HR-confidential and Site rules still apply, and a
 * handover owner must be someone who can open the event.
 */
final class HsTeamLeadIncidentLinkedEventTest extends TestCase
{
    use RefreshDatabase;

    private const NARRATIVE = 'PRIVATE-INCIDENT-NARRATIVE resident fell from the bath chair';

    private const FOLLOWUP = 'PRIVATE-FOLLOWUP-NOTE call the family';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_team_lead_sees_and_acts_on_an_incident_linked_event_without_incident_content(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $lead = $this->teamLead($site);
        [$incident, $event] = $this->incidentLinkedEvent($site, $lead);
        $attachment = ClientIncidentAttachment::query()->create(['incident_id' => $incident->id, 'uploaded_by' => $lead->id,
            'disk' => 'public', 'original_name' => 'scene.txt', 'path' => 'incident_attachments/scene.txt',
            'mime' => 'text/plain', 'mime_type' => 'text/plain', 'size' => 5]);
        (new IncidentFollowup)->forceFill(['client_incident_id' => $incident->id, 'assigned_to_user_id' => $lead->id,
            'created_by' => $lead->id, 'due_at' => now()->addDay(), 'notes' => self::FOLLOWUP])->save();
        $this->assertFalse($lead->canDo('incidents.viewAny'));
        $this->assertFalse($lead->canDo('incidents.viewAssigned'));

        $this->actingAs($lead)->get('/health-safety/events')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tabCounts.all', 1)->where('events.data.0.id', $event->id));

        $detail = $this->actingAs($lead)->get('/health-safety/events/'.$event->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.id', $event->id)
                ->where('detail.source', null)
                ->where('detail.linked_access.incident', 'restricted')
                ->where('detail.incident_followups', []));
        $this->assertStringNotContainsString('PRIVATE-INCIDENT-NARRATIVE', $detail->getContent());
        $this->assertStringNotContainsString('PRIVATE-FOLLOWUP-NOTE', $detail->getContent());
        $this->assertStringNotContainsString('/incidents/'.$incident->id, $detail->getContent());

        // No incident files or incident page.
        $this->actingAs($lead)->get("/health-safety/events/{$event->id}/incident-attachments/{$attachment->id}/download")->assertForbidden();
        $this->actingAs($lead)->get('/incidents?incident='.$incident->id)->assertForbidden();

        // The H&S work itself is theirs to do.
        $this->actingAs($lead)->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/accept-handover", ['acceptance_notes' => 'Reviewed and accepted.'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame(HsEvent::HANDOVER_ACCEPTED, $event->fresh()->handover_status);
        $this->assertSame($lead->id, (int) $event->fresh()->owner_user_id);
        $this->actingAs($lead)->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/worksafe/decision", [
                'notifiable' => false, 'reason' => 'Bruise treated on site; not a notifiable injury.', 'source' => 'manual'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertNotNull($event->fresh()->worksafe_decided_at);
    }

    public function test_another_sites_team_lead_gets_nothing(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $other = Site::factory()->create(['is_active' => true]);
        $lead = $this->teamLead($other);
        [, $event] = $this->incidentLinkedEvent($site, $this->teamLead($site));

        $this->actingAs($lead)->get('/health-safety/events')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tabCounts.all', 0)->has('events.data', 0));
        $this->actingAs($lead)->get('/health-safety/events/'.$event->id)->assertNotFound();
        $this->actingAs($lead)
            ->post("/health-safety/events/{$event->id}/accept-handover", ['acceptance_notes' => 'Not my Site.'])
            ->assertNotFound();
        $this->assertSame(HsEvent::HANDOVER_AWAITING_ACCEPTANCE, $event->fresh()->handover_status);
    }

    public function test_an_hr_confidential_incidents_event_stays_hidden_without_hr_case_read(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $lead = $this->teamLead($site);
        [, $event] = $this->incidentLinkedEvent($site, $lead, ['is_hr_confidential' => true]);
        $this->assertFalse($lead->canDo('hr.cases.view'));

        $this->actingAs($lead)->get('/health-safety/events')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tabCounts.all', 0));
        $this->actingAs($lead)->get('/health-safety/events/'.$event->id)->assertNotFound();
        $this->actingAs($lead)
            ->post("/health-safety/events/{$event->id}/accept-handover", ['acceptance_notes' => 'Hidden from me.'])
            ->assertNotFound();
    }

    public function test_handover_owners_are_only_people_who_can_open_the_event(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $lead = $this->teamLead($site);
        $manager = $this->teamLead($site);
        $manager->update(['name' => 'Aroha HR-cleared manager']);
        foreach (['incidents.viewAny', 'hr.cases.view'] as $key) {
            $manager->permissionOverrides()->syncWithoutDetaching([Permission::query()->where('key', $key)->sole()->id => ['allowed' => true]]);
        }
        [, $event] = $this->incidentLinkedEvent($site, $manager, ['is_hr_confidential' => true]);

        // The HR-cleared manager can open the confidential incident's event; the team lead can't, so isn't offered.
        $this->actingAs($manager->fresh())->get('/health-safety/events/'.$event->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.assignable_staff', fn ($staff) => collect($staff)->contains('id', $manager->id)
                    && ! collect($staff)->contains('id', $lead->id)));
        $this->actingAs($manager->fresh())->from('/health-safety/events/'.$event->id)
            ->post("/health-safety/events/{$event->id}/accept-handover", ['owner_user_id' => $lead->id])
            ->assertSessionHasErrors(['owner_user_id']);
        $this->assertSame(HsEvent::HANDOVER_AWAITING_ACCEPTANCE, $event->fresh()->handover_status);

        $this->actingAs($manager->fresh())->from('/health-safety/events/'.$event->id)
            ->post("/health-safety/events/{$event->id}/accept-handover", ['owner_user_id' => $manager->id])
            ->assertSessionHas('success');
        $this->assertSame($manager->id, (int) $event->fresh()->owner_user_id);
    }

    private function teamLead(Site $site): User
    {
        $user = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', 'team_lead')->sole());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'position_role' => 'team_lead',
            'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);
        $user = $user->fresh();
        $this->assertTrue($user->canDo('hazards.manage') && $user->canDo('hazards.view'), 'team_lead holds H&S view and manage.');

        return $user;
    }

    /** @return array{0: ClientIncident, 1: HsEvent} */
    private function incidentLinkedEvent(Site $site, User $reporter, array $incidentOverrides = []): array
    {
        $client = Client::factory()->create(['site_id' => $site->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->atSite($site)->submitted()->create([
            'client_id' => $client->id, 'reported_by' => $reporter->id, 'submitted_at' => now(),
            'description' => self::NARRATIVE, 'immediate_action_taken' => 'Checked the resident and called the nurse.',
            ...$incidentOverrides,
        ]));
        $event = HsEvent::factory()->forClientIncident($incident)->awaitingHandoverAcceptance($reporter)->worksafeUndecided()->create();
        $incident->updateQuietly(['hs_event_id' => $event->id]);

        return [$incident->fresh(), $event->fresh()];
    }
}
