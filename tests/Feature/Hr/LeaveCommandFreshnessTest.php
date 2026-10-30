<?php

namespace Tests\Feature\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveBalanceLedger;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Notifications\LeaveRequestNotification;
use App\Domain\Hr\Services\HrLeaveAccessService;
use App\Domain\Hr\Services\HrNotificationService;
use App\Domain\Hr\Services\LeaveService;
use App\Domain\Hr\Services\WorkforceAvailabilityCoverageService;
use App\Http\Controllers\Hr\LeaveController;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedFixtureCleanup;
use Tests\TestCase;

class LeaveCommandFreshnessTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        $this->travelTo(now()->setDate(2026, 10, 5)->startOfDay());
        Queue::fake();
        Notification::fake();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    }

    #[DataProvider('staleActors')]
    public function test_cached_actor_evidence_cannot_authorise_leave_changes_after_revocation(string $command, string $change): void
    {
        $actor = $this->actor(['hr.leave.approve', 'hr.leave.manage']);
        $worker = $this->actor([]);
        $leave = $this->pending($worker);
        $this->duty($worker);
        if ($command === 'cancel') {
            app(LeaveService::class)->approveRequest($leave, $actor);
            $leave = $leave->fresh();
            Notification::fake();
        }
        $this->warm($actor, $leave);
        $this->revoke($actor, $change);
        $before = $this->state();
        $this->assertTrue($actor->isApproved());
        $this->expectDenied(fn () => $this->command($command, $leave, $actor), in_array($change, ['approval', 'permission'], true) ? 403 : 404);
        $this->assertSame($before, $this->state());
        Notification::assertNothingSent();
    }

    public static function staleActors(): array
    {
        $cases = [];
        foreach (['approve', 'decline', 'cancel'] as $command) {
            foreach (['approval', 'permission', 'profile inactive', 'Site removed', 'Site archived'] as $change) {
                $cases[] = [$command, $change];
            }
        }

        return $cases;
    }

    #[DataProvider('reviewPermissions')]
    public function test_existing_exact_review_grants_keep_custom_role_review_behaviour(string $command, string $permission): void
    {
        $actor = $this->actor([$permission]);
        $worker = $this->actor([]);
        $leave = $this->pending($worker);
        $this->duty($worker);
        $result = $this->command($command, $leave, $actor);
        $this->assertSame($command === 'approve' ? 'approved' : 'declined', $result->status);
        $this->assertSame($actor->id, $result->reviewed_by);
        $this->assertSame($command === 'approve' ? 1 : 0, DB::table('staff_time_offs')->count());
        $this->assertSame($command === 'approve' ? 1 : 0, DB::table('workforce_availability_coverage_actions')->count());
        $this->assertSame($worker->id, Shift::query()->sole()->user_id);
    }

    public static function reviewPermissions(): array
    {
        return [['approve', 'hr.leave.approve'], ['approve', 'hr.leave.manage'],
            ['decline', 'hr.leave.approve'], ['decline', 'hr.leave.manage']];
    }

    public function test_authorised_self_review_keeps_the_existing_leave_policy(): void
    {
        $actor = $this->actor(['hr.leave.approve']);
        $result = app(LeaveService::class)->approveRequest($this->pending($actor), $actor);
        $this->assertSame('approved', $result->status);
        $this->assertSame($actor->id, $result->reviewed_by);
    }

    public function test_current_owner_cancellation_does_not_gain_a_manager_permission_or_site_prerequisite(): void
    {
        $worker = $this->actor([]);
        $leave = $this->pending($worker);
        DB::table('hr_employee_profiles')->where('user_id', $worker->id)->update(['primary_site_id' => null, 'secondary_site_ids' => '[]']);
        $result = app(LeaveService::class)->cancelRequest($leave, $worker);
        $this->assertSame('cancelled', $result->status);
        $this->assertSame($worker->id, $result->reviewed_by);
        $this->assertSame(0.0, (float) HrLeaveBalance::query()->sole()->pending_hours);
    }

    #[DataProvider('cancellableStates')]
    public function test_exact_manage_grant_cancels_visible_leave_without_changing_the_employee_assignment(string $status): void
    {
        $manager = $this->actor(['hr.leave.manage']);
        $worker = $this->actor([]);
        $leave = $this->pending($worker);
        $this->duty($worker);
        if ($status === 'approved') {
            app(LeaveService::class)->approveRequest($leave, $this->actor(['hr.leave.approve']));
            $leave = $leave->fresh();
        }

        $result = app(LeaveService::class)->cancelRequest($leave, $manager);
        $this->assertSame('cancelled', $result->status);
        $this->assertSame($manager->id, $result->reviewed_by);
        $balance = HrLeaveBalance::query()->sole();
        $this->assertSame(0.0, (float) $balance->pending_hours);
        $this->assertSame(0.0, (float) $balance->used_hours);
        $this->assertSame(0, DB::table('staff_time_offs')->count());
        $this->assertSame($worker->id, Shift::query()->sole()->user_id);
        $this->assertSame(0, DB::table('workforce_availability_coverage_actions')->where('status', 'open')->count());
    }

    public static function cancellableStates(): array
    {
        return [['pending'], ['approved']];
    }

    public function test_review_grant_alone_does_not_authorise_manager_cancellation(): void
    {
        $actor = $this->actor(['hr.leave.approve']);
        $leave = $this->pending($this->actor([]));
        $before = $this->state();
        $this->expectDenied(fn () => app(LeaveService::class)->cancelRequest($leave, $actor), 403);
        $this->assertSame($before, $this->state());
        Notification::assertNothingSent();
    }

    #[DataProvider('lateSubjectChanges')]
    public function test_subject_loss_after_native_cover_work_rolls_back_every_leave_projection(string $change): void
    {
        $actor = $this->actor(['hr.leave.approve']);
        $worker = $this->actor([]);
        $leave = $this->pending($worker);
        $this->duty($worker);
        $before = $this->state();
        $real = app(WorkforceAvailabilityCoverageService::class);
        $this->mock(WorkforceAvailabilityCoverageService::class, function ($mock) use ($real, $worker, $change): void {
            $mock->shouldReceive('syncApprovedLeave')->once()->andReturnUsing(function ($request, $actor) use ($real, $worker, $change) {
                $actions = $real->syncApprovedLeave($request, $actor);
                $this->assertCount(1, $actions);
                if ($change === 'approval') {
                    DB::table('users')->where('id', $worker->id)->update(['approved_at' => null]);
                } elseif ($change === 'portal role') {
                    DB::table('users')->where('id', $worker->id)->update(['role' => 'client']);
                } elseif ($change === 'profile ended') {
                    DB::table('hr_employee_profiles')->where('user_id', $worker->id)->update(['end_date' => '2026-10-04']);
                } else {
                    DB::table('hr_employee_profiles')->where('user_id', $worker->id)->update(['is_active' => false]);
                }

                return $actions;
            });
        });
        $this->expectDenied(fn () => app(LeaveService::class)->approveRequest($leave, $actor), 404);
        $this->assertSame($before, $this->state());
        $this->assertTrue($worker->fresh()->isApproved());
        Notification::assertNothingSent();
    }

    public static function lateSubjectChanges(): array
    {
        return [['approval'], ['portal role'], ['profile ended'], ['profile inactive']];
    }

    public function test_committed_subject_site_revocation_cannot_be_resurrected_from_an_earlier_snapshot(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $actor = $this->actor(['hr.leave.approve']);
        $worker = $this->actor([]);
        $leave = $this->pending($worker);
        $this->duty($worker);
        $before = $this->state();
        $writer = 'leave_evidence_writer';
        config(['database.connections.'.$writer => DB::connection()->getConfig()]);
        DB::purge($writer);
        DB::connection()->commit();
        try {
            DB::beginTransaction();
            $this->assertSame($this->site->id, (int) DB::table('hr_employee_profiles')
                ->where('user_id', $worker->id)->value('primary_site_id'));
            $real = app(WorkforceAvailabilityCoverageService::class);
            $this->mock(WorkforceAvailabilityCoverageService::class, function ($mock) use ($real, $worker, $writer): void {
                $mock->shouldReceive('syncApprovedLeave')->once()->andReturnUsing(function ($request, $reviewer) use ($real, $worker, $writer) {
                    $actions = $real->syncApprovedLeave($request, $reviewer);
                    $this->assertCount(1, $actions);
                    // Commit only the policy evidence from a separate session
                    // after the command's earlier consistent reads and cover work.
                    $this->assertSame(DB::connection()->getDatabaseName(), DB::connection($writer)->getDatabaseName());
                    $this->assertSame(0, DB::connection($writer)->transactionLevel());
                    DB::connection($writer)->table('hr_employee_profiles')->where('user_id', $worker->id)
                        ->update(['primary_site_id' => null, 'secondary_site_ids' => '[]']);
                    $this->assertNull(DB::connection($writer)->table('hr_employee_profiles')
                        ->where('user_id', $worker->id)->value('primary_site_id'));
                    // A normal read still sees the original RR snapshot. The
                    // final Site subquery must use current locked evidence.
                    $this->assertSame($this->site->id, (int) DB::table('hr_employee_profiles')
                        ->where('user_id', $worker->id)->value('primary_site_id'));

                    return $actions;
                });
            });
            $this->expectDenied(fn () => app(LeaveService::class)->approveRequest($leave, $actor), 404);
            $this->assertSame($before, $this->state());
            Notification::assertNothingSent();
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::purge($writer);
            DB::beginTransaction();
        }
    }

    #[DataProvider('lateCoverCommands')]
    public function test_final_actor_check_follows_cover_locks_and_rolls_back_native_work(string $command): void
    {
        $actor = $this->actor(['hr.leave.approve', 'hr.leave.manage']);
        $worker = $this->actor([]);
        $leave = $this->pending($worker);
        $this->duty($worker);
        if ($command === 'cancel') {
            app(LeaveService::class)->approveRequest($leave, $actor);
            $leave = $leave->fresh();
            Notification::fake();
        }
        $before = $this->state();
        $real = app(WorkforceAvailabilityCoverageService::class);
        $method = $command === 'approve' ? 'syncApprovedLeave' : 'cancelLeave';
        $this->mock(WorkforceAvailabilityCoverageService::class, function ($mock) use ($real, $method, $actor): void {
            $mock->shouldReceive($method)->once()->andReturnUsing(function ($request, $reviewer) use ($real, $method, $actor) {
                $result = $real->{$method}($request, $reviewer);
                DB::table('users')->where('id', $actor->id)->update(['approved_at' => null]);

                return $result;
            });
        });
        $this->expectDenied(fn () => $this->command($command, $leave, $actor), 403);
        $this->assertSame($before, $this->state());
        $this->assertTrue($actor->fresh()->isApproved());
        Notification::assertNothingSent();
    }

    public static function lateCoverCommands(): array
    {
        return [['approve'], ['cancel']];
    }

    public function test_decline_rechecks_after_balance_work_before_any_decline_notice(): void
    {
        $actor = $this->actor(['hr.leave.approve']);
        $leave = $this->pending($this->actor([]));
        $before = $this->state();
        $listener = 'eloquent.created: '.HrLeaveBalanceLedger::class;
        Event::listen($listener, function ($ledger) use ($actor): void {
            if ($ledger->entry_type === 'released') {
                DB::table('users')->where('id', $actor->id)->update(['approved_at' => null]);
            }
        });
        $this->mock(HrNotificationService::class, fn ($mock) => $mock->shouldNotReceive('notifyLeaveDeclined'));
        try {
            $this->expectDenied(fn () => app(LeaveService::class)->declineRequest($leave, $actor, 'Existing review reason'), 403);
        } finally {
            Event::forget($listener);
        }
        $this->assertSame($before, $this->state());
        Notification::assertNothingSent();
    }

    #[DataProvider('slaChanges')]
    public function test_sla_command_rechecks_fresh_actor_and_scope_without_changing_the_pending_request(string $change): void
    {
        $actor = $this->actor(['hr.leave.approve']);
        $leave = $this->pending($this->actor([]));
        $this->warm($actor, $leave);
        $this->revoke($actor, $change);
        $before = $this->state();
        $request = Request::create('/hr/leave/'.$leave->id.'/sla-due', 'POST', ['hours' => 24]);
        $request->setUserResolver(fn () => $actor);
        $this->expectDenied(fn () => app(LeaveController::class)->setSlaDue($request, $leave), $change === 'Site removed' ? 404 : 403);
        $this->assertSame($before, $this->state());
        Notification::assertNothingSent();
    }

    public static function slaChanges(): array
    {
        return [['approval'], ['permission'], ['Site removed']];
    }

    public function test_nested_roster_freshness_denial_rolls_back_pending_notice_reservation_and_cover(): void
    {
        $actor = $this->actor(['hr.leave.approve', 'staff.availability.updateAny']);
        $worker = $this->actor([]);
        $this->duty($worker);
        $before = $this->state();
        $listener = 'eloquent.created: '.HrLeaveRequest::class;
        Event::listen($listener, function () use ($actor): void {
            DB::table('users')->where('id', $actor->id)->update(['approved_at' => null]);
        });
        try {
            $this->expectDenied(fn () => app(LeaveService::class)->createRosterLeave($worker, $this->rosterPayload(), $actor), 403);
        } finally {
            Event::forget($listener);
        }
        $this->assertSame($before, $this->state());
        Notification::assertNothingSent();
    }

    public function test_pending_notice_waits_for_successful_outer_commit_and_is_delivered_once_with_the_existing_payload(): void
    {
        $actor = $this->actor(['hr.leave.approve', 'staff.availability.updateAny']);
        $worker = $this->actor([]);
        DB::beginTransaction();
        try {
            $leave = app(LeaveService::class)->createRosterLeave($worker, $this->rosterPayload(), $actor);
            $this->assertSame('approved', $leave->status);
            Notification::assertNothingSent();
            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
        Notification::assertSentToTimes($actor, LeaveRequestNotification::class, 1);
        Notification::assertSentTo($actor, LeaveRequestNotification::class, function ($notice) use ($leave, $worker, $actor): bool {
            $payload = $notice->toArray($actor);

            return $payload['leave_request_id'] === $leave->id && $payload['user_name'] === $worker->name
                && $payload['leave_type'] === 'annual' && $payload['action_url'] === '/hr/leave/'.$leave->id
                && $payload['type'] === 'leave_request'
                && $payload['starts_at'] === $leave->starts_at->toIso8601String()
                && $payload['ends_at'] === $leave->ends_at->toIso8601String()
                && ! array_key_exists('reason', $payload);
        });
        $this->assertSame(1, HrLeaveRequest::query()->count());
        $this->assertSame(1, DB::table('staff_time_offs')->count());
    }

    public function test_pending_notice_delivery_failure_cannot_turn_a_committed_request_into_a_failure(): void
    {
        $this->actor(['hr.leave.approve']);
        $worker = $this->actor([]);
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('Synthetic pending notice delivery failure'));
        $leave = app(LeaveService::class)->submitRequest($worker, $this->rosterPayload());
        $this->assertSame('pending', $leave->status);
        $this->assertSame(1, HrLeaveRequest::query()->count());
        $this->assertSame(8.0, (float) HrLeaveBalance::query()->sole()->pending_hours);
    }

    #[DataProvider('heldEvidence')]
    public function test_genuine_nowait_contention_rolls_back_leave_work_and_returns_a_recoverable_validation_error(string $held): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $actor = $this->actor(['hr.leave.approve']);
        $worker = $this->actor([]);
        $leave = $this->pending($worker);
        $this->duty($worker);
        $before = $this->state();
        $actorRoleId = $actor->roles()->sole()->id;
        $script = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['app.worker_timezone' => 'Pacific/Auckland']);
Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-10-05 00:00:00', config('app.timezone', 'UTC')));
Illuminate\Support\Facades\Queue::fake();
Illuminate\Support\Facades\Notification::fake();
$actor = App\Models\User::findOrFail((int) $argv[2]);
$leave = App\Domain\Hr\Models\HrLeaveRequest::findOrFail((int) $argv[3]);
try {
    app(App\Domain\Hr\Services\LeaveService::class)->approveRequest($leave, $actor);
    echo 'unsafe-success';
    exit(1);
} catch (Illuminate\Validation\ValidationException $exception) {
    echo json_encode(['status' => $exception->status, 'errors' => $exception->errors()]);
}
PHP;
        $process = null;
        DB::connection()->commit();
        try {
            DB::beginTransaction();
            match ($held) {
                // Holding User exclusively blocks the earlier created_by FK
                // check. Hold mutable actor RBAC evidence to reach the final
                // NOWAIT check after native leave/cover work instead.
                'actor role' => Role::query()->whereKey($actorRoleId)->lockForUpdate()->firstOrFail(),
                'subject profile' => HrEmployeeProfile::query()->where('user_id', $worker->id)->lockForUpdate()->firstOrFail(),
                'Site' => Site::query()->whereKey($this->site->id)->lockForUpdate()->firstOrFail(),
            };
            $config = DB::connection()->getConfig();
            $process = new Process([PHP_BINARY, '-r', $script, base_path(), (string) $actor->id, (string) $leave->id], base_path(), [
                'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $config['database'],
                'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
                'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
                'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
            ]);
            $process->setTimeout(25);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $receipt = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(422, $receipt['status']);
            $this->assertSame(['Leave access is being updated. Please try again.'], $receipt['errors']['leave_request']);
            DB::rollBack();
            $this->assertSame($before, $this->state());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process?->isRunning()) {
                $process->stop(1);
            }
            DB::beginTransaction();
        }
    }

    public static function heldEvidence(): array
    {
        return [['actor role'], ['subject profile'], ['Site']];
    }

    private function actor(array $permissions): User
    {
        $actor = User::factory()->create(['role' => 'custom_leave_delegate', 'approved_at' => now()]);
        $role = Role::query()->create(['name' => 'leave_command_'.$actor->id, 'label' => 'Custom leave role', 'type' => 'custom', 'level' => 50]);
        foreach ($permissions as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => 'Leave command fixture', 'group' => 'HR', 'module' => 'HR']);
            $role->permissions()->attach($permission);
        }
        $actor->roles()->attach($role);
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'start_date' => '2025-01-01', 'end_date' => null, 'is_active' => true,
            'hours_per_week' => 40, 'created_by' => $actor->id, 'updated_by' => $actor->id]);

        return $actor;
    }

    private function pending(User $worker): HrLeaveRequest
    {
        HrLeaveBalance::query()->create(['user_id' => $worker->id, 'leave_type' => 'annual', 'year' => 2026,
            'balance_hours' => 200, 'accrued_hours' => 200, 'pending_hours' => 8, 'used_hours' => 0,
            'source' => 'system', 'updated_by' => $worker->id]);

        return HrLeaveRequest::query()->create(['user_id' => $worker->id, 'leave_type' => 'annual', 'period' => 'full_day',
            'starts_at' => '2026-10-13 11:00:00', 'ends_at' => '2026-10-14 10:59:59', 'hours_requested' => 8,
            'status' => 'pending', 'submitted_at' => now(), 'approval_due_at' => now()->addDays(1), 'created_by' => $worker->id])->fresh();
    }

    private function duty(User $worker): Shift
    {
        return Shift::factory()->create(['client_id' => Client::factory()->create(['site_id' => $this->site->id])->id,
            'site_id' => $this->site->id, 'user_id' => $worker->id, 'created_by' => $worker->id,
            'starts_at' => '2026-10-13 20:00:00', 'ends_at' => '2026-10-13 23:00:00', 'status' => 'scheduled']);
    }

    private function rosterPayload(): array
    {
        return ['leave_type' => 'annual', 'starts_at' => '2026-10-14', 'ends_at' => '2026-10-14',
            'hours_requested' => 8, 'label' => 'Existing leave request reason'];
    }

    private function warm(User $actor, HrLeaveRequest $leave): void
    {
        $actor->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
        app(HrLeaveAccessService::class)->currentRequest($actor, $leave);
    }

    private function revoke(User $actor, string $change): void
    {
        match ($change) {
            'approval' => DB::table('users')->where('id', $actor->id)->update(['approved_at' => null]),
            'permission' => $actor->permissionOverrides()->syncWithoutDetaching(Permission::query()
                ->whereIn('key', ['hr.leave.approve', 'hr.leave.manage'])->pluck('id')
                ->mapWithKeys(fn ($id) => [$id => ['allowed' => false]])->all()),
            'profile inactive' => DB::table('hr_employee_profiles')->where('user_id', $actor->id)->update(['is_active' => false]),
            'Site removed' => DB::table('hr_employee_profiles')->where('user_id', $actor->id)->update(['primary_site_id' => null, 'secondary_site_ids' => '[]']),
            'Site archived' => DB::table('sites')->where('id', $this->site->id)->update(['archived' => true, 'archived_at' => now()]),
        };
    }

    private function command(string $command, HrLeaveRequest $leave, User $actor): HrLeaveRequest
    {
        return match ($command) {
            'approve' => app(LeaveService::class)->approveRequest($leave, $actor),
            'decline' => app(LeaveService::class)->declineRequest($leave, $actor, 'Existing review reason'),
            'cancel' => app(LeaveService::class)->cancelRequest($leave, $actor),
        };
    }

    private function state(): array
    {
        $tables = ['hr_leave_requests', 'hr_leave_balances', 'hr_leave_balance_ledgers', 'staff_time_offs',
            'shifts', 'shift_replacement_requests', 'shift_open_positions', 'workforce_availability_coverage_actions'];
        // Preserve no-side-effect checks for optional systems only when installed.
        foreach (['workforce_eligibility_rechecks', 'workforce_eligibility_observations'] as $table) {
            if (Schema::hasTable($table)) {
                $tables[] = $table;
            }
        }
        $tables[] = 'audit_logs';

        return collect($tables)
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
    }

    private function expectDenied(callable $command, int $status): void
    {
        try {
            $command();
            $this->fail('Current leave authority must deny this command.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        } catch (ModelNotFoundException) {
            $this->assertSame(404, $status);
        }
    }
}
