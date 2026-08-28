<?php

namespace Tests\Unit\ControlRoom;

use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoom\SignalType;
use App\Models\ControlRoomAlert;
use App\Models\Shift;
use App\Services\ControlRoom\ControlRoomNotificationService;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\ShiftSignalService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the no-show ↔ late-start state-transition path in
 * `SignalProcessingService::addSignalToAlert` (lines 354-411 of the service).
 *
 * Closes the H6 leftover from `docs/control-room-readiness-plan.md`.
 *
 * The path is triggered when a shift signal correlates against an existing
 * unresolved alert: if the existing alert was a `no_show` and the new signal
 * is a `late_start`, the alert is mutated in place rather than a new alert
 * being created. The alert_type, severity, and audit log entry should reflect
 * the transition.
 */
class SignalProcessingTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected SignalProcessingService $service;

    protected SignalSource $shiftSource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $notificationService = $this->mock(ControlRoomNotificationService::class);
        $notificationService->shouldReceive('notifyAlert')->andReturnNull();
        $notificationService->shouldReceive('stageAlertNotifications')->andReturn(collect());

        $this->service = new SignalProcessingService($notificationService);

        $this->shiftSource = SignalSource::create([
            'name' => 'Shift Operations',
            'slug' => 'shift_operations',
            'category' => 'operations',
            'vendor' => 'internal',
            'status' => 'active',
        ]);
    }

    public function test_late_start_signal_transitions_existing_no_show_alert_in_place(): void
    {
        $shiftSignals = app(ShiftSignalService::class);

        $noShowType = SignalType::create([
            'code' => ShiftSignalService::TYPE_NO_SHOW,
            'name' => 'Shift No-Show',
            'category' => 'operations',
            'default_severity' => 'high',
        ]);

        $lateStartType = SignalType::create([
            'code' => ShiftSignalService::TYPE_LATE_START,
            'name' => 'Shift Late Start',
            'category' => 'operations',
            'default_severity' => 'medium',
        ]);

        // Pre-existing no-show alert for shift #42 — must be inside the 30-min
        // dedup window so the new signal correlates rather than spawning a new
        // alert.
        $alert = ControlRoomAlert::factory()->open()->create([
            'source' => 'shift_operations',
            'alert_type' => $shiftSignals->alertTypeForSignalType(ShiftSignalService::TYPE_NO_SHOW),
            'severity' => 'high',
            'triggered_at' => now()->subMinutes(5),
            'context' => [
                'signal_type_code' => ShiftSignalService::TYPE_NO_SHOW,
                'normalized_data' => [
                    'shift_id' => 42,
                ],
            ],
        ]);

        // New signal is a late-start for the same shift
        $signal = Signal::create([
            'signal_source_id' => $this->shiftSource->id,
            'signal_type_id' => $lateStartType->id,
            'signal_type_code' => ShiftSignalService::TYPE_LATE_START,
            'idempotency_key' => 'shift-42-late-start',
            'severity_hint' => 'medium',
            'occurred_at' => now(),
            'payload' => [],
            'normalized_data' => ['shift_id' => 42],
            'status' => 'pending',
        ]);

        // Drive the addSignalToAlert path via the public process() entry point
        // by routing the signal through the dedup window: it should correlate
        // against the existing alert rather than creating a new one.
        $rule = SignalRule::create([
            'name' => 'Late start correlate',
            'signal_type_id' => $lateStartType->id,
            'signal_type_code' => ShiftSignalService::TYPE_LATE_START,
            'priority' => 1,
            'deduplicate' => true,
            'dedup_window_minutes' => 30,
            'is_active' => true,
        ]);

        $result = $this->service->process($signal);

        $this->assertNotNull($result);
        $this->assertSame(
            $alert->id,
            $result->id,
            'late-start signal should correlate against the existing no-show alert',
        );

        $alert->refresh();

        $this->assertSame(
            $shiftSignals->alertTypeForSignalType(ShiftSignalService::TYPE_LATE_START),
            $alert->alert_type,
            'alert_type should mutate from no_show to late_start',
        );

        $this->assertNotEmpty(
            $alert->context['state_transitions'] ?? [],
            'state_transitions ledger should record the transition',
        );

        $this->assertSame(
            $shiftSignals->alertTypeForSignalType(ShiftSignalService::TYPE_NO_SHOW),
            $alert->context['state_transitions'][0]['from_alert_type'],
        );
        $this->assertSame(
            $shiftSignals->alertTypeForSignalType(ShiftSignalService::TYPE_LATE_START),
            $alert->context['state_transitions'][0]['to_alert_type'],
        );

        // Signal should be marked correlated, not processed-new
        $signal->refresh();
        $this->assertSame('processed', $signal->status);
        $this->assertSame($alert->id, $signal->correlated_alert_id);
    }

    public function test_correlated_higher_severity_signal_raises_alert_severity(): void
    {
        $shiftSignals = app(ShiftSignalService::class);

        $type = SignalType::create([
            'code' => ShiftSignalService::TYPE_LATE_START,
            'name' => 'Late Start',
            'category' => 'operations',
            'default_severity' => 'medium',
        ]);

        // Existing late_start alert at medium, inside dedup window
        $alert = ControlRoomAlert::factory()->open()->create([
            'source' => 'shift_operations',
            'alert_type' => $shiftSignals->alertTypeForSignalType(ShiftSignalService::TYPE_LATE_START),
            'severity' => 'medium',
            'triggered_at' => now()->subMinutes(5),
            'context' => [
                'signal_type_code' => ShiftSignalService::TYPE_LATE_START,
                'normalized_data' => ['shift_id' => 99],
            ],
        ]);

        $signal = Signal::create([
            'signal_source_id' => $this->shiftSource->id,
            'signal_type_id' => $type->id,
            'signal_type_code' => ShiftSignalService::TYPE_LATE_START,
            'idempotency_key' => 'shift-99-late-start-2',
            'severity_hint' => 'critical',
            'occurred_at' => now(),
            'payload' => [],
            'normalized_data' => ['shift_id' => 99],
            'status' => 'pending',
        ]);

        SignalRule::create([
            'name' => 'Late start dedup',
            'signal_type_id' => $type->id,
            'signal_type_code' => ShiftSignalService::TYPE_LATE_START,
            'priority' => 1,
            'deduplicate' => true,
            'dedup_window_minutes' => 30,
            'is_active' => true,
        ]);

        $this->service->process($signal);

        $this->assertSame(
            'critical',
            $alert->fresh()->severity,
            'higher-severity correlated signal should raise the alert severity',
        );
    }

    public function test_correlated_signal_cannot_downgrade_a_controlled_content_marker(): void
    {
        $shiftSignals = app(ShiftSignalService::class);
        $type = SignalType::query()->create([
            'code' => ShiftSignalService::TYPE_LATE_START,
            'name' => 'Late Start',
            'category' => 'operations',
            'default_severity' => 'medium',
        ]);
        $alert = ControlRoomAlert::factory()->open()->create([
            'source' => 'shift_operations',
            'alert_type' => $shiftSignals->alertTypeForSignalType(ShiftSignalService::TYPE_LATE_START),
            'severity' => 'medium',
            'triggered_at' => now()->subMinutes(5),
            'context' => [
                'signal_type_code' => ShiftSignalService::TYPE_LATE_START,
                'normalized_data' => [
                    'shift_id' => 177,
                    'controlled_drug' => true,
                    'medication_name' => 'Restricted historical medicine',
                ],
            ],
        ]);
        $signal = Signal::query()->create([
            'signal_source_id' => $this->shiftSource->id,
            'signal_type_id' => $type->id,
            'signal_type_code' => ShiftSignalService::TYPE_LATE_START,
            'idempotency_key' => 'shift-177-late-start-refresh',
            'severity_hint' => 'medium',
            'occurred_at' => now(),
            'payload' => [],
            'normalized_data' => ['shift_id' => 177],
            'status' => 'pending',
        ]);
        SignalRule::query()->create([
            'name' => 'Sticky controlled classification',
            'signal_type_id' => $type->id,
            'signal_type_code' => ShiftSignalService::TYPE_LATE_START,
            'priority' => 1,
            'deduplicate' => true,
            'dedup_window_minutes' => 30,
            'is_active' => true,
        ]);

        $result = $this->service->process($signal);

        $this->assertTrue($result?->is($alert));
        $this->assertTrue(data_get($alert->fresh()->context, 'normalized_data.controlled_drug'));
    }
}
