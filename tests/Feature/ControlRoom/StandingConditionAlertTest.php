<?php

namespace Tests\Feature\ControlRoom;

use App\Jobs\DispatchFleetSignalOutbox;
use App\Jobs\FleetAutoAlertJob;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientMedication;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignalOutbox;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use App\Services\ControlRoom\ControlRoomNotificationService;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\Fleet\FleetSignalService;
use App\Services\HealthSafety\HsSignalService;
use App\Services\Medication\MedicationSignalService;
use App\Services\MedicationAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Daily checks (overdue maintenance, overdue H&S items, controlled-drug
 * discrepancies) report a standing condition. While its Control Room alert is
 * open, each later check refreshes that alert instead of opening another; once
 * it is resolved, the next check alerts again. Never across Sites or people.
 */
class StandingConditionAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-20 18:00:00', 'UTC'));

        $notifications = $this->mock(ControlRoomNotificationService::class);
        $notifications->shouldReceive('notifyAlert')->andReturnNull();
        $notifications->shouldReceive('stageAlertNotifications')->andReturn(collect());
    }

    public function test_a_later_signal_for_the_same_condition_refreshes_the_open_alert(): void
    {
        $site = Site::factory()->create();

        $first = $this->processConditionSignal($site, 'hs_corrective_action_overdue|corrective_action:7', 3, 'medium');
        $this->travel(1)->days();
        $second = $this->processConditionSignal($site, 'hs_corrective_action_overdue|corrective_action:7', 4, 'high');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ControlRoomAlert::query()->count());

        $alert = $first->fresh();
        $this->assertSame('open', $alert->status);
        $this->assertSame(4, data_get($alert->context, 'normalized_data.days_overdue'));
        $this->assertSame('Overdue item (4 days)', data_get($alert->context, 'normalized_data.title'));
        $this->assertSame('high', $alert->severity, 'Severity only goes up.');
        $this->assertCount(1, data_get($alert->context, 'correlated_signals'));

        $later = Signal::query()->latest('id')->first();
        $this->assertSame('processed', $later->status);
        $this->assertSame($alert->id, (int) $later->correlated_alert_id);
    }

    public function test_the_condition_alerts_again_after_its_alert_is_resolved(): void
    {
        $site = Site::factory()->create();

        $first = $this->processConditionSignal($site, 'hs_investigation_overdue|investigation:3', 2);
        app(ControlRoomAlertLifecycleService::class)->resolveAutomatically(
            $first,
            'Investigation completed.',
            'test',
            'test',
        );

        $this->travel(1)->days();
        $second = $this->processConditionSignal($site, 'hs_investigation_overdue|investigation:3', 3);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('resolved', $first->fresh()->status);
        $this->assertSame('open', $second->fresh()->status);
    }

    public function test_the_same_condition_key_never_groups_across_sites_people_or_items(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $clientA = Client::factory()->create(['site_id' => $siteA->id]);
        $clientB = Client::factory()->create(['site_id' => $siteA->id]);

        $atA = $this->processConditionSignal($siteA, 'shared|item:1', 1);
        $this->travel(1)->hours();
        $atB = $this->processConditionSignal($siteB, 'shared|item:1', 1);
        $this->travel(1)->hours();
        $forClientA = $this->processConditionSignal($siteA, 'shared|item:1', 1, client: $clientA);
        $this->travel(1)->hours();
        $forClientB = $this->processConditionSignal($siteA, 'shared|item:1', 1, client: $clientB);
        $this->travel(1)->hours();
        $otherItem = $this->processConditionSignal($siteA, 'shared|item:2', 1);

        $ids = collect([$atA, $atB, $forClientA, $forClientB, $otherItem])->pluck('id');
        $this->assertCount(5, $ids->unique());
        $this->assertSame($siteB->id, (int) $atB->site_id);
        $this->assertSame($clientB->id, (int) $forClientB->client_id);
    }

    public function test_a_signal_without_a_condition_key_keeps_its_old_behaviour(): void
    {
        $site = Site::factory()->create();

        $first = $this->processConditionSignal($site, null, 1);
        $this->travel(1)->days();
        $second = $this->processConditionSignal($site, null, 2);

        $this->assertNotSame($first->id, $second->id);
    }

    public function test_fleet_maintenance_check_keeps_one_open_alert_per_vehicle(): void
    {
        Queue::fake();
        $this->fleetSource();
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $vanA = $this->overdueVehicle($siteA, '2026-10-10');
        $vanB = $this->overdueVehicle($siteB, '2026-10-15');

        $this->runFleetChecks();
        $this->runFleetChecks(); // a re-run the same day

        $this->assertSame(2, $this->maintenanceAlerts()->count());
        $alertA = $this->maintenanceAlerts()->where('asset_id', $vanA->id)->sole();
        $alertB = $this->maintenanceAlerts()->where('asset_id', $vanB->id)->sole();
        $this->assertSame($siteA->id, (int) $alertA->site_id);
        $this->assertSame($siteB->id, (int) $alertB->site_id);

        $this->travel(1)->days();
        $this->runFleetChecks();

        $this->assertSame(2, $this->maintenanceAlerts()->count());
        $this->assertSame(11, data_get($alertA->fresh()->context, 'signal_payload.days_overdue'));

        app(ControlRoomAlertLifecycleService::class)->resolveAutomatically($alertA->fresh(), 'Booked in for a service.', 'test', 'test');
        $this->travel(1)->days();
        $this->runFleetChecks();

        $this->assertSame(3, $this->maintenanceAlerts()->count());
        $this->assertSame(2, $this->maintenanceAlerts()->where('asset_id', $vanA->id)->count());
        $this->assertSame(1, $this->maintenanceAlerts()->where('asset_id', $vanA->id)->unresolved()->count());
        $this->assertSame(1, $this->maintenanceAlerts()->where('asset_id', $vanB->id)->count());
    }

    public function test_a_fleet_signal_for_a_vehicle_whose_site_is_gone_is_unroutable_at_once(): void
    {
        Queue::fake();
        $this->fleetSource();
        $site = Site::factory()->create();
        $this->overdueVehicle($site, '2026-10-10');
        $site->delete();

        $this->runFleetChecks();

        $outbox = FleetSignalOutbox::query()->sole();
        $this->assertSame('unroutable', $outbox->status);
        $this->assertSame(1, (int) $outbox->attempts);
        $this->assertStringContainsString('Site no longer exists', (string) $outbox->last_error);
        $this->assertSame(0, ControlRoomAlert::query()->count());
    }

    public function test_hs_overdue_checks_keep_one_open_alert_per_item(): void
    {
        $site = Site::factory()->create();
        $hs = app(HsSignalService::class);

        foreach ([1, 2] as $day) {
            $hs->emitInvestigationOverdue(11, 'INV-11', 3 + $day, $site->id);
            $hs->emitCorrectiveActionOverdue(21, 'CA-21', 3 + $day, 'medium', $site->id);
            $hs->emitCorrectiveActionOverdue(22, 'CA-22', 3 + $day, 'medium', $site->id);
            $hs->emitRiskReviewOverdue(31, 'RA-31', 3 + $day, 'low', $site->id);
            $this->travel(1)->days();
        }

        $this->assertSame(1, ControlRoomAlert::query()->where('alert_type', 'Hs Investigation Overdue')->count());
        $this->assertSame(2, ControlRoomAlert::query()->where('alert_type', 'Hs Corrective Action Overdue')->count());
        $this->assertSame(1, ControlRoomAlert::query()->where('alert_type', 'Hs Risk Review Overdue')->count());
        $investigation = ControlRoomAlert::query()->where('alert_type', 'Hs Investigation Overdue')->sole();
        $this->assertSame(5, data_get($investigation->context, 'normalized_data.days_overdue'));
        $this->assertSame($site->id, (int) $investigation->site_id);

        app(ControlRoomAlertLifecycleService::class)->resolveAutomatically($investigation, 'Investigation closed.', 'test', 'test');
        $hs->emitInvestigationOverdue(11, 'INV-11', 6, $site->id);

        $this->assertSame(2, ControlRoomAlert::query()->where('alert_type', 'Hs Investigation Overdue')->count());
        $this->assertSame(1, ControlRoomAlert::query()->where('alert_type', 'Hs Investigation Overdue')->unresolved()->count());
    }

    public function test_controlled_discrepancy_check_keeps_one_open_alert_per_person(): void
    {
        Notification::fake();
        $site = Site::factory()->create();
        $reporter = User::factory()->create();
        $first = $this->clientWithOpenDiscrepancy($site, $reporter);
        $second = $this->clientWithOpenDiscrepancy($site, $reporter);

        foreach ([1, 2, 3] as $day) {
            app(MedicationAlertService::class)->generateClientAlerts($first->fresh());
            app(MedicationAlertService::class)->generateClientAlerts($second->fresh());
            $this->travel(1)->days();
        }

        $alerts = ControlRoomAlert::query()
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.signal_type_code')) = ?", [MedicationSignalService::TYPE_CONTROLLED_DISCREPANCY])
            ->get();
        $this->assertCount(2, $alerts);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $alerts->pluck('client_id')->map(fn ($id) => (int) $id)->all());
        $this->assertTrue($alerts->every(fn (ControlRoomAlert $alert): bool => (int) $alert->site_id === $site->id));
    }

    private function processConditionSignal(
        Site $site,
        ?string $conditionKey,
        int $daysOverdue,
        string $severity = 'medium',
        ?Client $client = null,
    ): ControlRoomAlert {
        $source = SignalSource::query()->firstOrCreate(
            ['slug' => 'health_safety'],
            ['name' => 'Health & Safety Monitoring', 'vendor' => 'internal', 'status' => 'active', 'config' => []],
        );
        $processor = app(SignalProcessingService::class);
        $signal = $processor->ingest([
            'signal_source_id' => $source->id,
            'signal_type_code' => 'standing_condition_test',
            'idempotency_key' => hash('sha256', 'standing-condition-test|'.$conditionKey.'|'.$site->id.'|'.$client?->id.'|'.now()->toISOString()),
            'site_id' => $site->id,
            'client_id' => $client?->id,
            'severity_hint' => $severity,
            'occurred_at' => now(),
            'payload' => [],
            'normalized_data' => array_filter([
                'title' => "Overdue item ({$daysOverdue} days)",
                'days_overdue' => $daysOverdue,
                SignalProcessingService::CONDITION_KEY => $conditionKey,
            ], fn ($value) => $value !== null),
        ]);

        $alert = $processor->process($signal);
        $this->assertNotNull($alert);

        return $alert;
    }

    private function fleetSource(): void
    {
        SignalSource::query()->firstOrCreate(
            ['slug' => 'queclink_fleet'],
            ['name' => 'Queclink Fleet', 'vendor' => 'queclink', 'status' => 'active'],
        );
    }

    private function overdueVehicle(Site $site, string $dueOn): Asset
    {
        return Asset::factory()->vehicle()->create([
            'site_id' => $site->id,
            'home_site_id' => null,
            'requires_maintenance' => true,
            'maintenance_due_at' => $dueOn,
            'wof_expires_at' => null,
            'registration_expires_at' => null,
        ]);
    }

    private function runFleetChecks(): void
    {
        (new FleetAutoAlertJob)->handle(app(FleetSignalService::class));

        FleetSignalOutbox::query()
            ->whereNotIn('status', ['sent', 'dead_letter', 'unroutable'])
            ->pluck('id')
            ->each(fn (int $id) => (new DispatchFleetSignalOutbox($id))->handle(app(SignalProcessingService::class)));
    }

    private function maintenanceAlerts()
    {
        return ControlRoomAlert::query()->where('alert_type', 'Fleet Maintenance Overdue');
    }

    private function clientWithOpenDiscrepancy(Site $site, User $reporter): Client
    {
        $client = Client::factory()->create(['site_id' => $site->id]);
        $order = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Methylphenidate',
            'active' => true,
            'state' => 'active',
            'is_prn' => true,
            'controlled_drug' => true,
            'start_date' => '2026-09-01',
            'end_date' => null,
        ]);
        ClientControlledDrugDiscrepancy::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $order->id,
            'on_hand_before' => 10,
            'on_hand_after' => 9,
            'difference' => -1,
            'status' => 'open',
            'reported_at' => now(),
            'reported_by' => $reporter->id,
        ]);

        return $client;
    }
}
