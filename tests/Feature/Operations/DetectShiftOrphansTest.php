<?php

namespace Tests\Feature\Operations;

use App\Console\Commands\DetectShiftOrphans;
use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftSignal;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DetectShiftOrphansTest extends TestCase
{
    use RefreshDatabase;

    protected Site $site;

    protected Client $client;

    protected ServiceContext $serviceContext;

    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-04-07 06:00:00'));

        $this->site = Site::factory()->create(['name' => 'Test Site']);
        $this->serviceContext = ServiceContext::factory()->create();
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
        ]);
        $this->staff = User::factory()->create([
            'approved_at' => now(),
            'role' => 'support_worker',
        ]);
    }

    public function test_command_succeeds_with_no_orphans(): void
    {
        $this->artisan('shifts:detect-orphans')
            ->assertExitCode(0);
    }

    public function test_detects_completed_shift_without_timesheet(): void
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->subDay()->setTime(9, 0),
            'ends_at' => now()->subDay()->setTime(17, 0),
            'actual_starts_at' => now()->subDay()->setTime(9, 5),
            'actual_ends_at' => now()->subDay()->setTime(17, 0),
            'status' => 'completed',
            'completed_by' => $this->staff->id,
        ]);

        $this->artisan('shifts:detect-orphans')
            ->assertExitCode(0);

        $signal = ShiftSignal::query()
            ->where('signal_type', DetectShiftOrphans::TYPE_MISSING_TIMESHEET)
            ->where('shift_id', $shift->id)
            ->first();

        $this->assertNotNull($signal);
        $this->assertSame('high', $signal->severity_hint);
        $this->assertSame($this->site->id, $signal->site_id);
        $this->assertSame($this->staff->id, $signal->user_id);
        $this->assertSame($shift->id, $signal->payload['shift_id']);
    }

    public function test_does_not_flag_completed_shift_with_timesheet(): void
    {
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->subDay()->setTime(9, 0),
            'ends_at' => now()->subDay()->setTime(17, 0),
            'actual_starts_at' => now()->subDay()->setTime(9, 5),
            'actual_ends_at' => now()->subDay()->setTime(17, 0),
            'status' => 'completed',
            'completed_by' => $this->staff->id,
        ]);

        Timesheet::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'shift_site_id' => $this->site->id,
        ]);

        $this->artisan('shifts:detect-orphans')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('shift_signals', [
            'signal_type' => DetectShiftOrphans::TYPE_MISSING_TIMESHEET,
            'shift_id' => $shift->id,
        ]);
    }

    public function test_detects_attendance_without_timesheet(): void
    {
        $session = HrAttendanceSession::query()->create([
            'tenant_id' => 1,
            'user_id' => $this->staff->id,
            'shift_id' => null,
            'site_id' => $this->site->id,
            'clock_in_at' => now()->subDay()->setTime(9, 0),
            'clock_out_at' => now()->subDay()->setTime(17, 0),
            'break_minutes' => 30,
            'status' => 'closed',
            'source' => 'manual',
            'created_by' => $this->staff->id,
            'closed_by' => $this->staff->id,
        ]);

        $this->artisan('shifts:detect-orphans')
            ->assertExitCode(0);

        $signal = ShiftSignal::query()
            ->where('signal_type', DetectShiftOrphans::TYPE_ORPHAN_ATTENDANCE)
            ->first();

        $this->assertNotNull($signal);
        $this->assertSame('medium', $signal->severity_hint);
        $this->assertSame($session->id, $signal->payload['attendance_session_id']);
    }

    public function test_detects_timesheet_with_deleted_shift_reference(): void
    {
        // Create a valid shift, then create a timesheet linked to it, then delete the shift
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->subDay()->setTime(9, 0),
            'ends_at' => now()->subDay()->setTime(17, 0),
            'status' => 'scheduled',
        ]);

        $timesheet = Timesheet::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $this->staff->id,
            'client_id' => $this->client->id,
            'shift_site_id' => $this->site->id,
        ]);

        // Simulate shift deletion (hard delete bypassing observers)
        Shift::withoutEvents(fn () => $shift->forceDelete());

        $this->artisan('shifts:detect-orphans')
            ->assertExitCode(0);

        $signal = ShiftSignal::query()
            ->where('signal_type', DetectShiftOrphans::TYPE_ORPHAN_TIMESHEET)
            ->first();

        $this->assertNotNull($signal);
        $this->assertSame($timesheet->id, $signal->payload['timesheet_id']);
        // shift_id was nulled by cascade (nullOnDelete), so this is detected as unlinked timesheet
        $this->assertNull($signal->payload['missing_shift_id']);
    }

    public function test_duplicate_runs_do_not_create_duplicate_signals(): void
    {
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->subDay()->setTime(9, 0),
            'ends_at' => now()->subDay()->setTime(17, 0),
            'actual_starts_at' => now()->subDay()->setTime(9, 5),
            'actual_ends_at' => now()->subDay()->setTime(17, 0),
            'status' => 'completed',
            'completed_by' => $this->staff->id,
        ]);

        $this->artisan('shifts:detect-orphans')
            ->assertExitCode(0);

        $firstCount = ShiftSignal::query()
            ->where('signal_type', DetectShiftOrphans::TYPE_MISSING_TIMESHEET)
            ->count();
        $this->assertSame(1, $firstCount);

        // Run again — should not create duplicate
        $this->artisan('shifts:detect-orphans')
            ->assertExitCode(0);

        $secondCount = ShiftSignal::query()
            ->where('signal_type', DetectShiftOrphans::TYPE_MISSING_TIMESHEET)
            ->count();
        $this->assertSame(1, $secondCount);
    }

    public function test_dry_run_does_not_emit_signals(): void
    {
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->subDay()->setTime(9, 0),
            'ends_at' => now()->subDay()->setTime(17, 0),
            'actual_starts_at' => now()->subDay()->setTime(9, 5),
            'actual_ends_at' => now()->subDay()->setTime(17, 0),
            'status' => 'completed',
            'completed_by' => $this->staff->id,
        ]);

        $this->artisan('shifts:detect-orphans --dry-run')
            ->assertExitCode(0);

        $this->assertSame(0, ShiftSignal::query()->count());
    }

    public function test_lookback_window_excludes_old_records(): void
    {
        // Old completed shift (beyond lookback)
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->subDays(30)->setTime(9, 0),
            'ends_at' => now()->subDays(30)->setTime(17, 0),
            'actual_starts_at' => now()->subDays(30)->setTime(9, 0),
            'actual_ends_at' => now()->subDays(30)->setTime(17, 0),
            'status' => 'completed',
            'completed_by' => $this->staff->id,
            'updated_at' => now()->subDays(30),
        ]);

        $this->artisan('shifts:detect-orphans --lookback-days=7')
            ->assertExitCode(0);

        $this->assertSame(0, ShiftSignal::query()->count());
    }

    public function test_multiple_orphan_types_detected_in_single_run(): void
    {
        // Completed shift without timesheet
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->subDay()->setTime(9, 0),
            'ends_at' => now()->subDay()->setTime(17, 0),
            'actual_starts_at' => now()->subDay()->setTime(9, 0),
            'actual_ends_at' => now()->subDay()->setTime(17, 0),
            'status' => 'completed',
            'completed_by' => $this->staff->id,
        ]);

        // Attendance without timesheet
        HrAttendanceSession::query()->create([
            'tenant_id' => 1,
            'user_id' => $this->staff->id,
            'site_id' => $this->site->id,
            'clock_in_at' => now()->subDay()->setTime(9, 0),
            'clock_out_at' => now()->subDay()->setTime(17, 0),
            'break_minutes' => 0,
            'status' => 'closed',
            'source' => 'manual',
            'created_by' => $this->staff->id,
            'closed_by' => $this->staff->id,
        ]);

        $this->artisan('shifts:detect-orphans')
            ->assertExitCode(0);

        $types = ShiftSignal::query()->pluck('signal_type')->unique()->sort()->values();
        $this->assertContains(DetectShiftOrphans::TYPE_MISSING_TIMESHEET, $types->all());
        $this->assertContains(DetectShiftOrphans::TYPE_ORPHAN_ATTENDANCE, $types->all());
    }
}
