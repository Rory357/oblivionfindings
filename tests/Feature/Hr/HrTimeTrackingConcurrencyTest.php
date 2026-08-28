<?php

namespace Tests\Feature\Hr;

use App\Domain\Hr\Models\HrAttendanceBreakEvent;
use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** These shared-MySQL barrier tests must run without parallel workers. */
#[Group('mysql-serial')]
class HrTimeTrackingConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->seed(RbacSeeder::class);
    }

    public function test_self_clock_in_and_clock_on_behalf_leave_exactly_one_active_entry(): void
    {
        [$manager, $worker, $site, $client, $shift] = $this->commandFixture();
        $token = Str::uuid()->toString();
        $readyPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-self-ready-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-behalf-ready-{$token}",
        ];
        $goPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-clock-go-{$token}";
        $processes = [];
        DB::connection()->commit();

        try {
            DB::connection()->beginTransaction();
            $this->lockPayrollMutex();
            $processes[] = $this->startWorker(
                $this->selfClockWorker(),
                [$shift->id, $worker->id, $readyPaths[0], $goPath],
            );
            $processes[] = $this->startWorker(
                $this->behalfClockWorker(),
                [$manager->id, $worker->id, $shift->id, $site->id, $client->id, $readyPaths[1], $goPath],
            );
            $this->waitForFiles($readyPaths);
            touch($goPath);
            usleep(250_000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Clock worker did not wait for the payroll mutex.');
            }
            DB::connection()->commit();

            $results = array_map(fn (Process $process): array => $this->waitForResult($process), $processes);
            $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['outcome'] === 'created'));
            $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['outcome'] === 'rejected'));
            $this->assertSame(1, HrTimeEntry::query()
                ->where('user_id', $worker->id)
                ->whereNull('clock_out')
                ->where('status', '!=', 'voided')
                ->count());
        } finally {
            if (DB::connection()->transactionLevel() > 0) {
                DB::connection()->rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            $this->removeFiles([...$readyPaths, $goPath]);
        }
    }

    public function test_permission_revocation_while_waiting_on_mutex_conceals_target_and_writes_nothing(): void
    {
        [$manager, $worker, $site, $client, $shift, $permission] = $this->commandFixture();
        $settingsAdmin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $settingsPermission = Permission::query()->where('key', 'settings.access.manage')->firstOrFail();
        $settingsAdmin->permissionOverrides()->syncWithoutDetaching([
            $settingsPermission->id => ['allowed' => true],
        ]);
        $token = Str::uuid()->toString();
        $readyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-auth-ready-{$token}";
        $goPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-auth-go-{$token}";
        $revokeReadyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-auth-revoke-ready-{$token}";
        $revokeGoPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-auth-revoke-go-{$token}";
        $process = null;
        $revoker = null;
        DB::connection()->commit();

        try {
            DB::connection()->beginTransaction();
            $this->lockPayrollMutex();
            $process = $this->startWorker(
                $this->behalfClockWorker(),
                [$manager->id, $worker->id, $shift->id, $site->id, $client->id, $readyPath, $goPath],
            );
            $this->waitForFiles([$readyPath]);
            touch($goPath);
            usleep(250_000);
            $this->assertTrue($process->isRunning(), 'Command did not wait for the payroll mutex.');

            // Exercise the real Settings/Access mutation path on an
            // independent connection. It does not acquire the payroll mutex,
            // so the deny must commit before the attendance command resumes.
            $revoker = $this->startWorker(
                $this->settingsRevocationWorker(),
                [$settingsAdmin->id, $manager->id, $permission->id, $revokeReadyPath, $revokeGoPath],
            );
            $this->waitForFiles([$revokeReadyPath]);
            touch($revokeGoPath);
            $revocationResult = $this->waitForResult($revoker);
            $this->assertSame('revoked', $revocationResult['outcome']);
            DB::connection()->commit();

            $result = $this->waitForResult($process);
            $this->assertSame('rejected', $result['outcome']);
            $this->assertSame(404, $result['status']);
            $this->assertFalse(HrTimeEntry::query()->where('shift_id', $shift->id)->exists());
        } finally {
            if (DB::connection()->transactionLevel() > 0) {
                DB::connection()->rollBack();
            }
            if ($process?->isRunning()) {
                $process->stop();
            }
            if ($revoker?->isRunning()) {
                $revoker->stop();
            }
            $this->removeFiles([$readyPath, $goPath, $revokeReadyPath, $revokeGoPath]);
        }
    }

    public function test_manager_profile_site_move_while_waiting_on_client_aggregate_writes_nothing(): void
    {
        [$manager, $worker, $site, $client, $shift] = $this->commandFixture();
        $foreignSite = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $shiftBefore = $shift->fresh()->getRawOriginal();
        $sessionCount = HrAttendanceSession::query()->count();
        $entryCount = HrTimeEntry::query()->count();
        $timesheetCount = Timesheet::query()->count();
        $timelineCount = DB::table('timeline_events')->count();
        $auditCount = DB::table('audit_logs')->count();
        $token = Str::uuid()->toString();
        $readyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-profile-site-ready-{$token}";
        $goPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-profile-site-go-{$token}";
        $process = null;
        $connection = DB::connection();
        $connection->commit();

        try {
            $connection->beginTransaction();
            Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $process = $this->startWorker(
                $this->behalfClockWorker(),
                [$manager->id, $worker->id, $shift->id, $site->id, $client->id, $readyPath, $goPath],
            );
            $this->waitForFiles([$readyPath]);
            touch($goPath);
            usleep(250_000);
            $this->assertTrue($process->isRunning(), 'HR command did not wait for the canonical Client aggregate.');

            HrEmployeeProfile::query()
                ->where('user_id', $manager->id)
                ->update([
                    'primary_site_id' => $foreignSite->id,
                    'updated_at' => now(),
                ]);
            $connection->commit();

            $result = $this->waitForResult($process);
            $this->assertSame('rejected', $result['outcome']);
            $this->assertSame(404, $result['status']);
            $this->assertSame($shiftBefore, $shift->fresh()->getRawOriginal());
            $this->assertSame($sessionCount, HrAttendanceSession::query()->count());
            $this->assertSame($entryCount, HrTimeEntry::query()->count());
            $this->assertSame($timesheetCount, Timesheet::query()->count());
            $this->assertSame($timelineCount, DB::table('timeline_events')->count());
            $this->assertSame($auditCount, DB::table('audit_logs')->count());
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            if ($process?->isRunning()) {
                $process->stop();
            }
            $this->removeFiles([$readyPath, $goPath]);
        }
    }

    public function test_production_access_revocation_serializes_after_an_authorized_hr_command(): void
    {
        [$manager, $worker, $site, $client, $shift, $permission] = $this->commandFixture();
        $settingsAdmin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $settingsPermission = Permission::query()->where('key', 'settings.access.manage')->firstOrFail();
        $settingsAdmin->permissionOverrides()->syncWithoutDetaching([
            $settingsPermission->id => ['allowed' => true],
        ]);
        $token = Str::uuid()->toString();
        $commandReadyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-evidence-ready-{$token}";
        $commandGoPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-evidence-go-{$token}";
        $revokeReadyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-evidence-revoke-ready-{$token}";
        $revokeGoPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-evidence-revoke-go-{$token}";
        $command = null;
        $revoker = null;
        DB::connection()->commit();

        try {
            $command = $this->startWorker(
                $this->authorizationBarrierBehalfWorker(),
                [$manager->id, $worker->id, $shift->id, $site->id, $client->id, $commandReadyPath, $commandGoPath],
            );
            $this->waitForFiles([$commandReadyPath]);

            $revoker = $this->startWorker(
                $this->settingsRevocationWorker(),
                [$settingsAdmin->id, $manager->id, $permission->id, $revokeReadyPath, $revokeGoPath],
            );
            $this->waitForFiles([$revokeReadyPath]);
            touch($revokeGoPath);
            usleep(250_000);
            $this->assertTrue($revoker->isRunning(), 'Production Access revocation did not wait for locked RBAC evidence.');

            touch($commandGoPath);
            $commandResult = $this->waitForResult($command);
            $revocationResult = $this->waitForResult($revoker);

            $this->assertSame('created', $commandResult['outcome']);
            $this->assertSame('revoked', $revocationResult['outcome']);
            $this->assertSame(1, HrTimeEntry::query()->where('shift_id', $shift->id)->count());
            $this->assertFalse((bool) DB::table('permission_user')
                ->where('user_id', $manager->id)
                ->where('permission_id', $permission->id)
                ->value('allowed'));
        } finally {
            foreach ([$command, $revoker] as $process) {
                if ($process?->isRunning()) {
                    $process->stop();
                }
            }
            $this->removeFiles([$commandReadyPath, $commandGoPath, $revokeReadyPath, $revokeGoPath]);
        }
    }

    public function test_production_role_revocations_win_before_a_waiting_hr_command_and_are_freshly_denied(): void
    {
        DB::connection()->commit();

        foreach (['role_detach', 'role_permission_detach'] as $scenario) {
            [$manager, $worker, $site, $client, $shift, , $role, $settingsAdmin] = $this->roleBackedCommandFixture();
            $token = Str::uuid()->toString();
            $commandReady = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-{$scenario}-command-ready-{$token}";
            $commandGo = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-{$scenario}-command-go-{$token}";
            $writerReady = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-{$scenario}-writer-ready-{$token}";
            $writerGo = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-{$scenario}-writer-go-{$token}";
            $command = null;
            $writer = null;

            try {
                DB::connection()->beginTransaction();
                $this->lockPayrollMutex();
                $command = $this->startWorker(
                    $this->behalfClockWorker(),
                    [$manager->id, $worker->id, $shift->id, $site->id, $client->id, $commandReady, $commandGo],
                );
                $this->waitForFiles([$commandReady]);
                touch($commandGo);
                usleep(250_000);
                $this->assertTrue($command->isRunning(), 'HR command did not wait for the payroll mutex.');

                $writer = $this->startWorker(
                    $scenario === 'role_detach' ? $this->roleDetachWorker() : $this->rolePermissionDetachWorker(),
                    [$settingsAdmin->id, $scenario === 'role_detach' ? $manager->id : $role->id, $writerReady, $writerGo],
                );
                $this->waitForFiles([$writerReady]);
                touch($writerGo);
                $writerResult = $this->waitForResult($writer);
                $this->assertSame('revoked', $writerResult['outcome']);

                DB::connection()->commit();
                $commandResult = $this->waitForResult($command);
                $this->assertSame('rejected', $commandResult['outcome']);
                $this->assertFalse(HrTimeEntry::query()->where('shift_id', $shift->id)->exists());
            } finally {
                if (DB::connection()->transactionLevel() > 0) {
                    DB::connection()->rollBack();
                }
                foreach ([$command, $writer] as $process) {
                    if ($process?->isRunning()) {
                        $process->stop();
                    }
                }
                $this->removeFiles([$commandReady, $commandGo, $writerReady, $writerGo]);
            }
        }
    }

    public function test_authorized_hr_command_serializes_before_production_role_revocations(): void
    {
        DB::connection()->commit();

        foreach (['role_detach', 'role_permission_detach'] as $scenario) {
            [$manager, $worker, $site, $client, $shift, , $role, $settingsAdmin] = $this->roleBackedCommandFixture();
            $token = Str::uuid()->toString();
            $commandReady = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-{$scenario}-held-ready-{$token}";
            $commandGo = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-{$scenario}-held-go-{$token}";
            $writerReady = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-{$scenario}-late-ready-{$token}";
            $writerGo = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-{$scenario}-late-go-{$token}";
            $command = null;
            $writer = null;

            try {
                $command = $this->startWorker(
                    $this->authorizationBarrierBehalfWorker(),
                    [$manager->id, $worker->id, $shift->id, $site->id, $client->id, $commandReady, $commandGo],
                );
                $this->waitForFiles([$commandReady]);

                $writer = $this->startWorker(
                    $scenario === 'role_detach' ? $this->roleDetachWorker() : $this->rolePermissionDetachWorker(),
                    [$settingsAdmin->id, $scenario === 'role_detach' ? $manager->id : $role->id, $writerReady, $writerGo],
                );
                $this->waitForFiles([$writerReady]);
                touch($writerGo);
                usleep(250_000);
                $this->assertTrue($writer->isRunning(), 'Production role revocation did not wait for authorization evidence.');

                touch($commandGo);
                $commandResult = $this->waitForResult($command);
                $writerResult = $this->waitForResult($writer);
                $this->assertSame('created', $commandResult['outcome']);
                $this->assertSame('revoked', $writerResult['outcome']);
                $this->assertSame(1, HrTimeEntry::query()->where('shift_id', $shift->id)->count());
            } finally {
                foreach ([$command, $writer] as $process) {
                    if ($process?->isRunning()) {
                        $process->stop();
                    }
                }
                $this->removeFiles([$commandReady, $commandGo, $writerReady, $writerGo]);
            }
        }
    }

    public function test_self_clock_in_rechecks_permission_and_profile_site_after_waiting_on_mutex(): void
    {
        DB::connection()->commit();

        foreach (['permission_revoked', 'profile_site_moved', 'site_deactivated'] as $scenario) {
            [, $worker, $site, , $shift] = $this->commandFixture();
            $shiftBefore = $shift->fresh()->getRawOriginal();
            $token = Str::uuid()->toString();
            $readyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-self-{$scenario}-ready-{$token}";
            $goPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-self-{$scenario}-go-{$token}";
            $process = null;

            try {
                DB::connection()->beginTransaction();
                $this->lockPayrollMutex();
                $process = $this->startWorker(
                    $this->selfClockWorker(),
                    [$shift->id, $worker->id, $readyPath, $goPath],
                );
                $this->waitForFiles([$readyPath]);
                touch($goPath);
                usleep(250_000);
                $this->assertTrue($process->isRunning(), 'Self clock-in did not wait for the payroll mutex.');

                if ($scenario === 'permission_revoked') {
                    $permissions = Permission::query()
                        ->whereIn('key', ['timesheets.create', 'shifts.viewAssigned', 'shifts.update', 'shifts.manageAny'])
                        ->get(['id']);
                    foreach ($permissions as $permission) {
                        DB::table('permission_user')->updateOrInsert(
                            ['user_id' => $worker->id, 'permission_id' => $permission->id],
                            ['allowed' => false],
                        );
                    }
                } elseif ($scenario === 'profile_site_moved') {
                    $movedSite = Site::factory()->create([
                        'is_active' => true,
                        'archived' => false,
                        'archived_at' => null,
                    ]);
                    HrEmployeeProfile::query()
                        ->where('user_id', $worker->id)
                        ->update(['primary_site_id' => $movedSite->id]);
                } else {
                    Site::query()->whereKey($site->id)->update(['is_active' => false]);
                }
                DB::connection()->commit();

                $result = $this->waitForResult($process);
                $this->assertSame('rejected', $result['outcome']);
                $this->assertSame($scenario === 'permission_revoked' ? 403 : 404, $result['status']);
                $this->assertSame($shiftBefore, $shift->fresh()->getRawOriginal());
                $this->assertFalse(HrAttendanceSession::query()->where('shift_id', $shift->id)->exists());
                $this->assertFalse(HrTimeEntry::query()->where('shift_id', $shift->id)->exists());
            } finally {
                if (DB::connection()->transactionLevel() > 0) {
                    DB::connection()->rollBack();
                }
                if ($process?->isRunning()) {
                    $process->stop();
                }
                $this->removeFiles([$readyPath, $goPath]);
            }
        }
    }

    public function test_admin_end_permission_revocation_while_waiting_on_mutex_rolls_back_every_record(): void
    {
        [$manager, $worker, $site, , $shift, $permission] = $this->commandFixture();
        $session = HrAttendanceSession::query()->create([
            'user_id' => $worker->id,
            'shift_id' => $shift->id,
            'site_id' => $site->id,
            'clock_in_at' => now()->subHours(2),
            'status' => 'open',
            'source' => 'concurrency-test',
            'created_by' => $worker->id,
        ]);
        $sessionBefore = $session->fresh()->getRawOriginal();
        $shiftBefore = $shift->fresh()->getRawOriginal();
        $entryCount = HrTimeEntry::query()->count();
        $timesheetCount = Timesheet::query()->count();
        $token = Str::uuid()->toString();
        $readyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-admin-end-ready-{$token}";
        $goPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-admin-end-go-{$token}";
        $process = null;
        DB::connection()->commit();

        try {
            DB::connection()->beginTransaction();
            $this->lockPayrollMutex();
            $process = $this->startWorker(
                $this->adminEndWorker(),
                [$manager->id, $session->id, $readyPath, $goPath],
            );
            $this->waitForFiles([$readyPath]);
            touch($goPath);
            usleep(250_000);
            $this->assertTrue($process->isRunning(), 'Admin end did not wait for the payroll mutex.');

            DB::table('permission_user')
                ->where('user_id', $manager->id)
                ->where('permission_id', $permission->id)
                ->update(['allowed' => false]);
            DB::connection()->commit();

            $result = $this->waitForResult($process);
            $this->assertSame('rejected', $result['outcome']);
            $this->assertSame(404, $result['status']);
            $this->assertSame($sessionBefore, $session->fresh()->getRawOriginal());
            $this->assertSame($shiftBefore, $shift->fresh()->getRawOriginal());
            $this->assertSame($entryCount, HrTimeEntry::query()->count());
            $this->assertSame($timesheetCount, Timesheet::query()->count());
        } finally {
            if (DB::connection()->transactionLevel() > 0) {
                DB::connection()->rollBack();
            }
            if ($process?->isRunning()) {
                $process->stop();
            }
            $this->removeFiles([$readyPath, $goPath]);
        }
    }

    public function test_self_attendance_permission_revocation_blocks_clock_out_and_break_mutations_after_mutex_wait(): void
    {
        DB::connection()->commit();

        foreach (['clockOut', 'startBreak', 'endBreak'] as $command) {
            [, $worker, $site, , $shift] = $this->commandFixture();
            $breakStartedAt = $command === 'endBreak' ? now()->subMinutes(15) : null;
            $session = HrAttendanceSession::query()->create([
                'user_id' => $worker->id,
                'shift_id' => $shift->id,
                'site_id' => $site->id,
                'clock_in_at' => now()->subHours(2),
                'break_started_at' => $breakStartedAt,
                'break_count' => $breakStartedAt ? 1 : 0,
                'status' => 'open',
                'source' => 'concurrency-test',
                'created_by' => $worker->id,
            ]);
            $breakEvent = $breakStartedAt
                ? HrAttendanceBreakEvent::query()->create([
                    'session_id' => $session->id,
                    'started_at' => $breakStartedAt,
                    'created_by' => $worker->id,
                ])
                : null;
            $sessionBefore = $session->fresh()->getRawOriginal();
            $shiftBefore = $shift->fresh()->getRawOriginal();
            $breakBefore = $breakEvent?->fresh()?->getRawOriginal();
            $entryCount = HrTimeEntry::query()->count();
            $timesheetCount = Timesheet::query()->count();
            $token = Str::uuid()->toString();
            $readyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-self-{$command}-ready-{$token}";
            $goPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-self-{$command}-go-{$token}";
            $process = null;

            try {
                DB::connection()->beginTransaction();
                $this->lockPayrollMutex();
                $process = $this->startWorker(
                    $this->selfAttendanceWorker(),
                    [$command, $worker->id, $session->id, $readyPath, $goPath],
                );
                $this->waitForFiles([$readyPath]);
                touch($goPath);
                usleep(250_000);
                $this->assertTrue($process->isRunning(), "{$command} did not wait for the payroll mutex.");

                $permissions = Permission::query()
                    ->whereIn('key', ['timesheets.create', 'shifts.viewAssigned', 'shifts.update', 'shifts.manageAny'])
                    ->get(['id']);
                foreach ($permissions as $permission) {
                    DB::table('permission_user')->updateOrInsert(
                        ['user_id' => $worker->id, 'permission_id' => $permission->id],
                        ['allowed' => false],
                    );
                }
                DB::connection()->commit();

                $result = $this->waitForResult($process);
                $this->assertSame('rejected', $result['outcome']);
                $this->assertSame(403, $result['status']);
                $this->assertSame($sessionBefore, $session->fresh()->getRawOriginal());
                $this->assertSame($shiftBefore, $shift->fresh()->getRawOriginal());
                $this->assertSame($breakBefore, $breakEvent?->fresh()?->getRawOriginal());
                $this->assertSame($entryCount, HrTimeEntry::query()->count());
                $this->assertSame($timesheetCount, Timesheet::query()->count());
            } finally {
                if (DB::connection()->transactionLevel() > 0) {
                    DB::connection()->rollBack();
                }
                if ($process?->isRunning()) {
                    $process->stop();
                }
                $this->removeFiles([$readyPath, $goPath]);
            }
        }
    }

    public function test_payroll_publication_wins_against_every_pay_affecting_timesheet_transition(): void
    {
        [$manager, $worker, $site, $client] = $this->commandFixture();
        DB::connection()->commit();

        $commands = [
            'update' => ['status' => 'draft', 'work_date' => '2026-08-20', 'payroll_date' => '2026-08-21'],
            'resubmit' => ['status' => 'returned', 'work_date' => '2026-08-22', 'payroll_date' => '2026-08-23'],
            'submit' => ['status' => 'draft', 'work_date' => '2026-08-24', 'payroll_date' => '2026-08-24'],
            'return' => ['status' => 'submitted', 'work_date' => '2026-08-25', 'payroll_date' => '2026-08-25'],
            'reject' => ['status' => 'submitted', 'work_date' => '2026-08-26', 'payroll_date' => '2026-08-26'],
            'approve' => ['status' => 'submitted', 'work_date' => '2026-08-27', 'payroll_date' => '2026-08-18'],
        ];

        foreach ($commands as $command => $fixture) {
            $proposedDate = $fixture['payroll_date'];
            $timesheet = Timesheet::query()->create([
                'user_id' => $worker->id,
                'client_id' => $client->id,
                'site_id' => $site->id,
                'work_date' => $fixture['work_date'],
                'starts_at' => '2026-08-19 21:00:00',
                'ends_at' => '2026-08-20 05:00:00',
                'break_minutes' => 30,
                'status' => $fixture['status'],
                'created_by' => $manager->id,
            ]);
            if ($command === 'approve') {
                $linkedEntry = HrTimeEntry::query()->create([
                    'user_id' => $worker->id,
                    'client_id' => $client->id,
                    'site_id' => $site->id,
                    'entry_date' => $proposedDate,
                    'clock_in' => $timesheet->starts_at,
                    'clock_out' => $timesheet->ends_at,
                    'break_minutes' => $timesheet->break_minutes,
                    'total_hours' => 7.5,
                    'entry_type' => 'timesheet',
                    'status' => 'submitted',
                    'source_type' => 'timesheet',
                    'source_id' => $timesheet->id,
                    'created_by' => $manager->id,
                ]);
                $timesheet->forceFill(['hr_time_entry_id' => $linkedEntry->id])->saveQuietly();
            }
            $before = $timesheet->fresh()->getRawOriginal();
            $token = Str::uuid()->toString();
            $readyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-{$command}-ready-{$token}";
            $goPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-{$command}-go-{$token}";
            $process = null;

            try {
                DB::connection()->beginTransaction();
                $this->lockPayrollMutex();
                $process = $this->startWorker(
                    $this->timesheetWorker(),
                    [$command, $timesheet->id, $manager->id, $proposedDate, $readyPath, $goPath],
                );
                $this->waitForFiles([$readyPath]);
                touch($goPath);
                usleep(250_000);
                $this->assertTrue($process->isRunning(), "{$command} did not wait for the payroll mutex.");

                HrPayrollRun::factory()->create([
                    'period_start' => $proposedDate,
                    'period_end' => $proposedDate,
                    'status' => 'locked',
                    'locked_at' => now(),
                    'locked_by' => $manager->id,
                    'created_by' => $manager->id,
                ]);
                DB::connection()->commit();

                $result = $this->waitForResult($process);
                $this->assertSame('rejected', $result['outcome']);
                $this->assertSame($before, $timesheet->fresh()->getRawOriginal());
            } finally {
                if (DB::connection()->transactionLevel() > 0) {
                    DB::connection()->rollBack();
                }
                if ($process?->isRunning()) {
                    $process->stop();
                }
                $this->removeFiles([$readyPath, $goPath]);
            }
        }
    }

    public function test_timesheet_review_rechecks_permission_and_profile_site_after_waiting_on_payroll_mutex(): void
    {
        DB::connection()->commit();

        foreach (['permission_revoked', 'profile_site_moved'] as $scenario) {
            [$manager, $worker, $site, $client, , $permission] = $this->commandFixture();
            $timesheet = Timesheet::query()->create([
                'user_id' => $worker->id,
                'client_id' => $client->id,
                'site_id' => $site->id,
                'work_date' => '2026-08-28',
                'starts_at' => '2026-08-28 09:00:00',
                'ends_at' => '2026-08-28 17:00:00',
                'break_minutes' => 30,
                'status' => 'submitted',
                'submitted_by' => $worker->id,
                'submitted_at' => now(),
                'created_by' => $worker->id,
            ]);
            $before = $timesheet->fresh()->getRawOriginal();
            $token = Str::uuid()->toString();
            $readyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-timesheet-{$scenario}-ready-{$token}";
            $goPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-timesheet-{$scenario}-go-{$token}";
            $process = null;

            try {
                DB::connection()->beginTransaction();
                $this->lockPayrollMutex();
                $process = $this->startWorker(
                    $this->timesheetWorker(),
                    ['approve', $timesheet->id, $manager->id, $timesheet->work_date->toDateString(), $readyPath, $goPath],
                );
                $this->waitForFiles([$readyPath]);
                touch($goPath);
                usleep(250_000);
                $this->assertTrue($process->isRunning(), 'Timesheet approval did not wait for the payroll mutex.');

                if ($scenario === 'permission_revoked') {
                    DB::table('permission_user')
                        ->where('user_id', $manager->id)
                        ->where('permission_id', $permission->id)
                        ->update(['allowed' => false]);
                } else {
                    $movedSite = Site::factory()->create([
                        'is_active' => true,
                        'archived' => false,
                        'archived_at' => null,
                    ]);
                    HrEmployeeProfile::query()
                        ->where('user_id', $manager->id)
                        ->update(['primary_site_id' => $movedSite->id]);
                }
                DB::connection()->commit();

                $result = $this->waitForResult($process);
                $this->assertSame('rejected', $result['outcome']);
                $this->assertSame(403, $result['status']);
                $this->assertSame($before, $timesheet->fresh()->getRawOriginal());
                $this->assertFalse(HrTimeEntry::query()
                    ->where('source_type', 'timesheet')
                    ->where('source_id', $timesheet->id)
                    ->exists());
            } finally {
                if (DB::connection()->transactionLevel() > 0) {
                    DB::connection()->rollBack();
                }
                if ($process?->isRunning()) {
                    $process->stop();
                }
                $this->removeFiles([$readyPath, $goPath]);
            }
        }
    }

    public function test_timesheet_draft_writes_recheck_exact_permission_ownership_and_site_after_waiting_on_payroll_mutex(): void
    {
        DB::connection()->commit();

        $scenarios = [
            'update_permission_revoked' => ['command' => 'update', 'status' => 'draft'],
            'submit_manage_revoked' => ['command' => 'submit', 'status' => 'draft'],
            'resubmit_profile_site_moved' => ['command' => 'resubmit', 'status' => 'returned'],
        ];

        foreach ($scenarios as $scenario => $fixture) {
            [$manager, $worker, $site, $client, , $managePermission] = $this->commandFixture();
            $timesheet = Timesheet::query()->create([
                'user_id' => $worker->id,
                'client_id' => $client->id,
                'site_id' => $site->id,
                'work_date' => '2026-08-28',
                'starts_at' => '2026-08-28 09:00:00',
                'ends_at' => '2026-08-28 17:00:00',
                'break_minutes' => 30,
                'notes' => 'Original draft evidence',
                'status' => $fixture['status'],
                'created_by' => $worker->id,
            ]);
            $movedSite = $scenario === 'resubmit_profile_site_moved'
                ? Site::factory()->create([
                    'is_active' => true,
                    'archived' => false,
                    'archived_at' => null,
                ])
                : null;
            $before = $timesheet->fresh()->getRawOriginal();
            $auditCount = AuditLog::query()->count();
            $entryCount = HrTimeEntry::query()->count();
            $allocationCount = DB::table('timesheet_client_allocations')->count();
            $token = Str::uuid()->toString();
            $readyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-timesheet-writer-{$scenario}-ready-{$token}";
            $goPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."hr-timesheet-writer-{$scenario}-go-{$token}";
            $process = null;

            try {
                DB::connection()->beginTransaction();
                $this->lockPayrollMutex();
                $process = $this->startWorker(
                    $this->timesheetWorker(),
                    [$fixture['command'], $timesheet->id, $manager->id, '2026-08-29', $readyPath, $goPath],
                );
                $this->waitForFiles([$readyPath]);
                touch($goPath);
                usleep(250_000);
                $this->assertTrue($process->isRunning(), "{$scenario} did not wait for the payroll mutex.");

                if ($scenario === 'update_permission_revoked') {
                    $permissionId = Permission::query()->where('key', 'timesheets.update')->value('id');
                    DB::table('permission_user')->updateOrInsert(
                        ['user_id' => $manager->id, 'permission_id' => $permissionId],
                        ['allowed' => false],
                    );
                } elseif ($scenario === 'submit_manage_revoked') {
                    DB::table('permission_user')->updateOrInsert(
                        ['user_id' => $manager->id, 'permission_id' => $managePermission->id],
                        ['allowed' => false],
                    );
                } else {
                    if (! $movedSite instanceof Site) {
                        throw new RuntimeException('Moved Site fixture was not prepared.');
                    }
                    HrEmployeeProfile::query()
                        ->where('user_id', $manager->id)
                        ->update(['primary_site_id' => $movedSite->id]);
                }
                DB::connection()->commit();

                $result = $this->waitForResult($process);
                $this->assertSame('rejected', $result['outcome']);
                $this->assertSame(403, $result['status']);
                $this->assertSame($before, $timesheet->fresh()->getRawOriginal());
                $this->assertSame($auditCount, AuditLog::query()->count());
                $this->assertSame($entryCount, HrTimeEntry::query()->count());
                $this->assertSame($allocationCount, DB::table('timesheet_client_allocations')->count());
            } finally {
                if (DB::connection()->transactionLevel() > 0) {
                    DB::connection()->rollBack();
                }
                if ($process?->isRunning()) {
                    $process->stop();
                }
                $this->removeFiles([$readyPath, $goPath]);
            }
        }
    }

    /** @return array{User, User, Site, Client, Shift, Permission} */
    private function commandFixture(): array
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $manager = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        foreach ([[$manager, null], [$worker, $manager]] as [$user, $reportsTo]) {
            HrEmployeeProfile::query()->create([
                'user_id' => $user->id,
                'employee_number' => 'EMP-CON-'.$user->id,
                'work_email' => $user->email,
                'position_title' => 'Support Worker',
                'position_role' => 'support_worker',
                'employment_type' => 'full_time',
                'start_date' => now()->subMonth()->toDateString(),
                'is_active' => true,
                'manager_user_id' => $reportsTo?->id,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
            ]);
        }
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'user_id' => $worker->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(7),
            'status' => 'scheduled',
            'created_by' => $manager->id,
        ]);
        $permission = Permission::query()->where('key', 'timesheets.manageAny')->firstOrFail();
        $writerPermissions = Permission::query()
            ->whereIn('key', ['timesheets.update', 'timesheets.submit'])
            ->get();
        $manager->permissionOverrides()->syncWithoutDetaching(
            $writerPermissions
                ->push($permission)
                ->mapWithKeys(fn (Permission $writerPermission): array => [
                    $writerPermission->id => ['allowed' => true],
                ])
                ->all(),
        );
        $selfAttendancePermission = Permission::query()->where('key', 'timesheets.create')->firstOrFail();
        $worker->permissionOverrides()->syncWithoutDetaching([
            $selfAttendancePermission->id => ['allowed' => true],
        ]);

        return [$manager, $worker, $site, $client, $shift, $permission];
    }

    /** @return array{User, User, Site, Client, Shift, Permission, Role, User} */
    private function roleBackedCommandFixture(): array
    {
        [$manager, $worker, $site, $client, $shift, $permission] = $this->commandFixture();
        $manager->permissionOverrides()->detach($permission->id);
        $role = Role::query()->create([
            'name' => 'hr_concurrency_'.Str::lower(Str::random(10)),
            'label' => 'HR concurrency role',
            'level' => 10,
            'type' => 'custom',
        ]);
        $role->permissions()->sync([$permission->id]);
        $manager->roles()->sync([$role->id]);

        $settingsAdmin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $settingsPermission = Permission::query()->where('key', 'settings.access.manage')->firstOrFail();
        $settingsAdmin->permissionOverrides()->syncWithoutDetaching([
            $settingsPermission->id => ['allowed' => true],
        ]);

        return [$manager, $worker, $site, $client, $shift, $permission, $role, $settingsAdmin];
    }

    private function lockPayrollMutex(): void
    {
        $mutex = DB::table('hr_payroll_run_mutexes')
            ->where('key', 'application')
            ->lockForUpdate()
            ->first();
        $this->assertNotNull($mutex);
    }

    private function startWorker(string $workerCode, array $arguments): Process
    {
        $process = new Process(
            [PHP_BINARY, '-r', $workerCode, base_path(), ...array_map('strval', $arguments)],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => DB::connection()->getDatabaseName(),
                'CACHE_STORE' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'SESSION_DRIVER' => 'array',
            ],
        );
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    private function waitForResult(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), trim($process->getErrorOutput()) ?: 'HR concurrency worker failed.');

        return json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<int, string> $paths */
    private function waitForFiles(array $paths): void
    {
        $deadline = microtime(true) + 15;
        while (collect($paths)->contains(fn (string $path): bool => ! is_file($path))) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('HR concurrency workers did not reach the barrier.');
            }
            usleep(10_000);
        }
    }

    /** @param array<int, string> $paths */
    private function removeFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function selfClockWorker(): string
    {
        return <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
touch($argv[4]);
while (!is_file($argv[5])) { usleep(10000); }
try {
    $session = $app->make(App\Domain\Hr\Services\AttendanceService::class)->clockIn(
        App\Models\User::query()->findOrFail((int) $argv[3]),
        ['shift_id' => (int) $argv[2], 'clock_in_at' => now(), 'source' => 'concurrency-test'],
    );
    echo json_encode(['outcome' => 'created', 'session_id' => $session->id]);
} catch (Throwable $e) {
    echo json_encode(['outcome' => 'rejected', 'class' => $e::class, 'message' => $e->getMessage(), 'status' => method_exists($e, 'getStatusCode') ? $e->getStatusCode() : null]);
}
PHP;
    }

    private function behalfClockWorker(): string
    {
        return <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
touch($argv[7]);
while (!is_file($argv[8])) { usleep(10000); }
try {
    $entry = $app->make(App\Domain\Hr\Services\TimeTrackingService::class)->clockOnBehalf(
        App\Models\User::query()->findOrFail((int) $argv[2]),
        (int) $argv[3],
        ['shift_id' => (int) $argv[4], 'site_id' => (int) $argv[5], 'client_id' => (int) $argv[6], 'clock_in' => now(), 'reason' => 'Concurrent manager clock'],
    );
    echo json_encode(['outcome' => 'created', 'entry_id' => $entry->id]);
} catch (Throwable $e) {
    echo json_encode(['outcome' => 'rejected', 'class' => $e::class, 'message' => $e->getMessage(), 'status' => method_exists($e, 'getStatusCode') ? $e->getStatusCode() : null]);
}
PHP;
    }

    private function adminEndWorker(): string
    {
        return <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$admin = App\Models\User::query()->findOrFail((int) $argv[2]);
$session = App\Domain\Hr\Models\HrAttendanceSession::query()->findOrFail((int) $argv[3]);
touch($argv[4]);
while (!is_file($argv[5])) { usleep(10000); }
try {
    $app->make(App\Domain\Hr\Services\AttendanceService::class)->adminEndSession(
        $admin,
        $session,
        'Concurrent permission revocation',
    );
    echo json_encode(['outcome' => 'updated']);
} catch (Throwable $e) {
    echo json_encode(['outcome' => 'rejected', 'class' => $e::class, 'message' => $e->getMessage(), 'status' => method_exists($e, 'getStatusCode') ? $e->getStatusCode() : null]);
}
PHP;
    }

    private function settingsRevocationWorker(): string
    {
        return <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$admin = App\Models\User::query()->findOrFail((int) $argv[2]);
$target = App\Models\User::query()->findOrFail((int) $argv[3]);
touch($argv[5]);
while (!is_file($argv[6])) { usleep(10000); }
try {
    $request = Illuminate\Http\Request::create('/settings/access/'.$target->id, 'PUT', [
        'role_ids' => $target->roles()->pluck('roles.id')->all(),
        'overrides' => [(int) $argv[4] => 'deny'],
    ]);
    $request->setUserResolver(fn () => $admin);
    $app->make(App\Http\Controllers\Settings\AccessController::class)->update($request, $target);
    echo json_encode(['outcome' => 'revoked']);
} catch (Throwable $e) {
    echo json_encode(['outcome' => 'failed', 'class' => $e::class, 'message' => $e->getMessage()]);
}
PHP;
    }

    private function authorizationBarrierBehalfWorker(): string
    {
        return <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app->singleton(App\Services\AuthorizationEvidenceLockService::class, function () use ($argv) {
    return new class($argv[7], $argv[8]) extends App\Services\AuthorizationEvidenceLockService
    {
        public function __construct(
            private readonly string $readyPath,
            private readonly string $goPath,
        ) {}

        public function lockForUsers(
            iterable $users,
            array $permissionKeys,
            array $additionalRoleIds = [],
        ): Illuminate\Support\Collection
        {
            $lockedUsers = parent::lockForUsers($users, $permissionKeys, $additionalRoleIds);

            // This barrier is reached from inside the real clockOnBehalf()
            // transaction, after its production authorization evidence locks
            // have been acquired. The competing production writer therefore
            // proves it serializes with the command itself.
            touch($this->readyPath);
            while (! is_file($this->goPath)) {
                usleep(10000);
            }

            return $lockedUsers;
        }
    };
});
try {
    $entryId = $app->make(App\Domain\Hr\Services\TimeTrackingService::class)->clockOnBehalf(
        App\Models\User::query()->findOrFail((int) $argv[2]),
        (int) $argv[3],
        ['shift_id' => (int) $argv[4], 'site_id' => (int) $argv[5], 'client_id' => (int) $argv[6], 'clock_in' => now(), 'reason' => 'Serialized authorization evidence'],
    )->id;
    echo json_encode(['outcome' => 'created', 'entry_id' => $entryId]);
} catch (Throwable $e) {
    echo json_encode(['outcome' => 'rejected', 'class' => $e::class, 'message' => $e->getMessage(), 'status' => method_exists($e, 'getStatusCode') ? $e->getStatusCode() : null]);
}
PHP;
    }

    private function roleDetachWorker(): string
    {
        return <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$admin = App\Models\User::query()->findOrFail((int) $argv[2]);
$target = App\Models\User::query()->findOrFail((int) $argv[3]);
touch($argv[4]);
while (!is_file($argv[5])) { usleep(10000); }
try {
    $request = Illuminate\Http\Request::create('/settings/access/'.$target->id, 'PUT', [
        'role_ids' => [],
        'overrides' => [],
    ]);
    $request->setUserResolver(fn () => $admin);
    $app->make(App\Http\Controllers\Settings\AccessController::class)->update($request, $target);
    echo json_encode(['outcome' => 'revoked']);
} catch (Throwable $e) {
    echo json_encode(['outcome' => 'failed', 'class' => $e::class, 'message' => $e->getMessage()]);
}
PHP;
    }

    private function rolePermissionDetachWorker(): string
    {
        return <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$admin = App\Models\User::query()->findOrFail((int) $argv[2]);
$role = App\Models\Role::query()->findOrFail((int) $argv[3]);
touch($argv[4]);
while (!is_file($argv[5])) { usleep(10000); }
try {
    $request = Illuminate\Http\Request::create('/settings/roles/'.$role->id, 'PUT', [
        'name' => $role->name,
        'label' => $role->label,
        'description' => $role->description,
        'permission_keys' => [],
        'landing_route' => $role->landing_route,
    ]);
    $request->setUserResolver(fn () => $admin);
    $app->make(App\Http\Controllers\Settings\RolesController::class)->update($request, $role);
    echo json_encode(['outcome' => 'revoked']);
} catch (Throwable $e) {
    echo json_encode(['outcome' => 'failed', 'class' => $e::class, 'message' => $e->getMessage()]);
}
PHP;
    }

    private function selfAttendanceWorker(): string
    {
        return <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$user = App\Models\User::query()->findOrFail((int) $argv[3]);
$session = App\Domain\Hr\Models\HrAttendanceSession::query()->findOrFail((int) $argv[4]);
touch($argv[5]);
while (!is_file($argv[6])) { usleep(10000); }
try {
    $service = $app->make(App\Domain\Hr\Services\AttendanceService::class);
    match ($argv[2]) {
        'startBreak' => $service->startBreak($user, $session),
        'endBreak' => $service->endBreak($user, $session),
        default => $service->clockOut($user, $session),
    };
    echo json_encode(['outcome' => 'updated']);
} catch (Throwable $e) {
    echo json_encode(['outcome' => 'rejected', 'class' => $e::class, 'message' => $e->getMessage(), 'status' => method_exists($e, 'getStatusCode') ? $e->getStatusCode() : null]);
}
PHP;
    }

    private function timesheetWorker(): string
    {
        return <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
touch($argv[6]);
while (!is_file($argv[7])) { usleep(10000); }
try {
    $service = $app->make(App\Domain\Shifts\Timesheets\TimesheetApprovalService::class);
    $timesheet = App\Models\Timesheet::query()->findOrFail((int) $argv[3]);
    $updates = ['work_date' => $argv[5], 'notes' => 'Concurrent payroll loser'];
    $actor = App\Models\User::query()->findOrFail((int) $argv[4]);
    match ($argv[2]) {
        'resubmit' => $service->resubmit($timesheet, $actor, $updates),
        'submit' => $service->submit($timesheet, $actor),
        'return' => $service->returnForChanges($timesheet, $actor, 'Concurrent payroll loser'),
        'reject' => $service->reject($timesheet, $actor, 'Concurrent payroll loser'),
        'approve' => $service->approve($timesheet, $actor, 'Concurrent payroll loser'),
        default => $service->updateEditable($timesheet, $actor, $updates),
    };
    echo json_encode(['outcome' => 'updated']);
} catch (Throwable $e) {
    echo json_encode(['outcome' => 'rejected', 'class' => $e::class, 'message' => $e->getMessage(), 'status' => method_exists($e, 'getStatusCode') ? $e->getStatusCode() : null]);
}
PHP;
    }
}
