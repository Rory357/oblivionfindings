<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationError;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\TimelineCommentLike;
use App\Models\TimelineEvent;
use App\Models\TimelineEventComment;
use App\Models\TimelineEventReaction;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicationTimelineProjectionVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_staff_client_profile_and_shift_timelines_apply_exact_controlled_and_canonical_scope(): void
    {
        $context = $this->context();
        $basePermissions = [
            'timeline.viewAny',
            'timeline.create',
            'clients.viewAny',
            'calendar.viewAny',
            'shifts.viewAny',
            'shifts.manageAny',
        ];
        $withoutMedication = $this->userWithPermissions($basePermissions, $context['site']);
        $ordinary = $this->userWithPermissions([
            ...$basePermissions,
            'medications.view',
        ], $context['site']);
        $controlled = $this->userWithPermissions([
            ...$basePermissions,
            'medications.view',
            'medications.controlled.view',
        ], $context['site']);

        $this->assertTimelineIds(
            $withoutMedication,
            route('timeline.staff', $context['actor']),
            [$context['ordinary_event']->id],
        );
        $this->assertTimelineIds(
            $ordinary,
            route('timeline.staff', $context['actor']),
            [
                $context['ordinary_event']->id,
                $context['ordinary_medication_event']->id,
                $context['ordinary_incident_event']->id,
            ],
        );
        $this->assertTimelineIds(
            $controlled,
            route('timeline.staff', $context['actor']),
            [
                $context['ordinary_event']->id,
                $context['ordinary_medication_event']->id,
                $context['controlled_medication_event']->id,
                $context['ordinary_incident_event']->id,
                $context['controlled_incident_event']->id,
            ],
        );

        $this->assertTimelineIds(
            $ordinary,
            route('timeline.client', $context['client']),
            [
                $context['shift_event']->id,
                $context['ordinary_event']->id,
                $context['ordinary_medication_event']->id,
                $context['ordinary_incident_event']->id,
            ],
        );
        $this->assertTimelineIds(
            $controlled,
            route('timeline.client', $context['client']),
            [
                $context['shift_event']->id,
                $context['ordinary_event']->id,
                $context['ordinary_medication_event']->id,
                $context['controlled_medication_event']->id,
                $context['ordinary_incident_event']->id,
                $context['controlled_incident_event']->id,
            ],
        );

        $ordinaryShift = $this->actingAs($ordinary)
            ->get(route('operations.shifts.show', $context['shift']))
            ->assertOk();
        $this->assertEqualsCanonicalizing(
            [
                $context['shift_event']->id,
                $context['ordinary_event']->id,
                $context['ordinary_medication_event']->id,
                $context['ordinary_incident_event']->id,
            ],
            collect($ordinaryShift->inertiaProps('auditTimeline'))->pluck('id')->all(),
        );
        $controlledShift = $this->actingAs($controlled)
            ->get(route('operations.shifts.show', $context['shift']))
            ->assertOk();
        $this->assertEqualsCanonicalizing(
            [
                $context['shift_event']->id,
                $context['ordinary_event']->id,
                $context['ordinary_medication_event']->id,
                $context['controlled_medication_event']->id,
                $context['ordinary_incident_event']->id,
                $context['controlled_incident_event']->id,
            ],
            collect($controlledShift->inertiaProps('auditTimeline'))->pluck('id')->all(),
        );

        foreach ([$ordinaryShift, $controlledShift] as $response) {
            $payload = json_encode($response->inertiaProps('auditTimeline'), JSON_THROW_ON_ERROR);
            foreach ($context['forged_sentinels'] as $sentinel) {
                $this->assertStringNotContainsString($sentinel, $payload);
            }
        }

        $ordinaryProfile = $this->actingAs($ordinary)
            ->get(route('operations.clients.show', $context['client']))
            ->assertOk();
        $this->assertEqualsCanonicalizing(
            [
                $context['shift_event']->id,
                $context['ordinary_event']->id,
                $context['ordinary_medication_event']->id,
                $context['ordinary_incident_event']->id,
            ],
            collect($ordinaryProfile->inertiaProps('events'))->pluck('id')->all(),
        );
        $this->assertSame(1, $ordinaryProfile->inertiaProps('emar_summary.active_medications_count'));
        $this->assertSame(
            $context['ordinary_administration']->administered_at->toDateTimeString(),
            $ordinaryProfile->inertiaProps('emar_summary.last_administration'),
        );
        $this->assertEqualsCanonicalizing(
            [
                $context['ordinary_medication']->id,
                $context['unverified_medication']->id,
                $context['superseded_medication']->id,
            ],
            collect($ordinaryProfile->inertiaProps('medical.medications'))->pluck('id')->all(),
        );
        $ordinaryCalendarIds = collect($ordinaryProfile->inertiaProps('calendar_events'))->pluck('id');
        $this->assertTrue($ordinaryCalendarIds->contains('med-'.$context['ordinary_administration']->id));
        $this->assertFalse($ordinaryCalendarIds->contains('med-'.$context['controlled_administration']->id));
        $this->assertFalse($ordinaryCalendarIds->contains('med-'.$context['forged_administration']->id));
        $this->assertFalse($ordinaryCalendarIds->contains(
            fn (string $id): bool => str_starts_with($id, 'medsched-'.$context['unverified_medication']->id.'-'),
        ));
        $this->assertFalse($ordinaryCalendarIds->contains(
            fn (string $id): bool => str_starts_with($id, 'medsched-'.$context['superseded_medication']->id.'-'),
        ));

        $controlledProfile = $this->actingAs($controlled)
            ->get(route('operations.clients.show', $context['client']))
            ->assertOk();
        $this->assertEqualsCanonicalizing(
            [
                $context['shift_event']->id,
                $context['ordinary_event']->id,
                $context['ordinary_medication_event']->id,
                $context['controlled_medication_event']->id,
                $context['ordinary_incident_event']->id,
                $context['controlled_incident_event']->id,
            ],
            collect($controlledProfile->inertiaProps('events'))->pluck('id')->all(),
        );
        $this->assertSame(2, $controlledProfile->inertiaProps('emar_summary.active_medications_count'));
        $this->assertSame(
            $context['controlled_administration']->administered_at->toDateTimeString(),
            $controlledProfile->inertiaProps('emar_summary.last_administration'),
        );
        $this->assertEqualsCanonicalizing(
            [
                $context['ordinary_medication']->id,
                $context['controlled_medication']->id,
                $context['unverified_medication']->id,
                $context['superseded_medication']->id,
            ],
            collect($controlledProfile->inertiaProps('medical.medications'))->pluck('id')->all(),
        );
        $controlledCalendarIds = collect($controlledProfile->inertiaProps('calendar_events'))->pluck('id');
        $this->assertTrue($controlledCalendarIds->contains('med-'.$context['ordinary_administration']->id));
        $this->assertTrue($controlledCalendarIds->contains('med-'.$context['controlled_administration']->id));
        $this->assertFalse($controlledCalendarIds->contains('med-'.$context['forged_administration']->id));
        $this->assertFalse($controlledCalendarIds->contains(
            fn (string $id): bool => str_starts_with($id, 'medsched-'.$context['unverified_medication']->id.'-'),
        ));
        $this->assertFalse($controlledCalendarIds->contains(
            fn (string $id): bool => str_starts_with($id, 'medsched-'.$context['superseded_medication']->id.'-'),
        ));
    }

    public function test_timeline_interactions_conceal_controlled_and_malformed_medication_events_before_mutation(): void
    {
        $context = $this->context();
        $permissions = [
            'timeline.viewAny',
            'timeline.create',
            'clients.viewAny',
        ];
        $withoutMedication = $this->userWithPermissions($permissions, $context['site']);
        $ordinary = $this->userWithPermissions([
            ...$permissions,
            'medications.view',
        ], $context['site']);
        $controlled = $this->userWithPermissions([
            ...$permissions,
            'medications.view',
            'medications.controlled.view',
        ], $context['site']);

        $protectedComment = TimelineEventComment::query()->create([
            'timeline_event_id' => $context['controlled_medication_event']->id,
            'user_id' => $controlled->id,
            'body' => 'Protected controlled medication comment',
        ]);
        $malformedComment = TimelineEventComment::query()->create([
            'timeline_event_id' => $context['missing_source_event']->id,
            'user_id' => $controlled->id,
            'body' => 'Malformed medication comment',
        ]);

        $this->actingAs($withoutMedication)
            ->post(route('timeline.comments.store', [
                'client' => $context['client'],
                'timelineEvent' => $context['ordinary_medication_event'],
            ]), ['body' => 'Must stay concealed'])
            ->assertNotFound();
        $this->assertDatabaseMissing('timeline_event_comments', ['body' => 'Must stay concealed']);
        $this->actingAs($withoutMedication)
            ->post(route('timeline.comments.store', [
                'client' => $context['client'],
                'timelineEvent' => $context['ordinary_incident_event'],
            ]), ['body' => 'Medication incident without module access'])
            ->assertNotFound();

        $this->actingAs($ordinary)
            ->post(route('timeline.comments.store', [
                'client' => $context['client'],
                'timelineEvent' => $context['controlled_medication_event'],
            ]), ['body' => 'Unauthorized controlled comment'])
            ->assertNotFound();
        $this->actingAs($ordinary)
            ->post(route('timeline.react', [
                'client' => $context['client'],
                'timelineEvent' => $context['controlled_medication_event'],
            ]), ['emoji' => '👍'])
            ->assertNotFound();
        $this->actingAs($ordinary)
            ->post(route('timeline.comments.like', [
                'client' => $context['client'],
                'timelineEventComment' => $protectedComment,
            ]))
            ->assertNotFound();
        $this->actingAs($ordinary)
            ->delete(route('timeline.comments.destroy', [
                'client' => $context['client'],
                'timelineEventComment' => $protectedComment,
            ]))
            ->assertNotFound();
        $this->assertDatabaseMissing('timeline_event_comments', ['body' => 'Unauthorized controlled comment']);
        $this->assertDatabaseMissing('timeline_event_reactions', [
            'timeline_event_id' => $context['controlled_medication_event']->id,
            'user_id' => $ordinary->id,
        ]);
        $this->assertDatabaseMissing('timeline_comment_likes', [
            'comment_id' => $protectedComment->id,
            'user_id' => $ordinary->id,
        ]);
        $this->assertDatabaseHas('timeline_event_comments', ['id' => $protectedComment->id]);

        $this->actingAs($ordinary)
            ->post(route('timeline.comments.store', [
                'client' => $context['client'],
                'timelineEvent' => $context['ordinary_medication_event'],
            ]), ['body' => 'Authorized ordinary medication comment'])
            ->assertRedirect();
        $this->assertDatabaseHas('timeline_event_comments', [
            'timeline_event_id' => $context['ordinary_medication_event']->id,
            'user_id' => $ordinary->id,
            'body' => 'Authorized ordinary medication comment',
        ]);
        $this->actingAs($ordinary)
            ->post(route('timeline.comments.store', [
                'client' => $context['client'],
                'timelineEvent' => $context['ordinary_incident_event'],
            ]), ['body' => 'Authorized ordinary medication incident comment'])
            ->assertRedirect();
        $this->actingAs($ordinary)
            ->post(route('timeline.comments.store', [
                'client' => $context['client'],
                'timelineEvent' => $context['controlled_incident_event'],
            ]), ['body' => 'Unauthorized controlled medication incident comment'])
            ->assertNotFound();

        $this->actingAs($controlled)
            ->post(route('timeline.comments.store', [
                'client' => $context['client'],
                'timelineEvent' => $context['controlled_medication_event'],
            ]), ['body' => 'Authorized controlled medication comment'])
            ->assertRedirect();
        $this->actingAs($controlled)
            ->post(route('timeline.comments.store', [
                'client' => $context['client'],
                'timelineEvent' => $context['controlled_incident_event'],
            ]), ['body' => 'Authorized controlled medication incident comment'])
            ->assertRedirect();
        $this->actingAs($controlled)
            ->post(route('timeline.react', [
                'client' => $context['client'],
                'timelineEvent' => $context['controlled_medication_event'],
            ]), ['emoji' => '👍'])
            ->assertRedirect();
        $this->actingAs($controlled)
            ->post(route('timeline.comments.like', [
                'client' => $context['client'],
                'timelineEventComment' => $protectedComment,
            ]))
            ->assertRedirect();
        $this->assertDatabaseHas('timeline_event_reactions', [
            'timeline_event_id' => $context['controlled_medication_event']->id,
            'user_id' => $controlled->id,
            'emoji' => '👍',
        ]);
        $this->assertDatabaseHas('timeline_comment_likes', [
            'comment_id' => $protectedComment->id,
            'user_id' => $controlled->id,
        ]);
        $this->actingAs($controlled)
            ->delete(route('timeline.comments.destroy', [
                'client' => $context['client'],
                'timelineEventComment' => $protectedComment,
            ]))
            ->assertRedirect();
        $this->assertDatabaseMissing('timeline_event_comments', ['id' => $protectedComment->id]);

        $this->actingAs($controlled)
            ->post(route('timeline.comments.store', [
                'client' => $context['client'],
                'timelineEvent' => $context['missing_source_event'],
            ]), ['body' => 'Malformed source mutation'])
            ->assertNotFound();
        $this->actingAs($controlled)
            ->post(route('timeline.comments.store', [
                'client' => $context['client'],
                'timelineEvent' => $context['malformed_incident_event'],
            ]), ['body' => 'Malformed incident ownership mutation'])
            ->assertNotFound();
        $this->actingAs($controlled)
            ->post(route('timeline.react', [
                'client' => $context['client'],
                'timelineEvent' => $context['missing_source_event'],
            ]), ['emoji' => '👍'])
            ->assertNotFound();
        $this->actingAs($controlled)
            ->post(route('timeline.comments.like', [
                'client' => $context['client'],
                'timelineEventComment' => $malformedComment,
            ]))
            ->assertNotFound();
        $this->actingAs($controlled)
            ->delete(route('timeline.comments.destroy', [
                'client' => $context['client'],
                'timelineEventComment' => $malformedComment,
            ]))
            ->assertNotFound();
        $this->assertDatabaseMissing('timeline_event_comments', ['body' => 'Malformed source mutation']);
        $this->assertDatabaseMissing('timeline_event_reactions', [
            'timeline_event_id' => $context['missing_source_event']->id,
        ]);
        $this->assertDatabaseMissing('timeline_comment_likes', [
            'comment_id' => $malformedComment->id,
        ]);
        $this->assertDatabaseHas('timeline_event_comments', ['id' => $malformedComment->id]);

        foreach ([$context['forged_client_event'], $context['forged_site_event']] as $forgedEvent) {
            $this->actingAs($controlled)
                ->post(route('timeline.comments.store', [
                    'client' => $context['client'],
                    'timelineEvent' => $forgedEvent,
                ]), ['body' => 'Forged ownership mutation '.$forgedEvent->id])
                ->assertNotFound();
        }

        $this->assertSame(0, TimelineCommentLike::query()
            ->where('user_id', $ordinary->id)
            ->count());
        $this->assertSame(0, TimelineEventReaction::query()
            ->where('user_id', $ordinary->id)
            ->where('timeline_event_id', $context['controlled_medication_event']->id)
            ->count());
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        $site = Site::factory()->create();
        $foreignSite = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
        $actor = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [$foreignSite->id],
            'is_active' => true,
            'start_date' => today()->subDay(),
            'end_date' => null,
        ]);
        $ordinaryMedication = $this->medication($client, 'Ordinary timeline medicine');
        $controlledMedication = $this->medication($client, 'Controlled timeline medicine', true);
        $unverifiedMedication = $this->medication($client, 'Unverified timeline medicine');
        $unverifiedMedication->forceFill(['approval_status' => 'pending_verification'])->saveQuietly();
        $supersededMedication = $this->medication($client, 'Superseded timeline medicine');
        $supersededMedication->forceFill(['superseded_by' => $ordinaryMedication->id])->saveQuietly();
        $foreignMedication = $this->medication($foreignClient, 'Foreign timeline medicine', true);
        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'user_id' => $actor->id,
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(5),
            'status' => 'scheduled',
        ]);
        $foreignShift = Shift::factory()->create([
            'client_id' => $foreignClient->id,
            'site_id' => $foreignSite->id,
            'user_id' => $actor->id,
        ]);
        $shiftEvent = TimelineEvent::query()
            ->where('source_type', Shift::class)
            ->where('source_id', $shift->id)
            ->where('type', 'shift')
            ->sole();
        $ordinaryAdministration = $this->administration(
            $client,
            $ordinaryMedication,
            $actor,
            $shift,
            now()->subHour(),
        );
        $controlledAdministration = $this->administration(
            $client,
            $controlledMedication,
            $actor,
            $shift,
            now(),
        );
        $foreignAdministration = $this->administration(
            $foreignClient,
            $foreignMedication,
            $actor,
            $foreignShift,
            now(),
        );
        $forgedAdministration = $this->administration(
            $client,
            $foreignMedication,
            $actor,
            $shift,
            now()->addMinute(),
        );
        $ordinaryIncident = $this->medicationIncident(
            $client,
            $site,
            $shift,
            $actor,
            'Ordinary medication incident projection',
        );
        $this->medicationError($client, $ordinaryMedication, $ordinaryIncident, $actor);
        $controlledIncident = $this->medicationIncident(
            $client,
            $site,
            $shift,
            $actor,
            'Controlled medication incident projection',
        );
        $this->medicationError($client, $controlledMedication, $controlledIncident, $actor);
        $malformedIncident = $this->medicationIncident(
            $client,
            $site,
            $shift,
            $actor,
            'MALFORMED MEDICATION INCIDENT PROJECTION',
        );
        $this->medicationError($client, $foreignMedication, $malformedIncident, $actor);
        $ordinaryIncidentEvent = $this->incidentEvent($ordinaryIncident);
        $controlledIncidentEvent = $this->incidentEvent($controlledIncident);
        $malformedIncidentEvent = $this->incidentEvent($malformedIncident);

        $ordinaryEvent = $this->event([
            'actor_user_id' => $actor->id,
            'client_id' => $client->id,
            'shift_id' => $shift->id,
            'site_id' => $site->id,
            'type' => 'note',
            'subject' => 'Ordinary care note',
        ]);
        $ordinaryMedicationEvent = $this->medicationEvent(
            $ordinaryAdministration,
            $actor,
            $client,
            $shift,
            $site,
            'Ordinary medication timeline event',
        );
        $controlledMedicationEvent = $this->medicationEvent(
            $controlledAdministration,
            $actor,
            $client,
            $shift,
            $site,
            'Controlled medication timeline event',
        );
        $foreignEvent = $this->medicationEvent(
            $foreignAdministration,
            $actor,
            $foreignClient,
            $foreignShift,
            $foreignSite,
            'FOREIGN MEDICATION EVENT',
        );
        $forgedClientEvent = $this->medicationEvent(
            $forgedAdministration,
            $actor,
            $client,
            $shift,
            $site,
            'FORGED CROSS CLIENT MEDICATION EVENT',
        );
        $forgedSiteEvent = $this->event([
            'source_type' => $ordinaryAdministration->getMorphClass(),
            'source_id' => $ordinaryAdministration->id,
            'actor_user_id' => $actor->id,
            'client_id' => $client->id,
            'shift_id' => $shift->id,
            'site_id' => $foreignSite->id,
            'type' => 'medication_forged_site',
            'subject' => 'FORGED SITE MEDICATION EVENT',
        ]);
        $missingSourceEvent = $this->event([
            'actor_user_id' => $actor->id,
            'client_id' => $client->id,
            'shift_id' => $shift->id,
            'site_id' => $site->id,
            'type' => 'medication_missing_source',
            'subject' => 'MISSING SOURCE MEDICATION EVENT',
        ]);

        return [
            'site' => $site,
            'client' => $client,
            'actor' => $actor,
            'shift' => $shift,
            'shift_event' => $shiftEvent,
            'ordinary_medication' => $ordinaryMedication,
            'controlled_medication' => $controlledMedication,
            'unverified_medication' => $unverifiedMedication,
            'superseded_medication' => $supersededMedication,
            'ordinary_administration' => $ordinaryAdministration,
            'controlled_administration' => $controlledAdministration,
            'forged_administration' => $forgedAdministration,
            'ordinary_event' => $ordinaryEvent,
            'ordinary_medication_event' => $ordinaryMedicationEvent,
            'controlled_medication_event' => $controlledMedicationEvent,
            'ordinary_incident_event' => $ordinaryIncidentEvent,
            'controlled_incident_event' => $controlledIncidentEvent,
            'malformed_incident_event' => $malformedIncidentEvent,
            'forged_client_event' => $forgedClientEvent,
            'forged_site_event' => $forgedSiteEvent,
            'missing_source_event' => $missingSourceEvent,
            'forged_sentinels' => [
                $foreignEvent->subject,
                $forgedClientEvent->subject,
                $forgedSiteEvent->subject,
                $missingSourceEvent->subject,
                $malformedIncidentEvent->subject,
            ],
        ];
    }

    private function medicationIncident(
        Client $client,
        Site $site,
        Shift $shift,
        User $actor,
        string $title,
    ): ClientIncident {
        return ClientIncident::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'shift_id' => $shift->id,
            'reported_by' => $actor->id,
            'type' => 'medication_error',
            'title' => $title,
            'description' => $title,
            'occurred_at' => now(),
        ]);
    }

    private function medicationError(
        Client $client,
        ClientMedication $medication,
        ClientIncident $incident,
        User $actor,
    ): MedicationError {
        return MedicationError::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'client_incident_id' => $incident->id,
            'error_type' => 'wrong_dose',
            'severity' => 'minor',
            'description' => 'Focused medication incident projection proof',
            'reported_by' => $actor->id,
            'reported_at' => now(),
            'status' => 'reported',
        ]);
    }

    private function incidentEvent(ClientIncident $incident): TimelineEvent
    {
        return TimelineEvent::query()
            ->where('source_type', $incident->getMorphClass())
            ->where('source_id', $incident->id)
            ->where('type', 'incident')
            ->sole();
    }

    private function medication(Client $client, string $name, bool $controlled = false): ClientMedication
    {
        return ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => $name,
            'controlled_drug' => $controlled,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'is_prn' => false,
            'dose_times' => ['09:00'],
            'frequency' => '09:00',
            'start_date' => today()->subDay(),
            'end_date' => null,
        ]);
    }

    private function administration(
        Client $client,
        ClientMedication $medication,
        User $actor,
        Shift $shift,
        \DateTimeInterface $administeredAt,
    ): ClientMedicationAdministration {
        return ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'shift_id' => $shift->id,
            'service_context_id' => $client->service_context_id,
            'administered_by' => $actor->id,
            'scheduled_for' => $administeredAt,
            'administered_at' => $administeredAt,
            'status' => 'missed',
            'dose_given' => '1 tablet',
        ]);
    }

    private function medicationEvent(
        ClientMedicationAdministration $administration,
        User $actor,
        Client $client,
        Shift $shift,
        Site $site,
        string $subject,
    ): TimelineEvent {
        return $this->event([
            'source_type' => $administration->getMorphClass(),
            'source_id' => $administration->id,
            'actor_user_id' => $actor->id,
            'client_id' => $client->id,
            'shift_id' => $shift->id,
            'site_id' => $site->id,
            'type' => 'medication_missed',
            'subject' => $subject,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function event(array $attributes): TimelineEvent
    {
        return TimelineEvent::query()->create([
            'occurred_at' => now(),
            'body' => 'Focused projection proof',
            'visibility' => 'internal',
            ...$attributes,
        ]);
    }

    /** @param array<int, int> $expectedIds */
    private function assertTimelineIds(User $viewer, string $uri, array $expectedIds): void
    {
        $response = $this->actingAs($viewer)->get($uri)->assertOk();
        $this->assertEqualsCanonicalizing(
            $expectedIds,
            collect($response->inertiaProps('events'))->pluck('id')->all(),
        );
    }

    /** @param array<int, string> $permissions */
    private function userWithPermissions(array $permissions, Site $site): User
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subDay(),
            'end_date' => null,
        ]);
        $permissionIds = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $this->assertCount(count($permissions), $permissionIds);
        $user->permissionOverrides()->sync(
            $permissionIds->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all(),
        );

        return $user->fresh();
    }
}
