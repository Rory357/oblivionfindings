<?php

namespace Tests\Feature\ControlRoom;

use App\Console\Commands\AutoResolveStaleAlerts;
use App\Models\ControlRoomAlert;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

class StaleAlertAutoResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-04-07 12:00:00'));
    }

    public function test_stale_open_shift_alert_is_auto_resolved(): void
    {
        $alert = ControlRoomAlert::factory()->create([
            'source' => 'shift_operations',
            'alert_type' => 'Shift No Show',
            'severity' => 'high',
            'status' => 'open',
            'triggered_at' => now()->subHours(30), // 30h > 24h TTL
        ]);

        $this->artisan('control-room:auto-resolve-stale-alerts')
            ->assertExitCode(0);

        $alert->refresh();
        $this->assertSame('resolved', $alert->status);
        $this->assertNotNull($alert->resolved_at);
        $this->assertNull($alert->resolved_by_user_id);
        $this->assertStringContainsString('staleness threshold', $alert->notes);
        $this->assertSame(
            AutoResolveStaleAlerts::RESOLUTION_SOURCE,
            $alert->context['resolution']['source'] ?? null,
        );
    }

    public function test_recent_alert_is_not_auto_resolved(): void
    {
        $alert = ControlRoomAlert::factory()->create([
            'source' => 'shift_operations',
            'alert_type' => 'Shift No Show',
            'severity' => 'high',
            'status' => 'open',
            'triggered_at' => now()->subHours(12), // 12h < 24h TTL
        ]);

        $this->artisan('control-room:auto-resolve-stale-alerts')
            ->assertExitCode(0);

        $alert->refresh();
        $this->assertSame('open', $alert->status);
        $this->assertNull($alert->resolved_at);
    }

    public function test_non_eligible_alert_type_is_not_resolved(): void
    {
        $alert = ControlRoomAlert::factory()->create([
            'source' => 'device_monitoring',
            'alert_type' => 'Device Offline',
            'severity' => 'medium',
            'status' => 'open',
            'triggered_at' => now()->subDays(5),
        ]);

        $this->artisan('control-room:auto-resolve-stale-alerts')
            ->assertExitCode(0);

        $alert->refresh();
        $this->assertSame('open', $alert->status);
    }

    public function test_escalated_alert_is_not_auto_resolved(): void
    {
        $alert = ControlRoomAlert::factory()->create([
            'source' => 'shift_operations',
            'alert_type' => 'Shift No Show',
            'severity' => 'high',
            'status' => 'open',
            'triggered_at' => now()->subHours(30),
            'escalated_at' => now()->subHours(20), // has been escalated
        ]);

        $this->artisan('control-room:auto-resolve-stale-alerts')
            ->assertExitCode(0);

        $alert->refresh();
        $this->assertSame('open', $alert->status);
    }

    public function test_acknowledged_alert_is_not_auto_resolved(): void
    {
        $alert = ControlRoomAlert::factory()->create([
            'source' => 'shift_operations',
            'alert_type' => 'Shift No Show',
            'severity' => 'high',
            'status' => 'ack',
            'triggered_at' => now()->subHours(30),
            'acknowledged_at' => now()->subHours(25),
        ]);

        $this->artisan('control-room:auto-resolve-stale-alerts')
            ->assertExitCode(0);

        $alert->refresh();
        $this->assertSame('ack', $alert->status);
    }

    public function test_candidate_acknowledged_after_selection_is_rechecked_under_lock(): void
    {
        $alert = $this->makeStaleShiftAlert();

        $this->runWithCandidateMutation([
            'status' => ControlRoomAlert::STATUS_ACK,
            'acknowledged_at' => now(),
        ])->assertCommandIsSuccessful();

        $alert->refresh();
        $this->assertSame(ControlRoomAlert::STATUS_ACK, $alert->status);
        $this->assertNull($alert->resolved_at);
    }

    public function test_candidate_escalated_after_selection_is_rechecked_under_lock(): void
    {
        $alert = $this->makeStaleShiftAlert();
        $escalatedAt = now();

        $this->runWithCandidateMutation([
            'escalated_at' => $escalatedAt,
        ])->assertCommandIsSuccessful();

        $alert->refresh();
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->status);
        $this->assertTrue($alert->escalated_at->equalTo($escalatedAt));
        $this->assertNull($alert->resolved_at);
    }

    public function test_candidate_made_recent_after_selection_is_rechecked_under_lock(): void
    {
        $alert = $this->makeStaleShiftAlert();
        $newTriggeredAt = now()->subHour();

        $this->runWithCandidateMutation([
            'triggered_at' => $newTriggeredAt,
        ])->assertCommandIsSuccessful();

        $alert->refresh();
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->status);
        $this->assertTrue($alert->triggered_at->equalTo($newTriggeredAt));
        $this->assertNull($alert->resolved_at);
    }

    public function test_already_resolved_alert_is_not_touched(): void
    {
        $alert = ControlRoomAlert::factory()->create([
            'source' => 'shift_operations',
            'alert_type' => 'Shift Late Start',
            'severity' => 'medium',
            'status' => 'resolved',
            'triggered_at' => now()->subDays(3),
            'resolved_at' => now()->subDay(),
        ]);

        $this->artisan('control-room:auto-resolve-stale-alerts')
            ->assertExitCode(0);

        $alert->refresh();
        $this->assertSame('resolved', $alert->status);
    }

    public function test_resolution_metadata_is_correct(): void
    {
        $alert = ControlRoomAlert::factory()->create([
            'source' => 'shift_operations',
            'alert_type' => 'Shift Late Start',
            'severity' => 'medium',
            'status' => 'open',
            'triggered_at' => now()->subHours(30),
            'context' => ['existing_key' => 'preserved'],
        ]);

        $this->artisan('control-room:auto-resolve-stale-alerts')
            ->assertExitCode(0);

        $alert->refresh();
        $context = $alert->context;

        // Existing context preserved
        $this->assertSame('preserved', $context['existing_key'] ?? null);

        // Resolution metadata written
        $this->assertSame(AutoResolveStaleAlerts::RESOLUTION_SOURCE, $context['resolution']['source']);
        $this->assertSame('system', $context['resolution']['actor']);
        $this->assertSame(24, $context['resolution']['ttl_hours']);
        $this->assertNotEmpty($context['resolution']['resolved_at']);

        // Resolution history appended
        $this->assertCount(1, $context['resolution_history']);
        $this->assertSame(AutoResolveStaleAlerts::RESOLUTION_SOURCE, $context['resolution_history'][0]['source']);
        $this->assertSame('system', $context['resolution_history'][0]['actor']);
    }

    public function test_idempotent_on_repeated_runs(): void
    {
        $alert = ControlRoomAlert::factory()->create([
            'source' => 'shift_operations',
            'alert_type' => 'Shift Uncovered',
            'severity' => 'high',
            'status' => 'open',
            'triggered_at' => now()->subHours(50), // > 48h TTL
        ]);

        $this->artisan('control-room:auto-resolve-stale-alerts')
            ->assertExitCode(0);

        $alert->refresh();
        $this->assertSame('resolved', $alert->status);
        $firstResolvedAt = $alert->resolved_at->toISOString();

        // Run again — should not update anything
        $this->artisan('control-room:auto-resolve-stale-alerts')
            ->assertExitCode(0);

        $alert->refresh();
        $this->assertSame($firstResolvedAt, $alert->resolved_at->toISOString());
        $this->assertCount(1, $alert->context['resolution_history'] ?? []);
    }

    public function test_dry_run_does_not_resolve_alerts(): void
    {
        ControlRoomAlert::factory()->create([
            'source' => 'shift_operations',
            'alert_type' => 'Shift No Show',
            'severity' => 'high',
            'status' => 'open',
            'triggered_at' => now()->subHours(30),
        ]);

        $this->artisan('control-room:auto-resolve-stale-alerts --dry-run')
            ->assertExitCode(0);

        $this->assertSame(
            1,
            ControlRoomAlert::where('status', 'open')->count(),
        );
    }

    public function test_orphan_detection_alert_uses_longer_ttl(): void
    {
        // 5 days old — below 168h (7 day) TTL
        $recentOrphan = ControlRoomAlert::factory()->create([
            'source' => 'shift_operations',
            'alert_type' => 'Completed Shift Missing Timesheet',
            'severity' => 'high',
            'status' => 'open',
            'triggered_at' => now()->subDays(5),
        ]);

        // 8 days old — above 168h TTL
        $staleOrphan = ControlRoomAlert::factory()->create([
            'source' => 'shift_operations',
            'alert_type' => 'Completed Shift Missing Timesheet',
            'severity' => 'high',
            'status' => 'open',
            'triggered_at' => now()->subDays(8),
        ]);

        $this->artisan('control-room:auto-resolve-stale-alerts')
            ->assertExitCode(0);

        $recentOrphan->refresh();
        $staleOrphan->refresh();

        $this->assertSame('open', $recentOrphan->status);
        $this->assertSame('resolved', $staleOrphan->status);
    }

    public function test_command_succeeds_with_no_eligible_alerts(): void
    {
        $this->artisan('control-room:auto-resolve-stale-alerts')
            ->assertExitCode(0);
    }

    public function test_multiple_alert_types_resolved_in_single_run(): void
    {
        ControlRoomAlert::factory()->create([
            'source' => 'shift_operations',
            'alert_type' => 'Shift No Show',
            'status' => 'open',
            'triggered_at' => now()->subHours(30),
        ]);

        ControlRoomAlert::factory()->create([
            'source' => 'shift_operations',
            'alert_type' => 'Shift Late Start',
            'status' => 'open',
            'triggered_at' => now()->subHours(30),
        ]);

        ControlRoomAlert::factory()->create([
            'source' => 'shift_operations',
            'alert_type' => 'Shift Not Completed',
            'status' => 'open',
            'triggered_at' => now()->subHours(30),
        ]);

        $this->artisan('control-room:auto-resolve-stale-alerts')
            ->assertExitCode(0);

        $this->assertSame(0, ControlRoomAlert::where('status', 'open')->count());
        $this->assertSame(3, ControlRoomAlert::where('status', 'resolved')->count());
    }

    private function makeStaleShiftAlert(): ControlRoomAlert
    {
        return ControlRoomAlert::factory()->create([
            'source' => 'shift_operations',
            'alert_type' => 'Shift No Show',
            'severity' => 'high',
            'status' => ControlRoomAlert::STATUS_OPEN,
            'triggered_at' => now()->subHours(30),
            'escalated_at' => null,
        ]);
    }

    /** @param array<string, mixed> $changes */
    private function runWithCandidateMutation(array $changes): CommandTester
    {
        $command = new class($changes) extends AutoResolveStaleAlerts
        {
            /** @param array<string, mixed> $changes */
            public function __construct(private readonly array $changes)
            {
                parent::__construct();
            }

            protected function resolveAsStale(
                ControlRoomAlert $alert,
                int $ttlHours,
                ControlRoomAlertLifecycleService $lifecycle,
            ): bool {
                ControlRoomAlert::query()
                    ->whereKey($alert->id)
                    ->update($this->changes);

                return parent::resolveAsStale($alert, $ttlHours, $lifecycle);
            }
        };
        $command->setLaravel($this->app);

        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }
}
