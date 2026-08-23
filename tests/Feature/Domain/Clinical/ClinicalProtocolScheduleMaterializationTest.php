<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Enums\ProtocolFrequency;
use App\Domain\Clinical\Models\ClinicalProtocol;
use App\Domain\Clinical\Models\ClinicalProtocolSchedule;
use App\Domain\Clinical\Models\ClinicalProtocolScheduleMaterialization;
use App\Domain\Clinical\Services\ClinicalProtocolService;
use App\Domain\Clinical\Services\ClinicalSiteAccessService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ClinicalProtocolScheduleMaterializationTest extends TestCase
{
    use RefreshDatabase;

    private ClinicalProtocolService $service;

    private Site $siteA;

    private Site $siteB;

    private Client $clientA;

    private Client $clientB;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ClinicalPermissionsSeeder::class);
        $this->siteA = Site::factory()->create(['name' => 'Protocol Site A', 'is_active' => true]);
        $this->siteB = Site::factory()->create(['name' => 'Protocol Site B', 'is_active' => true]);
        $this->clientA = Client::factory()->create(['site_id' => $this->siteA->id, 'status' => 'active']);
        $this->clientB = Client::factory()->create(['site_id' => $this->siteB->id, 'status' => 'active']);
        $this->actor = $this->userAtSite('coordinator', $this->siteA);
        $this->service = app(ClinicalProtocolService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_active_http_creation_materializes_once_and_replay_converges(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-22 10:00:00', 'UTC'));
        $key = (string) Str::uuid();
        $payload = [
            'idempotency_key' => $key,
            'client_id' => $this->clientA->id,
            'name' => 'Twice daily pain monitoring',
            'observation_type' => ObservationType::Pain->value,
            'frequency' => ProtocolFrequency::TwiceDaily->value,
            'instructions' => 'Record before medication rounds.',
            'alert_if_missed_hours' => 12,
            'is_active' => true,
            'starts_at' => '2026-08-22',
            'ends_at' => '2026-08-23',
        ];

        $this->actingAs($this->actor)->post('/health-clinical/protocols', $payload)
            ->assertRedirect('/health-clinical/protocols');
        $this->actingAs($this->actor)->post('/health-clinical/protocols', $payload)
            ->assertRedirect('/health-clinical/protocols');

        $protocol = ClinicalProtocol::query()->sole();
        $this->assertSame(1, ClinicalProtocol::query()->count());
        $this->assertSame(4, $protocol->schedules()->count());
        $this->assertEquals([
            '2026-08-22 10:00:00',
            '2026-08-22 22:00:00',
            '2026-08-23 10:00:00',
            '2026-08-23 22:00:00',
        ], $protocol->schedules()->orderBy('due_at')->pluck('due_at')->map(
            fn ($dueAt): string => CarbonImmutable::parse((string) $dueAt, 'UTC')->format('Y-m-d H:i:s'),
        )->all());
        $this->assertSame(1, ClinicalProtocolScheduleMaterialization::query()->count());
        $this->assertSame(4, ClinicalProtocolSchedule::query()->distinct('occurrence_key')->count('occurrence_key'));
    }

    public function test_each_time_based_frequency_materializes_a_bounded_schedule_visible_to_due_and_overdue_consumers(): void
    {
        $effectiveAt = CarbonImmutable::parse('2026-08-22 10:00:00', 'UTC');
        Carbon::setTestNow($effectiveAt);
        CarbonImmutable::setTestNow($effectiveAt);
        $cases = [
            [ProtocolFrequency::Daily, null, 31],
            [ProtocolFrequency::TwiceDaily, null, 61],
            [ProtocolFrequency::Weekly, null, 5],
            [ProtocolFrequency::Fortnightly, null, 3],
            [ProtocolFrequency::Monthly, null, 2],
            [ProtocolFrequency::Custom, 6, 121],
        ];
        $clients = [];

        foreach ($cases as [$frequency, $customHours, $expectedCount]) {
            $client = Client::factory()->create([
                'site_id' => $this->siteA->id,
                'status' => 'active',
            ]);
            $clients[] = [$client, $expectedCount];
            $data = $this->protocolData($client);
            $data['name'] = "{$frequency->value} observations";
            $data['frequency'] = $frequency->value;
            $data['custom_frequency_hours'] = $customHours;
            $data['ends_at'] = '2026-09-30';

            $this->actingAs($this->actor)
                ->post('/health-clinical/protocols', [
                    ...$data,
                    'idempotency_key' => (string) Str::uuid(),
                ])
                ->assertRedirect('/health-clinical/protocols');
            $protocol = ClinicalProtocol::query()
                ->where('client_id', $client->id)
                ->sole();

            $this->assertSame($expectedCount, $protocol->schedules()->count());
            $this->assertSame($expectedCount, $this->service->getDueForClient($client)->count());
            $this->assertLessThanOrEqual(ClinicalProtocolService::MAX_OCCURRENCES_PER_COMMAND, $expectedCount);
        }

        $oneSecondLater = $effectiveAt->addSecond();
        Carbon::setTestNow($oneSecondLater);
        CarbonImmutable::setTestNow($oneSecondLater);
        foreach ($clients as [$client]) {
            $this->assertSame(1, $this->service->getOverdue($client)->count());
        }
    }

    public function test_inactive_and_every_shift_commands_are_durable_no_ops_then_activation_materializes(): void
    {
        $effectiveAt = CarbonImmutable::parse('2026-08-22 08:00:00', 'UTC');
        $inactive = ClinicalProtocol::factory()->dailyWeight()->inactive()->create([
            'client_id' => $this->clientA->id,
            'created_by' => $this->actor->id,
            'starts_at' => '2026-08-22',
            'ends_at' => '2026-08-23',
        ]);

        $stillInactive = $this->service->setActive(
            $this->actor,
            $inactive->id,
            false,
            (string) Str::uuid(),
            $effectiveAt,
        );
        $this->assertFalse($stillInactive->is_active);
        $this->assertSame(0, $inactive->schedules()->count());

        $activationKey = (string) Str::uuid();
        $activated = $this->service->setActive(
            $this->actor,
            $inactive->id,
            true,
            $activationKey,
            $effectiveAt,
        );
        $replayed = $this->service->setActive(
            $this->actor,
            $inactive->id,
            true,
            $activationKey,
            $effectiveAt,
        );
        $this->assertTrue($activated->is_active);
        $this->assertTrue($replayed->is_active);
        $this->assertSame(2, $activated->schedule_version);
        $this->assertSame(2, $inactive->schedules()->count());

        $everyShift = ClinicalProtocol::factory()->everyShiftVitals()->create([
            'client_id' => $this->clientA->id,
            'created_by' => $this->actor->id,
        ]);
        $occurrences = $this->service->reconcileSchedule(
            $this->actor,
            $everyShift->id,
            $effectiveAt,
            $effectiveAt->addDay(),
            (string) Str::uuid(),
        );
        $this->assertCount(0, $occurrences);
        $this->assertSame(0, $everyShift->schedules()->count());
    }

    public function test_exact_inclusive_window_and_timezone_keep_one_anchor_across_overlaps(): void
    {
        $protocol = ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $this->clientA->id,
            'created_by' => $this->actor->id,
            'starts_at' => '2026-08-22',
            'ends_at' => '2026-08-24',
            'schedule_anchor_at' => null,
        ]);
        $firstFrom = CarbonImmutable::parse('2026-08-22 08:00:00', 'Pacific/Auckland');
        $firstTo = CarbonImmutable::parse('2026-08-23 08:00:00', 'Pacific/Auckland');

        $first = $this->service->reconcileSchedule(
            $this->actor,
            $protocol->id,
            $firstFrom,
            $firstTo,
            (string) Str::uuid(),
            'Pacific/Auckland',
        );
        $overlap = $this->service->reconcileSchedule(
            $this->actor,
            $protocol->id,
            CarbonImmutable::parse('2026-08-22 12:00:00', 'Pacific/Auckland'),
            CarbonImmutable::parse('2026-08-24 08:00:00', 'Pacific/Auckland'),
            (string) Str::uuid(),
            'Pacific/Auckland',
        );

        $this->assertCount(2, $first);
        $this->assertCount(2, $overlap);
        $this->assertSame([
            '2026-08-21T20:00:00Z',
            '2026-08-22T20:00:00Z',
            '2026-08-23T20:00:00Z',
        ], $protocol->schedules()->orderBy('due_at')->get()->map(
            fn (ClinicalProtocolSchedule $schedule): string => $schedule->due_at->utc()->format('Y-m-d\TH:i:s\Z'),
        )->all());
        $this->assertSame('2026-08-21T20:00:00Z', $protocol->fresh()->schedule_anchor_at->utc()->format('Y-m-d\TH:i:s\Z'));
    }

    public function test_legacy_occurrences_supply_the_cadence_anchor_without_rewriting_history(): void
    {
        $protocol = ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $this->clientA->id,
            'created_by' => $this->actor->id,
            'starts_at' => null,
            'ends_at' => null,
            'schedule_anchor_at' => null,
        ]);
        $legacyDueAt = CarbonImmutable::parse('2026-08-20 06:00:00', 'UTC');
        $legacy = ClinicalProtocolSchedule::factory()->completed()->create([
            'clinical_protocol_id' => $protocol->id,
            'due_at' => $legacyDueAt,
        ]);

        $occurrences = $this->service->reconcileSchedule(
            $this->actor,
            $protocol->id,
            CarbonImmutable::parse('2026-08-22 08:00:00', 'UTC'),
            CarbonImmutable::parse('2026-08-24 08:00:00', 'UTC'),
            (string) Str::uuid(),
        );

        $this->assertCount(2, $occurrences);
        $this->assertSame('completed', $legacy->fresh()->status);
        $this->assertSame($legacyDueAt->toIso8601ZuluString(), $protocol->fresh()->schedule_anchor_at->toIso8601ZuluString());
        $this->assertSame([
            '2026-08-23T06:00:00Z',
            '2026-08-24T06:00:00Z',
        ], $occurrences->pluck('due_at')->map(
            fn ($dueAt): string => CarbonImmutable::instance($dueAt)->utc()->format('Y-m-d\TH:i:s\Z'),
        )->all());
    }

    public function test_same_key_changed_window_conflicts_without_partial_materialization(): void
    {
        $protocol = ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $this->clientA->id,
            'created_by' => $this->actor->id,
        ]);
        $from = CarbonImmutable::parse('2026-08-22 08:00:00', 'UTC');
        $key = (string) Str::uuid();
        $this->service->reconcileSchedule(
            $this->actor,
            $protocol->id,
            $from,
            $from->addDay(),
            $key,
        );

        try {
            $this->service->reconcileSchedule(
                $this->actor,
                $protocol->id,
                $from,
                $from->addDays(2),
                $key,
            );
            $this->fail('A changed scheduling request must conflict.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }

        $this->assertSame(2, $protocol->schedules()->count());
        $this->assertSame(1, ClinicalProtocolScheduleMaterialization::query()->count());
    }

    public function test_update_can_establish_a_new_cadence_before_history_and_extend_its_window_without_drift(): void
    {
        $effectiveAt = CarbonImmutable::parse('2026-08-22 08:00:00', 'UTC');
        $protocol = ClinicalProtocol::factory()->dailyWeight()->inactive()->create([
            'client_id' => $this->clientA->id,
            'created_by' => $this->actor->id,
            'starts_at' => '2026-08-22',
            'ends_at' => '2026-08-22',
        ]);
        $updated = $this->service->updateProtocol(
            $this->actor,
            $protocol->id,
            [
                'frequency' => ProtocolFrequency::Custom->value,
                'custom_frequency_hours' => 6,
                'is_active' => true,
                'ends_at' => '2026-08-22',
            ],
            (string) Str::uuid(),
            $effectiveAt,
        );
        $this->assertSame(2, $updated->schedule_version);
        $this->assertSame(3, $updated->schedules()->count());

        $extended = $this->service->updateProtocol(
            $this->actor,
            $updated->id,
            ['ends_at' => '2026-08-23'],
            (string) Str::uuid(),
            $effectiveAt,
        );
        $this->assertSame(7, $extended->schedules()->count());
        $this->assertSame(7, $extended->schedules()->distinct('occurrence_key')->count('occurrence_key'));
    }

    public function test_deactivation_and_window_changes_reconcile_only_future_pending_rows(): void
    {
        $effectiveAt = CarbonImmutable::parse('2026-08-22 08:00:00', 'UTC');
        $protocol = ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $this->clientA->id,
            'created_by' => $this->actor->id,
            'starts_at' => '2026-08-18',
            'ends_at' => '2026-08-30',
        ]);
        $historicalPending = ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $protocol->id,
            'due_at' => $effectiveAt->subDay(),
            'status' => 'pending',
        ]);
        $historicalCompleted = ClinicalProtocolSchedule::factory()->completed()->create([
            'clinical_protocol_id' => $protocol->id,
            'due_at' => $effectiveAt->subHours(12),
        ]);
        $futureInsideWindow = ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $protocol->id,
            'due_at' => $effectiveAt->addDay(),
            'status' => 'pending',
        ]);
        $futureOutsideWindow = ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $protocol->id,
            'due_at' => $effectiveAt->addDays(3),
            'status' => 'pending',
        ]);

        $updated = $this->service->updateProtocol(
            $this->actor,
            $protocol->id,
            [
                'starts_at' => '2026-08-22',
                'ends_at' => '2026-08-23',
            ],
            (string) Str::uuid(),
            $effectiveAt,
        );

        $this->assertSame('pending', $historicalPending->fresh()->status);
        $this->assertSame('completed', $historicalCompleted->fresh()->status);
        $this->assertSame('pending', $futureInsideWindow->fresh()->status);
        $this->assertSame('skipped', $futureOutsideWindow->fresh()->status);

        $this->service->setActive(
            $this->actor,
            $updated->id,
            false,
            (string) Str::uuid(),
            $effectiveAt,
        );

        $this->assertSame('pending', $historicalPending->fresh()->status);
        $this->assertSame('completed', $historicalCompleted->fresh()->status);
        $this->assertSame('skipped', $futureInsideWindow->fresh()->status);
        $this->assertSame(0, $updated->fresh()->schedules()->where('status', 'pending')->where('due_at', '>=', $effectiveAt)->count());
    }

    public function test_daily_reconciliation_extends_the_active_horizon_and_replay_is_stable(): void
    {
        $effectiveAt = CarbonImmutable::parse('2026-08-22 10:00:00', 'UTC');
        $protocol = $this->service->createProtocol(
            $this->actor,
            [
                ...$this->protocolData($this->clientA),
                'ends_at' => null,
            ],
            (string) Str::uuid(),
            $effectiveAt,
            'UTC',
        );
        $inactive = ClinicalProtocol::factory()->dailyWeight()->inactive()->create([
            'client_id' => $this->clientA->id,
            'created_by' => $this->actor->id,
        ]);
        $everyShift = ClinicalProtocol::factory()->everyShiftVitals()->create([
            'client_id' => $this->clientA->id,
            'created_by' => $this->actor->id,
        ]);

        $this->assertSame(31, $protocol->schedules()->count());
        foreach (range(1, 2) as $_) {
            $this->artisan('clinical:reconcile-protocol-schedules', [
                '--at' => '2026-08-23T12:00:00Z',
                '--timezone' => 'UTC',
            ])->expectsOutput('Reconciled 1 clinical protocols across 31 bounded occurrences.')
                ->assertSuccessful();
        }

        $this->assertSame(32, $protocol->schedules()->count());
        $this->assertSame(32, $protocol->schedules()->distinct('occurrence_key')->count('occurrence_key'));
        $this->assertSame(0, $inactive->schedules()->count());
        $this->assertSame(0, $everyShift->schedules()->count());
        $scheduledCommand = ClinicalProtocolScheduleMaterialization::query()
            ->where('clinical_protocol_id', $protocol->id)
            ->where('action', 'scheduled_reconcile')
            ->sole();
        $this->assertNull($scheduledCommand->requested_by);
        $this->assertSame(31, $scheduledCommand->occurrence_count);

        $scheduledEvent = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains(
                (string) ($event->command ?? ''),
                'clinical:reconcile-protocol-schedules',
            ));
        $this->assertNotNull($scheduledEvent);
        $this->assertSame('10 0 * * *', $scheduledEvent->expression);
        $this->assertSame('UTC', (string) $scheduledEvent->timezone);
        $this->assertTrue($scheduledEvent->onOneServer);
        $this->assertTrue($scheduledEvent->withoutOverlapping);
    }

    public function test_direct_service_calls_require_exact_action_and_canonical_site_client_protocol_scope(): void
    {
        $foreignProtocol = ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $this->clientB->id,
            'created_by' => $this->actor->id,
        ]);
        $beforeCommands = ClinicalProtocolScheduleMaterialization::query()->count();

        $viewer = $this->userAtSite('team_lead', $this->siteA);
        try {
            $this->service->createProtocol(
                $viewer,
                $this->protocolData($this->clientA),
                (string) Str::uuid(),
                CarbonImmutable::parse('2026-08-22 08:00:00', 'UTC'),
            );
            $this->fail('Site access without the protocol-manage capability must not materialize schedules.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        foreach (['create', 'reconcile'] as $operation) {
            try {
                if ($operation === 'create') {
                    $this->service->createProtocol(
                        $this->actor,
                        $this->protocolData($this->clientB),
                        (string) Str::uuid(),
                        CarbonImmutable::parse('2026-08-22 08:00:00', 'UTC'),
                    );
                } else {
                    $this->service->reconcileSchedule(
                        $this->actor,
                        $foreignProtocol->id,
                        CarbonImmutable::parse('2026-08-22 08:00:00', 'UTC'),
                        CarbonImmutable::parse('2026-08-23 08:00:00', 'UTC'),
                        (string) Str::uuid(),
                    );
                }
                $this->fail('Foreign Client and protocol IDs must be concealed.');
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame($beforeCommands, ClinicalProtocolScheduleMaterialization::query()->count());
        $this->assertSame(0, $foreignProtocol->schedules()->count());

        $global = $this->userAtSite('clinical_lead', $this->siteA);
        $created = $this->service->createProtocol(
            $global,
            $this->protocolData($this->clientB),
            (string) Str::uuid(),
            CarbonImmutable::parse('2026-08-22 08:00:00', 'UTC'),
        );
        $this->assertSame($this->clientB->id, $created->client_id);
        $this->assertGreaterThan(0, $created->schedules()->count());
    }

    public function test_materialization_failure_rolls_back_protocol_command_anchor_and_occurrences(): void
    {
        $failing = new class(app(ClinicalSiteAccessService::class)) extends ClinicalProtocolService
        {
            private int $attempts = 0;

            protected function persistOccurrence(
                ClinicalProtocol $protocol,
                CarbonImmutable $dueAt,
            ): ClinicalProtocolSchedule {
                $occurrence = parent::persistOccurrence($protocol, $dueAt);
                if (++$this->attempts === 2) {
                    throw new RuntimeException('Injected schedule materialization failure.');
                }

                return $occurrence;
            }
        };

        try {
            $failing->createProtocol(
                $this->actor,
                $this->protocolData($this->clientA),
                (string) Str::uuid(),
                CarbonImmutable::parse('2026-08-22 08:00:00', 'UTC'),
            );
            $this->fail('The injected occurrence failure must abort the aggregate transition.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected schedule materialization failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('clinical_protocols', 0);
        $this->assertDatabaseCount('clinical_protocol_schedules', 0);
        $this->assertDatabaseCount('clinical_protocol_schedule_materializations', 0);
    }

    public function test_concurrent_reconciliation_converges_on_one_occurrence_set(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $protocol = ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $this->clientA->id,
            'created_by' => $this->actor->id,
            'starts_at' => '2026-08-22',
            'ends_at' => '2026-08-24',
        ]);
        $from = CarbonImmutable::parse('2026-08-22 08:00:00', 'UTC');
        $to = $from->addDays(2);
        $database = $connection->getDatabaseName();
        $token = (string) Str::uuid();
        $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."clinical-materialization-release-{$token}";
        $readyPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."clinical-materialization-ready-a-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."clinical-materialization-ready-b-{$token}",
        ];
        $attemptPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."clinical-materialization-attempt-a-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."clinical-materialization-attempt-b-{$token}",
        ];
        $keys = [(string) Str::uuid(), (string) Str::uuid()];
        $processes = [];
        $protocolId = (int) $protocol->id;
        $actorId = (int) $this->actor->id;
        $clientIds = [(int) $this->clientA->id, (int) $this->clientB->id];
        $siteIds = [(int) $this->siteA->id, (int) $this->siteB->id];

        $connection->commit();

        try {
            $connection->beginTransaction();
            ClinicalProtocol::query()->whereKey($protocolId)->lockForUpdate()->firstOrFail();

            foreach ([0, 1] as $index) {
                $processes[] = $this->startReconciliationWorker(
                    $database,
                    $actorId,
                    $protocolId,
                    $from,
                    $to,
                    $keys[$index],
                    $readyPaths[$index],
                    $attemptPaths[$index],
                    $releasePath,
                );
            }

            $this->waitForFiles($readyPaths, 'Both materialization workers did not connect.');
            touch($releasePath);
            $this->waitForFiles($attemptPaths, 'Both materialization workers did not reach reconciliation.');
            usleep(250_000);

            foreach ($processes as $process) {
                $this->assertTrue(
                    $process->isRunning(),
                    trim($process->getErrorOutput()) ?: 'A worker exited before the protocol lock was released.',
                );
            }

            $connection->commit();

            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    trim($process->getErrorOutput()) ?: 'A schedule materialization worker failed.',
                );
                $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame(3, $result['occurrence_count']);
            }

            $this->assertSame(3, ClinicalProtocolSchedule::query()->where('clinical_protocol_id', $protocolId)->count());
            $this->assertSame(3, ClinicalProtocolSchedule::query()
                ->where('clinical_protocol_id', $protocolId)
                ->distinct('occurrence_key')
                ->count('occurrence_key'));
            $this->assertSame(2, ClinicalProtocolScheduleMaterialization::query()
                ->where('clinical_protocol_id', $protocolId)
                ->where('action', 'reconcile')
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

            $scheduleIds = DB::table('clinical_protocol_schedules')
                ->where('clinical_protocol_id', $protocolId)
                ->pluck('id');
            DB::table('audit_logs')->where(function ($audit) use ($actorId, $clientIds, $protocolId, $scheduleIds): void {
                $audit->where('user_id', $actorId)
                    ->orWhereIn('client_id', $clientIds)
                    ->orWhere(function ($protocolAudit) use ($protocolId): void {
                        $protocolAudit->where('auditable_type', ClinicalProtocol::class)
                            ->where('auditable_id', $protocolId);
                    })
                    ->orWhere(function ($scheduleAudit) use ($scheduleIds): void {
                        $scheduleAudit->where('auditable_type', ClinicalProtocolSchedule::class)
                            ->whereIn('auditable_id', $scheduleIds);
                    });
            })->delete();
            DB::table('clinical_protocol_schedule_materializations')->where('clinical_protocol_id', $protocolId)->delete();
            DB::table('clinical_protocol_schedules')->where('clinical_protocol_id', $protocolId)->delete();
            DB::table('clinical_protocols')->where('id', $protocolId)->delete();
            DB::table('hr_employee_profiles')->where('user_id', $actorId)->delete();
            DB::table('role_user')->where('user_id', $actorId)->delete();
            DB::table('clients')->whereIn('id', $clientIds)->delete();
            DB::table('users')->where('id', $actorId)->delete();
            DB::table('sites')->whereIn('id', $siteIds)->delete();
            $connection->beginTransaction();
        }
    }

    /** @return array<string, mixed> */
    private function protocolData(Client $client): array
    {
        return [
            'client_id' => $client->id,
            'name' => 'Canonical daily observations',
            'observation_type' => ObservationType::Weight->value,
            'frequency' => ProtocolFrequency::Daily->value,
            'custom_frequency_hours' => null,
            'instructions' => null,
            'alert_if_missed_hours' => 24,
            'is_active' => true,
            'starts_at' => '2026-08-22',
            'ends_at' => '2026-08-24',
        ];
    }

    private function userAtSite(string $roleName, Site $site): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);
        $role = Role::query()->where('name', $roleName)->firstOrFail();
        $user->roles()->attach($role);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return $user;
    }

    private function startReconciliationWorker(
        string $database,
        int $actorId,
        int $protocolId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $idempotencyKey,
        string $readyPath,
        string $attemptPath,
        string $releasePath,
    ): Process {
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$actor = App\Models\User::query()->findOrFail((int) $argv[2]);
file_put_contents($argv[7], (string) Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
$deadline = microtime(true) + 15;
while (! is_file($argv[9])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the schedule materialization release barrier.');
    }
    usleep(10_000);
}
file_put_contents($argv[8], 'attempting');
$occurrences = $app->make(App\Domain\Clinical\Services\ClinicalProtocolService::class)->reconcileSchedule(
    $actor,
    (int) $argv[3],
    Carbon\CarbonImmutable::parse($argv[4], 'UTC'),
    Carbon\CarbonImmutable::parse($argv[5], 'UTC'),
    $argv[6],
    'UTC',
);
echo json_encode(['occurrence_count' => $occurrences->count()], JSON_THROW_ON_ERROR);
PHP;

        $process = new Process([
            PHP_BINARY,
            '-r',
            $worker,
            base_path(),
            (string) $actorId,
            (string) $protocolId,
            $from->format('Y-m-d H:i:s'),
            $to->format('Y-m-d H:i:s'),
            $idempotencyKey,
            $readyPath,
            $attemptPath,
            $releasePath,
        ], base_path(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => $database,
            'QUEUE_CONNECTION' => 'sync',
        ]);
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
