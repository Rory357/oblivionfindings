<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Models\HrTimeEntryAmendment;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\ShiftTimelineService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('mysql-serial')]
class ShiftCompletionAttendanceConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql_completion_and_clock_in_cannot_leave_a_completed_shift_with_an_open_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-28 12:00:00', config('app.worker_timezone', 'Pacific/Auckland')));
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());

        $site = Site::factory()->create([
            'type' => 'house',
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $context = ServiceContext::factory()->create([
            'site_id' => $site->id,
            'type' => 'residential',
            'is_active' => true,
        ]);
        $worker = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $this->grantCurrentShiftWorkerAuthority($worker, $site);
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'status' => 'active',
        ]);
        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'user_id' => $worker->id,
            'starts_at' => now()->subHours(4),
            'ends_at' => now(),
            'actual_starts_at' => now()->subHours(4),
            'actual_ends_at' => null,
            'started_by' => $worker->id,
            'completed_by' => null,
            'created_by' => $worker->id,
            'status' => 'in_progress',
        ]);

        $token = Str::uuid()->toString();
        $completionReadyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."shift-completion-ready-{$token}";
        $clockInReadyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."shift-clock-in-ready-{$token}";
        $goPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."shift-attendance-go-{$token}";
        $processes = [];
        $connection->commit();

        try {
            // Hold the first canonical aggregate row so both workers are
            // demonstrably queued on the same Client -> Shift lock order.
            $connection->beginTransaction();
            Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();

            $processes['completion'] = $this->startCompletionWorker(
                $completionReadyPath,
                $goPath,
                $shift,
                $worker,
                $connection->getDatabaseName(),
            );
            $processes['clock_in'] = $this->startClockInWorker(
                $clockInReadyPath,
                $goPath,
                $shift,
                $worker,
                $connection->getDatabaseName(),
            );
            $this->waitForFiles([$completionReadyPath, $clockInReadyPath]);
            touch($goPath);
            usleep(250_000);

            foreach ($processes as $process) {
                $this->assertTrue(
                    $process->isRunning(),
                    'Concurrent Shift completion/clock-in worker did not wait for the canonical Client lock.',
                );
            }

            $connection->commit();

            $results = [];
            foreach ($processes as $name => $process) {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    trim($process->getErrorOutput()) ?: "The {$name} worker failed.",
                );
                $results[$name] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }

            $finalShift = Shift::query()->findOrFail($shift->id);
            $openSessionCount = HrAttendanceSession::query()
                ->where('shift_id', $shift->id)
                ->open()
                ->count();

            $this->assertFalse(
                $finalShift->status === 'completed' && $openSessionCount > 0,
                'A completed Shift must never coexist with a newly open attendance session.',
            );

            if ($results['completion']['outcome'] === 'completed') {
                $this->assertSame('blocked', $results['clock_in']['outcome']);
                $this->assertSame(LogicException::class, $results['clock_in']['class']);
                $this->assertStringContainsString('already been completed', $results['clock_in']['message']);
                $this->assertSame('completed', $finalShift->status);
                $this->assertSame(0, $openSessionCount);
            } else {
                $this->assertSame('blocked', $results['completion']['outcome']);
                $this->assertSame(ValidationException::class, $results['completion']['class']);
                $this->assertArrayHasKey('status', $results['completion']['errors']);
                $this->assertSame('clocked_in', $results['clock_in']['outcome']);
                $this->assertSame('open', $results['clock_in']['session_status']);
                $this->assertSame('in_progress', $finalShift->status);
                $this->assertSame(1, $openSessionCount);
            }
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ([$completionReadyPath, $clockInReadyPath, $goPath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            $attendanceSessions = HrAttendanceSession::query()->where('shift_id', $shift->id)->get();
            $timelineEvents = TimelineEvent::query()->where('shift_id', $shift->id)->get();
            $timesheets = Timesheet::query()->where('shift_id', $shift->id)->get();
            foreach ([$site, $context, $client, $shift, ...$attendanceSessions, ...$timelineEvents, ...$timesheets] as $auditable) {
                DB::table('audit_logs')
                    ->where('auditable_type', $auditable->getMorphClass())
                    ->where('auditable_id', $auditable->id)
                    ->delete();
            }
            DB::table('timeline_events')->where('shift_id', $shift->id)->delete();
            DB::table('hr_attendance_break_events')->whereIn('session_id', $attendanceSessions->pluck('id'))->delete();
            DB::table('hr_time_entries')->whereIn('attendance_session_id', $attendanceSessions->pluck('id'))->delete();
            DB::table('timesheets')->where('shift_id', $shift->id)->delete();
            DB::table('hr_attendance_sessions')->where('shift_id', $shift->id)->delete();
            DB::table('shifts')->where('id', $shift->id)->delete();
            DB::table('clients')->where('id', $client->id)->delete();
            DB::table('service_contexts')->where('id', $context->id)->delete();
            DB::table('hr_employee_profiles')->where('user_id', $worker->id)->delete();
            DB::table('permission_user')->where('user_id', $worker->id)->delete();
            DB::table('users')->where('id', $worker->id)->delete();
            DB::table('permissions')->where('key', 'shifts.update')->delete();
            DB::table('sites')->where('id', $site->id)->delete();
            $connection->beginTransaction();
            Carbon::setTestNow();
        }
    }

    public function test_mysql_completion_and_clock_out_share_client_shift_attendance_lock_order(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-28 12:00:00', config('app.worker_timezone', 'Pacific/Auckland')));
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());

        $site = Site::factory()->create([
            'type' => 'house',
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $context = ServiceContext::factory()->create([
            'site_id' => $site->id,
            'type' => 'residential',
            'is_active' => true,
        ]);
        $worker = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $this->grantCurrentShiftWorkerAuthority($worker, $site);
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'status' => 'active',
        ]);
        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'user_id' => $worker->id,
            'starts_at' => now()->subHours(4),
            'ends_at' => now(),
            'actual_starts_at' => now()->subHours(4),
            'actual_ends_at' => null,
            'started_by' => $worker->id,
            'completed_by' => null,
            'created_by' => $worker->id,
            'status' => 'in_progress',
        ]);
        $session = HrAttendanceSession::query()->create([
            'user_id' => $worker->id,
            'shift_id' => $shift->id,
            'site_id' => $site->id,
            'clock_in_at' => now()->subHours(4),
            'status' => 'open',
            'source' => 'manual',
            'created_by' => $worker->id,
        ]);

        $token = Str::uuid()->toString();
        $completionReadyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."shift-completion-ready-{$token}";
        $clockOutReadyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."shift-clock-out-ready-{$token}";
        $completionGoPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."shift-completion-go-{$token}";
        $clockOutGoPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."shift-clock-out-go-{$token}";
        $processes = [];
        $connection->commit();

        try {
            // Queue completion first on the canonical Client row. A clock-out
            // that locks attendance before Client would then form the inverse
            // wait cycle when the parent releases this mutex.
            $connection->beginTransaction();
            Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();

            $processes['completion'] = $this->startCompletionWorker(
                $completionReadyPath,
                $completionGoPath,
                $shift,
                $worker,
                $connection->getDatabaseName(),
            );
            $processes['clock_out'] = $this->startClockOutWorker(
                $clockOutReadyPath,
                $clockOutGoPath,
                $shift,
                $worker,
                $connection->getDatabaseName(),
            );
            $this->waitForFiles([$completionReadyPath, $clockOutReadyPath]);

            touch($completionGoPath);
            usleep(250_000);
            $this->assertTrue(
                $processes['completion']->isRunning(),
                'The completion worker did not wait for the canonical Client lock.',
            );

            touch($clockOutGoPath);
            usleep(250_000);
            foreach ($processes as $process) {
                $this->assertTrue(
                    $process->isRunning(),
                    'Concurrent Shift completion/clock-out worker did not wait for the canonical Client lock.',
                );
            }

            $connection->commit();

            $results = [];
            foreach ($processes as $name => $process) {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    trim($process->getErrorOutput()) ?: "The {$name} worker failed.",
                );
                $results[$name] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }

            $this->assertSame('blocked', $results['completion']['outcome']);
            $this->assertSame(ValidationException::class, $results['completion']['class']);
            $this->assertArrayHasKey('status', $results['completion']['errors']);
            $this->assertSame('clocked_out', $results['clock_out']['outcome']);
            $this->assertSame('closed', $results['clock_out']['session_status']);
            $this->assertSame('completed', $results['clock_out']['shift_status']);
            $this->assertSame('closed', $session->fresh()->status);
            $this->assertSame('completed', $shift->fresh()->status);
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ([$completionReadyPath, $clockOutReadyPath, $completionGoPath, $clockOutGoPath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            $attendanceSessions = HrAttendanceSession::query()->where('shift_id', $shift->id)->get();
            $timelineEvents = TimelineEvent::query()->where('shift_id', $shift->id)->get();
            $timesheets = Timesheet::query()->where('shift_id', $shift->id)->get();
            foreach ([$site, $context, $client, $shift, ...$attendanceSessions, ...$timelineEvents, ...$timesheets] as $auditable) {
                DB::table('audit_logs')
                    ->where('auditable_type', $auditable->getMorphClass())
                    ->where('auditable_id', $auditable->id)
                    ->delete();
            }
            DB::table('timeline_events')->where('shift_id', $shift->id)->delete();
            DB::table('hr_attendance_break_events')->whereIn('session_id', $attendanceSessions->pluck('id'))->delete();
            DB::table('hr_time_entries')->whereIn('attendance_session_id', $attendanceSessions->pluck('id'))->delete();
            DB::table('timesheets')->where('shift_id', $shift->id)->delete();
            DB::table('hr_attendance_sessions')->where('shift_id', $shift->id)->delete();
            DB::table('shifts')->where('id', $shift->id)->delete();
            DB::table('clients')->where('id', $client->id)->delete();
            DB::table('service_contexts')->where('id', $context->id)->delete();
            DB::table('hr_employee_profiles')->where('user_id', $worker->id)->delete();
            DB::table('permission_user')->where('user_id', $worker->id)->delete();
            DB::table('users')->where('id', $worker->id)->delete();
            DB::table('permissions')->where('key', 'shifts.update')->delete();
            DB::table('sites')->where('id', $site->id)->delete();
            $connection->beginTransaction();
            Carbon::setTestNow();
        }
    }

    public function test_clock_out_handover_prelock_serializes_a_competing_save_and_rolls_back_every_partial_write(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-28 12:00:00', config('app.worker_timezone', 'Pacific/Auckland')));
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());

        $site = Site::factory()->create([
            'type' => 'house',
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $context = ServiceContext::factory()->create([
            'site_id' => $site->id,
            'type' => 'residential',
            'is_active' => true,
        ]);
        $outgoingWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $incomingWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->grantCurrentShiftWorkerAuthority($outgoingWorker, $site);
        $this->grantCurrentShiftWorkerAuthority($incomingWorker, $site);
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'status' => 'active',
        ]);
        $outgoingShift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'user_id' => $outgoingWorker->id,
            'starts_at' => now()->subHours(4),
            'ends_at' => now(),
            'actual_starts_at' => now()->subHours(4),
            'started_by' => $outgoingWorker->id,
            'created_by' => $outgoingWorker->id,
            'status' => 'in_progress',
        ]);
        $incomingShift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'user_id' => $incomingWorker->id,
            'starts_at' => now(),
            'ends_at' => now()->addHours(8),
            'created_by' => $outgoingWorker->id,
            'status' => 'scheduled',
        ]);
        $session = HrAttendanceSession::query()->create([
            'user_id' => $outgoingWorker->id,
            'shift_id' => $outgoingShift->id,
            'site_id' => $site->id,
            'clock_in_at' => now()->subHours(4),
            'status' => 'open',
            'source' => 'manual',
            'created_by' => $outgoingWorker->id,
        ]);
        $handover = ShiftHandover::query()->create([
            'outgoing_shift_id' => $outgoingShift->id,
            'incoming_shift_id' => $incomingShift->id,
            'client_id' => $client->id,
            'outgoing_staff_id' => $outgoingWorker->id,
            'incoming_staff_id' => $incomingWorker->id,
            'status' => 'draft',
            'handover_notes' => 'Initial durable handover.',
            'version' => 1,
        ]);
        $sessionBefore = $session->fresh()->getRawOriginal();
        $shiftBefore = $outgoingShift->fresh()->getRawOriginal();
        $token = Str::uuid()->toString();
        $clockReadyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."attendance-handover-clock-ready-{$token}";
        $saveReadyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."attendance-handover-save-ready-{$token}";
        $goPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."attendance-handover-go-{$token}";
        $handoverSavedPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."attendance-handover-nested-saved-{$token}";
        $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."attendance-handover-release-{$token}";
        $processes = [];
        $connection->commit();

        try {
            $processes['clock_out'] = $this->startClockOutHandoverRollbackWorker(
                $clockReadyPath,
                $goPath,
                $handoverSavedPath,
                $releasePath,
                $session,
                $outgoingWorker,
                $connection->getDatabaseName(),
            );
            $processes['save'] = $this->startCompetingHandoverSaveWorker(
                $saveReadyPath,
                $goPath,
                $handoverSavedPath,
                $outgoingShift,
                $outgoingWorker,
                $connection->getDatabaseName(),
            );
            $this->waitForFiles([$clockReadyPath, $saveReadyPath]);
            touch($goPath);
            $this->waitForFiles([$handoverSavedPath]);
            usleep(250_000);
            $this->assertTrue($processes['clock_out']->isRunning());
            $this->assertTrue($processes['save']->isRunning(), 'The competing save did not wait for the clock-out handover aggregate.');
            touch($releasePath);

            $results = [];
            foreach ($processes as $name => $process) {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    trim($process->getErrorOutput()) ?: "The {$name} worker failed.",
                );
                $results[$name] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }

            $this->assertSame('blocked', $results['clock_out']['outcome']);
            $this->assertSame('saved', $results['save']['outcome']);
            $this->assertSame($sessionBefore, $session->fresh()->getRawOriginal());
            $this->assertSame($shiftBefore, $outgoingShift->fresh()->getRawOriginal());
            $this->assertSame('Durable competing handover.', $handover->fresh()->handover_notes);
            $this->assertSame(2, (int) $handover->fresh()->version);
            $this->assertFalse(HrTimeEntry::query()->where('attendance_session_id', $session->id)->exists());
            $this->assertFalse(Timesheet::query()->where('attendance_session_id', $session->id)->exists());
            $this->assertSame(1, AuditLog::query()
                ->where('action', 'shift.handover.updated')
                ->where('auditable_type', ShiftHandover::class)
                ->where('auditable_id', $handover->id)
                ->count());
            $timeline = TimelineEvent::query()
                ->where('type', ShiftTimelineService::HANDOVER_CREATED_EVENT_TYPE)
                ->where('source_type', ShiftHandover::class)
                ->where('source_id', $handover->id)
                ->sole();
            $this->assertStringContainsString('Durable competing handover.', (string) $timeline->body);
            $this->assertStringNotContainsString('Rolled-back clock-out handover.', (string) $timeline->body);
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ([$clockReadyPath, $saveReadyPath, $goPath, $handoverSavedPath, $releasePath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            DB::table('timeline_events')->whereIn('shift_id', [$outgoingShift->id, $incomingShift->id])->delete();
            DB::table('audit_logs')->where('client_id', $client->id)->delete();
            DB::table('shift_handovers')->where('client_id', $client->id)->delete();
            DB::table('timesheets')->whereIn('shift_id', [$outgoingShift->id, $incomingShift->id])->delete();
            DB::table('hr_time_entries')->whereIn('shift_id', [$outgoingShift->id, $incomingShift->id])->delete();
            DB::table('hr_attendance_sessions')->whereIn('shift_id', [$outgoingShift->id, $incomingShift->id])->delete();
            DB::table('shifts')->whereIn('id', [$outgoingShift->id, $incomingShift->id])->delete();
            DB::table('clients')->where('id', $client->id)->delete();
            DB::table('service_contexts')->where('id', $context->id)->delete();
            DB::table('hr_employee_profiles')->whereIn('user_id', [$outgoingWorker->id, $incomingWorker->id])->delete();
            DB::table('permission_user')->whereIn('user_id', [$outgoingWorker->id, $incomingWorker->id])->delete();
            DB::table('users')->whereIn('id', [$outgoingWorker->id, $incomingWorker->id])->delete();
            DB::table('sites')->where('id', $site->id)->delete();
            $connection->beginTransaction();
            Carbon::setTestNow();
        }
    }

    public function test_completion_permission_revoked_while_waiting_on_client_aggregate_writes_nothing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-28 12:00:00', config('app.worker_timezone', 'Pacific/Auckland')));
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());

        $site = Site::factory()->create([
            'type' => 'house',
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $context = ServiceContext::factory()->create([
            'site_id' => $site->id,
            'type' => 'residential',
            'is_active' => true,
        ]);
        $worker = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $this->grantCurrentShiftWorkerAuthority($worker, $site);
        $permission = Permission::query()->where('key', 'shifts.update')->firstOrFail();
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'status' => 'active',
        ]);
        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'user_id' => $worker->id,
            'starts_at' => now()->subHours(4),
            'ends_at' => now(),
            'actual_starts_at' => now()->subHours(4),
            'actual_ends_at' => null,
            'started_by' => $worker->id,
            'completed_by' => null,
            'created_by' => $worker->id,
            'status' => 'in_progress',
        ]);
        $shiftBefore = $shift->fresh()->getRawOriginal();
        $timesheetCount = Timesheet::query()->count();
        $timelineCount = TimelineEvent::query()->count();
        $auditCount = AuditLog::query()->count();
        $noteCount = DB::table('client_notes')->count();

        $token = Str::uuid()->toString();
        $readyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."shift-authority-ready-{$token}";
        $goPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."shift-authority-go-{$token}";
        $process = null;
        $connection->commit();

        try {
            $connection->beginTransaction();
            Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $process = $this->startCompletionWorker(
                $readyPath,
                $goPath,
                $shift,
                $worker,
                $connection->getDatabaseName(),
            );
            $this->waitForFiles([$readyPath]);
            touch($goPath);
            usleep(250_000);
            $this->assertTrue(
                $process->isRunning(),
                'Completion did not wait for the canonical Client aggregate.',
            );

            DB::table('permission_user')->updateOrInsert(
                [
                    'user_id' => $worker->id,
                    'permission_id' => $permission->id,
                ],
                ['allowed' => false],
            );
            $connection->commit();

            $process->wait();
            $this->assertTrue(
                $process->isSuccessful(),
                trim($process->getErrorOutput()) ?: 'The completion authorization worker failed.',
            );
            $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('blocked', $result['outcome']);
            $this->assertSame(403, $result['http_status']);
            $this->assertSame($shiftBefore, $shift->fresh()->getRawOriginal());
            $this->assertSame($timesheetCount, Timesheet::query()->count());
            $this->assertSame($timelineCount, TimelineEvent::query()->count());
            $this->assertSame($auditCount, AuditLog::query()->count());
            $this->assertSame($noteCount, DB::table('client_notes')->count());
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            if ($process?->isRunning()) {
                $process->stop(1);
            }
            foreach ([$readyPath, $goPath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            DB::table('timeline_events')->where('shift_id', $shift->id)->delete();
            DB::table('audit_logs')
                ->where('auditable_type', $shift->getMorphClass())
                ->where('auditable_id', $shift->id)
                ->delete();
            DB::table('timesheets')->where('shift_id', $shift->id)->delete();
            DB::table('shifts')->where('id', $shift->id)->delete();
            DB::table('clients')->where('id', $client->id)->delete();
            DB::table('service_contexts')->where('id', $context->id)->delete();
            DB::table('hr_employee_profiles')->where('user_id', $worker->id)->delete();
            DB::table('permission_user')->where('user_id', $worker->id)->delete();
            DB::table('users')->where('id', $worker->id)->delete();
            DB::table('permissions')->where('key', 'shifts.update')->delete();
            DB::table('sites')->where('id', $site->id)->delete();
            $connection->beginTransaction();
            Carbon::setTestNow();
        }
    }

    public function test_mysql_attendance_correction_and_timesheet_approval_serialize_on_the_payroll_mutex(): void
    {
        Carbon::setTestNow(
            Carbon::parse('2026-08-28 12:00:00', config('app.worker_timezone', 'Pacific/Auckland'))
                ->setTimezone(config('app.timezone', 'UTC')),
        );
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());

        $site = Site::factory()->create([
            'type' => 'house',
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $context = ServiceContext::factory()->create([
            'site_id' => $site->id,
            'type' => 'residential',
            'is_active' => true,
        ]);
        $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $manager = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
        foreach ([$worker, $manager] as $user) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $user->id,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
                'start_date' => today()->subYear(),
                'end_date' => null,
                'is_active' => true,
            ]);
        }
        $permission = Permission::query()->create([
            'key' => 'timesheets.manageAny',
            'description' => 'Concurrency fixture',
            'group' => 'timesheets',
            'module' => 'hr',
        ]);
        $manager->permissionOverrides()->attach($permission->id, ['allowed' => true]);

        $client = Client::factory()->create([
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'status' => 'active',
        ]);
        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'user_id' => $worker->id,
            'starts_at' => now()->subHours(8),
            'ends_at' => now()->subHours(2),
            'actual_starts_at' => now()->subHours(8),
            'actual_ends_at' => now()->subHours(2),
            'started_by' => $worker->id,
            'completed_by' => $worker->id,
            'created_by' => $worker->id,
            'status' => 'completed',
        ]);
        $session = HrAttendanceSession::query()->create([
            'user_id' => $worker->id,
            'shift_id' => $shift->id,
            'site_id' => $site->id,
            'clock_in_at' => now()->subHours(8),
            'clock_out_at' => now()->subHours(2),
            'break_minutes' => 30,
            'status' => 'closed',
            'source' => 'manual',
            'created_by' => $worker->id,
            'closed_by' => $worker->id,
        ]);
        // Use a genuinely new model instance: refresh() can merge the Carbon
        // object retained from create(), while the subprocess reloads this
        // persisted naive datetime in the application's UTC timezone.
        $session = HrAttendanceSession::query()->findOrFail($session->id);
        $workDate = $session->clock_in_at->copy()
            ->setTimezone(config('app.worker_timezone', config('app.timezone', 'UTC')))
            ->toDateString();
        $entry = HrTimeEntry::query()->create([
            'user_id' => $worker->id,
            'shift_id' => $shift->id,
            'attendance_session_id' => $session->id,
            'site_id' => $site->id,
            'client_id' => $client->id,
            'entry_date' => $workDate,
            'clock_in' => $session->clock_in_at,
            'clock_out' => $session->clock_out_at,
            'break_minutes' => 30,
            'total_hours' => 5.5,
            'entry_type' => 'clock',
            'status' => 'submitted',
            'source_type' => 'attendance',
            'source_id' => $session->id,
            'created_by' => $worker->id,
        ]);
        $timesheet = Timesheet::factory()->create([
            'shift_id' => $shift->id,
            'attendance_session_id' => $session->id,
            'user_id' => $worker->id,
            'client_id' => $client->id,
            'shift_site_id' => $site->id,
            'work_date' => $workDate,
            'starts_at' => $session->clock_in_at,
            'ends_at' => $session->clock_out_at,
            'break_minutes' => 30,
            'status' => 'draft',
            'created_by' => $worker->id,
        ]);
        $timesheet->forceFill([
            'status' => 'submitted',
            'submitted_at' => now()->subHour(),
            'submitted_by' => $worker->id,
        ])->saveQuietly();

        $token = Str::uuid()->toString();
        $correctionReadyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."attendance-correction-ready-{$token}";
        $approvalReadyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."timesheet-approval-ready-{$token}";
        $correctionGoPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."attendance-correction-go-{$token}";
        $approvalGoPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."timesheet-approval-go-{$token}";
        $processes = [];
        $correctedOut = now()->subHour();
        $connection->commit();

        try {
            // Hold the first shared lock, queue correction first, then approval.
            // Both commands must wait on this exact row before touching their
            // aggregate or Timesheet locks, so the first waiter deterministically
            // owns the correction/approval decision.
            $connection->beginTransaction();
            $this->assertNotNull(
                DB::table('hr_payroll_run_mutexes')
                    ->where('key', 'application')
                    ->lockForUpdate()
                    ->first(),
                'The application payroll mutex fixture is missing.',
            );

            $processes['correction'] = $this->startCorrectionWorker(
                $correctionReadyPath,
                $correctionGoPath,
                $session,
                $manager,
                $correctedOut,
                $connection->getDatabaseName(),
            );
            $this->waitForFiles([$correctionReadyPath]);
            touch($correctionGoPath);
            usleep(250_000);
            $this->assertTrue($processes['correction']->isRunning(), 'Correction did not wait for the payroll mutex.');

            $processes['approval'] = $this->startApprovalWorker(
                $approvalReadyPath,
                $approvalGoPath,
                $timesheet,
                $manager,
                $connection->getDatabaseName(),
            );
            $this->waitForFiles([$approvalReadyPath]);
            touch($approvalGoPath);
            usleep(250_000);
            $this->assertTrue($processes['approval']->isRunning(), 'Approval did not wait for the payroll mutex.');

            $connection->commit();

            $results = [];
            foreach ($processes as $name => $process) {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    trim($process->getErrorOutput()) ?: "The {$name} worker failed.",
                );
                $results[$name] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }

            $this->assertSame(
                'corrected',
                $results['correction']['outcome'],
                json_encode($results['correction'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            );
            $this->assertSame(
                'blocked',
                $results['approval']['outcome'],
                json_encode($results['approval'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            );
            $this->assertSame(ValidationException::class, $results['approval']['class']);
            $this->assertSame('draft', $timesheet->fresh()->status);
            $this->assertSame($correctedOut->timestamp, $session->fresh()->clock_out_at->timestamp);
            $this->assertSame($correctedOut->timestamp, $entry->fresh()->clock_out->timestamp);
            $this->assertSame(1, HrTimeEntryAmendment::query()->where('hr_time_entry_id', $entry->id)->count());
            $this->assertSame(1, AuditLog::query()
                ->where('action', 'attendance.session.corrected')
                ->where('auditable_id', $session->id)
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
            foreach ([$correctionReadyPath, $approvalReadyPath, $correctionGoPath, $approvalGoPath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            foreach ([$site, $context, $client, $shift, $session, $timesheet] as $auditable) {
                DB::table('audit_logs')
                    ->where('auditable_type', $auditable->getMorphClass())
                    ->where('auditable_id', $auditable->id)
                    ->delete();
            }
            DB::table('hr_time_entry_amendments')->where('hr_time_entry_id', $entry->id)->delete();
            DB::table('hr_time_entries')->where('id', $entry->id)->delete();
            DB::table('timesheets')->where('id', $timesheet->id)->delete();
            DB::table('hr_attendance_sessions')->where('id', $session->id)->delete();
            DB::table('shifts')->where('id', $shift->id)->delete();
            DB::table('clients')->where('id', $client->id)->delete();
            DB::table('service_contexts')->where('id', $context->id)->delete();
            DB::table('permission_user')->where('permission_id', $permission->id)->delete();
            DB::table('permissions')->where('id', $permission->id)->delete();
            DB::table('hr_employee_profiles')->whereIn('user_id', [$worker->id, $manager->id])->delete();
            DB::table('users')->whereIn('id', [$worker->id, $manager->id])->delete();
            DB::table('sites')->where('id', $site->id)->delete();
            $connection->beginTransaction();
            Carbon::setTestNow();
        }
    }

    private function startCompletionWorker(
        string $readyPath,
        string $goPath,
        Shift $shift,
        User $worker,
        string $database,
    ): Process {
        $workerCode = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Carbon\Carbon::setTestNow(Carbon\Carbon::parse($argv[6], config('app.worker_timezone', 'Pacific/Auckland')));
$shift = App\Models\Shift::query()->findOrFail((int) $argv[2]);
$worker = App\Models\User::query()->findOrFail((int) $argv[3]);
file_put_contents($argv[4], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[5])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the Shift completion/attendance barrier.');
    }
    usleep(10_000);
}
try {
    $completed = $app->make(App\Domain\Shifts\Lifecycle\ShiftLifecycleService::class)->complete(
        $shift,
        $worker,
        new App\Domain\Shifts\Lifecycle\Data\CompleteShiftData(
            actualEndsAt: now(),
            source: App\Domain\Shifts\Lifecycle\ShiftLifecycleSource::Manual,
            createSummaryNote: false,
            syncDraftTimesheet: false,
        ),
    );
    $result = [
        'outcome' => 'completed',
        'status' => $completed->status,
    ];
} catch (Throwable $exception) {
    $result = [
        'outcome' => 'blocked',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'errors' => method_exists($exception, 'errors') ? $exception->errors() : [],
        'http_status' => method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : null,
    ];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;

        return $this->startWorkerProcess(
            $workerCode,
            $readyPath,
            $goPath,
            $shift,
            $worker,
            $database,
        );
    }

    private function startClockInWorker(
        string $readyPath,
        string $goPath,
        Shift $shift,
        User $worker,
        string $database,
    ): Process {
        $workerCode = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Carbon\Carbon::setTestNow(Carbon\Carbon::parse($argv[6], config('app.worker_timezone', 'Pacific/Auckland')));
$shift = App\Models\Shift::query()->findOrFail((int) $argv[2]);
$worker = App\Models\User::query()->findOrFail((int) $argv[3]);
file_put_contents($argv[4], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[5])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the Shift completion/attendance barrier.');
    }
    usleep(10_000);
}
try {
    $session = $app->make(App\Domain\Hr\Services\AttendanceService::class)->clockIn($worker, [
        'shift_id' => $shift->id,
        'clock_in_at' => now(),
        'source' => 'manual',
    ]);
    $result = [
        'outcome' => 'clocked_in',
        'session_status' => $session->status,
    ];
} catch (Throwable $exception) {
    $result = [
        'outcome' => 'blocked',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'errors' => method_exists($exception, 'errors') ? $exception->errors() : [],
    ];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;

        return $this->startWorkerProcess(
            $workerCode,
            $readyPath,
            $goPath,
            $shift,
            $worker,
            $database,
        );
    }

    private function startClockOutWorker(
        string $readyPath,
        string $goPath,
        Shift $shift,
        User $worker,
        string $database,
    ): Process {
        $workerCode = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Carbon\Carbon::setTestNow(Carbon\Carbon::parse($argv[6], config('app.worker_timezone', 'Pacific/Auckland')));
$shift = App\Models\Shift::query()->findOrFail((int) $argv[2]);
$worker = App\Models\User::query()->findOrFail((int) $argv[3]);
$session = App\Domain\Hr\Models\HrAttendanceSession::query()
    ->where('shift_id', $shift->id)
    ->where('user_id', $worker->id)
    ->open()
    ->sole();
file_put_contents($argv[4], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[5])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the Shift completion/attendance barrier.');
    }
    usleep(10_000);
}
try {
    $closed = $app->make(App\Domain\Hr\Services\AttendanceService::class)->clockOut(
        $worker,
        $session,
        [
            'clock_out_at' => now()->toIso8601String(),
            'force' => true,
            'override_reason' => 'Concurrency lock-order regression.',
        ],
    );
    $result = [
        'outcome' => 'clocked_out',
        'session_status' => $closed->status,
        'shift_status' => $closed->shift?->status,
    ];
} catch (Throwable $exception) {
    $result = [
        'outcome' => 'blocked',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'errors' => method_exists($exception, 'errors') ? $exception->errors() : [],
    ];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;

        return $this->startWorkerProcess(
            $workerCode,
            $readyPath,
            $goPath,
            $shift,
            $worker,
            $database,
        );
    }

    private function startClockOutHandoverRollbackWorker(
        string $readyPath,
        string $goPath,
        string $handoverSavedPath,
        string $releasePath,
        HrAttendanceSession $session,
        User $worker,
        string $database,
    ): Process {
        $workerCode = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Carbon\Carbon::setTestNow(Carbon\Carbon::parse($argv[8], config('app.worker_timezone', 'Pacific/Auckland')));
$app->singleton(App\Services\ShiftHandoverService::class, function ($app) use ($argv) {
    return new class(
        $app->make(App\Services\ShiftTimelineService::class),
        $app->make(App\Services\UserSiteAccessService::class),
        $app->make(App\Services\Medication\MedicationGovernanceScopeService::class),
        $app->make(App\Services\AuthorizationEvidenceLockService::class),
        $argv[6],
        $argv[7],
    ) extends App\Services\ShiftHandoverService
    {
        public function __construct(
            App\Services\ShiftTimelineService $timelineService,
            App\Services\UserSiteAccessService $siteAccess,
            App\Services\Medication\MedicationGovernanceScopeService $medicationGovernance,
            App\Services\AuthorizationEvidenceLockService $authorizationEvidence,
            private readonly string $handoverSavedPath,
            private readonly string $releasePath,
        ) {
            parent::__construct($timelineService, $siteAccess, $medicationGovernance, $authorizationEvidence);
        }

        public function save(
            App\Models\Shift $outgoingShift,
            App\Models\User $actor,
            array $data,
            array $additionalParticipantUserIds = [],
        ): array {
            $result = parent::save($outgoingShift, $actor, $data, $additionalParticipantUserIds);
            file_put_contents($this->handoverSavedPath, 'saved');
            $deadline = microtime(true) + 15;
            while (! is_file($this->releasePath)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Timed out waiting to release the nested handover transaction.');
                }
                usleep(10_000);
            }

            return $result;
        }
    };
});
$session = App\Domain\Hr\Models\HrAttendanceSession::query()->findOrFail((int) $argv[2]);
$worker = App\Models\User::query()->findOrFail((int) $argv[3]);
file_put_contents($argv[4], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[5])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the outer attendance/handover barrier.');
    }
    usleep(10_000);
}
try {
    $app->make(App\Domain\Hr\Services\AttendanceService::class)->clockOut(
        $worker,
        $session,
        [
            'clock_out_at' => now()->addHour()->toIso8601String(),
            'handover' => [
                'meds_completed' => true,
                'follow_up_needed' => false,
                'handover_notes' => 'Rolled-back clock-out handover.',
            ],
        ],
    );
    $result = ['outcome' => 'clocked_out'];
} catch (Throwable $exception) {
    $result = [
        'outcome' => 'blocked',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;

        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                $workerCode,
                base_path(),
                (string) $session->id,
                (string) $worker->id,
                $readyPath,
                $goPath,
                $handoverSavedPath,
                $releasePath,
                now()->toIso8601String(),
            ],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => $database,
                'CACHE_STORE' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'SESSION_DRIVER' => 'array',
            ],
        );
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    private function startCompetingHandoverSaveWorker(
        string $readyPath,
        string $goPath,
        string $handoverSavedPath,
        Shift $shift,
        User $worker,
        string $database,
    ): Process {
        $workerCode = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Carbon\Carbon::setTestNow(Carbon\Carbon::parse($argv[7], config('app.worker_timezone', 'Pacific/Auckland')));
$shift = App\Models\Shift::query()->findOrFail((int) $argv[2]);
$worker = App\Models\User::query()->findOrFail((int) $argv[3]);
file_put_contents($argv[4], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[5]) || ! is_file($argv[6])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the nested handover save barrier.');
    }
    usleep(10_000);
}
try {
    $saved = $app->make(App\Services\ShiftHandoverService::class)->save(
        $shift,
        $worker,
        [
            'handover_notes' => 'Durable competing handover.',
            'replace_owned_draft' => true,
            'submit' => false,
        ],
    );
    $result = ['outcome' => 'saved', 'id' => $saved['handover']->id];
} catch (Throwable $exception) {
    $result = [
        'outcome' => 'blocked',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'errors' => method_exists($exception, 'errors') ? $exception->errors() : [],
    ];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;

        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                $workerCode,
                base_path(),
                (string) $shift->id,
                (string) $worker->id,
                $readyPath,
                $goPath,
                $handoverSavedPath,
                now()->toIso8601String(),
            ],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => $database,
                'CACHE_STORE' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'SESSION_DRIVER' => 'array',
            ],
        );
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    private function startCorrectionWorker(
        string $readyPath,
        string $goPath,
        HrAttendanceSession $session,
        User $manager,
        Carbon $correctedOut,
        string $database,
    ): Process {
        $workerCode = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Carbon\Carbon::setTestNow(Carbon\Carbon::parse($argv[7], config('app.worker_timezone', 'Pacific/Auckland')));
$session = App\Domain\Hr\Models\HrAttendanceSession::query()->findOrFail((int) $argv[2]);
$manager = App\Models\User::query()->findOrFail((int) $argv[3]);
file_put_contents($argv[4], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[5])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the attendance-correction barrier.');
    }
    usleep(10_000);
}
try {
    $corrected = $app->make(App\Domain\Hr\Services\AttendanceService::class)->correctSession(
        $manager,
        $session,
        Carbon\Carbon::parse($argv[6]),
        30,
        'Deterministic correction/approval serialization.',
    );
    $result = [
        'outcome' => 'corrected',
        'session_status' => $corrected->status,
    ];
} catch (Throwable $exception) {
    $diagnosticSession = App\Domain\Hr\Models\HrAttendanceSession::query()->find((int) $argv[2]);
    $diagnosticEntry = App\Domain\Hr\Models\HrTimeEntry::withTrashed()
        ->where('attendance_session_id', (int) $argv[2])
        ->first();
    $workerTimezone = config('app.worker_timezone', config('app.timezone', 'UTC'));
    $result = [
        'outcome' => 'blocked',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'errors' => method_exists($exception, 'errors') ? $exception->errors() : [],
        'diagnostics' => [
            'app_timezone' => config('app.timezone'),
            'worker_timezone' => $workerTimezone,
            'session_clock_in_raw' => $diagnosticSession?->getRawOriginal('clock_in_at'),
            'session_clock_in_iso' => $diagnosticSession?->clock_in_at?->toIso8601String(),
            'session_work_date' => $diagnosticSession?->clock_in_at?->copy()->setTimezone($workerTimezone)->toDateString(),
            'entry_date_raw' => $diagnosticEntry?->getRawOriginal('entry_date'),
            'entry_date_cast' => $diagnosticEntry?->entry_date?->toDateString(),
            'test_now_iso' => now()->toIso8601String(),
        ],
    ];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;

        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                $workerCode,
                base_path(),
                (string) $session->id,
                (string) $manager->id,
                $readyPath,
                $goPath,
                $correctedOut->toIso8601String(),
                now()->toIso8601String(),
            ],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => $database,
                'CACHE_STORE' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'SESSION_DRIVER' => 'array',
            ],
        );
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    private function startApprovalWorker(
        string $readyPath,
        string $goPath,
        Timesheet $timesheet,
        User $manager,
        string $database,
    ): Process {
        $workerCode = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Carbon\Carbon::setTestNow(Carbon\Carbon::parse($argv[6], config('app.worker_timezone', 'Pacific/Auckland')));
$timesheet = App\Models\Timesheet::query()->findOrFail((int) $argv[2]);
$manager = App\Models\User::query()->findOrFail((int) $argv[3]);
file_put_contents($argv[4], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[5])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the Timesheet-approval barrier.');
    }
    usleep(10_000);
}
try {
    $app->make(App\Domain\Shifts\Timesheets\TimesheetApprovalService::class)
        ->approve($timesheet, $manager, 'Concurrency regression.');
    $result = ['outcome' => 'approved'];
} catch (Throwable $exception) {
    $result = [
        'outcome' => 'blocked',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'errors' => method_exists($exception, 'errors') ? $exception->errors() : [],
    ];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;

        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                $workerCode,
                base_path(),
                (string) $timesheet->id,
                (string) $manager->id,
                $readyPath,
                $goPath,
                now()->toIso8601String(),
            ],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => $database,
                'CACHE_STORE' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'SESSION_DRIVER' => 'array',
            ],
        );
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    private function startWorkerProcess(
        string $workerCode,
        string $readyPath,
        string $goPath,
        Shift $shift,
        User $worker,
        string $database,
    ): Process {
        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                $workerCode,
                base_path(),
                (string) $shift->id,
                (string) $worker->id,
                $readyPath,
                $goPath,
                now()->toIso8601String(),
            ],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => $database,
                'CACHE_STORE' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'SESSION_DRIVER' => 'array',
            ],
        );
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    private function grantCurrentShiftWorkerAuthority(User $worker, Site $site): void
    {
        HrEmployeeProfile::factory()->create([
            'user_id' => $worker->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $permission = Permission::query()->firstOrCreate(
            ['key' => 'shifts.update'],
            [
                'description' => 'Shift lifecycle concurrency fixture',
                'group' => 'shifts',
                'module' => 'operations',
            ],
        );
        $worker->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    /** @param array<int, string> $paths */
    private function waitForFiles(array $paths): void
    {
        $deadline = microtime(true) + 15;
        while (collect($paths)->contains(fn (string $path): bool => ! is_file($path))) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Shift completion/attendance concurrency workers did not become ready.');
            }
            usleep(10_000);
        }
    }
}
