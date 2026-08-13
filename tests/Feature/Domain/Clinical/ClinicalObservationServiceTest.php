<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Events\ObservationRecorded;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Clinical\Models\ClinicalProtocol;
use App\Domain\Clinical\Models\ClinicalProtocolSchedule;
use App\Domain\Clinical\Services\ClinicalObservationService;
use App\Models\Client;
use App\Models\Shift;
use App\Models\TimelineEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ClinicalObservationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ClinicalObservationService $service;
    protected Client $client;
    protected User $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ClinicalObservationService::class);
        $this->client = Client::factory()->create();
        $this->recorder = User::factory()->create();
    }

    // ── record() ─────────────────────────────────────────────────────────

    public function test_records_vitals_observation(): void
    {
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Vitals,
            'data' => ['systolic' => 120, 'diastolic' => 80, 'pulse' => 72, 'temperature' => 36.8],
        ]);

        $this->assertDatabaseHas('clinical_observations', [
            'id' => $observation->id,
            'client_id' => $this->client->id,
            'recorded_by' => $this->recorder->id,
            'observation_type' => 'vitals',
        ]);
        $this->assertEquals(120, $observation->data['systolic']);
    }

    public function test_records_weight_observation(): void
    {
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Weight,
            'data' => ['weight_kg' => 72.5],
            'notes' => 'Before breakfast',
        ]);

        $this->assertEquals(ObservationType::Weight, $observation->observation_type);
        $this->assertEquals(72.5, $observation->data['weight_kg']);
        $this->assertEquals('Before breakfast', $observation->notes);
    }

    public function test_records_bowel_observation(): void
    {
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => 'bowel',
            'data' => ['bristol_type' => 4],
        ]);

        $this->assertEquals(ObservationType::Bowel, $observation->observation_type);
    }

    public function test_records_sleep_observation(): void
    {
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Sleep,
            'data' => ['bed_time' => '22:00', 'wake_time' => '07:00', 'quality' => 'good'],
        ]);

        $this->assertEquals('good', $observation->data['quality']);
    }

    public function test_records_fluid_intake_observation(): void
    {
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::FluidIntake,
            'data' => ['amount_ml' => 250, 'fluid_type' => 'water'],
        ]);

        $this->assertEquals(250, $observation->data['amount_ml']);
    }

    public function test_records_pain_observation(): void
    {
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Pain,
            'data' => ['score' => 6, 'location' => 'lower back'],
        ]);

        $this->assertEquals(6, $observation->data['score']);
    }

    public function test_records_general_observation(): void
    {
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::General,
            'data' => [],
            'notes' => 'Client appeared well today',
        ]);

        $this->assertEquals(ObservationType::General, $observation->observation_type);
    }

    public function test_accepts_string_observation_type(): void
    {
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => 'weight',
            'data' => ['weight_kg' => 70],
        ]);

        $this->assertEquals(ObservationType::Weight, $observation->observation_type);
    }

    // ── Shift context ────────────────────────────────────────────────────

    public function test_records_with_shift_context(): void
    {
        $shift = Shift::factory()->create(['client_id' => $this->client->id]);

        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Vitals,
            'data' => ['systolic' => 120, 'diastolic' => 80, 'pulse' => 72],
        ], $shift);

        $this->assertEquals($shift->id, $observation->shift_id);
        $this->assertEquals($shift->site_id, $observation->site_id);
    }

    public function test_records_without_shift_uses_client_site(): void
    {
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Weight,
            'data' => ['weight_kg' => 70],
        ]);

        $this->assertNull($observation->shift_id);
        $this->assertEquals($this->client->site_id, $observation->site_id);
    }

    // ── Timeline event ───────────────────────────────────────────────────

    public function test_creates_timeline_event(): void
    {
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Vitals,
            'data' => ['systolic' => 130, 'diastolic' => 85, 'pulse' => 80],
        ]);

        $this->assertDatabaseHas('timeline_events', [
            'type' => ClinicalObservationService::TIMELINE_TYPE_OBSERVATION,
            'source_type' => ClinicalObservation::class,
            'source_id' => $observation->id,
            'client_id' => $this->client->id,
            'actor_user_id' => $this->recorder->id,
        ]);
    }

    public function test_timeline_body_includes_vital_signs_summary(): void
    {
        $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Vitals,
            'data' => ['systolic' => 130, 'diastolic' => 85, 'pulse' => 80, 'o2_saturation' => 98],
        ]);

        $timeline = TimelineEvent::where('type', ClinicalObservationService::TIMELINE_TYPE_OBSERVATION)->first();
        $this->assertStringContainsString('BP 130/85', $timeline->body);
        $this->assertStringContainsString('Pulse 80', $timeline->body);
    }

    public function test_timeline_body_includes_weight_summary(): void
    {
        $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Weight,
            'data' => ['weight_kg' => 72.5],
        ]);

        $timeline = TimelineEvent::where('type', ClinicalObservationService::TIMELINE_TYPE_OBSERVATION)->first();
        $this->assertStringContainsString('72.5 kg', $timeline->body);
    }

    // ── Protocol schedule completion ─────────────────────────────────────

    public function test_completes_protocol_schedule_when_provided(): void
    {
        $protocol = ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $this->client->id,
        ]);
        $schedule = ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $protocol->id,
            'due_at' => now(),
            'status' => 'pending',
        ]);

        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Weight,
            'data' => ['weight_kg' => 70],
            'protocol_schedule_id' => $schedule->id,
        ]);

        $schedule->refresh();
        $this->assertEquals('completed', $schedule->status);
        $this->assertEquals($this->recorder->id, $schedule->completed_by);
        $this->assertEquals($observation->id, $schedule->clinical_observation_id);
        $this->assertDatabaseHas('clinical_observations', [
            'id' => $observation->id,
            'client_id' => $this->client->id,
            'observation_type' => ObservationType::Weight->value,
            'protocol_schedule_id' => $schedule->id,
        ]);
        $this->assertSame(1, TimelineEvent::query()
            ->where('type', ClinicalObservationService::TIMELINE_TYPE_OBSERVATION)
            ->where('source_id', $observation->id)
            ->count());
    }

    public function test_rejects_already_completed_schedule_without_partial_writes(): void
    {
        $protocol = ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $this->client->id,
        ]);
        $schedule = ClinicalProtocolSchedule::factory()->completed()->create([
            'clinical_protocol_id' => $protocol->id,
        ]);
        $original = $schedule->only([
            'status',
            'completed_by',
            'completed_at',
            'clinical_observation_id',
        ]);

        $this->assertScheduleRejected($this->client, ObservationType::Weight, $schedule);

        $schedule->refresh();
        $this->assertSame($original['status'], $schedule->status);
        $this->assertSame($original['completed_by'], $schedule->completed_by);
        $this->assertTrue($schedule->completed_at->equalTo($original['completed_at']));
        $this->assertSame($original['clinical_observation_id'], $schedule->clinical_observation_id);
        $this->assertDatabaseCount('clinical_observations', 0);
        $this->assertDatabaseCount('timeline_events', 0);
    }

    public function test_rejects_cross_resident_and_cross_type_schedules_without_partial_writes(): void
    {
        $otherClient = Client::factory()->create();
        $otherResidentProtocol = ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $otherClient->id,
        ]);
        $otherResidentSchedule = ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $otherResidentProtocol->id,
        ]);

        $this->assertScheduleRejected($this->client, ObservationType::Weight, $otherResidentSchedule);

        $wrongTypeProtocol = ClinicalProtocol::factory()->everyShiftVitals()->create([
            'client_id' => $this->client->id,
            'frequency' => 'daily',
        ]);
        $wrongTypeSchedule = ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $wrongTypeProtocol->id,
        ]);

        $this->assertScheduleRejected($this->client, ObservationType::Weight, $wrongTypeSchedule);

        $this->assertDatabaseCount('clinical_observations', 0);
        $this->assertDatabaseCount('timeline_events', 0);
        $this->assertSame('pending', $otherResidentSchedule->fresh()->status);
        $this->assertSame('pending', $wrongTypeSchedule->fresh()->status);
    }

    public function test_rejects_inactive_expired_and_wrong_frequency_protocol_schedules(): void
    {
        $protocols = [
            ClinicalProtocol::factory()->dailyWeight()->inactive()->create([
                'client_id' => $this->client->id,
            ]),
            ClinicalProtocol::factory()->dailyWeight()->expired()->create([
                'client_id' => $this->client->id,
            ]),
            ClinicalProtocol::factory()->everyShiftVitals()->create([
                'client_id' => $this->client->id,
                'observation_type' => ObservationType::Weight,
            ]),
        ];

        foreach ($protocols as $protocol) {
            $schedule = ClinicalProtocolSchedule::factory()->create([
                'clinical_protocol_id' => $protocol->id,
            ]);

            $this->assertScheduleRejected($this->client, ObservationType::Weight, $schedule);
            $this->assertSame('pending', $schedule->fresh()->status);
        }

        $this->assertDatabaseCount('clinical_observations', 0);
        $this->assertDatabaseCount('timeline_events', 0);
    }

    public function test_rejects_missed_and_skipped_stale_schedules(): void
    {
        $protocol = ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $this->client->id,
        ]);

        foreach (['missed', 'skipped'] as $status) {
            $schedule = ClinicalProtocolSchedule::factory()->create([
                'clinical_protocol_id' => $protocol->id,
                'status' => $status,
                'skip_reason' => $status === 'skipped' ? 'Resident unavailable' : null,
            ]);

            $this->assertScheduleRejected($this->client, ObservationType::Weight, $schedule);
            $this->assertSame($status, $schedule->fresh()->status);
        }

        $this->assertDatabaseCount('clinical_observations', 0);
        $this->assertDatabaseCount('timeline_events', 0);
    }

    public function test_rolls_back_observation_timeline_schedule_and_audit_when_a_later_effect_fails(): void
    {
        $protocol = ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $this->client->id,
        ]);
        $schedule = ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $protocol->id,
        ]);
        $auditCountBefore = DB::table('audit_logs')->count();

        Event::listen(ObservationRecorded::class, static function (): never {
            throw new RuntimeException('Forced post-completion failure.');
        });

        try {
            $this->service->record($this->client, $this->recorder, [
                'observation_type' => ObservationType::Weight,
                'data' => ['weight_kg' => 70],
                'protocol_schedule_id' => $schedule->id,
            ]);
            self::fail('The forced post-completion failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced post-completion failure.', $exception->getMessage());
        }

        $schedule->refresh();
        $this->assertSame('pending', $schedule->status);
        $this->assertNull($schedule->completed_by);
        $this->assertNull($schedule->completed_at);
        $this->assertNull($schedule->clinical_observation_id);
        $this->assertDatabaseCount('clinical_observations', 0);
        $this->assertDatabaseCount('timeline_events', 0);
        $this->assertSame($auditCountBefore, DB::table('audit_logs')->count());
    }

    public function test_concurrent_schedule_completion_records_one_observation_and_one_final_effect(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());

        $protocol = ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $this->client->id,
            'created_by' => $this->recorder->id,
        ]);
        $schedule = ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $protocol->id,
            'due_at' => now(),
        ]);
        $database = $connection->getDatabaseName();
        $token = Str::uuid()->toString();
        $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."clinical-schedule-release-{$token}";
        $readyPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."clinical-schedule-ready-a-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."clinical-schedule-ready-b-{$token}",
        ];
        $attemptPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."clinical-schedule-attempt-a-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."clinical-schedule-attempt-b-{$token}",
        ];
        $processes = [];
        $clientId = $this->client->id;
        $recorderId = $this->recorder->id;
        $protocolId = $protocol->id;
        $scheduleId = $schedule->id;

        // RefreshDatabase's transaction must be committed so independent MySQL
        // workers can see the fixtures. A replacement transaction is opened in
        // finally for the framework teardown callback.
        $connection->commit();

        try {
            $connection->beginTransaction();
            ClinicalProtocol::query()->whereKey($protocolId)->lockForUpdate()->firstOrFail();

            foreach ([0, 1] as $index) {
                $processes[] = $this->startScheduleCompletionWorker(
                    $clientId,
                    $recorderId,
                    $scheduleId,
                    $readyPaths[$index],
                    $attemptPaths[$index],
                    $releasePath,
                    $database,
                );
            }

            $this->waitForFiles($readyPaths, 'Both schedule workers did not connect.');
            touch($releasePath);
            $this->waitForFiles($attemptPaths, 'Both schedule workers did not reach the command.');
            usleep(250_000);

            foreach ($processes as $process) {
                $this->assertTrue(
                    $process->isRunning(),
                    trim($process->getErrorOutput()) ?: 'A worker exited before the protocol lock was released.',
                );
            }

            $connection->commit();

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    trim($process->getErrorOutput()) ?: 'A schedule concurrency worker failed.',
                );
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            $statuses = collect($results)->pluck('status')->sort()->values()->all();
            $this->assertSame(['recorded', 'rejected'], $statuses);

            $observations = ClinicalObservation::query()
                ->where('protocol_schedule_id', $scheduleId)
                ->get();
            $this->assertCount(1, $observations);
            $observation = $observations->sole();

            $schedule->refresh();
            $this->assertSame('completed', $schedule->status);
            $this->assertSame($recorderId, $schedule->completed_by);
            $this->assertSame($observation->id, $schedule->clinical_observation_id);
            $this->assertNotNull($schedule->completed_at);
            $this->assertSame(1, TimelineEvent::query()
                ->where('type', ClinicalObservationService::TIMELINE_TYPE_OBSERVATION)
                ->where('source_id', $observation->id)
                ->count());
            $this->assertSame(1, DB::table('audit_logs')
                ->where('action', 'clinicalprotocolschedule.update')
                ->where('auditable_id', $scheduleId)
                ->count());
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            foreach ([...$readyPaths, ...$attemptPaths, $releasePath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            try {
                DB::table('audit_logs')
                    ->where('client_id', $clientId)
                    ->orWhere('user_id', $recorderId)
                    ->orWhere(function ($query) use ($scheduleId) {
                        $query->where('auditable_type', ClinicalProtocolSchedule::class)
                            ->where('auditable_id', $scheduleId);
                    })
                    ->delete();
                DB::table('timeline_events')->where('client_id', $clientId)->delete();
                DB::table('clinical_protocol_schedules')->where('id', $scheduleId)->delete();
                DB::table('clinical_observations')->where('client_id', $clientId)->delete();
                DB::table('clinical_protocols')->where('id', $protocolId)->delete();
                DB::table('clients')->where('id', $clientId)->delete();
                DB::table('users')->where('id', $recorderId)->delete();
            } finally {
                $connection->beginTransaction();
            }
        }
    }

    // ── Domain event ─────────────────────────────────────────────────────

    public function test_dispatches_observation_recorded_event(): void
    {
        Event::fake([ObservationRecorded::class]);

        $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Weight,
            'data' => ['weight_kg' => 70],
        ]);

        Event::assertDispatched(ObservationRecorded::class, function ($event) {
            return $event->observation->client_id === $this->client->id;
        });
    }

    // ── Validation ───────────────────────────────────────────────────────

    public function test_rejects_vitals_missing_required_fields(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Vitals,
            'data' => ['systolic' => 120], // missing diastolic and pulse
        ]);
    }

    public function test_rejects_weight_missing_weight_kg(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::Weight,
            'data' => ['notes' => 'forgot to weigh'],
        ]);
    }

    public function test_allows_general_observation_with_empty_data(): void
    {
        $observation = $this->service->record($this->client, $this->recorder, [
            'observation_type' => ObservationType::General,
            'data' => [],
            'notes' => 'Client settled well',
        ]);

        $this->assertNotNull($observation->id);
    }

    // ── getLatest() ──────────────────────────────────────────────────────

    public function test_get_latest_returns_recent_observations(): void
    {
        ClinicalObservation::factory()->count(5)->create([
            'client_id' => $this->client->id,
        ]);
        ClinicalObservation::factory()->create(); // different client

        $results = $this->service->getLatest($this->client);
        $this->assertCount(5, $results);
    }

    public function test_get_latest_filters_by_type(): void
    {
        ClinicalObservation::factory()->vitals()->create(['client_id' => $this->client->id]);
        ClinicalObservation::factory()->weight()->create(['client_id' => $this->client->id]);
        ClinicalObservation::factory()->vitals()->create(['client_id' => $this->client->id]);

        $results = $this->service->getLatest($this->client, ObservationType::Vitals);
        $this->assertCount(2, $results);
    }

    // ── getTrends() ──────────────────────────────────────────────────────

    public function test_get_trends_returns_data_within_range(): void
    {
        ClinicalObservation::factory()->weight()->create([
            'client_id' => $this->client->id,
            'recorded_at' => now()->subDays(3),
        ]);
        ClinicalObservation::factory()->weight()->create([
            'client_id' => $this->client->id,
            'recorded_at' => now()->subDays(1),
        ]);
        ClinicalObservation::factory()->weight()->create([
            'client_id' => $this->client->id,
            'recorded_at' => now()->subDays(10),
        ]);

        $results = $this->service->getTrends(
            $this->client,
            ObservationType::Weight,
            now()->subDays(7),
            now(),
        );

        $this->assertCount(2, $results);
    }

    private function assertScheduleRejected(
        Client $client,
        ObservationType $type,
        ClinicalProtocolSchedule $schedule,
    ): void {
        try {
            $this->service->record($client, $this->recorder, [
                'observation_type' => $type,
                'data' => ['weight_kg' => 70],
                'protocol_schedule_id' => $schedule->id,
            ]);
            self::fail('The invalid protocol schedule was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('protocol_schedule_id', $exception->errors());
        }
    }

    private function startScheduleCompletionWorker(
        int $clientId,
        int $recorderId,
        int $scheduleId,
        string $readyPath,
        string $attemptPath,
        string $releasePath,
        string $database,
    ): Process {
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$client = App\Models\Client::query()->findOrFail((int) $argv[2]);
$recorder = App\Models\User::query()->findOrFail((int) $argv[3]);
$scheduleId = (int) $argv[4];
file_put_contents($argv[5], (string) Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
$deadline = microtime(true) + 15;
while (! is_file($argv[7])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the schedule concurrency release barrier.');
    }
    usleep(10_000);
}
file_put_contents($argv[6], 'attempting');
try {
    $observation = $app->make(App\Domain\Clinical\Services\ClinicalObservationService::class)->record(
        $client,
        $recorder,
        [
            'observation_type' => App\Domain\Clinical\Enums\ObservationType::Weight,
            'data' => ['weight_kg' => 70],
            'protocol_schedule_id' => $scheduleId,
        ],
    );
    $result = ['status' => 'recorded', 'observation_id' => $observation->id];
} catch (Illuminate\Validation\ValidationException $exception) {
    $result = ['status' => 'rejected', 'errors' => array_keys($exception->errors())];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;

        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                $worker,
                base_path(),
                (string) $clientId,
                (string) $recorderId,
                (string) $scheduleId,
                $readyPath,
                $attemptPath,
                $releasePath,
            ],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => $database,
                'QUEUE_CONNECTION' => 'sync',
            ],
        );
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    /** @param list<string> $paths */
    private function waitForFiles(array $paths, string $message): void
    {
        $deadline = microtime(true) + 15;

        do {
            if (collect($paths)->every(fn (string $path): bool => is_file($path))) {
                return;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException($message);
    }
}
