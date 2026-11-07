<?php

namespace Tests\Feature\Incidents;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsCorrectiveActionService;
use App\Services\HealthSafety\HsEventClosureService;
use App\Services\Incidents\IncidentJourneyService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentJourneyStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_a_missing_response_does_not_claim_live_response_or_complete_work(): void
    {
        $journeys = app(IncidentJourneyService::class);
        $this->assertSame('Incident review pending', $journeys->journeyState(new ClientIncident(['status' => 'submitted']), null, null));
        $this->assertSame('Incident draft', $journeys->journeyState(new ClientIncident(['status' => 'draft']), null, null));
        $this->assertSame('Journey needs review', $journeys->journeyState(null, null, null));
        $this->assertSame('Journey needs review', $journeys->journeyState(null, new ControlRoomAlert(['status' => 'unknown_legacy']), null));
        $this->assertSame('Operational response active', $journeys->journeyState(null, new ControlRoomAlert(['status' => 'open']), null));
    }

    public function test_it_reports_each_cross_module_journey_state_from_canonical_records(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create([
            'site_id' => $site->id,
        ]);
        $owner = $this->siteBoundUser($site, ['healthSafety.events.close']);
        $acceptor = User::factory()->create();
        $verifier = $this->siteBoundUser($site, ['hazards.manage']);
        $alert = ControlRoomAlert::factory()->triaging()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'severity' => 'low',
        ]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'control_room_alert_id' => $alert->id,
            'type' => 'fall',
            'severity' => 'low',
        ]));
        $hsEvent = HsEvent::factory()
            ->forClientIncident($incident)
            ->awaitingHandoverAcceptance($owner)
            ->worksafeNotNotifiable($owner)
            ->create([
                'control_room_alert_id' => $alert->id,
                'site_id' => $site->id,
                'client_id' => $client->id,
                'severity' => HsEvent::SEVERITY_LOW,
            ]);
        $journeys = app(IncidentJourneyService::class);

        $this->assertSame(
            'Operational response active',
            $journeys->journeyState($incident, $alert, $hsEvent),
        );

        $alert->forceFill([
            'status' => ControlRoomAlert::STATUS_RESOLVED,
            'resolved_at' => now(),
        ])->save();
        $this->assertSame(
            'Operationally resolved',
            $journeys->journeyState($incident, $alert->fresh(), $hsEvent),
        );

        $incident->forceFill([
            'status' => 'reviewed',
            'reviewed_at' => now(),
            'reviewed_by' => $owner->id,
        ])->save();
        $this->assertSame(
            'Incident review complete',
            $journeys->journeyState($incident->fresh(), $alert->fresh(), null),
        );
        $this->assertSame(
            'H&S acceptance pending',
            $journeys->journeyState($incident->fresh(), $alert->fresh(), $hsEvent),
        );

        $hsEvent->refresh()->forceFill([
            'handover_status' => HsEvent::HANDOVER_ACCEPTED,
            'accepted_by_user_id' => $acceptor->id,
            'accepted_at' => now(),
            'owner_user_id' => $owner->id,
        ])->save();
        $this->assertSame(
            'H&S governance active',
            $journeys->journeyState($incident->fresh(), $alert->fresh(), $hsEvent->fresh()),
        );

        $action = HsCorrectiveAction::factory()->completed()->create([
            'hs_event_id' => $hsEvent->id,
            'assigned_to_user_id' => $owner->id,
            'completed_by_user_id' => $owner->id,
            'created_by' => $owner->id,
        ]);
        $this->assertSame(
            'Awaiting independent verification',
            $journeys->journeyState($incident->fresh(), $alert->fresh(), $hsEvent->fresh()),
        );

        $this->actingAs($verifier);
        $verified = app(HsCorrectiveActionService::class)->verify($action, [
            'verified_by_user_id' => $verifier->id,
            'evidence_reviewed' => true,
            'effectiveness_confirmed' => true,
            'verification_notes' => 'Independent review confirmed the recorded completion evidence.',
        ]);
        $this->assertSame(HsCorrectiveAction::STATUS_VERIFIED, $verified->status);
        $this->assertSame($verifier->id, $verified->verified_by_user_id);
        $closure = app(HsEventClosureService::class);
        $readyEvent = $hsEvent->fresh();
        $this->assertFalse($readyEvent->investigation_required);
        $readiness = $closure->readiness($readyEvent);
        $this->assertSame([], array_column($readiness->blockers(), 'key'));
        $this->assertTrue($readiness->ordinaryAllowed());

        $this->actingAs($owner);
        $hsEvent = $closure->closeEvent($hsEvent, 'Governance work completed.', $owner);
        $this->assertSame(HsEvent::STATUS_CLOSED, $hsEvent->status);
        $this->assertSame($owner->id, $hsEvent->closed_by);
        $this->assertSame(
            'Governance closed',
            $journeys->journeyState($incident->fresh(), $alert->fresh(), $hsEvent->fresh()),
        );

        $incident->forceFill(['status' => 'closed'])->save();
        $alert->forceFill([
            'status' => ControlRoomAlert::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by_user_id' => $owner->id,
        ])->save();
        $this->assertSame(
            'Journey closed',
            $journeys->journeyState($incident->fresh(), $alert->fresh(), $hsEvent->fresh()),
        );
    }

    public function test_standalone_terminal_alerts_never_report_an_active_response(): void
    {
        $journeys = app(IncidentJourneyService::class);
        $closed = ControlRoomAlert::factory()->closed()->create();
        $dismissed = ControlRoomAlert::factory()->create([
            'status' => ControlRoomAlert::STATUS_DISMISSED,
        ]);

        $this->assertSame('Journey closed', $journeys->journeyState(null, $closed, null));
        $this->assertSame('Journey closed', $journeys->journeyState(null, $dismissed, null));
    }

    /**
     * @param  list<string>  $permissionKeys
     */
    private function siteBoundUser(Site $site, array $permissionKeys): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        $permissionIds = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
        $user->permissionOverrides()->sync($permissionIds->mapWithKeys(
            fn ($permissionId) => [$permissionId => ['allowed' => true]],
        ));
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $user;
    }
}
