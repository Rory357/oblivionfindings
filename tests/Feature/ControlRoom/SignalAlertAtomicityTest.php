<?php

namespace Tests\Feature\ControlRoom;

use App\Models\Client;
use App\Models\ControlRoom\MaintenanceWindow;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoom\SignalType;
use App\Models\ControlRoomAlert;
use App\Models\Site;
use App\Services\ControlRoom\ControlRoomNotificationService;
use App\Services\ControlRoom\SignalProcessingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SignalAlertAtomicityTest extends TestCase
{
    use RefreshDatabase;

    protected SignalProcessingService $service;

    protected ControlRoomNotificationService $notifications;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notifications = $this->mock(ControlRoomNotificationService::class);
        $this->notifications
            ->shouldReceive('stageAlertNotifications')
            ->andReturn(collect())
            ->byDefault();
        $this->service = new SignalProcessingService($this->notifications);
    }

    public function test_normal_processing_persists_one_typed_origin_and_retry_returns_it(): void
    {
        $this->notifications
            ->shouldReceive('stageAlertNotifications')
            ->once()
            ->andReturn(collect());
        $signal = $this->pendingSignal('normal-origin-retry');

        $created = $this->service->process($signal);
        $retry = $this->service->process($signal->fresh());

        $this->assertNotNull($created);
        $this->assertTrue($created->is($retry));
        $this->assertSame($signal->id, $created->fresh()->origin_signal_id);
        $this->assertSame($created->id, $signal->fresh()->alert_id);
        $this->assertSame('processed', $signal->fresh()->status);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('action', 'controlRoom.alert.created')
                ->where('auditable_id', $created->id)
                ->count(),
        );
    }

    public function test_retry_recovers_a_stale_signal_from_durable_origin_without_side_effects(): void
    {
        $this->notifications->shouldNotReceive('stageAlertNotifications');
        $signal = $this->pendingSignal('durable-origin-recovery');
        $alert = ControlRoomAlert::factory()->open()->create([
            'origin_signal_id' => $signal->id,
            'source' => $signal->signalSource->slug,
            'site_id' => null,
            'client_id' => null,
            'asset_id' => null,
            'device_id' => null,
            'context' => ['signal_id' => $signal->id],
        ]);

        $result = $this->service->process($signal);

        $this->assertTrue($result?->is($alert));
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertSame('processed', $signal->fresh()->status);
        $this->assertSame($alert->id, $signal->fresh()->alert_id);
        $this->assertNull($signal->fresh()->correlated_alert_id);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'controlRoom.alert.created',
            'auditable_id' => $alert->id,
        ]);
        $this->assertDatabaseCount('control_room_communications', 0);
    }

    public function test_failure_between_alert_insert_and_signal_link_rolls_back_every_phase(): void
    {
        $signal = $this->pendingSignal('crash-window-rollback');
        $service = new class($this->notifications) extends SignalProcessingService
        {
            protected function afterOriginAlertCreated(
                Signal $signal,
                ControlRoomAlert $alert,
            ): void {
                throw new RuntimeException('Injected interruption after alert insert.');
            }
        };

        try {
            $service->process($signal);
            $this->fail('Injected failure must escape the atomic transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected interruption after alert insert.', $exception->getMessage());
        }

        $this->assertSame('pending', $signal->fresh()->status);
        $this->assertNull($signal->fresh()->alert_id);
        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'controlRoom.alert.created']);
        $this->assertDatabaseCount('control_room_communications', 0);
    }

    public function test_database_rejects_a_second_alert_for_one_origin_signal(): void
    {
        $signal = $this->pendingSignal('unique-origin-contract');
        ControlRoomAlert::factory()->open()->create([
            'origin_signal_id' => $signal->id,
        ]);

        try {
            ControlRoomAlert::factory()->open()->create([
                'origin_signal_id' => $signal->id,
            ]);
            $this->fail('The database must reject duplicate origin signal ownership.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', (string) $exception->getCode());
        }

        $this->assertSame(1, ControlRoomAlert::query()->where('origin_signal_id', $signal->id)->count());
    }

    public function test_wrong_site_relationship_fails_without_partial_alert_side_effects(): void
    {
        $signalSite = Site::factory()->create();
        $foreignSite = Site::factory()->create();
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
        $signal = $this->pendingSignal('wrong-site-signal', [
            'site_id' => $signalSite->id,
            'client_id' => $foreignClient->id,
            'normalized_data' => [
                'site_id' => $signalSite->id,
                'client_id' => $foreignClient->id,
            ],
        ]);

        $this->assertSame(0, $this->service->processAllPending(1));

        $this->assertSame('failed', $signal->fresh()->status);
        $this->assertStringContainsString('different Site', $signal->fresh()->processing_notes);
        $this->assertDatabaseCount('control_room_alerts', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'controlRoom.alert.created']);
        $this->assertDatabaseCount('control_room_communications', 0);
    }

    public function test_maintenance_suppression_retains_lifecycle_without_claiming_an_alert(): void
    {
        $signal = $this->pendingSignal('maintenance-suppression');
        MaintenanceWindow::create([
            'name' => 'Planned test maintenance',
            'signal_source_id' => $signal->signal_source_id,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addMinute(),
            'status' => 'active',
        ]);

        $this->assertNull($this->service->process($signal));

        $this->assertSame('suppressed', $signal->fresh()->status);
        $this->assertNull($signal->fresh()->alert_id);
        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_two_independent_mysql_workers_serialize_to_one_alert_and_link(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $signal = $this->pendingSignal('parallel-worker-origin');
        $database = $connection->getDatabaseName();
        $token = Str::uuid()->toString();
        $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."cr-signal-go-{$token}";
        $readyPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."cr-signal-ready-a-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."cr-signal-ready-b-{$token}",
        ];
        $processes = [];
        $sourceId = $signal->signal_source_id;
        $signalTypeId = $signal->signal_type_id;

        // Make fixtures visible to both independent connections before either
        // worker enters the row-locked canonical processing transaction.
        $connection->commit();

        try {
            foreach ($readyPaths as $readyPath) {
                $process = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/ControlRoomSignalProcessorWorker.php'),
                    $database,
                    (string) $signal->id,
                    $readyPath,
                    $releasePath,
                ]);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }

            foreach ($readyPaths as $index => $readyPath) {
                $this->waitForWorker($processes[$index], $readyPath);
            }

            file_put_contents($releasePath, 'go', LOCK_EX);

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    trim($process->getErrorOutput()) ?: 'Concurrent signal worker failed.',
                );
                $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }

            $alertIds = collect($results)->pluck('alert_id')->unique()->values();
            $this->assertCount(1, $alertIds);
            $this->assertNotNull($alertIds->first());
            $this->assertSame(1, DB::table('control_room_alerts')->where('origin_signal_id', $signal->id)->count());
            $this->assertDatabaseHas('control_room_signals', [
                'id' => $signal->id,
                'status' => 'processed',
                'alert_id' => $alertIds->first(),
                'correlated_alert_id' => null,
            ]);
            $this->assertSame(
                1,
                DB::table('audit_logs')
                    ->where('action', 'controlRoom.alert.created')
                    ->where('auditable_id', $alertIds->first())
                    ->count(),
            );
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ([...$readyPaths, $releasePath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            try {
                DB::table('control_room_signals')->where('id', $signal->id)->update([
                    'alert_id' => null,
                    'correlated_alert_id' => null,
                ]);
                $alertIds = DB::table('control_room_alerts')
                    ->where('origin_signal_id', $signal->id)
                    ->pluck('id');
                DB::table('audit_logs')
                    ->where('auditable_type', (new ControlRoomAlert)->getMorphClass())
                    ->whereIn('auditable_id', $alertIds)
                    ->delete();
                DB::table('control_room_alerts')->whereIn('id', $alertIds)->delete();
                DB::table('control_room_signals')->where('id', $signal->id)->delete();
                DB::table('control_room_signal_types')->where('id', $signalTypeId)->delete();
                DB::table('control_room_signal_sources')->where('id', $sourceId)->delete();
            } finally {
                $connection->beginTransaction();
            }
        }
    }

    private function pendingSignal(string $key, array $overrides = []): Signal
    {
        $source = SignalSource::create([
            'name' => 'Atomic signal source '.$key,
            'slug' => 'atomic-signal-'.Str::slug($key),
            'category' => 'operations',
            'vendor' => 'internal',
            'status' => 'active',
        ]);
        $signalType = SignalType::create([
            'code' => 'atomic.'.str_replace('-', '_', Str::slug($key)),
            'name' => 'Atomic signal '.$key,
            'category' => 'operations',
            'default_severity' => 'high',
        ]);

        return Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_id' => $signalType->id,
            'signal_type_code' => $signalType->code,
            'idempotency_key' => $key,
            'severity_hint' => 'high',
            'occurred_at' => now(),
            'payload' => ['test' => true],
            'normalized_data' => [],
            'status' => 'pending',
            ...$overrides,
        ]);
    }

    private function waitForWorker(Process $process, string $readyPath): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($readyPath)) {
            if (! $process->isRunning()) {
                $this->fail(trim($process->getErrorOutput()) ?: 'Signal worker exited before becoming ready.');
            }
            if (microtime(true) >= $deadline) {
                $this->fail('Signal worker did not reach the concurrency barrier.');
            }

            usleep(20_000);
        }
    }
}
