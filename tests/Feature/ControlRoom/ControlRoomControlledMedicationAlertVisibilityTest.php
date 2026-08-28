<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\Communication;
use App\Models\ControlRoom\OperatorNote;
use App\Models\ControlRoom\Shift;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\MedicationError;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\ControlRoomAlertNotification;
use App\Services\ControlRoom\ControlRoomAlertAccessService;
use App\Services\ControlRoom\ControlRoomNotificationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ControlRoomControlledMedicationAlertVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $foreignSite;

    private User $viewer;

    private TriageQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->site = Site::factory()->create(['name' => 'Visible Kauri House']);
        $this->foreignSite = Site::factory()->create(['name' => 'Hidden Rimu House']);
        $this->viewer = $this->siteBoundTeamLead($this->site);
        $this->queue = TriageQueue::query()->create([
            'name' => 'Medication response queue',
            'code' => 'medication-response-queue',
            'tier' => 1,
            'is_active' => true,
        ]);
    }

    public function test_reader_without_exact_controlled_permission_gets_list_and_direct_object_concealment(): void
    {
        [$ordinary, $controlled, $foreignControlled] = $this->alertsForVisibilityProof();
        $this->grant($this->viewer, 'controlRoom.alerts.assign');
        $this->grant($this->viewer, 'incidents.create');
        $this->viewer = $this->viewer->fresh();

        $this->assertTrue($this->viewer->canDo('controlRoom.alerts.view'));
        $this->assertFalse($this->viewer->canDo('medications.controlled.view'));

        $response = $this->actingAs($this->viewer)
            ->get('/control-room/alerts?alert='.$controlled->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/alerts/index')
                ->has('alerts.data', 1)
                ->where('alerts.data.0.id', $ordinary->id)
                ->where('stats.total', 1)
                ->where('stats.critical', 0)
                ->where('queues.0.active_alerts', 1)
                ->where('detail', null));

        $encoded = json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Restricted methadone response', $encoded);
        $this->assertStringNotContainsString('Methadone 10mg/ml', $encoded);
        $this->assertStringNotContainsString('Hidden controlled response', $encoded);

        $this->actingAs($this->viewer)
            ->get('/control-room/alerts/'.$controlled->id)
            ->assertNotFound();
        $this->actingAs($this->viewer)
            ->getJson('/control-room/alerts/'.$controlled->id.'/tasks')
            ->assertNotFound();
        $this->actingAs($this->viewer)
            ->post('/control-room/alerts/'.$controlled->id.'/acknowledge')
            ->assertNotFound();
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $controlled->fresh()->status);
        $this->actingAs($this->viewer)
            ->post('/control-room/alerts/bulk-assign', [
                'alert_ids' => [$controlled->id],
                'assigned_to_user_id' => $this->viewer->id,
            ])
            ->assertForbidden();
        $this->assertNull($controlled->fresh()->assigned_to_user_id);
        $this->actingAs($this->viewer)
            ->postJson('/control-room/alerts/'.$controlled->id.'/create-incident', [
                'type' => 'medication_error',
                'severity' => 'critical',
                'description' => 'Must not create an incident from concealed alert content.',
                'immediate_action_taken' => 'No write is authorized.',
            ])
            ->assertNotFound();
        $this->assertDatabaseMissing('client_incidents', [
            'control_room_alert_id' => $controlled->id,
        ]);
        $this->actingAs($this->viewer)
            ->get('/control-room/alerts/'.$foreignControlled->id)
            ->assertNotFound();
        $this->actingAs($this->viewer)
            ->get('/control-room/alerts/999999')
            ->assertNotFound();
    }

    public function test_medication_error_alert_ingress_requires_exact_read_scope_and_stamps_sticky_controlled_classification(): void
    {
        $this->grant($this->viewer, 'controlRoom.alerts.create');
        $this->setPermission($this->viewer, 'medications.view', false);
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $ordinaryMedication = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Routine antibiotic',
            'dosage' => '250mg',
            'frequency' => 'Daily',
            'dose_times' => ['08:00'],
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
        ]);
        $controlledMedication = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Restricted controlled medicine',
            'dosage' => '10mg',
            'frequency' => 'Daily',
            'dose_times' => ['08:00'],
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
        ]);
        $ordinaryError = MedicationError::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $ordinaryMedication->id,
            'error_type' => 'wrong_time',
            'severity' => 'major',
            'description' => 'Routine medication incident detail',
            'immediate_action' => 'Clinical review completed.',
            'reported_by' => $this->viewer->id,
            'reported_at' => now(),
            'status' => 'reported',
        ]);
        $controlledError = MedicationError::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $controlledMedication->id,
            'error_type' => 'wrong_dose',
            'severity' => 'critical',
            'description' => 'Restricted controlled incident detail',
            'immediate_action' => 'Controlled medicine secured.',
            'reported_by' => $this->viewer->id,
            'reported_at' => now(),
            'status' => 'reported',
        ]);
        DB::table('client_medications')
            ->where('id', $controlledMedication->id)
            ->update(['deleted_at' => now()]);
        $foreignClient = Client::factory()->create(['site_id' => $this->foreignSite->id]);
        $foreignMedication = ClientMedication::query()->create([
            'client_id' => $foreignClient->id,
            'name' => 'Foreign routine medicine',
            'dosage' => '5mg',
            'frequency' => 'Daily',
            'dose_times' => ['08:00'],
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
        ]);
        $foreignError = MedicationError::query()->create([
            'client_id' => $foreignClient->id,
            'client_medication_id' => $foreignMedication->id,
            'error_type' => 'wrong_time',
            'severity' => 'major',
            'description' => 'Foreign medication incident detail',
            'immediate_action' => 'Foreign clinical review completed.',
            'reported_by' => $this->viewer->id,
            'reported_at' => now(),
            'status' => 'reported',
        ]);
        $forgedError = MedicationError::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $foreignMedication->id,
            'error_type' => 'wrong_time',
            'severity' => 'major',
            'description' => 'Forged cross-client medication incident detail',
            'immediate_action' => 'Must remain concealed.',
            'reported_by' => $this->viewer->id,
            'reported_at' => now(),
            'status' => 'reported',
        ]);
        $payload = fn (MedicationError $error): array => [
            'source_type' => 'medication_error',
            'source_id' => $error->id,
            'severity' => 'high',
            'notes' => 'Canonical incident escalation.',
        ];

        $this->actingAs($this->viewer)
            ->post('/control-room/incidents/create-alert', $payload($ordinaryError))
            ->assertForbidden();
        $this->assertDatabaseCount('control_room_alerts', 0);

        $this->grant($this->viewer, 'medications.view');
        $this->viewer = $this->viewer->fresh();
        $this->actingAs($this->viewer)
            ->post('/control-room/incidents/create-alert', $payload($ordinaryError))
            ->assertRedirect();
        $ordinaryAlert = ControlRoomAlert::query()->sole();
        $this->assertFalse(data_get($ordinaryAlert->context, 'normalized_data.controlled_drug'));
        $this->assertSame(
            $ordinaryMedication->id,
            (int) data_get($ordinaryAlert->context, 'normalized_data.client_medication_id'),
        );

        foreach ([$foreignError, $forgedError] as $concealedError) {
            $this->actingAs($this->viewer)
                ->post('/control-room/incidents/create-alert', $payload($concealedError))
                ->assertNotFound();
        }
        $this->actingAs($this->viewer)
            ->post('/control-room/incidents/create-alert', [
                'source_type' => 'medication_error',
                'source_id' => 999999,
                'severity' => 'high',
            ])
            ->assertNotFound();
        $this->assertDatabaseCount('control_room_alerts', 1);

        $this->actingAs($this->viewer)
            ->post('/control-room/incidents/create-alert', $payload($controlledError))
            ->assertNotFound();
        $this->assertDatabaseCount('control_room_alerts', 1);

        $this->grant($this->viewer, 'medications.controlled.view');
        $this->viewer = $this->viewer->fresh();
        $this->actingAs($this->viewer)
            ->post('/control-room/incidents/create-alert', $payload($controlledError))
            ->assertRedirect();
        $controlledAlert = ControlRoomAlert::query()->latest('id')->firstOrFail();
        $this->assertTrue(data_get($controlledAlert->context, 'normalized_data.controlled_drug'));
        $this->assertSame(
            $controlledMedication->id,
            (int) data_get($controlledAlert->context, 'normalized_data.client_medication_id'),
        );

        $this->setPermission($this->viewer, 'medications.controlled.view', false);
        $this->viewer = $this->viewer->fresh();
        $response = $this->actingAs($this->viewer)
            ->get('/control-room/alerts?alert='.$controlledAlert->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('alerts.data', 1)
                ->where('alerts.data.0.id', $ordinaryAlert->id)
                ->where('detail', null));
        $this->assertStringNotContainsString(
            'Restricted controlled incident detail',
            json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR),
        );
        $this->actingAs($this->viewer)
            ->get('/control-room/alerts/'.$controlledAlert->id)
            ->assertNotFound();
    }

    public function test_controlled_alerts_are_concealed_across_control_room_worklists_messages_and_direct_reports(): void
    {
        [$ordinary, $controlled] = $this->alertsForVisibilityProof();
        $ordinary->forceFill(['assigned_to_user_id' => $this->viewer->id])->save();
        $controlled->forceFill(['assigned_to_user_id' => $this->viewer->id])->save();

        $ordinaryNote = $this->followup($ordinary, 'Ordinary alert follow-up');
        $controlledNote = $this->followup($controlled, 'Restricted methadone follow-up');
        $shift = Shift::query()->create([
            'name' => 'Controlled content visibility shift',
            'starts_at' => now()->subHour(),
            'status' => 'active',
            'shift_lead_user_id' => $this->viewer->id,
            'team_members' => [$this->viewer->id],
        ]);
        $ordinaryNote->forceFill(['shift_id' => $shift->id])->save();
        $controlledNote->forceFill(['shift_id' => $shift->id])->save();
        $this->message($ordinary, 'Ordinary alert message');
        $this->message($controlled, 'Restricted methadone message');
        $slaDefinition = SlaDefinition::query()->create([
            'name' => 'Controlled content concealment',
            'code' => 'controlled-content-concealment',
            'acknowledge_target_minutes' => 5,
            'is_active' => true,
        ]);
        foreach ([$ordinary, $controlled] as $alert) {
            AlertSla::query()->create([
                'alert_id' => $alert->id,
                'sla_definition_id' => $slaDefinition->id,
                'acknowledge_deadline' => now()->subMinutes(10),
                'acknowledge_breached' => true,
                'first_breach_at' => now()->subMinutes(5),
            ]);
        }

        $this->actingAs($this->viewer)
            ->get('/control-room')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('worklist.data', 1)
                ->where('worklist.data.0.id', $ordinary->id)
                ->where('stats.total', 1));

        $myTasks = $this->actingAs($this->viewer)
            ->get('/control-room/my-tasks')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('my_alerts', 1)
                ->where('my_alerts.0.id', $ordinary->id)
                ->has('my_followups', 1)
                ->where('my_followups.0.id', $ordinaryNote->id)
                ->where('stats.my_open', 1));
        $this->assertStringNotContainsString(
            'Restricted methadone',
            json_encode($myTasks->inertiaProps(), JSON_THROW_ON_ERROR),
        );

        $this->actingAs($this->viewer)
            ->post('/control-room/my-tasks/followups/'.$controlledNote->id.'/complete')
            ->assertNotFound();
        $this->assertTrue($controlledNote->fresh()->requires_followup);

        $this->actingAs($this->viewer)
            ->get('/control-room/shifts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('notes', 1)
                ->where('notes.0.id', $ordinaryNote->id)
                ->where('openAlertsCount', 1)
                ->where('criticalAlertsCount', 0));
        $this->actingAs($this->viewer)
            ->post('/control-room/shifts/'.$shift->id.'/note', [
                'type' => OperatorNote::TYPE_NOTE,
                'content' => 'Forbidden controlled shift note',
                'alert_id' => $controlled->id,
            ])
            ->assertNotFound();
        $this->assertDatabaseMissing('control_room_operator_notes', [
            'content' => 'Forbidden controlled shift note',
        ]);

        $this->actingAs($this->viewer)
            ->get('/control-room/escalations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('worklist.data', 1)
                ->where('worklist.data.0.id', $ordinary->id)
                ->where('queues.0.alert_count', 1)
                ->where('summary.total_alerts', 1));

        $this->actingAs($this->viewer)
            ->get('/control-room/incidents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('journeys.data', 1)
                ->where('journeys.data.0.alert.id', $ordinary->id)
                ->where('stats.total', 1));

        $messaging = $this->actingAs($this->viewer)
            ->get('/control-room/messaging')
            ->assertOk();
        $messagingProps = $messaging->viewData('page')['props'];
        $this->assertSame(
            ['alert-'.$ordinary->id],
            collect($messagingProps['threads'])->pluck('id')->values()->all(),
        );
        $this->assertStringNotContainsString(
            'Restricted methadone',
            json_encode($messagingProps, JSON_THROW_ON_ERROR),
        );

        $this->actingAs($this->viewer)
            ->getJson('/control-room/messaging/thread?alert_id='.$controlled->id)
            ->assertNotFound();

        $this->actingAs($this->viewer)
            ->get('/control-room/map')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('alerts', 1)
                ->where('alerts.0.id', $ordinary->id)
                ->where('stats.active_alerts', 1));

        $this->actingAs($this->viewer)
            ->get('/control-room/integration-alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('alerts.data', 1)
                ->where('alerts.data.0.id', $ordinary->id)
                ->where('stats.total', 1));

        $export = $this->actingAs($this->viewer)
            ->get('/control-room/reports/export?period=7d')
            ->assertOk();
        $this->assertStringContainsString('Routine welfare response', $export->getContent());
        $this->assertStringNotContainsString('Restricted methadone response', $export->getContent());

        $this->actingAs($this->viewer)
            ->get('/control-room/reports?period=7d')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('volume.total', 1)
                ->where('sla.total_with_sla', 1)
                ->where('escalation.total_alerts', 1)
                ->where('workload.active_per_user.0.active_alerts', 1)
                ->where('workload.per_queue.0.active_alerts', 1));

        $this->actingAs($this->viewer)
            ->getJson('/control-room/reports/sla?period=7d')
            ->assertOk()
            ->assertJsonPath('total_with_sla', 1);
        $this->actingAs($this->viewer)
            ->getJson('/control-room/reports/alerts?period=7d')
            ->assertOk()
            ->assertJsonPath('total', 1);
        $this->actingAs($this->viewer)
            ->getJson('/control-room/reports/workload?period=7d')
            ->assertOk()
            ->assertJsonPath('active_per_user.0.active_alerts', 1)
            ->assertJsonPath('per_queue.0.active_alerts', 1);
        $this->actingAs($this->viewer)
            ->getJson('/control-room/reports/summary?period=7d')
            ->assertOk()
            ->assertJsonPath('total_alerts', 1)
            ->assertJsonPath('sla_breached', 1);

        $this->actingAs($this->viewer)
            ->get('/control-room/stats?period=7d')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kpis.open_alerts', 1)
                ->where('kpis.alerts_today', 1)
                ->has('top_alert_types', 1)
                ->where('top_alert_types.0.name', 'Routine welfare response'));

        $this->actingAs($this->viewer)
            ->get('/control-room/sla')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('slaDefinitions', 1)
                ->where('slaDefinitions.0.total_alerts', 1));

        $this->actingAs($this->viewer)
            ->get('/control-room/sla/breaches')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('breaches.data', 1)
                ->where('breaches.data.0.alert_id', $ordinary->id)
                ->where('stats.total', 1));
    }

    public function test_exact_controlled_reader_sees_local_controlled_alert_but_not_foreign_site_data(): void
    {
        [$ordinary, $controlled, $foreignControlled] = $this->alertsForVisibilityProof();
        $this->grant($this->viewer, 'medications.controlled.view');
        $this->viewer = $this->viewer->fresh();

        $this->assertTrue($this->viewer->canDo('medications.controlled.view'));

        $this->actingAs($this->viewer)
            ->get('/control-room/alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('alerts.data', 2)
                ->where('stats.total', 2));

        $this->actingAs($this->viewer)
            ->get('/control-room/alerts/'.$controlled->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/show')
                ->where('alert.id', $controlled->id));

        $this->actingAs($this->viewer)
            ->get('/control-room/alerts/'.$foreignControlled->id)
            ->assertNotFound();

        $visibleIds = app(ControlRoomAlertAccessService::class)
            ->applyVisibleScope(ControlRoomAlert::query(), $this->viewer)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $this->assertEqualsCanonicalizing([$ordinary->id, $controlled->id], $visibleIds);
    }

    public function test_controlled_notification_outbox_excludes_ineligible_audiences_and_rechecks_before_delivery(): void
    {
        [, $controlled] = $this->alertsForVisibilityProof();
        $authorized = $this->siteBoundTeamLead($this->site);
        $this->grant($authorized, 'medications.controlled.view');
        $foreignAuthorized = $this->siteBoundTeamLead($this->foreignSite);
        $this->grant($foreignAuthorized, 'medications.controlled.view');
        $this->queue->forceFill([
            'assigned_users' => [
                $this->viewer->id,
                $authorized->id,
                $foreignAuthorized->id,
            ],
        ])->save();
        Notification::fake();

        $delivery = app(ControlRoomNotificationService::class)
            ->stageAlertNotifications($controlled, null, $this->queue->fresh())
            ->sole();

        $this->assertSame($authorized->id, (int) $delivery->target_user_id);
        $this->assertDatabaseMissing('control_room_communications', [
            'alert_id' => $controlled->id,
            'target_user_id' => $this->viewer->id,
        ]);
        $this->assertDatabaseMissing('control_room_communications', [
            'alert_id' => $controlled->id,
            'target_user_id' => $foreignAuthorized->id,
        ]);

        $this->setPermission($authorized, 'medications.controlled.view', false);
        app(ControlRoomNotificationService::class)->deliverStagedNotification($delivery);

        Notification::assertNotSentTo($authorized, ControlRoomAlertNotification::class);
        Notification::assertNotSentTo($this->viewer, ControlRoomAlertNotification::class);
        Notification::assertNotSentTo($foreignAuthorized, ControlRoomAlertNotification::class);
        $this->assertNotNull($delivery->fresh()->superseded_at);
        $this->assertSame(1, Communication::query()->where('alert_id', $controlled->id)->count());
    }

    public function test_legacy_unmarked_medication_alerts_fail_closed_without_hiding_ordinary_legacy_alerts(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $ordinary = ControlRoomAlert::factory()->open()->create([
            'source' => 'manual',
            'alert_type' => 'Legacy ordinary welfare response',
            'severity' => 'high',
            'triggered_at' => now()->subMinute(),
            'site_id' => $this->site->id,
            'client_id' => $client->id,
            'queue_id' => $this->queue->id,
            'notes' => 'Ordinary legacy detail remains available.',
            'context' => [
                'title' => 'Legacy ordinary welfare response',
                'description' => 'Ordinary legacy detail remains available.',
            ],
        ]);
        $legacyMedication = ControlRoomAlert::factory()->open()->create([
            'source' => 'manual',
            'alert_type' => 'medication_error',
            'severity' => 'critical',
            'triggered_at' => now()->subMinute(),
            'site_id' => $this->site->id,
            'client_id' => $client->id,
            'queue_id' => $this->queue->id,
            'notes' => 'Legacy restricted medication error',
            // This is the exact pre-classification shape emitted by the manual
            // medication-error ingress before controlled_drug was stamped.
            'context' => [
                'incident_source_type' => 'medication_error',
                'incident_source_id' => 9876,
                'title' => 'Legacy restricted medication error',
                'description' => 'Legacy controlled medicine detail must remain concealed.',
            ],
        ]);

        $access = app(ControlRoomAlertAccessService::class);
        $this->assertFalse($access->requiresControlledMedicationPermission($ordinary));
        $this->assertTrue($access->requiresControlledMedicationPermission($legacyMedication));

        $response = $this->actingAs($this->viewer)
            ->get('/control-room/alerts?alert='.$legacyMedication->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('alerts.data', 1)
                ->where('alerts.data.0.id', $ordinary->id)
                ->where('stats.total', 1)
                ->where('detail', null));
        $this->assertStringNotContainsString(
            'Legacy restricted medication error',
            json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR),
        );

        $this->actingAs($this->viewer)
            ->get('/control-room/alerts/'.$ordinary->id)
            ->assertOk();
        $this->actingAs($this->viewer)
            ->get('/control-room/alerts/'.$legacyMedication->id)
            ->assertNotFound();

        $export = $this->actingAs($this->viewer)
            ->get('/control-room/reports/export?period=7d')
            ->assertOk();
        $this->assertStringContainsString('Legacy ordinary welfare response', $export->getContent());
        $this->assertStringNotContainsString('Legacy restricted medication error', $export->getContent());

        $authorized = $this->siteBoundTeamLead($this->site);
        $this->grant($authorized, 'medications.controlled.view');
        $this->queue->forceFill([
            'assigned_users' => [$this->viewer->id, $authorized->id],
        ])->save();

        $ordinaryDeliveries = app(ControlRoomNotificationService::class)
            ->stageAlertNotifications($ordinary, null, $this->queue->fresh());
        $this->assertTrue($ordinaryDeliveries->contains(
            fn (Communication $delivery): bool => (int) $delivery->target_user_id === (int) $this->viewer->id,
        ));

        $legacyMedicationDeliveries = app(ControlRoomNotificationService::class)
            ->stageAlertNotifications($legacyMedication, null, $this->queue->fresh());
        $this->assertSame([$authorized->id], $legacyMedicationDeliveries
            ->pluck('target_user_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all());
        $this->assertDatabaseMissing('control_room_communications', [
            'alert_id' => $legacyMedication->id,
            'target_user_id' => $this->viewer->id,
        ]);
    }

    public function test_blank_present_legacy_medication_provenance_is_concealed_and_not_notified(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $provenanceCases = [
            ['client_medication_id', ''],
            ['medication_id', '   '],
            ['client_medication_id', []],
        ];
        $alerts = collect($provenanceCases)->map(
            fn (array $provenance, int $index): ControlRoomAlert => ControlRoomAlert::factory()->open()->create([
                'source' => 'manual',
                'alert_type' => 'Legacy provenance-only response '.($index + 1),
                'severity' => 'high',
                'triggered_at' => now()->subMinute(),
                'site_id' => $this->site->id,
                'client_id' => $client->id,
                'queue_id' => $this->queue->id,
                'context' => [
                    'normalized_data' => [
                        $provenance[0] => $provenance[1],
                    ],
                ],
            ]),
        );

        $access = app(ControlRoomAlertAccessService::class);
        foreach ($alerts as $alert) {
            $this->assertTrue($access->requiresControlledMedicationPermission($alert));
        }
        $visibleIds = $access
            ->applyVisibleScope(
                ControlRoomAlert::query()->whereIn('id', $alerts->pluck('id')->all()),
                $this->viewer,
            )
            ->pluck('id')
            ->all();
        $this->assertSame([], $visibleIds);

        $authorized = $this->siteBoundTeamLead($this->site);
        $this->grant($authorized, 'medications.controlled.view');
        $this->queue->forceFill([
            'assigned_users' => [$this->viewer->id, $authorized->id],
        ])->save();

        foreach ($alerts as $alert) {
            $deliveries = app(ControlRoomNotificationService::class)
                ->stageAlertNotifications($alert, null, $this->queue->fresh());
            $this->assertSame([$authorized->id], $deliveries
                ->pluck('target_user_id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all());
            $this->assertDatabaseMissing('control_room_communications', [
                'alert_id' => $alert->id,
                'target_user_id' => $this->viewer->id,
            ]);
        }
    }

    public function test_mixed_case_legacy_medication_provenance_matches_object_and_sql_classifiers(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $provenanceCases = [
            [
                'source' => 'MeDiCaTiOn integration',
                'alert_type' => 'Legacy source response',
                'context' => [],
            ],
            [
                'source' => 'manual',
                'alert_type' => 'MeDiCaTiOn error',
                'context' => [],
            ],
            [
                'source' => 'manual',
                'alert_type' => 'Legacy module response',
                'context' => ['normalized_data' => ['source_module' => 'MeDiCaTiOn']],
            ],
            [
                'source' => 'manual',
                'alert_type' => 'Legacy incident response',
                'context' => ['incident_source_type' => 'MeDiCaTiOn_Error'],
            ],
        ];
        $alerts = collect($provenanceCases)->map(
            fn (array $provenance): ControlRoomAlert => ControlRoomAlert::factory()->open()->create([
                ...$provenance,
                'severity' => 'high',
                'triggered_at' => now()->subMinute(),
                'site_id' => $this->site->id,
                'client_id' => $client->id,
                'queue_id' => $this->queue->id,
            ]),
        );

        $access = app(ControlRoomAlertAccessService::class);
        foreach ($alerts as $alert) {
            $this->assertTrue($access->requiresControlledMedicationPermission($alert));
        }
        $visibleIds = $access
            ->applyVisibleScope(
                ControlRoomAlert::query()->whereIn('id', $alerts->pluck('id')->all()),
                $this->viewer,
            )
            ->pluck('id')
            ->all();
        $this->assertSame([], $visibleIds);
    }

    /** @return array{ControlRoomAlert, ControlRoomAlert, ControlRoomAlert} */
    private function alertsForVisibilityProof(): array
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $foreignClient = Client::factory()->create(['site_id' => $this->foreignSite->id]);

        $ordinary = ControlRoomAlert::factory()->open()->create([
            'source' => 'integration_care',
            'alert_type' => 'Routine welfare response',
            'severity' => 'high',
            'triggered_at' => now()->subMinute(),
            'site_id' => $this->site->id,
            'client_id' => $client->id,
            'queue_id' => $this->queue->id,
            'context' => [
                'normalized_data' => [
                    'controlled_drug' => false,
                    'description' => 'Routine support check',
                ],
            ],
        ]);
        $controlled = ControlRoomAlert::factory()->open()->create([
            'source' => 'integration_medication',
            'alert_type' => 'Restricted methadone response',
            'severity' => 'critical',
            'triggered_at' => now()->subMinute(),
            'site_id' => $this->site->id,
            'client_id' => $client->id,
            'queue_id' => $this->queue->id,
            'context' => [
                'normalized_data' => [
                    'controlled_drug' => true,
                    'medication_name' => 'Methadone 10mg/ml',
                    'description' => 'Restricted methadone response detail',
                ],
            ],
        ]);
        $foreignControlled = ControlRoomAlert::factory()->open()->create([
            'source' => 'medication',
            'alert_type' => 'Hidden controlled response',
            'severity' => 'critical',
            'triggered_at' => now()->subMinute(),
            'site_id' => $this->foreignSite->id,
            'client_id' => $foreignClient->id,
            'queue_id' => $this->queue->id,
            'context' => [
                'normalized_data' => [
                    'controlled_drug' => true,
                    'medication_name' => 'Foreign controlled medicine',
                ],
            ],
        ]);

        return [$ordinary, $controlled, $foreignControlled];
    }

    private function siteBoundTeamLead(Site $site): User
    {
        $role = Role::query()->where('name', 'team_lead')->firstOrFail();
        $user = User::factory()->create([
            'role' => 'team_lead',
            'approved_at' => now(),
        ]);
        $user->roles()->attach($role);
        $this->grant($user, 'controlRoom.viewAny');
        $this->grant($user, 'controlRoom.alerts.manage');

        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $user;
    }

    private function grant(User $user, string $permissionKey): void
    {
        $this->setPermission($user, $permissionKey, true);
    }

    private function setPermission(User $user, string $permissionKey, bool $allowed): void
    {
        $permission = Permission::query()->where('key', $permissionKey)->firstOrFail();
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => $allowed],
        ]);
        $user->unsetRelation('permissionOverrides');
        $user->unsetRelation('roles');
    }

    private function followup(ControlRoomAlert $alert, string $content): OperatorNote
    {
        return OperatorNote::query()->create([
            'alert_id' => $alert->id,
            'user_id' => $this->viewer->id,
            'type' => 'note',
            'content' => $content,
            'requires_followup' => true,
        ]);
    }

    private function message(ControlRoomAlert $alert, string $content): Communication
    {
        return Communication::query()->create([
            'alert_id' => $alert->id,
            'channel' => 'in_app',
            'direction' => 'inbound',
            'purpose' => 'update',
            'status' => 'sent',
            'content' => $content,
            'initiated_by_user_id' => $this->viewer->id,
            'sent_at' => now(),
        ]);
    }
}
