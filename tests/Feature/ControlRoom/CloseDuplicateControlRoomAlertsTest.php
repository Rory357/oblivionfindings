<?php

namespace Tests\Feature\ControlRoom;

use App\Console\Commands\CloseDuplicateControlRoomAlerts;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoomAlert;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\Medication\DoseSlots\OverdueDoses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The one-off clean-up keeps one open alert per overdue item, closes the
 * rest through the automated resolve with a reason, never closes an alert
 * with open tasks, and stamps the kept alert so the daily check refreshes it.
 */
class CloseDuplicateControlRoomAlertsTest extends TestCase
{
    use RefreshDatabase;

    private string $manifest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-30 01:00:00', 'UTC'));
        $this->manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cr-dup-'.uniqid().'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->manifest);

        parent::tearDown();
    }

    public function test_dry_run_counts_and_changes_nothing(): void
    {
        [$site, $van] = $this->vehicleAtSite();
        $this->fleetAlerts($site, $van, 3);

        $this->artisan('control-room:close-duplicate-alerts', ['--dry-run' => true])
            ->expectsOutputToContain('Fleet Maintenance Overdue: 1 items, 1 kept, 2 would close, 0 skipped')
            ->assertExitCode(0);

        $this->assertSame(3, ControlRoomAlert::query()->unresolved()->count());
    }

    public function test_a_real_run_needs_a_manifest(): void
    {
        $this->artisan('control-room:close-duplicate-alerts')->assertExitCode(1);
    }

    public function test_it_keeps_one_open_alert_per_item_and_closes_the_rest_with_a_reason(): void
    {
        [$siteA, $vanA] = $this->vehicleAtSite();
        [$siteB, $vanB] = $this->vehicleAtSite();
        $aAlerts = $this->fleetAlerts($siteA, $vanA, 3);
        $worked = $aAlerts[0];
        $worked->forceFill(['status' => 'ack', 'acknowledged_at' => now()->subDays(2)])->save();
        $bAlerts = $this->fleetAlerts($siteB, $vanB, 2);
        $alreadyResolved = ControlRoomAlert::factory()->resolved()->create($this->fleetAttributes($siteA, $vanA, 9));

        $caAlerts = collect(range(1, 3))->map(fn (int $day) => ControlRoomAlert::factory()->create([
            'source' => 'health_safety',
            'alert_type' => 'Hs Corrective Action Overdue',
            'site_id' => $siteA->id,
            'triggered_at' => now()->subDays($day),
            'context' => ['signal_type_code' => 'hs_corrective_action_overdue', 'normalized_data' => ['hs_corrective_action_id' => 21]],
        ]));
        $otherAction = ControlRoomAlert::factory()->create([
            'source' => 'health_safety',
            'alert_type' => 'Hs Corrective Action Overdue',
            'site_id' => $siteA->id,
            'triggered_at' => now()->subDays(5),
            'context' => ['signal_type_code' => 'hs_corrective_action_overdue', 'normalized_data' => ['hs_corrective_action_id' => 22]],
        ]);
        AlertTask::query()->create([
            'alert_id' => $caAlerts[2]->id,
            'title' => 'Chase the contractor',
            'priority' => 'medium',
            'status' => 'open',
            'due_at' => now()->addDay(),
            'created_by_user_id' => User::factory()->create()->id,
            'sort_order' => 1,
        ]);

        $this->artisan('control-room:close-duplicate-alerts', ['--manifest' => $this->manifest])
            ->assertExitCode(0);

        // Vehicle A: the alert a person acknowledged is kept, the other two closed.
        $this->assertSame('ack', $worked->fresh()->status);
        foreach ([$aAlerts[1], $aAlerts[2]] as $duplicate) {
            $duplicate->refresh();
            $this->assertSame('resolved', $duplicate->status);
            $this->assertSame(CloseDuplicateControlRoomAlerts::RESOLUTION_CODE, $duplicate->resolution_code);
            $this->assertSame(CloseDuplicateControlRoomAlerts::RESOLUTION_SOURCE, data_get($duplicate->context, 'resolution.source'));
            $this->assertSame($worked->id, data_get($duplicate->context, 'resolution.kept_alert_id'));
            $this->assertStringContainsString("Duplicate of alert #{$worked->id}", (string) $duplicate->notes);
        }
        $this->assertSame(
            SignalProcessingService::conditionKey('fleet_maintenance_overdue', 'asset', $vanA->id),
            data_get($worked->fresh()->context, 'normalized_data.condition_key'),
        );

        // Vehicle B (another Site) keeps its own newest alert.
        $this->assertSame('open', $bAlerts[0]->fresh()->status);
        $this->assertSame('resolved', $bAlerts[1]->fresh()->status);
        $this->assertSame($bAlerts[0]->id, data_get($bAlerts[1]->fresh()->context, 'resolution.kept_alert_id'));

        // Corrective action 21: the alert with an open task is the one kept;
        // the other two are closed as its duplicates.
        $this->assertSame('open', $caAlerts[2]->fresh()->status);
        $this->assertSame('resolved', $caAlerts[0]->fresh()->status);
        $this->assertSame('resolved', $caAlerts[1]->fresh()->status);
        $this->assertSame(
            SignalProcessingService::conditionKey('hs_corrective_action_overdue', 'entity', 'corrective_action_21'),
            data_get($caAlerts[2]->fresh()->context, 'normalized_data.condition_key'),
        );
        $this->assertSame('open', $otherAction->fresh()->status);
        $this->assertSame('resolved', $alreadyResolved->fresh()->status);

        $this->assertSame(4, ControlRoomAlert::query()->unresolved()->count());

        $rows = collect(file($this->manifest, FILE_IGNORE_NEW_LINES))->map(fn (string $line) => json_decode($line, true));
        $this->assertCount(9, $rows);
        $this->assertSame(5, $rows->where('action', 'close')->count());
        $this->assertSame(4, $rows->where('action', 'keep')->count());
        $this->assertFalse($rows->pluck('id')->contains($alreadyResolved->id));
    }

    public function test_old_medication_alerts_that_name_no_dose_close_only_when_replaced(): void
    {
        $this->travelTo(Carbon::parse('2026-04-29 00:00', 'Pacific/Auckland')->utc());
        $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $context = ServiceContext::factory()->create(['type' => 'residential', 'is_active' => true]);
        $person = fn () => Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $context->id, 'status' => 'active']);
        $withPerDoseAlert = $person();
        $nothingOverdue = $person();
        $stillOverdue = $person();
        ClientMedication::create([
            'client_id' => $stillOverdue->id, 'name' => 'Synthetic amlodipine 5mg', 'dosage' => '1 tablet', 'dose_amount' => 1,
            'dose_unit' => 'tablet', 'frequency' => 'Daily', 'dose_times' => ['08:00'], 'is_prn' => false, 'controlled_drug' => false,
            'active' => true, 'state' => 'active', 'approval_status' => 'verified', 'start_date' => '2026-04-01',
        ]);
        // 10:00 NZ: the 08:00 dose (window to 09:00) is overdue and nothing raised it per dose.
        $this->travelTo(Carbon::parse('2026-04-30 10:00', 'Pacific/Auckland')->utc());
        $this->assertNotEmpty(app(OverdueDoses::class)->at(now(), [$stillOverdue->id]));

        $legacy = fn (Client $client, int $count) => collect(range(1, $count))->map(fn (int $day) => ControlRoomAlert::factory()->create([
            'source' => 'medication',
            'alert_type' => 'Medication Overdue',
            'site_id' => $site->id,
            'client_id' => $client->id,
            'triggered_at' => now()->subDays($day),
            'context' => ['signal_type_code' => 'medication_overdue', 'normalized_data' => ['client_id' => $client->id]],
        ]));
        $replaced = $legacy($withPerDoseAlert, 3);
        $perDose = ControlRoomAlert::factory()->create([
            'source' => 'medication',
            'alert_type' => 'Medication Overdue',
            'site_id' => $site->id,
            'client_id' => $withPerDoseAlert->id,
            'triggered_at' => now()->subHour(),
            'context' => ['signal_type_code' => 'medication_overdue', 'normalized_data' => [
                'client_id' => $withPerDoseAlert->id, 'dose_key' => '99@2026-04-29T20:00Z', 'dose_spell_key' => '99@2026-04-29T20:00Z~1',
            ]],
        ]);
        $noLongerOverdue = $legacy($nothingOverdue, 2);
        $mayBeOnlyAlert = $legacy($stillOverdue, 2);

        $this->artisan('control-room:close-duplicate-alerts', [
            '--type' => [CloseDuplicateControlRoomAlerts::LEGACY_MEDICATION_TYPE],
            '--manifest' => $this->manifest,
        ])
            ->expectsOutputToContain('Medication Overdue (no dose named): 3 people, 2 kept open, 5 closed, 0 skipped')
            ->assertExitCode(0);

        foreach ($replaced as $alert) {
            $alert->refresh();
            $this->assertSame('resolved', $alert->status);
            $this->assertSame(CloseDuplicateControlRoomAlerts::SUPERSEDED_CODE, $alert->resolution_code);
            $this->assertStringContainsString('Replaced by per-dose alerts', (string) $alert->notes);
            $this->assertSame($perDose->id, data_get($alert->context, 'resolution.per_dose_alert_id'));
        }
        $this->assertSame('open', $perDose->fresh()->status);
        foreach ($noLongerOverdue as $alert) {
            $this->assertSame('resolved', $alert->fresh()->status);
            $this->assertSame('no_overdue_dose', data_get($alert->fresh()->context, 'resolution.replaced_by'));
        }
        foreach ($mayBeOnlyAlert as $alert) {
            $this->assertSame('open', $alert->fresh()->status, 'An overdue dose with no per-dose alert keeps its old alerts.');
        }
    }

    public function test_a_duplicate_with_open_tasks_is_never_closed(): void
    {
        [$site, $van] = $this->vehicleAtSite();
        $alerts = $this->fleetAlerts($site, $van, 2);
        foreach ($alerts as $alert) {
            AlertTask::query()->create([
                'alert_id' => $alert->id,
                'title' => 'Book a service',
                'priority' => 'medium',
                'status' => 'open',
                'due_at' => now()->addDay(),
                'created_by_user_id' => User::factory()->create()->id,
                'sort_order' => 1,
            ]);
        }

        $this->artisan('control-room:close-duplicate-alerts', ['--manifest' => $this->manifest])
            ->expectsOutputToContain('Fleet Maintenance Overdue: 1 items, 1 kept, 0 closed, 1 skipped')
            ->assertExitCode(0);

        $this->assertSame(2, ControlRoomAlert::query()->unresolved()->count());
    }

    /** @return array{0: Site, 1: Asset} */
    private function vehicleAtSite(): array
    {
        $site = Site::factory()->create();

        return [$site, Asset::factory()->vehicle()->create(['site_id' => $site->id])];
    }

    /** @return list<ControlRoomAlert> newest first */
    private function fleetAlerts(Site $site, Asset $van, int $count): array
    {
        return collect(range(1, $count))
            ->map(fn (int $day) => ControlRoomAlert::factory()->create($this->fleetAttributes($site, $van, $day)))
            ->all();
    }

    private function fleetAttributes(Site $site, Asset $van, int $daysAgo): array
    {
        return [
            'source' => 'queclink_fleet',
            'alert_type' => 'Fleet Maintenance Overdue',
            'site_id' => $site->id,
            'asset_id' => $van->id,
            'client_id' => null,
            'severity' => 'high',
            'triggered_at' => now()->subDays($daysAgo),
            'context' => ['signal_type_code' => 'fleet_maintenance_overdue', 'normalized_data' => []],
        ];
    }
}
