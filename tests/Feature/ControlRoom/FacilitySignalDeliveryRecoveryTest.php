<?php

namespace Tests\Feature\ControlRoom;

use App\Jobs\DispatchFacilitySignalOutbox;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoomAlert;
use App\Models\FacilitySignal;
use App\Models\FacilitySignalOutbox;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteInspectionRecord;
use App\Models\SiteInspectionSchedule;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomNotificationService;
use App\Services\ControlRoom\SafetySignalDeliveryRecoveryService;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\Facility\FacilitySignalService;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FacilitySignalDeliveryRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-31 10:00:00');
        Queue::fake();
        $this->seed(RbacSeeder::class);

        $this->site = Site::factory()->create([
            'name' => 'Facility Delivery Site',
            'type' => 'facility',
            'is_active' => true,
        ]);
        $this->user = User::factory()->create([
            'name' => 'Facility Delivery Manager',
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->user->roles()->sync([
            Role::query()->where('name', 'admin')->firstOrFail()->id,
        ]);
        $this->user = $this->user->fresh(['roles']);

        $notifications = $this->mock(ControlRoomNotificationService::class);
        $notifications->shouldReceive('notifyAlert')->andReturnNull();
        $notifications->shouldReceive('stageAlertNotifications')->andReturn(collect());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_failed_inspection_acceptance_rolls_back_when_outbox_persistence_fails(): void
    {
        $schedule = $this->schedule();
        $dueDate = $schedule->next_due_date->toDateString();
        $eventName = 'eloquent.creating: '.FacilitySignalOutbox::class;

        Event::listen($eventName, function (): never {
            throw new RuntimeException('injected Facility outbox persistence failure');
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->user)->post(
                route('sites.inspections.complete', [$this->site, $schedule]),
                [
                    'result' => 'fail',
                    'findings' => 'Emergency lighting failed.',
                    'corrective_actions' => 'Replace the failed fitting.',
                ],
            );
            $this->fail('The injected persistence failure should abort inspection acceptance.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'injected Facility outbox persistence failure',
                $exception->getMessage(),
            );
        } finally {
            Event::forget($eventName);
        }

        $this->assertDatabaseCount('site_inspection_records', 0);
        $this->assertDatabaseCount('facility_signals', 0);
        $this->assertDatabaseCount('facility_signal_outbox', 0);
        $this->assertSame($dueDate, $schedule->fresh()->next_due_date->toDateString());
        Queue::assertNothingPushed();
    }

    public function test_failed_emission_rejects_non_failed_and_mismatched_canonical_ownership(): void
    {
        $schedule = $this->schedule();
        $otherSchedule = $this->schedule(['title' => 'Other inspection']);
        $otherSite = Site::factory()->create([
            'name' => 'Other Facility Site',
            'type' => 'facility',
            'is_active' => true,
        ]);
        $records = [
            SiteInspectionRecord::query()->create([
                'schedule_id' => $schedule->id,
                'site_id' => $this->site->id,
                'due_date' => $schedule->next_due_date,
                'completed_at' => now(),
                'completed_by_user_id' => $this->user->id,
                'result' => 'pass',
            ]),
            SiteInspectionRecord::query()->create([
                'schedule_id' => $otherSchedule->id,
                'site_id' => $this->site->id,
                'due_date' => $otherSchedule->next_due_date,
                'completed_at' => now(),
                'completed_by_user_id' => $this->user->id,
                'result' => 'fail',
            ]),
            SiteInspectionRecord::query()->create([
                'schedule_id' => $schedule->id,
                'site_id' => $otherSite->id,
                'due_date' => $schedule->next_due_date,
                'completed_at' => now(),
                'completed_by_user_id' => $this->user->id,
                'result' => 'fail',
            ]),
        ];

        foreach ($records as $record) {
            try {
                app(FacilitySignalService::class)->emitInspectionFailed($schedule, $record);
                $this->fail('Non-canonical failed inspection evidence must be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('exact persisted failed inspection', $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('facility_signals', 0);
        $this->assertDatabaseCount('facility_signal_outbox', 0);
        Queue::assertNothingPushed();
    }

    public function test_outer_acceptance_rollback_discards_an_already_registered_dispatch(): void
    {
        $schedule = $this->schedule();
        $dueDate = $schedule->next_due_date->toDateString();
        $eventName = 'eloquent.updating: '.SiteInspectionSchedule::class;

        Event::listen($eventName, function (): never {
            throw new RuntimeException('injected schedule advance failure');
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->user)->post(
                route('sites.inspections.complete', [$this->site, $schedule]),
                [
                    'result' => 'fail',
                    'findings' => 'Emergency lighting failed.',
                    'corrective_actions' => 'Replace the failed fitting.',
                ],
            );
            $this->fail('The injected schedule failure should roll back acceptance.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected schedule advance failure', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertDatabaseCount('site_inspection_records', 0);
        $this->assertDatabaseCount('facility_signals', 0);
        $this->assertDatabaseCount('facility_signal_outbox', 0);
        $this->assertSame($dueDate, $schedule->fresh()->next_due_date->toDateString());
        Queue::assertNothingPushed();
    }

    public function test_same_day_failed_record_collision_rolls_back_the_second_acceptance(): void
    {
        $schedule = $this->schedule();
        $request = [
            'result' => 'fail',
            'findings' => 'Emergency lighting failed.',
            'corrective_actions' => 'Replace the failed fitting.',
        ];

        $this->actingAs($this->user)
            ->post(route('sites.inspections.complete', [$this->site, $schedule]), $request)
            ->assertRedirect(route('sites.inspections.index', $this->site));

        $firstRecord = SiteInspectionRecord::query()->sole();
        $signal = FacilitySignal::query()->sole();
        $acceptedNextDueDate = $schedule->fresh()->next_due_date->toDateString();

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->user)
                ->post(route('sites.inspections.complete', [$this->site, $schedule]), $request);
            $this->fail('A different failed record must not reuse the first record\'s durable Facility intent.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'exact immutable signal type, Site, schedule, and record provenance',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('site_inspection_records', 1);
        $this->assertDatabaseCount('facility_signals', 1);
        $this->assertDatabaseCount('facility_signal_outbox', 1);
        $this->assertSame($firstRecord->id, $signal->inspection_record_id);
        $this->assertSame($acceptedNextDueDate, $schedule->fresh()->next_due_date->toDateString());
        Queue::assertPushed(DispatchFacilitySignalOutbox::class, 1);
    }

    public function test_post_commit_dispatch_failure_preserves_the_accepted_pending_intent(): void
    {
        $schedule = $this->schedule();
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(DispatchFacilitySignalOutbox::class))
            ->andThrow(new RuntimeException('injected queue outage'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        $this->actingAs($this->user)
            ->post(
                route('sites.inspections.complete', [$this->site, $schedule]),
                [
                    'result' => 'fail',
                    'findings' => 'Emergency lighting failed.',
                    'corrective_actions' => 'Replace the failed fitting.',
                ],
            )
            ->assertRedirect(route('sites.inspections.index', $this->site));

        $record = SiteInspectionRecord::query()->sole();
        $signal = FacilitySignal::query()->sole();
        $outbox = FacilitySignalOutbox::query()->sole();

        $this->assertSame('fail', $record->result);
        $this->assertSame($this->site->id, $signal->site_id);
        $this->assertSame($schedule->id, $signal->inspection_schedule_id);
        $this->assertSame($record->id, $signal->inspection_record_id);
        $this->assertSame('pending', $outbox->status);
        $this->assertSame(0, $outbox->attempts);
        $this->assertSame('2026-09-15', $schedule->fresh()->next_due_date->toDateString());
        $this->assertDatabaseCount('control_room_signals', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_overdue_emission_preserves_schedule_and_daily_identity_boundaries(): void
    {
        $schedule = $this->schedule([
            'next_due_date' => '2026-08-20',
        ]);
        $secondSchedule = $this->schedule([
            'title' => 'Fire-door inspection',
            'next_due_date' => '2026-08-20',
        ]);

        $service = app(FacilitySignalService::class);
        $service->emitInspectionOverdue($schedule, 11);
        $service->emitInspectionOverdue($schedule, 11);
        $service->emitInspectionOverdue($secondSchedule, 11);
        Carbon::setTestNow('2026-09-01 10:00:00');
        $service->emitInspectionOverdue($schedule, 12);

        $signals = FacilitySignal::query()->orderBy('id')->get();
        $signal = $signals->firstWhere('idempotency_key', hash('sha256', implode('|', [
            'facility',
            FacilitySignalService::TYPE_INSPECTION_OVERDUE,
            $schedule->id,
            '2026-08-31',
        ])));

        $this->assertNotNull($signal);
        $this->assertSame(FacilitySignalService::TYPE_INSPECTION_OVERDUE, $signal->signal_type);
        $this->assertSame('high', $signal->severity_hint);
        $this->assertSame($this->site->id, $signal->site_id);
        $this->assertSame($schedule->id, $signal->inspection_schedule_id);
        $this->assertNull($signal->inspection_record_id);
        $this->assertSame(11, $signal->payload['days_overdue']);
        $this->assertSame('facility', $signal->payload['source_module']);
        $this->assertSame([
            hash('sha256', implode('|', [
                'facility',
                FacilitySignalService::TYPE_INSPECTION_OVERDUE,
                $schedule->id,
                '2026-08-31',
            ])),
            hash('sha256', implode('|', [
                'facility',
                FacilitySignalService::TYPE_INSPECTION_OVERDUE,
                $secondSchedule->id,
                '2026-08-31',
            ])),
            hash('sha256', implode('|', [
                'facility',
                FacilitySignalService::TYPE_INSPECTION_OVERDUE,
                $schedule->id,
                '2026-09-01',
            ])),
        ], $signals->pluck('idempotency_key')->all());
        $this->assertDatabaseCount('facility_signals', 3);
        $this->assertDatabaseCount('facility_signal_outbox', 3);
        $this->assertSame(['pending'], FacilitySignalOutbox::query()->pluck('status')->unique()->values()->all());
        Queue::assertPushed(DispatchFacilitySignalOutbox::class, 3);
        $this->assertSame(
            FacilitySignalOutbox::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            Queue::pushed(DispatchFacilitySignalOutbox::class)
                ->map(fn (DispatchFacilitySignalOutbox $job): int => $job->outboxId)
                ->sort()
                ->values()
                ->all(),
        );
    }

    public function test_failed_delivery_operator_retry_and_duplicate_jobs_converge_on_one_site_alert(): void
    {
        $schedule = $this->schedule();
        $record = $this->failedRecord($schedule);
        app(FacilitySignalService::class)->emitInspectionFailed($schedule, $record);

        $facilitySignal = FacilitySignal::query()->sole();
        $outbox = FacilitySignalOutbox::query()->sole();
        $failedProcessor = Mockery::mock(SignalProcessingService::class);
        $failedProcessor->shouldReceive('ingestFromFacilitySignal')
            ->once()
            ->withArgs(fn (FacilitySignal $signal): bool => $signal->is($facilitySignal))
            ->andThrow(new RuntimeException('injected Facility router failure'));

        try {
            (new DispatchFacilitySignalOutbox($outbox->id))->handle($failedProcessor);
            $this->fail('The injected routing failure should remain retryable.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected Facility router failure', $exception->getMessage());
        }

        $this->assertSame('failed', $outbox->fresh()->status);
        $this->assertSame(1, $outbox->fresh()->attempts);
        $this->assertSame('injected Facility router failure', $outbox->fresh()->last_error);
        $this->assertDatabaseCount('control_room_alerts', 0);

        Queue::fake();
        app(SafetySignalDeliveryRecoveryService::class)->retry('facility', $outbox->id);
        Queue::assertPushed(
            DispatchFacilitySignalOutbox::class,
            fn (DispatchFacilitySignalOutbox $job): bool => $job->outboxId === $outbox->id,
        );

        $job = new DispatchFacilitySignalOutbox($outbox->id);
        $job->handle(app(SignalProcessingService::class));
        $job->handle(app(SignalProcessingService::class));

        $controlSignal = Signal::query()->sole();
        $alert = ControlRoomAlert::query()->sole();

        $this->assertSame('sent', $outbox->fresh()->status);
        $this->assertSame($this->site->id, $controlSignal->site_id);
        $this->assertSame($this->site->id, $alert->site_id);
        $this->assertSame('facility', $controlSignal->signalSource?->slug);
        $this->assertSame('facility_signal_'.$facilitySignal->id, $controlSignal->external_ref);
        $this->assertSame($facilitySignal->id, $controlSignal->normalized_data['facility_signal_id']);
        $this->assertSame($controlSignal->id, $alert->origin_signal_id);
        $this->assertSame(
            hash('sha256', 'safety-signal|facility|'.$facilitySignal->idempotency_key),
            $controlSignal->idempotency_key,
        );
        $this->assertDatabaseCount('control_room_signals', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);
    }

    public function test_conflicting_control_room_identity_is_visible_and_never_credited_as_sent(): void
    {
        $schedule = $this->schedule();
        $record = $this->failedRecord($schedule);
        app(FacilitySignalService::class)->emitInspectionFailed($schedule, $record);

        $facilitySignal = FacilitySignal::query()->sole();
        $outbox = FacilitySignalOutbox::query()->sole();
        $source = SignalSource::query()->create([
            'slug' => 'facility',
            'name' => 'Facility / Site Operations',
            'vendor' => 'internal',
            'status' => 'active',
        ]);
        $controlSignal = Signal::query()->create([
            'signal_source_id' => $source->id,
            'signal_type_code' => $facilitySignal->signal_type,
            'idempotency_key' => hash('sha256', 'safety-signal|facility|'.$facilitySignal->idempotency_key),
            'site_id' => $facilitySignal->site_id,
            'external_ref' => 'conflicting_facility_signal',
            'severity_hint' => $facilitySignal->severity_hint,
            'occurred_at' => $facilitySignal->occurred_at,
            'normalized_data' => [
                'facility_signal_id' => $facilitySignal->id,
                'inspection_schedule_id' => $facilitySignal->inspection_schedule_id,
                'inspection_record_id' => $facilitySignal->inspection_record_id,
                'site_id' => $facilitySignal->site_id,
            ],
            'status' => 'pending',
        ]);

        $this->assertSame(0, app(SignalProcessingService::class)->processAllPending());
        $this->assertSame('failed', $controlSignal->fresh()->status);
        $this->assertDatabaseCount('control_room_alerts', 0);

        (new DispatchFacilitySignalOutbox($outbox->id))
            ->handle(app(SignalProcessingService::class));

        $this->assertSame('unroutable', $outbox->fresh()->status);
        $this->assertSame(1, $outbox->fresh()->attempts);
        $this->assertStringContainsString('idempotency identity conflicts', $outbox->fresh()->last_error);
        $this->assertSame('failed', $controlSignal->fresh()->status);
        $this->assertStringContainsString(
            SignalProcessingService::FACILITY_QUARANTINE_PREFIX,
            $controlSignal->fresh()->processing_notes,
        );
        $this->assertDatabaseCount('control_room_signals', 1);
        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_conflicting_control_room_evidence_is_visible_and_never_credited_as_sent(): void
    {
        $schedule = $this->schedule();
        $record = $this->failedRecord($schedule);
        app(FacilitySignalService::class)->emitInspectionFailed($schedule, $record);

        $facilitySignal = FacilitySignal::query()->sole();
        $outbox = FacilitySignalOutbox::query()->sole();
        $source = SignalSource::query()->create([
            'slug' => 'facility',
            'name' => 'Facility / Site Operations',
            'vendor' => 'internal',
            'status' => 'active',
        ]);
        $controlSignal = Signal::query()->create([
            'signal_source_id' => $source->id,
            'signal_type_code' => $facilitySignal->signal_type,
            'idempotency_key' => hash('sha256', 'safety-signal|facility|'.$facilitySignal->idempotency_key),
            'site_id' => $facilitySignal->site_id,
            'external_ref' => 'facility_signal_'.$facilitySignal->id,
            'severity_hint' => 'low',
            'occurred_at' => $facilitySignal->occurred_at,
            'payload' => [],
            'normalized_data' => array_merge($facilitySignal->payload, [
                'title' => 'Substituted non-safety evidence',
                'facility_signal_id' => $facilitySignal->id,
                'inspection_schedule_id' => $facilitySignal->inspection_schedule_id,
                'inspection_record_id' => $facilitySignal->inspection_record_id,
                'site_id' => $facilitySignal->site_id,
            ]),
            'status' => 'pending',
        ]);

        $this->assertSame(0, app(SignalProcessingService::class)->processAllPending());
        $this->assertSame('failed', $controlSignal->fresh()->status);
        $this->assertDatabaseCount('control_room_alerts', 0);

        (new DispatchFacilitySignalOutbox($outbox->id))
            ->handle(app(SignalProcessingService::class));

        $this->assertSame('unroutable', $outbox->fresh()->status);
        $this->assertSame(1, $outbox->fresh()->attempts);
        $this->assertStringContainsString('idempotency identity conflicts', $outbox->fresh()->last_error);
        $this->assertSame('low', $controlSignal->fresh()->severity_hint);
        $this->assertSame('Substituted non-safety evidence', $controlSignal->fresh()->normalized_data['title']);
        $this->assertSame('failed', $controlSignal->fresh()->status);
        $this->assertStringContainsString(
            SignalProcessingService::FACILITY_QUARANTINE_PREFIX,
            $controlSignal->fresh()->processing_notes,
        );
        $this->assertDatabaseCount('control_room_signals', 1);
        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_canonical_round_tripped_signal_survives_temporary_source_outage_and_retry(): void
    {
        $schedule = $this->schedule();
        $record = $this->failedRecord($schedule);
        app(FacilitySignalService::class)->emitInspectionFailed($schedule, $record);

        $facilitySignal = FacilitySignal::query()->sole();
        $outbox = FacilitySignalOutbox::query()->sole();
        $source = SignalSource::query()->create([
            'slug' => 'facility',
            'name' => 'Facility / Site Operations',
            'vendor' => 'internal',
            'status' => 'active',
        ]);
        $normalizedData = collect(array_merge($facilitySignal->payload, [
            'facility_signal_id' => $facilitySignal->id,
            'inspection_schedule_id' => $facilitySignal->inspection_schedule_id,
            'inspection_record_id' => $facilitySignal->inspection_record_id,
            'site_id' => $facilitySignal->site_id,
        ]))->sortKeysDesc()->all();
        $controlSignal = Signal::query()->create([
            'signal_source_id' => $source->id,
            'signal_type_code' => $facilitySignal->signal_type,
            'idempotency_key' => hash('sha256', 'safety-signal|facility|'.$facilitySignal->idempotency_key),
            'site_id' => $facilitySignal->site_id,
            'external_ref' => 'facility_signal_'.$facilitySignal->id,
            'severity_hint' => $facilitySignal->severity_hint,
            'occurred_at' => $facilitySignal->occurred_at,
            'payload' => [],
            'normalized_data' => $normalizedData,
            'status' => 'pending',
        ])->fresh();
        $source->update(['status' => 'inactive']);

        (new DispatchFacilitySignalOutbox($outbox->id))
            ->handle(app(SignalProcessingService::class));

        $this->assertSame('unroutable', $outbox->fresh()->status);
        $this->assertSame(1, $outbox->fresh()->attempts);
        $this->assertSame('failed', $controlSignal->fresh()->status);
        $this->assertStringContainsString(
            'no active signal source',
            $controlSignal->fresh()->processing_notes,
        );
        $this->assertSame(0, app(SignalProcessingService::class)->processAllPending());
        $this->assertDatabaseCount('control_room_alerts', 0);

        $source->update(['status' => 'active']);
        Queue::fake();
        app(SafetySignalDeliveryRecoveryService::class)->retry('facility', $outbox->id);
        Queue::assertPushed(
            DispatchFacilitySignalOutbox::class,
            fn (DispatchFacilitySignalOutbox $job): bool => $job->outboxId === $outbox->id,
        );

        (new DispatchFacilitySignalOutbox($outbox->id))
            ->handle(app(SignalProcessingService::class));

        $this->assertSame('sent', $outbox->fresh()->status);
        $this->assertSame(2, $outbox->fresh()->attempts);
        $this->assertSame('processed', $controlSignal->fresh()->status);
        $this->assertNull($controlSignal->fresh()->processing_notes);
        $this->assertDatabaseCount('control_room_signals', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);
    }

    public function test_source_flip_between_ingest_and_process_rechecks_with_current_lock(): void
    {
        $schedule = $this->schedule();
        $record = $this->failedRecord($schedule);
        app(FacilitySignalService::class)->emitInspectionFailed($schedule, $record);

        $facilitySignal = FacilitySignal::query()->sole();
        $outbox = FacilitySignalOutbox::query()->sole();
        $source = SignalSource::query()->create([
            'slug' => 'facility',
            'name' => 'Facility / Site Operations',
            'vendor' => 'internal',
            'status' => 'active',
        ]);
        $normalizedData = array_merge($facilitySignal->payload, [
            'facility_signal_id' => $facilitySignal->id,
            'inspection_schedule_id' => $facilitySignal->inspection_schedule_id,
            'inspection_record_id' => $facilitySignal->inspection_record_id,
            'site_id' => $facilitySignal->site_id,
        ]);
        $controlSignal = Signal::query()->create([
            'signal_source_id' => $source->id,
            'signal_type_code' => $facilitySignal->signal_type,
            'idempotency_key' => hash('sha256', 'safety-signal|facility|'.$facilitySignal->idempotency_key),
            'site_id' => $facilitySignal->site_id,
            'external_ref' => 'facility_signal_'.$facilitySignal->id,
            'severity_hint' => $facilitySignal->severity_hint,
            'occurred_at' => $facilitySignal->occurred_at,
            'payload' => [],
            'normalized_data' => $normalizedData,
            'status' => 'pending',
        ]);
        $processor = new class(app(ControlRoomNotificationService::class)) extends SignalProcessingService
        {
            public function ingestFromFacilitySignal(FacilitySignal $facilitySignal): Signal
            {
                $controlSignal = parent::ingestFromFacilitySignal($facilitySignal);
                SignalSource::query()
                    ->whereKey($controlSignal->signal_source_id)
                    ->update(['status' => 'inactive']);

                return $controlSignal;
            }
        };

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            (new DispatchFacilitySignalOutbox($outbox->id))->handle($processor);
            $queries = collect(DB::getQueryLog())
                ->pluck('query')
                ->map(fn (string $query): string => strtolower($query));
        } finally {
            DB::disableQueryLog();
        }

        $controlSignalLock = $queries->search(fn (string $query): bool => str_contains(
            $query,
            'from `control_room_signals`',
        ) && str_contains($query, 'for update'));
        $sourceLock = $queries->search(fn (string $query): bool => str_contains(
            $query,
            'from `control_room_signal_sources`',
        ) && str_contains($query, 'for update'));

        $this->assertNotFalse($controlSignalLock);
        $this->assertNotFalse($sourceLock);
        $this->assertLessThan($sourceLock, $controlSignalLock);
        $this->assertSame('inactive', $source->fresh()->status);
        $this->assertSame('unroutable', $outbox->fresh()->status);
        $this->assertSame('failed', $controlSignal->fresh()->status);
        $this->assertStringContainsString('no active signal source', $outbox->fresh()->last_error);
        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_parent_cleanup_retains_immutable_source_and_projected_provenance(): void
    {
        $schedule = $this->schedule();
        $record = $this->failedRecord($schedule);
        app(FacilitySignalService::class)->emitInspectionFailed($schedule, $record);

        $facilitySignal = FacilitySignal::query()->sole();
        $outbox = FacilitySignalOutbox::query()->sole();
        $sourceIds = [
            'site_id' => $this->site->id,
            'inspection_schedule_id' => $schedule->id,
            'inspection_record_id' => $record->id,
        ];

        $record->delete();
        $schedule->delete();

        $this->assertSame($sourceIds, $facilitySignal->fresh()->only(array_keys($sourceIds)));

        (new DispatchFacilitySignalOutbox($outbox->id))
            ->handle(app(SignalProcessingService::class));

        $controlSignal = Signal::query()->sole();
        $this->assertSame(
            $sourceIds,
            [
                'site_id' => $controlSignal->normalized_data['site_id'],
                'inspection_schedule_id' => $controlSignal->normalized_data['inspection_schedule_id'],
                'inspection_record_id' => $controlSignal->normalized_data['inspection_record_id'],
            ],
        );
        $this->assertSame('sent', $outbox->fresh()->status);
        $this->assertDatabaseCount('control_room_alerts', 1);
    }

    public function test_existing_failed_control_room_signal_remains_visible_and_retryable(): void
    {
        $schedule = $this->schedule();
        $record = $this->failedRecord($schedule);
        app(FacilitySignalService::class)->emitInspectionFailed($schedule, $record);

        $facilitySignal = FacilitySignal::query()->sole();
        $outbox = FacilitySignalOutbox::query()->sole();
        $source = SignalSource::query()->create([
            'slug' => 'facility',
            'name' => 'Facility / Site Operations',
            'vendor' => 'internal',
            'status' => 'active',
        ]);
        $controlSignal = Signal::query()->create([
            'signal_source_id' => $source->id,
            'signal_type_code' => $facilitySignal->signal_type,
            'idempotency_key' => hash('sha256', 'safety-signal|facility|'.$facilitySignal->idempotency_key),
            'site_id' => $facilitySignal->site_id,
            'external_ref' => 'facility_signal_'.$facilitySignal->id,
            'severity_hint' => $facilitySignal->severity_hint,
            'occurred_at' => $facilitySignal->occurred_at,
            'payload' => [],
            'normalized_data' => array_merge($facilitySignal->payload, [
                'facility_signal_id' => $facilitySignal->id,
                'inspection_schedule_id' => $facilitySignal->inspection_schedule_id,
                'inspection_record_id' => $facilitySignal->inspection_record_id,
                'site_id' => $facilitySignal->site_id,
            ]),
            'status' => 'failed',
        ]);

        try {
            (new DispatchFacilitySignalOutbox($outbox->id))
                ->handle(app(SignalProcessingService::class));
            $this->fail('A failed Control Room signal must not be credited as delivered.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Facility safety signal did not reach an accepted terminal processing state.',
                $exception->getMessage(),
            );
        }

        $this->assertSame('failed', $outbox->fresh()->status);
        $this->assertSame(1, $outbox->fresh()->attempts);
        $this->assertSame('failed', $controlSignal->fresh()->status);
        $this->assertDatabaseCount('control_room_alerts', 0);
    }

    public function test_legacy_facility_source_signals_without_durable_markers_remain_compatible(): void
    {
        $source = SignalSource::query()->create([
            'slug' => 'facility',
            'name' => 'Facility / Site Operations',
            'vendor' => 'internal',
            'status' => 'active',
        ]);
        $legacyPending = Signal::query()->create([
            'signal_source_id' => $source->id,
            'signal_type_code' => FacilitySignalService::TYPE_INSPECTION_FAILED,
            'idempotency_key' => hash('sha256', 'legacy-facility-pending'),
            'site_id' => $this->site->id,
            'external_ref' => 'legacy_facility_pending',
            'severity_hint' => 'high',
            'occurred_at' => now(),
            'payload' => [],
            'normalized_data' => [
                'source_module' => 'facility',
                'signal_type' => FacilitySignalService::TYPE_INSPECTION_FAILED,
            ],
            'status' => 'pending',
        ]);
        $legacyProcessed = Signal::query()->create([
            'signal_source_id' => $source->id,
            'signal_type_code' => FacilitySignalService::TYPE_INSPECTION_FAILED,
            'idempotency_key' => hash('sha256', 'legacy-facility-processed'),
            'site_id' => $this->site->id,
            'external_ref' => 'legacy_facility_processed',
            'severity_hint' => 'high',
            'occurred_at' => now(),
            'payload' => [],
            'normalized_data' => [
                'source_module' => 'facility',
                'signal_type' => FacilitySignalService::TYPE_INSPECTION_FAILED,
            ],
            'status' => 'processed',
            'processed_at' => now(),
        ]);

        $this->assertNull(app(SignalProcessingService::class)->process($legacyProcessed));
        $this->assertSame(1, app(SignalProcessingService::class)->processAllPending());

        $this->assertSame('processed', $legacyProcessed->fresh()->status);
        $this->assertSame('processed', $legacyPending->fresh()->status);
        $this->assertDatabaseCount('control_room_alerts', 1);
    }

    public function test_missing_site_and_inactive_source_fail_closed_as_visible_unroutable_deliveries(): void
    {
        $schedule = $this->schedule();
        $missingSiteSignal = FacilitySignal::query()->create([
            'site_id' => null,
            'inspection_schedule_id' => $schedule->id,
            'signal_type' => FacilitySignalService::TYPE_INSPECTION_OVERDUE,
            'severity_hint' => 'medium',
            'occurred_at' => now(),
            'idempotency_key' => hash('sha256', 'facility-missing-site'),
            'payload' => ['source_module' => 'facility'],
        ]);
        $missingSiteOutbox = FacilitySignalOutbox::query()->create([
            'facility_signal_id' => $missingSiteSignal->id,
            'status' => 'pending',
        ]);

        (new DispatchFacilitySignalOutbox($missingSiteOutbox->id))
            ->handle(app(SignalProcessingService::class));

        $this->assertSame('unroutable', $missingSiteOutbox->fresh()->status);
        $this->assertSame(1, $missingSiteOutbox->fresh()->attempts);
        $this->assertStringContainsString('canonical Site', $missingSiteOutbox->fresh()->last_error);
        $this->assertDatabaseCount('control_room_signals', 0);

        SignalSource::query()->create([
            'slug' => 'facility',
            'name' => 'Disabled Facility Source',
            'vendor' => 'internal',
            'status' => 'inactive',
        ]);
        $inactiveSourceSignal = FacilitySignal::query()->create([
            'site_id' => $this->site->id,
            'inspection_schedule_id' => $schedule->id,
            'signal_type' => FacilitySignalService::TYPE_INSPECTION_OVERDUE,
            'severity_hint' => 'medium',
            'occurred_at' => now(),
            'idempotency_key' => hash('sha256', 'facility-inactive-source'),
            'payload' => ['source_module' => 'facility'],
        ]);
        $inactiveSourceOutbox = FacilitySignalOutbox::query()->create([
            'facility_signal_id' => $inactiveSourceSignal->id,
            'status' => 'pending',
        ]);

        (new DispatchFacilitySignalOutbox($inactiveSourceOutbox->id))
            ->handle(app(SignalProcessingService::class));

        $this->assertSame('unroutable', $inactiveSourceOutbox->fresh()->status);
        $this->assertSame(1, $inactiveSourceOutbox->fresh()->attempts);
        $this->assertStringContainsString('active signal source', $inactiveSourceOutbox->fresh()->last_error);
        $this->assertDatabaseCount('control_room_signals', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);

        $report = app(SafetySignalDeliveryRecoveryService::class)->recover(reportOnly: true);
        $this->assertSame(2, $report['failures']['facility']);
        $failureRows = collect($report['failure_rows']);
        $this->assertCount(2, $failureRows);
        $this->assertEqualsCanonicalizing(
            [$missingSiteOutbox->id, $inactiveSourceOutbox->id],
            $failureRows->pluck('id')->all(),
        );
        $this->assertSame(['facility'], $failureRows->pluck('source')->unique()->values()->all());
        $this->assertSame(['unroutable'], $failureRows->pluck('status')->unique()->values()->all());
        $this->assertSame(
            [
                'Facility safety signal has no active signal source.',
                'Facility safety signal has no canonical Site.',
            ],
            $failureRows->pluck('last_error')->sort()->values()->all(),
        );
    }

    public function test_recovery_report_and_retry_commands_support_facility_outboxes(): void
    {
        $signal = FacilitySignal::query()->create([
            'site_id' => $this->site->id,
            'inspection_schedule_id' => $this->schedule()->id,
            'signal_type' => FacilitySignalService::TYPE_INSPECTION_OVERDUE,
            'severity_hint' => 'medium',
            'occurred_at' => now(),
            'idempotency_key' => hash('sha256', 'missing-facility-outbox'),
            'payload' => [
                'title' => 'Inspection overdue',
                'description' => 'Inspection overdue',
                'source_module' => 'facility',
                'signal_type' => FacilitySignalService::TYPE_INSPECTION_OVERDUE,
            ],
        ]);

        $this->artisan('safety-signals:recover', ['--limit' => 100])
            ->expectsOutput('fleet: 0 reconciled, 0 queued, 0 failed/dead-letter/unroutable')
            ->expectsOutput('shift: 0 reconciled, 0 queued, 0 failed/dead-letter/unroutable')
            ->expectsOutput('device: 0 reconciled, 0 queued, 0 failed/dead-letter/unroutable')
            ->expectsOutput('incident: 0 reconciled, 0 queued, 0 failed/dead-letter/unroutable')
            ->expectsOutput('facility: 1 reconciled, 1 queued, 0 failed/dead-letter/unroutable')
            ->assertSuccessful();

        $outbox = FacilitySignalOutbox::query()
            ->where('facility_signal_id', $signal->id)
            ->sole();
        Queue::assertPushed(
            DispatchFacilitySignalOutbox::class,
            fn (DispatchFacilitySignalOutbox $job): bool => $job->outboxId === $outbox->id,
        );

        $outbox->forceFill([
            'status' => 'failed',
            'attempts' => 3,
            'last_attempt_at' => now(),
            'last_error' => 'visible Facility delivery failure',
        ])->save();
        Queue::fake();
        $failedOutbox = $outbox->fresh();

        $this->artisan('safety-signals:recover', ['--report-only' => true])
            ->expectsOutputToContain('facility')
            ->expectsTable(
                ['Source', 'Outbox', 'Status', 'Attempts', 'Last attempt', 'Error'],
                [[
                    'facility',
                    $failedOutbox->id,
                    'failed',
                    3,
                    $failedOutbox->last_attempt_at->toISOString(),
                    'visible Facility delivery failure',
                ]],
            )
            ->assertSuccessful();
        $this->assertSame('failed', $outbox->fresh()->status);
        Queue::assertNothingPushed();

        $this->artisan('safety-signals:recover', ['--limit' => 100])
            ->expectsOutputToContain('facility: 0 reconciled, 0 queued, 1 failed/dead-letter/unroutable')
            ->assertSuccessful();
        $this->assertSame('dead_letter', $outbox->fresh()->status);
        Queue::assertNothingPushed();

        $this->artisan('safety-signals:retry', [
            'source' => 'facility',
            'outbox' => $outbox->id,
        ])
            ->expectsOutput('Safety-signal delivery replay queued.')
            ->assertSuccessful();
        $this->assertSame('pending', $outbox->fresh()->status);
        $this->assertNull($outbox->fresh()->last_error);
        Queue::assertPushed(
            DispatchFacilitySignalOutbox::class,
            fn (DispatchFacilitySignalOutbox $job): bool => $job->outboxId === $outbox->id,
        );

        $this->artisan('safety-signals:retry', [
            'source' => 'facility',
            'outbox' => $outbox->id,
        ])
            ->expectsOutput('Only failed, dead-letter, or unroutable deliveries can be replayed.')
            ->assertFailed();
    }

    private function schedule(array $overrides = []): SiteInspectionSchedule
    {
        return SiteInspectionSchedule::query()->create(array_merge([
            'site_id' => $this->site->id,
            'inspection_type' => 'fire_safety',
            'title' => 'Emergency lighting inspection',
            'frequency' => 'monthly',
            'first_due_date' => '2026-08-15',
            'next_due_date' => '2026-08-15',
            'assigned_to_user_id' => $this->user->id,
            'is_active' => true,
        ], $overrides));
    }

    private function failedRecord(SiteInspectionSchedule $schedule): SiteInspectionRecord
    {
        return SiteInspectionRecord::query()->create([
            'schedule_id' => $schedule->id,
            'site_id' => $this->site->id,
            'due_date' => $schedule->next_due_date,
            'completed_at' => now(),
            'completed_by_user_id' => $this->user->id,
            'result' => 'fail',
            'findings' => 'Emergency lighting failed.',
            'corrective_actions' => 'Replace the failed fitting.',
        ]);
    }
}
