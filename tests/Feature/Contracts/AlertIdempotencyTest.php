<?php

namespace Tests\Feature\Contracts;

use App\Jobs\ShiftAutoAlertJob;
use App\Models\Client;
use App\Models\Shift;
use App\Models\ShiftSignal;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\ShiftCoverageService;
use App\Services\ShiftSignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AlertIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_alert_job_does_not_emit_duplicate_shift_signals_for_same_window(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-04-14 10:30:00'));

        $staff = User::factory()->create(['organization_id' => 1]);
        $site = Site::factory()->create();
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $site->id,
        ]);
        $shift = Shift::factory()->create([
            'organization_id' => 1,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'user_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-04-14 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-14 17:00:00'),
            'status' => 'scheduled',
            'created_by' => $staff->id,
        ]);

        $job = app(ShiftAutoAlertJob::class);

        $job->handle(
            app(ShiftSignalService::class),
            app(ShiftCoverageService::class),
            app(SignalProcessingService::class),
        );
        $job->handle(
            app(ShiftSignalService::class),
            app(ShiftCoverageService::class),
            app(SignalProcessingService::class),
        );

        $expectedKey = app(ShiftSignalService::class)
            ->buildShiftIdempotencyKey($shift, ShiftSignalService::TYPE_NO_SHOW, 'threshold:90');

        $this->assertSame(1, ShiftSignal::query()->count());
        $this->assertDatabaseHas('shift_signals', [
            'shift_id' => $shift->id,
            'signal_type' => ShiftSignalService::TYPE_NO_SHOW,
            'idempotency_key' => $expectedKey,
        ]);
    }
}
