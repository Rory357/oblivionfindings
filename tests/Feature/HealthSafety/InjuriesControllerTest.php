<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Governance\Models\NotifiableIncident;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\ReturnToWorkPlan;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Models\WorkplaceInjuryAttachment;
use App\Services\ControlRoom\ComprehensiveAlertBridgeService;
use App\Services\HealthSafety\WorkplaceInjuryJourneyService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Injuries & RTW redesign — controller + observer coverage (the module had zero
 * HTTP tests before the rebuild). Hero/tabs/detail/can, store with derived ACC +
 * incident link, WorkSafe NotifiableIncident seam, lifecycle transitions, RTW /
 * capacity / modified-duty sub-modals, premium attachments (IDOR guard).
 */
class InjuriesControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
        $this->site = Site::factory()->create();
    }

    private function injury(array $overrides = []): WorkplaceInjury
    {
        $site = Site::query()->find($overrides['site_id'] ?? $this->site->id) ?? $this->site;
        $overrides['user_id'] ??= $this->staffAtSite($site)->id;

        $injury = WorkplaceInjury::factory()->create(array_merge([
            'site_id' => $site->id,
            'status' => 'reported',
        ], $overrides));

        app(WorkplaceInjuryJourneyService::class)->synchronize($injury);

        return $injury;
    }

    private function staffAtSite(Site $site): User
    {
        $staff = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $staff->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);

        return $staff;
    }

    private function incidentAtSite(Site $site): ClientIncident
    {
        $client = Client::factory()->create(['site_id' => $site->id]);

        return ClientIncident::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
        ]);
    }

    /** @param list<string> $permissions */
    private function siteViewer(Site $site, array $permissions): User
    {
        $viewer = $this->staffAtSite($site);
        $role = Role::query()->create([
            'name' => 'injury_site_'.str()->uuid(),
            'label' => 'Injury Site test role',
            'level' => 50,
            'type' => 'custom',
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', $permissions)->pluck('id'),
        );
        $viewer->roles()->attach($role);

        return $viewer;
    }

    /** @return array<string, mixed> */
    private function validInjuryPayload(Site $site, User $worker, array $overrides = []): array
    {
        return array_merge([
            'user_id' => $worker->id,
            'site_id' => $site->id,
            'injury_date' => now()->toDateString(),
            'injury_type' => 'manual_handling',
            'body_part_affected' => 'Lower back',
            'severity' => 'moderate',
            'description' => 'Strained back during a supported transfer.',
            'medical_treatment_type' => 'gp_visit',
        ], $overrides);
    }

    public function test_index_renders_hero_tabcounts_and_can(): void
    {
        $this->injury(['status' => 'reported']);
        $this->injury(['status' => 'under_treatment']);

        $this->actingAs($this->admin)
            ->get('/health-safety/injuries')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('health-safety/injuries/index')
                ->where('tab', 'all')
                ->where('tabCounts.all', 2)
                ->where('tabCounts.reported', 1)
                ->where('can.manage', true)
                ->has('hero.live')
                ->has('hero.attention')
                ->has('hero.badges')
                ->has('staff')
                ->has('incidents')
                ->where('detail', null));
    }

    public function test_register_counts_pickers_and_detail_are_scoped_to_canonical_site_access(): void
    {
        $hiddenSite = Site::factory()->create();
        $viewer = $this->siteViewer($this->site, ['hazards.view', 'hazards.manage', 'hazards.create']);
        $visibleWorker = $this->staffAtSite($this->site);
        $hiddenWorker = $this->staffAtSite($hiddenSite);
        $visibleIncident = $this->incidentAtSite($this->site);
        $hiddenIncident = $this->incidentAtSite($hiddenSite);
        $visible = $this->injury(['user_id' => $visibleWorker->id]);
        $hidden = $this->injury(['site_id' => $hiddenSite->id, 'user_id' => $hiddenWorker->id]);
        $missingSite = WorkplaceInjury::withoutEvents(fn () => WorkplaceInjury::factory()->create([
            'site_id' => null,
            'user_id' => $visibleWorker->id,
        ]));

        $this->actingAs($viewer)
            ->get('/health-safety/injuries')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tabCounts.all', 1)
                ->has('injuries.data', 1)
                ->where('injuries.data.0.id', $visible->id)
                ->where('sites', fn ($sites) => collect($sites)->pluck('id')->all() === [$this->site->id])
                ->where('staff', fn ($staff) => collect($staff)->pluck('id')->contains($visibleWorker->id)
                    && ! collect($staff)->pluck('id')->contains($hiddenWorker->id))
                ->where('incidents', fn ($incidents) => collect($incidents)->pluck('id')->contains($visibleIncident->id)
                    && ! collect($incidents)->pluck('id')->contains($hiddenIncident->id)));

        $this->actingAs($viewer)
            ->get('/health-safety/injuries?injury='.$hidden->id)
            ->assertForbidden();
        $this->actingAs($viewer)
            ->get('/health-safety/injuries?injury='.$missingSite->id)
            ->assertForbidden();
        $this->actingAs($viewer)
            ->get('/health-safety/injuries?site_id='.$hiddenSite->id)
            ->assertForbidden();
        $this->actingAs($viewer)
            ->get('/health-safety/injuries/export?site_id='.$hiddenSite->id)
            ->assertForbidden();
    }

    public function test_cross_site_injury_and_nested_direct_actions_are_denied(): void
    {
        Storage::fake('private');
        $hiddenSite = Site::factory()->create();
        $viewer = $this->siteViewer($this->site, ['hazards.view', 'hazards.manage', 'hazards.create']);
        $hiddenWorker = $this->staffAtSite($hiddenSite);
        $injury = $this->injury(['site_id' => $hiddenSite->id, 'user_id' => $hiddenWorker->id]);
        $plan = ReturnToWorkPlan::factory()->create([
            'workplace_injury_id' => $injury->id,
            'worker_id' => $hiddenWorker->id,
        ]);
        $attachment = WorkplaceInjuryAttachment::factory()->create([
            'workplace_injury_id' => $injury->id,
            'disk' => 'private',
        ]);

        $this->actingAs($viewer)->get('/health-safety/injuries/'.$injury->id)->assertForbidden();
        $this->actingAs($viewer)->put('/health-safety/injuries/'.$injury->id, ['lost_time_days' => 2])->assertForbidden();
        $this->actingAs($viewer)->post('/health-safety/injuries/'.$injury->id.'/status', ['status' => 'closed'])->assertForbidden();
        $this->actingAs($viewer)->post('/health-safety/injuries/'.$injury->id.'/rtw-plans', [])->assertForbidden();
        $this->actingAs($viewer)->put('/health-safety/injuries/rtw-plans/'.$plan->id, ['status' => 'completed'])->assertForbidden();
        $this->actingAs($viewer)->post('/health-safety/injuries/'.$injury->id.'/capacity-assessments', [])->assertForbidden();
        $this->actingAs($viewer)->post('/health-safety/injuries/rtw-plans/'.$plan->id.'/modified-duties', [])->assertForbidden();
        $this->actingAs($viewer)->post('/health-safety/injuries/'.$injury->id.'/attachments', [])->assertForbidden();
        $this->actingAs($viewer)
            ->get('/health-safety/injuries/'.$injury->id.'/attachments/'.$attachment->id.'/download')
            ->assertForbidden();
        $this->actingAs($viewer)
            ->delete('/health-safety/injuries/'.$injury->id.'/attachments/'.$attachment->id)
            ->assertForbidden();
    }

    public function test_store_rejects_cross_site_staff_incident_and_conflicting_client_provenance(): void
    {
        $hiddenSite = Site::factory()->create();
        $viewer = $this->siteViewer($this->site, ['hazards.view', 'hazards.manage', 'hazards.create']);
        $visibleWorker = $this->staffAtSite($this->site);
        $hiddenWorker = $this->staffAtSite($hiddenSite);
        $hiddenIncident = $this->incidentAtSite($hiddenSite);
        $conflictingClient = Client::factory()->create(['site_id' => $hiddenSite->id]);
        $conflictingIncident = ClientIncident::factory()->create([
            'client_id' => $conflictingClient->id,
            'site_id' => $this->site->id,
        ]);

        $before = WorkplaceInjury::query()->count();

        $this->actingAs($viewer)
            ->post('/health-safety/injuries', $this->validInjuryPayload($hiddenSite, $hiddenWorker))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->post('/health-safety/injuries', $this->validInjuryPayload($this->site, $hiddenWorker))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->post('/health-safety/injuries', $this->validInjuryPayload($this->site, $visibleWorker, [
                'related_incident_id' => $hiddenIncident->id,
            ]))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->post('/health-safety/injuries', $this->validInjuryPayload($this->site, $visibleWorker, [
                'related_incident_id' => $conflictingIncident->id,
            ]))
            ->assertForbidden();

        $this->assertSame($before, WorkplaceInjury::query()->count());
    }

    public function test_update_rejects_cross_site_staff_site_and_incident_provenance(): void
    {
        $hiddenSite = Site::factory()->create();
        $viewer = $this->siteViewer($this->site, ['hazards.view', 'hazards.manage']);
        $visibleWorker = $this->staffAtSite($this->site);
        $hiddenWorker = $this->staffAtSite($hiddenSite);
        $hiddenIncident = $this->incidentAtSite($hiddenSite);
        $injury = $this->injury([
            'user_id' => $visibleWorker->id,
            'severity' => 'moderate',
        ]);

        $this->actingAs($viewer)
            ->put('/health-safety/injuries/'.$injury->id, ['user_id' => $hiddenWorker->id])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->put('/health-safety/injuries/'.$injury->id, ['site_id' => $hiddenSite->id])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->put('/health-safety/injuries/'.$injury->id, ['related_incident_id' => $hiddenIncident->id])
            ->assertForbidden();

        $injury->refresh();
        $this->assertSame($visibleWorker->id, $injury->user_id);
        $this->assertSame($this->site->id, $injury->site_id);
        $this->assertNull($injury->related_incident_id);
    }

    public function test_detail_loads_only_with_injury_param(): void
    {
        $inj = $this->injury();

        $this->actingAs($this->admin)
            ->get('/health-safety/injuries?injury='.$inj->id)
            ->assertInertia(fn (Assert $p) => $p
                ->where('detail.id', $inj->id)
                ->where('detail.reference', $inj->reference_number)
                ->has('detail.rtw_plans')
                ->has('detail.attachments')
                ->where('detail.can.manage', true));
    }

    public function test_store_creates_injury_with_derived_acc_and_incident_link(): void
    {
        $worker = $this->staffAtSite($this->site);
        $incident = $this->incidentAtSite($this->site);

        $this->actingAs($this->admin)
            ->from('/health-safety/injuries')
            ->post('/health-safety/injuries', [
                'user_id' => $worker->id,
                'site_id' => $this->site->id,
                'related_incident_id' => $incident->id,
                'injury_date' => now()->toDateString(),
                'injury_type' => 'manual_handling',
                'body_part_affected' => 'Lower back',
                'severity' => 'moderate',
                'description' => 'Strained back during a transfer.',
                'medical_treatment_type' => 'gp_visit',
                'acc_claim_number' => '26/123456',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $inj = WorkplaceInjury::latest('id')->first();
        $this->assertSame('reported', $inj->status);
        $this->assertSame(0, (int) $inj->lost_time_days);
        $this->assertTrue((bool) $inj->acc_claim_lodged, 'ACC lodged should derive from a captured claim number');
        $this->assertSame($incident->id, $inj->related_incident_id);
        $this->assertEquals($inj->id, session('created_injury_id'));
    }

    public function test_store_worksafe_notifiable_creates_notifiable_incident(): void
    {
        $worker = $this->staffAtSite($this->site);

        $this->actingAs($this->admin)
            ->post('/health-safety/injuries', [
                'user_id' => $worker->id,
                'site_id' => $this->site->id,
                'injury_date' => now()->toDateString(),
                'injury_type' => 'fracture',
                'body_part_affected' => 'Right wrist',
                'severity' => 'serious',
                'description' => 'Fall from a ladder; wrist fracture requiring hospitalisation.',
                'medical_treatment_type' => 'hospitalisation',
                'worksafe_notifiable' => true,
            ])
            ->assertSessionHasNoErrors();

        $inj = WorkplaceInjury::latest('id')->first();
        $notifiable = NotifiableIncident::where('workplace_injury_id', $inj->id)->first();

        $this->assertNotNull($notifiable, 'A worksafe-notifiable injury must create a NotifiableIncident (seam 4)');
        $this->assertSame('worksafe', $notifiable->notification_authority);
        $this->assertSame('pending', $notifiable->status);
    }

    public function test_store_rolls_back_injury_and_hs_event_when_required_control_room_projection_fails(): void
    {
        $worker = $this->staffAtSite($this->site);
        $bridge = $this->mock(ComprehensiveAlertBridgeService::class);
        $bridge->shouldReceive('bridgeOperationalAlert')
            ->once()
            ->andThrow(new \RuntimeException('Control Room unavailable'));

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->admin)->post('/health-safety/injuries', $this->validInjuryPayload(
                $this->site,
                $worker,
                [
                    'injury_type' => 'fracture',
                    'severity' => 'serious',
                    'medical_treatment_type' => 'hospitalisation',
                    'worksafe_notifiable' => true,
                ],
            ));
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
    }

    public function test_update_rolls_back_source_and_hs_event_when_escalation_projection_fails(): void
    {
        $injury = $this->injury(['severity' => 'moderate', 'worksafe_notifiable' => false]);
        $event = HsEvent::query()
            ->where('source_type', WorkplaceInjury::class)
            ->where('source_id', $injury->id)
            ->firstOrFail();

        $bridge = $this->mock(ComprehensiveAlertBridgeService::class);
        $bridge->shouldReceive('bridgeOperationalAlert')
            ->once()
            ->andThrow(new \RuntimeException('Control Room unavailable'));

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->admin)
                ->put('/health-safety/injuries/'.$injury->id, ['severity' => 'serious']);
            self::fail('The required escalation projection failure should surface.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Control Room unavailable', $exception->getMessage());
        } finally {
            $this->withExceptionHandling();
        }

        $this->assertSame('moderate', $injury->fresh()->severity);
        $this->assertSame('medium', $event->fresh()->severity);
        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_required_injury_journey_is_idempotent_and_site_linked(): void
    {
        $worker = $this->staffAtSite($this->site);

        $this->actingAs($this->admin)
            ->post('/health-safety/injuries', $this->validInjuryPayload($this->site, $worker, [
                'injury_type' => 'fracture',
                'severity' => 'serious',
                'medical_treatment_type' => 'hospitalisation',
                'worksafe_notifiable' => true,
            ]))
            ->assertSessionHasNoErrors();

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
        $this->assertSame($worker->id, (int) $events->first()->staff_id);
        $this->assertCount(1, $alerts);
        $this->assertSame($alerts->first()->id, $events->first()->control_room_alert_id);
        $this->assertSame(1, NotifiableIncident::query()->where('workplace_injury_id', $injury->id)->count());
    }

    public function test_downgrade_retracts_pending_worksafe_and_resolves_active_alert_without_deleting_history(): void
    {
        $injury = $this->injury(['severity' => 'serious', 'worksafe_notifiable' => true]);
        $event = HsEvent::query()
            ->where('source_type', WorkplaceInjury::class)
            ->where('source_id', $injury->id)
            ->firstOrFail();
        $alert = ControlRoomAlert::query()->findOrFail($event->control_room_alert_id);
        $notifiable = NotifiableIncident::query()
            ->where('workplace_injury_id', $injury->id)
            ->firstOrFail();

        $this->assertSame('critical', $alert->severity);
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->status);
        $this->assertSame('pending', $notifiable->status);

        $this->actingAs($this->admin)
            ->put('/health-safety/injuries/'.$injury->id, [
                'severity' => 'moderate',
                'worksafe_notifiable' => false,
            ])
            ->assertSessionHasNoErrors();

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
        $this->assertSame($this->admin->id, (int) $notifiable->closed_by);

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
        $injury = $this->injury(['severity' => 'serious', 'worksafe_notifiable' => true]);
        $event = HsEvent::query()
            ->where('source_type', WorkplaceInjury::class)
            ->where('source_id', $injury->id)
            ->firstOrFail();
        $alert = ControlRoomAlert::query()->findOrFail($event->control_room_alert_id);
        $notifiable = NotifiableIncident::query()
            ->where('workplace_injury_id', $injury->id)
            ->firstOrFail();

        $this->actingAs($this->admin)
            ->put('/health-safety/injuries/'.$injury->id, ['worksafe_notifiable' => false])
            ->assertSessionHasNoErrors();

        $alert->refresh();
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->status);
        $this->assertSame('high', $alert->severity);
        $this->assertFalse((bool) data_get($alert->context, 'worksafe_notifiable'));
        $this->assertSame($alert->id, $event->fresh()->control_room_alert_id);
        $this->assertSame('closed', $notifiable->fresh()->status);
    }

    public function test_re_escalation_preserves_resolved_history_and_creates_a_new_active_alert(): void
    {
        $injury = $this->injury(['severity' => 'serious', 'worksafe_notifiable' => true]);
        $event = HsEvent::query()
            ->where('source_type', WorkplaceInjury::class)
            ->where('source_id', $injury->id)
            ->firstOrFail();
        $firstAlertId = (int) $event->control_room_alert_id;
        $notifiableId = NotifiableIncident::query()
            ->where('workplace_injury_id', $injury->id)
            ->value('id');

        $this->actingAs($this->admin)
            ->put('/health-safety/injuries/'.$injury->id, [
                'severity' => 'moderate',
                'worksafe_notifiable' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->put('/health-safety/injuries/'.$injury->id, [
                'severity' => 'serious',
                'worksafe_notifiable' => true,
            ])
            ->assertSessionHasNoErrors();

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
        $injury = $this->injury(['severity' => 'serious', 'worksafe_notifiable' => true]);
        $event = HsEvent::query()
            ->where('source_type', WorkplaceInjury::class)
            ->where('source_id', $injury->id)
            ->firstOrFail();
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

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->admin)
                ->put('/health-safety/injuries/'.$injury->id, [
                    'severity' => 'moderate',
                    'worksafe_notifiable' => false,
                ]);
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
        $this->assertSame('high', $event->fresh()->severity);
        $this->assertTrue($event->fresh()->worksafe_notifiable);
        $this->assertSame('pending', $notifiable->fresh()->status);
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->fresh()->status);
        $this->assertSame('critical', $alert->fresh()->severity);
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

    public function test_update_edits_injury_fields(): void
    {
        $inj = $this->injury(['lost_time_days' => 0]);

        $this->actingAs($this->admin)
            ->put('/health-safety/injuries/'.$inj->id, [
                'lost_time_days' => 5,
                'body_part_affected' => 'Left shoulder',
            ])
            ->assertSessionHasNoErrors();

        $inj->refresh();
        $this->assertSame(5, (int) $inj->lost_time_days);
        $this->assertSame('Left shoulder', $inj->body_part_affected);
    }

    public function test_transition_status_advances_and_sets_return_date(): void
    {
        $inj = $this->injury(['status' => 'under_treatment', 'actual_return_date' => null]);

        $this->actingAs($this->admin)
            ->post('/health-safety/injuries/'.$inj->id.'/status', ['status' => 'recovered'])
            ->assertSessionHasNoErrors();

        $inj->refresh();
        $this->assertSame('recovered', $inj->status);
        $this->assertNotNull($inj->actual_return_date, 'Recovered should stamp actual_return_date');
    }

    public function test_transition_status_rejects_illegal_jump(): void
    {
        $inj = $this->injury(['status' => 'reported']);

        $this->actingAs($this->admin)
            ->from('/health-safety/injuries')
            ->post('/health-safety/injuries/'.$inj->id.'/status', ['status' => 'recovered'])
            ->assertSessionHas('error');

        $this->assertSame('reported', $inj->fresh()->status);
    }

    public function test_store_rtw_plan(): void
    {
        $inj = $this->injury();

        $this->actingAs($this->admin)
            ->post('/health-safety/injuries/'.$inj->id.'/rtw-plans', [
                'plan_start_date' => now()->toDateString(),
                'goals' => ['Return to full duties'],
                'stages' => [[
                    'name' => 'Graduated return',
                    'start_date' => now()->toDateString(),
                    'hours_per_week' => 20,
                ]],
            ])
            ->assertSessionHasNoErrors();

        $plan = ReturnToWorkPlan::where('workplace_injury_id', $inj->id)->first();
        $this->assertNotNull($plan);
        $this->assertSame('active', $plan->status);
        $this->assertSame($inj->user_id, $plan->worker_id);
    }

    public function test_store_capacity_assessment(): void
    {
        $inj = $this->injury();

        $this->actingAs($this->admin)
            ->post('/health-safety/injuries/'.$inj->id.'/capacity-assessments', [
                'assessment_date' => now()->toDateString(),
                'assessor_type' => 'gp',
                'capacity_status' => 'fit_for_modified_duties',
                'restrictions' => 'No lifting over 10 kg.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_capacity_assessments', [
            'workplace_injury_id' => $inj->id,
            'capacity_status' => 'fit_for_modified_duties',
        ]);
    }

    public function test_store_modified_duty_keyed_by_plan(): void
    {
        $inj = $this->injury();
        $plan = ReturnToWorkPlan::factory()->create(['workplace_injury_id' => $inj->id, 'worker_id' => $inj->user_id]);

        $this->actingAs($this->admin)
            ->post('/health-safety/injuries/rtw-plans/'.$plan->id.'/modified-duties', [
                'start_date' => now()->toDateString(),
                'modified_duties_description' => 'Desk duties only.',
                'hours_per_day' => 6,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('modified_duties', [
            'return_to_work_plan_id' => $plan->id,
            'modified_duties_description' => 'Desk duties only.',
        ]);
    }

    public function test_upload_download_destroy_attachment_with_idor_guard(): void
    {
        Storage::fake('private');
        $inj = $this->injury();
        $other = $this->injury();

        // Upload (real fake image so it passes the mimes allowlist)
        $this->actingAs($this->admin)
            ->post('/health-safety/injuries/'.$inj->id.'/attachments', [
                'file' => UploadedFile::fake()->image('injury-evidence.jpg'),
                'kind' => 'medical_cert',
            ])
            ->assertSessionHasNoErrors();

        $att = WorkplaceInjuryAttachment::where('workplace_injury_id', $inj->id)->first();
        $this->assertNotNull($att);
        $this->assertSame('medical_cert', $att->kind);
        // Stored on the PRIVATE disk now — never world-readable under /storage.
        Storage::disk('private')->assertExists($att->path);
        $this->assertSame('private', $att->disk);

        // IDOR guard: the attachment belongs to $inj, not $other → 404 under $other.
        $this->actingAs($this->admin)
            ->get('/health-safety/injuries/'.$other->id.'/attachments/'.$att->id.'/download')
            ->assertNotFound();

        // Correct parent downloads fine — streamed from the private disk with the
        // hardened CSP-sandbox header from ServesPrivateAttachments (nosniff +
        // X-Frame-Options come from the edge layer, not the app).
        $this->actingAs($this->admin)
            ->get('/health-safety/injuries/'.$inj->id.'/attachments/'.$att->id.'/download')
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox; frame-ancestors 'none'");

        // Destroy
        $this->actingAs($this->admin)
            ->delete('/health-safety/injuries/'.$inj->id.'/attachments/'.$att->id)
            ->assertSessionHasNoErrors();
        $this->assertSoftDeleted('workplace_injury_attachments', ['id' => $att->id]);
    }

    public function test_attachment_rejects_scriptable_type(): void
    {
        Storage::fake('private');
        $inj = $this->injury();

        $this->actingAs($this->admin)
            ->from('/health-safety/injuries?injury='.$inj->id)
            ->post('/health-safety/injuries/'.$inj->id.'/attachments', [
                'file' => UploadedFile::fake()->create('evil.svg', 5, 'image/svg+xml'),
            ])
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('workplace_injury_attachments', 0);
    }

    public function test_worksafe_flip_on_null_created_by_still_registers_notifiable(): void
    {
        // Seeded/imported injury with no created_by, later flagged via the edit wizard.
        $inj = $this->injury(['created_by' => null, 'worksafe_notifiable' => false]);

        $this->actingAs($this->admin)
            ->put('/health-safety/injuries/'.$inj->id, ['worksafe_notifiable' => true])
            ->assertSessionHasNoErrors();

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

    public function test_update_does_not_change_status(): void
    {
        $inj = $this->injury(['status' => 'reported']);

        $this->actingAs($this->admin)
            ->put('/health-safety/injuries/'.$inj->id, ['status' => 'recovered', 'lost_time_days' => 3])
            ->assertSessionHasNoErrors();

        $inj->refresh();
        $this->assertSame('reported', $inj->status, 'status must not be settable via update() — only via transitionStatus()');
        $this->assertSame(3, (int) $inj->lost_time_days);
    }

    public function test_client_incident_reverse_relation(): void
    {
        $incident = ClientIncident::factory()->create();
        $inj = $this->injury(['related_incident_id' => $incident->id]);

        $this->assertTrue($incident->workplaceInjuries()->where('id', $inj->id)->exists());
    }

    public function test_export_streams_filtered_csv(): void
    {
        $inj = $this->injury(['injury_type' => 'fracture']);

        $res = $this->actingAs($this->admin)->get('/health-safety/injuries/export');
        $res->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('Content-Type'));
        $this->assertStringContainsString($inj->reference_number, $res->streamedContent());
    }

    public function test_show_redirects_to_register_modal(): void
    {
        $inj = $this->injury();

        $this->actingAs($this->admin)
            ->get('/health-safety/injuries/'.$inj->id)
            ->assertRedirect('/health-safety/injuries?injury='.$inj->id);
    }
}
