<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Governance\Models\NotifiableIncident;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\ReturnToWorkPlan;
use App\Models\Site;
use App\Models\WorkplaceInjury;
use App\Models\WorkplaceInjuryAttachment;
use App\Services\ControlRoom\ComprehensiveAlertBridgeService;
use App\Services\HealthSafety\WorkplaceInjuryJourneyService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Injuries & RTW — the old register cases that the workspace suites do not
 * already cover, rewritten against the keyed injury commands (UUID
 * idempotency_key, expected_version, current staff at the Site): Site
 * isolation over HTTP, the WorkSafe NotifiableIncident seam, the required
 * H&S event / Control Room journey and its rollback, lifecycle refusal and
 * the scriptable-upload guard. Every other old case maps to a newer test;
 * see docs/health-safety-build/PLAN.md.
 */
class InjuriesControllerTest extends WorkplaceInjurySourceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('private');
    }

    /** @return array<string, mixed> */
    private function notifiableOverrides(array $overrides = []): array
    {
        return array_merge([
            'injury_type' => 'fracture',
            'body_part_affected' => 'Right wrist',
            'severity' => 'serious',
            'description' => 'Fall from a ladder; wrist fracture requiring hospitalisation.',
            'medical_treatment_type' => 'hospitalisation',
            'worksafe_notifiable' => true,
        ], $overrides);
    }

    private function legacyInjury(Site $site, int $workerId, array $overrides = []): WorkplaceInjury
    {
        return WorkplaceInjury::withoutEvents(fn () => WorkplaceInjury::factory()->create(array_merge([
            'site_id' => $site->id,
            'user_id' => $workerId,
            'severity' => 'moderate',
            'status' => 'reported',
            'lost_time_days' => 0,
        ], $overrides)));
    }

    private function incidentAtSite(Site $site, ?Site $clientSite = null): ClientIncident
    {
        $client = Client::factory()->create(['site_id' => ($clientSite ?? $site)->id]);

        return ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
        ]));
    }

    private function eventFor(WorkplaceInjury $injury): HsEvent
    {
        return HsEvent::query()
            ->where('source_type', WorkplaceInjury::class)
            ->where('source_id', $injury->id)
            ->firstOrFail();
    }

    public function test_register_counts_pickers_and_detail_are_scoped_to_canonical_site_access(): void
    {
        $hiddenSite = Site::factory()->create(['is_active' => true]);
        $viewer = $this->staff(['hazards.view', 'hazards.manage', 'hazards.create']);
        $hiddenWorker = $this->staff([], $hiddenSite);
        $visible = $this->injury();
        $hidden = $this->legacyInjury($hiddenSite, $hiddenWorker->id, ['reference_number' => 'WI-HIDDEN-SITE']);
        $missingSite = $this->legacyInjury($this->site, $this->worker->id, ['site_id' => null]);

        $this->actingAs($viewer)
            ->get('/health-safety/injuries')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-safety/injuries/workspace-index')
                ->where('tabCounts.all', 1)
                ->has('injuries.data', 1)
                ->where('injuries.data.0.id', $visible->id)
                ->where('sites', fn ($sites) => collect($sites)->pluck('id')->all() === [$this->site->id]));

        // Old in-register detail bookmarks and the permanent record both conceal other Sites.
        $this->actingAs($viewer)->get('/health-safety/injuries?injury='.$hidden->id)->assertNotFound();
        $this->actingAs($viewer)->get('/health-safety/injuries?injury='.$missingSite->id)->assertNotFound();
        $this->actingAs($viewer)->getJson('/health-safety/injuries/'.$hidden->id)->assertNotFound();
        $this->actingAs($viewer)->getJson('/health-safety/injuries/'.$missingSite->id)->assertNotFound();
        $this->actingAs($viewer)->get('/health-safety/injuries?site_id='.$hiddenSite->id)->assertNotFound();
        $this->actingAs($viewer)->get('/health-safety/injuries/export?site_id='.$hiddenSite->id)->assertNotFound();

        $csv = $this->actingAs($viewer)->get('/health-safety/injuries/export')->assertOk()->streamedContent();
        $this->assertStringContainsString($visible->reference_number, $csv);
        $this->assertStringNotContainsString('WI-HIDDEN-SITE', $csv);
    }

    public function test_cross_site_injury_and_nested_direct_actions_are_denied(): void
    {
        $hiddenSite = Site::factory()->create(['is_active' => true]);
        // Medical/recovery authority is held, so every refusal below is the Site rule, not the medical gate.
        $viewer = $this->staff(['hazards.view', 'hazards.manage', 'hazards.create', 'hr.wellbeing.view']);
        $hiddenWorker = $this->staff([], $hiddenSite);
        $injury = $this->legacyInjury($hiddenSite, $hiddenWorker->id, ['lost_time_days' => 0]);
        $plan = ReturnToWorkPlan::withoutEvents(fn () => ReturnToWorkPlan::factory()->create([
            'workplace_injury_id' => $injury->id,
            'worker_id' => $hiddenWorker->id,
            'status' => 'active',
        ]));
        $attachment = WorkplaceInjuryAttachment::factory()->create([
            'workplace_injury_id' => $injury->id,
            'disk' => 'private',
            'path' => 'workplace_injury_attachments/'.$injury->id.'/hidden.pdf',
        ]);
        Storage::disk('private')->put($attachment->path, 'hidden medical evidence');

        $this->actingAs($viewer)->getJson('/health-safety/injuries/'.$injury->id)->assertNotFound();
        $this->actingAs($viewer)->putJson('/health-safety/injuries/'.$injury->id, $this->command($injury, ['lost_time_days' => 2]))->assertNotFound();
        $this->actingAs($viewer)->postJson('/health-safety/injuries/'.$injury->id.'/status',
            $this->command($injury, ['status' => 'closed', 'reason' => 'Cross-Site closure attempt']))->assertNotFound();
        $this->actingAs($viewer)->postJson('/health-safety/injuries/'.$injury->id.'/rtw-plans', $this->planPayload($injury))->assertNotFound();
        $this->actingAs($viewer)->putJson('/health-safety/injuries/rtw-plans/'.$plan->id,
            $this->command($injury, ['status' => 'completed']))->assertNotFound();
        $this->actingAs($viewer)->postJson('/health-safety/injuries/'.$injury->id.'/capacity-assessments', $this->capacityPayload($injury))->assertNotFound();
        $this->actingAs($viewer)->postJson('/health-safety/injuries/rtw-plans/'.$plan->id.'/modified-duties', $this->command($injury,
            ['start_date' => '2026-10-12', 'modified_duties_description' => 'Cross-Site duty', 'hours_per_day' => 4]))->assertNotFound();
        $this->actingAs($viewer)->postJson('/health-safety/injuries/'.$injury->id.'/attachments',
            [...$this->command($injury, ['kind' => 'medical_cert']), 'file' => UploadedFile::fake()->create('certificate.pdf', 10, 'application/pdf')])
            ->assertNotFound();
        $this->actingAs($viewer)
            ->get('/health-safety/injuries/'.$injury->id.'/attachments/'.$attachment->id.'/download')
            ->assertNotFound();
        $this->actingAs($viewer)
            ->deleteJson('/health-safety/injuries/'.$injury->id.'/attachments/'.$attachment->id, $this->command($injury, ['reason' => 'Cross-Site removal attempt']))
            ->assertNotFound();

        $injury->refresh();
        $this->assertSame(0, (int) $injury->lost_time_days);
        $this->assertSame('reported', $injury->status);
        $this->assertSame(0, (int) $injury->source_revision);
        $this->assertSame('active', $plan->fresh()->status);
        $this->assertNull($attachment->fresh()->deleted_at);
        $this->assertDatabaseCount('modified_duties', 0);
        $this->assertDatabaseCount('work_capacity_assessments', 0);
        $this->assertDatabaseCount('workplace_injury_command_receipts', 0);
    }

    public function test_store_rejects_cross_site_staff_incident_and_conflicting_client_provenance(): void
    {
        $hiddenSite = Site::factory()->create(['is_active' => true]);
        $viewer = $this->staff(['hazards.view', 'hazards.manage', 'hazards.create', 'incidents.viewAny', 'clients.viewAny']);
        $hiddenWorker = $this->staff([], $hiddenSite);
        $hiddenIncident = $this->incidentAtSite($hiddenSite);
        $conflictingIncident = $this->incidentAtSite($this->site, $hiddenSite);

        $before = WorkplaceInjury::query()->count();

        $this->actingAs($viewer)
            ->postJson('/health-safety/injuries', $this->createPayload(['user_id' => $hiddenWorker->id, 'site_id' => $hiddenSite->id]))
            ->assertNotFound();
        $this->actingAs($viewer)
            ->postJson('/health-safety/injuries', $this->createPayload(['user_id' => $hiddenWorker->id]))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->postJson('/health-safety/injuries', $this->createPayload(['related_incident_id' => $hiddenIncident->id]))
            ->assertNotFound();

        // An incident whose own Site and Client Site disagree fails closed; nothing is linked or saved.
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($viewer)
                ->postJson('/health-safety/injuries', $this->createPayload(['related_incident_id' => $conflictingIncident->id]));
            self::fail('Conflicting Client incident provenance must refuse the injury capture.');
        } catch (\LogicException $exception) {
            $this->assertSame('Client incident provenance does not converge on one Site.', $exception->getMessage());
        } finally {
            $this->withExceptionHandling();
        }

        $this->assertSame($before, WorkplaceInjury::query()->count());
        $this->assertDatabaseCount('workplace_injury_command_receipts', 0);
    }

    public function test_update_rejects_cross_site_staff_site_and_incident_provenance(): void
    {
        $hiddenSite = Site::factory()->create(['is_active' => true]);
        $viewer = $this->staff(['hazards.view', 'hazards.manage', 'incidents.viewAny', 'clients.viewAny']);
        $hiddenWorker = $this->staff([], $hiddenSite);
        $hiddenIncident = $this->incidentAtSite($hiddenSite);
        $injury = $this->injury(['severity' => 'moderate']);

        $this->actingAs($viewer)
            ->putJson('/health-safety/injuries/'.$injury->id, $this->command($injury, ['user_id' => $hiddenWorker->id]))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->putJson('/health-safety/injuries/'.$injury->id, $this->command($injury, ['site_id' => $hiddenSite->id]))
            ->assertNotFound();
        $this->actingAs($viewer)
            ->putJson('/health-safety/injuries/'.$injury->id, $this->command($injury, ['related_incident_id' => $hiddenIncident->id]))
            ->assertNotFound();

        $injury->refresh();
        $this->assertSame($this->worker->id, (int) $injury->user_id);
        $this->assertSame($this->site->id, (int) $injury->site_id);
        $this->assertNull($injury->related_incident_id);
        $this->assertSame(1, (int) $injury->source_revision);
    }

    public function test_store_creates_injury_with_derived_acc_and_incident_link(): void
    {
        $recorder = $this->staff(['hazards.view', 'hazards.create', 'hazards.manage', 'hr.wellbeing.view', 'incidents.viewAny', 'clients.viewAny']);
        $incident = $this->incidentAtSite($this->site);

        $response = $this->actingAs($recorder)
            ->postJson('/health-safety/injuries', $this->createPayload([
                'related_incident_id' => $incident->id,
                'injury_type' => 'manual_handling',
                'body_part_affected' => 'Lower back',
                'severity' => 'moderate',
                'description' => 'Strained back during a transfer.',
                'acc_claim_number' => '26/123456',
            ]))
            ->assertOk();

        $inj = WorkplaceInjury::latest('id')->first();
        $this->assertSame('reported', $inj->status);
        $this->assertSame(0, (int) $inj->lost_time_days);
        $this->assertTrue((bool) $inj->acc_claim_lodged, 'ACC lodged should derive from a captured claim number');
        $this->assertSame($incident->id, $inj->related_incident_id);
        $this->assertSame($inj->id, $response->json('injury_confirmation.injury_id'));
    }

    public function test_store_worksafe_notifiable_creates_notifiable_incident(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/health-safety/injuries', $this->createPayload($this->notifiableOverrides()))
            ->assertOk();

        $inj = WorkplaceInjury::latest('id')->first();
        $notifiable = NotifiableIncident::where('workplace_injury_id', $inj->id)->first();

        $this->assertNotNull($notifiable, 'A worksafe-notifiable injury must create a NotifiableIncident (seam 4)');
        $this->assertSame('worksafe', $notifiable->notification_authority);
        $this->assertSame('pending', $notifiable->status);
    }

    public function test_create_only_reporter_escalates_without_signing_the_canonical_worksafe_decision(): void
    {
        $this->actingAs($this->capturer)
            ->postJson('/health-safety/injuries', $this->createPayload($this->notifiableOverrides()))
            ->assertOk();

        $injury = WorkplaceInjury::query()->latest('id')->firstOrFail();
        $event = HsEvent::query()
            ->where('source_type', WorkplaceInjury::class)
            ->where('source_id', $injury->id)
            ->sole();

        $this->assertNotNull(
            NotifiableIncident::query()->where('workplace_injury_id', $injury->id)->first(),
        );
        $this->assertNull($event->worksafe_notifiable);
        $this->assertNull($event->worksafe_decided_at);
        $this->assertNull($event->worksafe_decided_by_user_id);
        $this->assertNull($event->worksafe_decision_tree_version);
        $this->assertNull($event->worksafe_source_effective_date);
    }

    public function test_store_rolls_back_injury_and_hs_event_when_required_control_room_projection_fails(): void
    {
        $bridge = $this->mock(ComprehensiveAlertBridgeService::class);
        $bridge->shouldReceive('bridgeOperationalAlert')
            ->once()
            ->andThrow(new \RuntimeException('Control Room unavailable'));

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->manager)->postJson('/health-safety/injuries', $this->createPayload($this->notifiableOverrides()));
            self::fail('The required Control Room projection failure should surface.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Control Room unavailable', $exception->getMessage());
        } finally {
            $this->withExceptionHandling();
        }

        $this->assertDatabaseCount('workplace_injuries', 0);
        $this->assertDatabaseCount('hs_events', 0);
        $this->assertDatabaseCount('notifiable_incidents', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertDatabaseCount('workplace_injury_command_receipts', 0);
    }

    public function test_update_rolls_back_source_and_hs_event_when_escalation_projection_fails(): void
    {
        $injury = $this->injury(['severity' => 'moderate', 'worksafe_notifiable' => false]);
        $event = $this->eventFor($injury);

        $bridge = $this->mock(ComprehensiveAlertBridgeService::class);
        $bridge->shouldReceive('bridgeOperationalAlert')
            ->once()
            ->andThrow(new \RuntimeException('Control Room unavailable'));

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->manager)
                ->putJson('/health-safety/injuries/'.$injury->id, $this->command($injury, ['severity' => 'serious']));
            self::fail('The required escalation projection failure should surface.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Control Room unavailable', $exception->getMessage());
        } finally {
            $this->withExceptionHandling();
        }

        $this->assertSame('moderate', $injury->fresh()->severity);
        $this->assertSame(1, (int) $injury->fresh()->source_revision);
        $this->assertSame('medium', $event->fresh()->severity);
        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_required_injury_journey_is_idempotent_and_site_linked(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/health-safety/injuries', $this->createPayload($this->notifiableOverrides()))
            ->assertOk();

        $injury = WorkplaceInjury::query()->latest('id')->firstOrFail();
        $journey = app(WorkplaceInjuryJourneyService::class);
        $journey->synchronize($injury);
        $journey->synchronize($injury->fresh());

        $events = HsEvent::query()
            ->where('source_type', WorkplaceInjury::class)
            ->where('source_id', $injury->id)
            ->get();
        $alerts = ControlRoomAlert::query()
            ->where('source', 'operations')
            ->where('alert_type', 'operations.workplace_injury')
            ->get()
            ->filter(fn (ControlRoomAlert $alert) => (int) data_get($alert->context, 'workplace_injury_id') === $injury->id);

        $this->assertCount(1, $events);
        $this->assertSame($this->site->id, (int) $events->first()->site_id);
        $this->assertSame($this->worker->id, (int) $events->first()->staff_id);
        $this->assertCount(1, $alerts);
        $this->assertSame($alerts->first()->id, $events->first()->control_room_alert_id);
        $this->assertSame(1, NotifiableIncident::query()->where('workplace_injury_id', $injury->id)->count());
    }

    public function test_downgrade_retracts_pending_worksafe_and_resolves_active_alert_without_deleting_history(): void
    {
        $injury = $this->injury($this->notifiableOverrides());
        $event = $this->eventFor($injury);
        $alert = ControlRoomAlert::query()->findOrFail($event->control_room_alert_id);
        $notifiable = NotifiableIncident::query()
            ->where('workplace_injury_id', $injury->id)
            ->firstOrFail();

        $this->assertSame('critical', $alert->severity);
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->status);
        $this->assertSame('pending', $notifiable->status);

        $this->actingAs($this->manager)
            ->putJson('/health-safety/injuries/'.$injury->id, $this->command($injury, [
                'severity' => 'moderate',
                'worksafe_notifiable' => false,
            ]))
            ->assertOk();

        $this->assertSame('moderate', $injury->fresh()->severity);
        $this->assertFalse($injury->fresh()->worksafe_notifiable);

        $event->refresh();
        $this->assertSame('medium', $event->severity);
        $this->assertFalse($event->worksafe_notifiable);
        $this->assertNull($event->worksafe_status);
        $this->assertSame($alert->id, $event->control_room_alert_id);

        $notifiable->refresh();
        $this->assertSame('closed', $notifiable->status);
        $this->assertSame(
            'Reclassified as not WorkSafe-notifiable before notification.',
            $notifiable->outcome,
        );
        $this->assertNotNull($notifiable->closed_at);
        $this->assertSame($this->manager->id, (int) $notifiable->closed_by);

        $alert->refresh();
        $this->assertSame(ControlRoomAlert::STATUS_RESOLVED, $alert->status);
        $this->assertSame('medium', $alert->severity);
        $this->assertSame('workplace_injury_reclassified', $alert->resolution_code);
        $this->assertNotNull($alert->resolved_at);
        $this->assertSame($this->site->id, (int) $alert->site_id);
        $this->assertSame($this->site->id, (int) data_get($alert->context, 'site_id'));
        $this->assertSame($injury->id, (int) data_get($alert->context, 'workplace_injury_id'));
        $this->assertFalse((bool) data_get($alert->context, 'worksafe_notifiable'));
    }

    public function test_worksafe_retraction_keeps_serious_injury_alert_active_but_downgrades_it(): void
    {
        $injury = $this->injury($this->notifiableOverrides());
        $event = $this->eventFor($injury);
        $alert = ControlRoomAlert::query()->findOrFail($event->control_room_alert_id);
        $notifiable = NotifiableIncident::query()
            ->where('workplace_injury_id', $injury->id)
            ->firstOrFail();

        $this->actingAs($this->manager)
            ->putJson('/health-safety/injuries/'.$injury->id, $this->command($injury, ['worksafe_notifiable' => false]))
            ->assertOk();

        $alert->refresh();
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->status);
        $this->assertSame('high', $alert->severity);
        $this->assertFalse((bool) data_get($alert->context, 'worksafe_notifiable'));
        $this->assertSame($alert->id, $event->fresh()->control_room_alert_id);
        $this->assertSame('closed', $notifiable->fresh()->status);
    }

    public function test_re_escalation_preserves_resolved_history_and_creates_a_new_active_alert(): void
    {
        $injury = $this->injury($this->notifiableOverrides());
        $event = $this->eventFor($injury);
        $firstAlertId = (int) $event->control_room_alert_id;
        $notifiableId = NotifiableIncident::query()
            ->where('workplace_injury_id', $injury->id)
            ->value('id');

        $this->actingAs($this->manager)
            ->putJson('/health-safety/injuries/'.$injury->id, $this->command($injury, [
                'severity' => 'moderate',
                'worksafe_notifiable' => false,
            ]))
            ->assertOk();

        $this->actingAs($this->manager)
            ->putJson('/health-safety/injuries/'.$injury->id, $this->command($injury, [
                'severity' => 'serious',
                'worksafe_notifiable' => true,
            ]))
            ->assertOk();

        $alerts = ControlRoomAlert::query()
            ->where('source', 'operations')
            ->where('alert_type', 'operations.workplace_injury')
            ->where('context->workplace_injury_id', $injury->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $alerts);
        $this->assertSame($firstAlertId, $alerts->first()->id);
        $this->assertSame(ControlRoomAlert::STATUS_RESOLVED, $alerts->first()->status);
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alerts->last()->status);
        $this->assertSame('critical', $alerts->last()->severity);
        $this->assertSame($alerts->last()->id, $event->fresh()->control_room_alert_id);
        $this->assertSame($notifiableId, NotifiableIncident::query()
            ->where('workplace_injury_id', $injury->id)
            ->value('id'));
        $this->assertSame('pending', NotifiableIncident::query()->findOrFail($notifiableId)->status);
    }

    #[DataProvider('poisonedControlRoomAlertTupleProvider')]
    public function test_update_fails_closed_and_rolls_back_when_linked_control_room_alert_tuple_is_poisoned(
        string $surface,
    ): void {
        $injury = $this->injury($this->notifiableOverrides());
        $event = $this->eventFor($injury);
        $alert = ControlRoomAlert::query()->findOrFail($event->control_room_alert_id);
        $notifiable = NotifiableIncident::query()
            ->where('workplace_injury_id', $injury->id)
            ->firstOrFail();
        $otherSite = Site::factory()->create();
        $context = $alert->context;

        match ($surface) {
            'source' => $alert->forceFill(['source' => 'monitoring'])->save(),
            'alert_type' => $alert->forceFill(['alert_type' => 'operations.other'])->save(),
            'site_id' => $alert->forceFill(['site_id' => $otherSite->id])->save(),
            'context_site_id' => $alert->forceFill([
                'context' => array_merge($context, ['site_id' => $otherSite->id]),
            ])->save(),
            'context_injury_id' => $alert->forceFill([
                'context' => array_merge($context, ['workplace_injury_id' => $injury->id + 999999]),
            ])->save(),
        };

        $input = $this->command($injury, [
            'severity' => 'moderate',
            'worksafe_notifiable' => false,
        ]);
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->manager)
                ->putJson('/health-safety/injuries/'.$injury->id, $input);
            self::fail('A poisoned Control Room alert tuple must fail the injury update.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'The Control Room alert does not match the workplace injury source, type, and Site tuple.',
                $exception->getMessage(),
            );
        } finally {
            $this->withExceptionHandling();
        }

        $this->assertSame('serious', $injury->fresh()->severity);
        $this->assertTrue($injury->fresh()->worksafe_notifiable);
        $this->assertSame(1, (int) $injury->fresh()->source_revision);
        $this->assertSame('high', $event->fresh()->severity);
        $this->assertTrue($event->fresh()->worksafe_notifiable);
        $this->assertSame('pending', $notifiable->fresh()->status);
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->fresh()->status);
        $this->assertSame('critical', $alert->fresh()->severity);
        $this->assertDatabaseMissing('workplace_injury_command_receipts', ['idempotency_key' => $input['idempotency_key']]);
    }

    /** @return array<string, array{string}> */
    public static function poisonedControlRoomAlertTupleProvider(): array
    {
        return [
            'wrong source' => ['source'],
            'wrong type' => ['alert_type'],
            'wrong root Site' => ['site_id'],
            'wrong context Site' => ['context_site_id'],
            'wrong injury context' => ['context_injury_id'],
        ];
    }

    public function test_transition_status_rejects_illegal_jump(): void
    {
        $inj = $this->injury();

        $this->actingAs($this->manager)
            ->postJson('/health-safety/injuries/'.$inj->id.'/status', $this->command($inj, [
                'status' => 'recovered',
                'reason' => 'Attempted lifecycle skip',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('reported', $inj->fresh()->status);
        $this->assertSame(1, (int) $inj->fresh()->source_revision);
    }

    public function test_attachment_rejects_scriptable_type(): void
    {
        $inj = $this->injury();

        $this->actingAs($this->manager)
            ->postJson('/health-safety/injuries/'.$inj->id.'/attachments', [
                ...$this->command($inj, ['kind' => 'photo']),
                'file' => UploadedFile::fake()->create('evil.svg', 5, 'image/svg+xml'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('workplace_injury_attachments', 0);
        $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertSame(1, (int) $inj->fresh()->source_revision);
    }

    public function test_worksafe_flip_on_null_created_by_still_registers_notifiable(): void
    {
        // Seeded/imported injury with no created_by, later flagged via the edit wizard.
        $inj = WorkplaceInjury::factory()->create([
            'site_id' => $this->site->id,
            'user_id' => $this->worker->id,
            'created_by' => null,
            'severity' => 'minor',
            'worksafe_notifiable' => false,
        ]);

        $this->actingAs($this->manager)
            ->putJson('/health-safety/injuries/'.$inj->id, $this->command($inj, ['worksafe_notifiable' => true]))
            ->assertOk();

        $this->assertDatabaseHas('notifiable_incidents', [
            'workplace_injury_id' => $inj->id,
            'notification_authority' => 'worksafe',
        ]);
    }

    public function test_notifiable_incidents_submitted_by_accepts_null(): void
    {
        // Item 1 — the column is nullable, so a queued/CLI auto-registration with no
        // created_by and no auth() user still inserts the statutory record.
        $id = DB::table('notifiable_incidents')->insertGetId([
            'incident_type' => 'serious_harm',
            'notification_authority' => 'worksafe',
            'title' => 'No submitter',
            'description' => 'Notifiable record with a null submitter.',
            'severity' => 'high',
            'status' => 'pending',
            'occurred_at' => now(),
            'submitted_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('notifiable_incidents', ['id' => $id, 'submitted_by' => null]);
    }

    public function test_notifiable_incidents_workplace_injury_id_is_unique(): void
    {
        // Item 2 — a unique index DB-enforces one NotifiableIncident per injury, closing
        // the observer's exists()-only race. Two non-null rows for one injury must fail.
        $inj = $this->injury();
        $row = [
            'incident_type' => 'serious_harm',
            'notification_authority' => 'worksafe',
            'title' => 'First',
            'description' => 'First notifiable for the injury.',
            'severity' => 'high',
            'status' => 'pending',
            'occurred_at' => now(),
            'workplace_injury_id' => $inj->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('notifiable_incidents')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('notifiable_incidents')->insert(array_merge($row, ['title' => 'Duplicate']));
    }

    public function test_client_incident_reverse_relation(): void
    {
        $incident = ClientIncident::factory()->create();
        $inj = WorkplaceInjury::withoutEvents(fn () => WorkplaceInjury::factory()->create(['related_incident_id' => $incident->id]));

        $this->assertTrue($incident->workplaceInjuries()->where('id', $inj->id)->exists());
    }
}
