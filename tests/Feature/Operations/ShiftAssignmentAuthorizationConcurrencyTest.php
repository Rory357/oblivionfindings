<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Permission;
use App\Models\RosterSuggestion;
use App\Models\RosterSuggestionRun;
use App\Models\Shift;
use App\Models\ShiftEligibilityOverride;
use App\Models\Site;
use App\Models\StaffTimeOff;
use App\Models\TimelineEvent;
use App\Models\User;
use Database\Seeders\OperationsPermissionsSeeder;
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
class ShiftAssignmentAuthorizationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->seed([
            RbacSeeder::class,
            OperationsPermissionsSeeder::class,
        ]);
    }

    public function test_native_assignment_rechecks_manage_permission_after_waiting_on_the_application_mutex(): void
    {
        $site = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $actor = User::factory()->create(['approved_at' => now()]);
        $assignee = User::factory()->create(['approved_at' => now()]);
        $this->makeCurrentAtSite($actor, $site, ['shifts.manageAny']);
        $this->makeCurrentAtSite($assignee, $site);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $shift = Shift::factory()->unassigned()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(8),
            'status' => 'draft',
            'created_by' => $actor->id,
        ]);
        $shiftBefore = $shift->fresh()->getRawOriginal();
        $timelineCount = TimelineEvent::query()->where('shift_id', $shift->id)->count();
        $overrideCount = ShiftEligibilityOverride::query()->where('shift_id', $shift->id)->count();
        $auditCount = AuditLog::query()
            ->where('auditable_type', Shift::class)
            ->where('auditable_id', $shift->id)
            ->count();
        $permission = Permission::query()->where('key', 'shifts.manageAny')->firstOrFail();
        $token = Str::uuid()->toString();
        $readyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."shift-assignment-permission-ready-{$token}";
        $goPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."shift-assignment-permission-go-{$token}";
        $process = null;
        DB::connection()->commit();

        try {
            DB::connection()->beginTransaction();
            $this->lockApplicationMutex();
            $process = $this->startWorker(
                $this->assignmentWorker(),
                [$shift->id, $actor->id, $assignee->id, $readyPath, $goPath],
            );
            $this->waitForFiles([$readyPath]);
            touch($goPath);
            usleep(250_000);
            $this->assertTrue($process->isRunning(), 'Assignment did not wait for the application mutex.');

            DB::table('permission_user')->updateOrInsert(
                ['user_id' => $actor->id, 'permission_id' => $permission->id],
                ['allowed' => false],
            );
            DB::connection()->commit();

            $result = $this->waitForResult($process);
            $this->assertSame('rejected', $result['outcome']);
            $this->assertSame(403, $result['status']);
            $this->assertSame($shiftBefore, $shift->fresh()->getRawOriginal());
            $this->assertSame($timelineCount, TimelineEvent::query()->where('shift_id', $shift->id)->count());
            $this->assertSame($overrideCount, ShiftEligibilityOverride::query()->where('shift_id', $shift->id)->count());
            $this->assertSame($auditCount, AuditLog::query()
                ->where('auditable_type', Shift::class)
                ->where('auditable_id', $shift->id)
                ->count());
            $this->assertFalse(DB::table('coverage_reservations')->where('shift_id', $shift->id)->exists());
        } finally {
            $this->finishProcessScenario($process, [$readyPath, $goPath]);
            $this->deleteFixture($site, $client, [$shift], [$actor, $assignee]);
        }
    }

    public function test_roster_suggestion_recomputes_eligibility_after_wait_and_leaves_everything_unchanged_on_drift(): void
    {
        $site = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $actor = User::factory()->create(['approved_at' => now()]);
        $candidate = User::factory()->create(['approved_at' => now()]);
        $this->makeCurrentAtSite($actor, $site, ['rostering.autoSchedule']);
        $this->makeCurrentAtSite($candidate, $site);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $shift = Shift::factory()->unassigned()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(8),
            'status' => 'scheduled',
            'created_by' => $actor->id,
        ]);
        $run = RosterSuggestionRun::factory()->create([
            'site_id' => $site->id,
            'requested_by' => $actor->id,
            'status' => RosterSuggestionRun::STATUS_COMPLETED,
            'expires_at' => now()->addDay(),
        ]);
        $suggestion = RosterSuggestion::factory()->create([
            'roster_suggestion_run_id' => $run->id,
            'shift_id' => $shift->id,
            'candidate_user_id' => $candidate->id,
            'status' => RosterSuggestion::STATUS_ACCEPTED,
            'accepted_by' => $actor->id,
            'accepted_at' => now(),
        ]);
        $shiftBefore = $shift->fresh()->getRawOriginal();
        $suggestionBefore = $suggestion->fresh()->getRawOriginal();
        $timelineCount = TimelineEvent::query()->where('shift_id', $shift->id)->count();
        $overrideCount = ShiftEligibilityOverride::query()->where('shift_id', $shift->id)->count();
        $token = Str::uuid()->toString();
        $readyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."roster-assignment-drift-ready-{$token}";
        $goPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."roster-assignment-drift-go-{$token}";
        $process = null;
        DB::connection()->commit();

        try {
            DB::connection()->beginTransaction();
            $this->lockApplicationMutex();
            $process = $this->startWorker(
                $this->rosterSuggestionWorker(),
                [$suggestion->id, $actor->id, $readyPath, $goPath],
            );
            $this->waitForFiles([$readyPath]);
            touch($goPath);
            usleep(250_000);
            $this->assertTrue($process->isRunning(), 'Roster suggestion apply did not wait for the application mutex.');

            StaffTimeOff::query()->create([
                'user_id' => $candidate->id,
                'starts_at' => $shift->starts_at->copy()->subMinute(),
                'ends_at' => $shift->ends_at->copy()->addMinute(),
                'type' => 'unavailable',
                'label' => 'Eligibility changed while assignment waited',
                'created_by' => $actor->id,
            ]);
            DB::connection()->commit();

            $result = $this->waitForResult($process);
            $this->assertSame('rejected', $result['outcome']);
            $this->assertSame($shiftBefore, $shift->fresh()->getRawOriginal());
            $this->assertSame($suggestionBefore, $suggestion->fresh()->getRawOriginal());
            $this->assertSame($timelineCount, TimelineEvent::query()->where('shift_id', $shift->id)->count());
            $this->assertSame($overrideCount, ShiftEligibilityOverride::query()->where('shift_id', $shift->id)->count());
            $this->assertFalse(DB::table('coverage_reservations')->where('shift_id', $shift->id)->exists());
        } finally {
            $this->finishProcessScenario($process, [$readyPath, $goPath]);
            StaffTimeOff::query()->where('user_id', $candidate->id)->delete();
            DB::table('roster_suggestions')->where('id', $suggestion->id)->delete();
            DB::table('roster_suggestion_runs')->where('id', $run->id)->delete();
            $this->deleteFixture($site, $client, [$shift], [$actor, $candidate]);
        }
    }

    /** @param array<int, string> $permissionKeys */
    private function makeCurrentAtSite(User $user, Site $site, array $permissionKeys = []): void
    {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $permissions = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->get();
        $this->assertCount(count($permissionKeys), $permissions);
        $user->permissionOverrides()->syncWithoutDetaching(
            $permissions->mapWithKeys(fn (Permission $permission): array => [
                $permission->id => ['allowed' => true],
            ])->all(),
        );
    }

    private function lockApplicationMutex(): void
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

    /** @return array<string, mixed> */
    private function waitForResult(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), trim($process->getErrorOutput()) ?: 'Assignment worker failed.');

        return json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<int, string> $paths */
    private function waitForFiles(array $paths): void
    {
        $deadline = microtime(true) + 15;
        while (collect($paths)->contains(fn (string $path): bool => ! is_file($path))) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Assignment workers did not reach the barrier.');
            }
            usleep(10_000);
        }
    }

    /** @param array<int, string> $paths */
    private function finishProcessScenario(?Process $process, array $paths): void
    {
        while (DB::connection()->transactionLevel() > 0) {
            DB::connection()->rollBack();
        }
        if ($process?->isRunning()) {
            $process->stop(1);
        }
        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @param  array<int, Shift>  $shifts
     * @param  array<int, User>  $users
     */
    private function deleteFixture(Site $site, Client $client, array $shifts, array $users): void
    {
        $shiftIds = collect($shifts)->pluck('id')->all();
        $userIds = collect($users)->pluck('id')->all();
        DB::table('timeline_events')->whereIn('shift_id', $shiftIds)->delete();
        DB::table('audit_logs')
            ->where(function ($query) use ($client, $userIds): void {
                $query->where('client_id', $client->id)
                    ->orWhereIn('user_id', $userIds);
            })
            ->delete();
        DB::table('shift_eligibility_overrides')->whereIn('shift_id', $shiftIds)->delete();
        DB::table('coverage_reservations')->whereIn('shift_id', $shiftIds)->delete();
        DB::table('shifts')->whereIn('id', $shiftIds)->delete();
        DB::table('clients')->where('id', $client->id)->delete();
        DB::table('hr_employee_profiles')->whereIn('user_id', $userIds)->delete();
        DB::table('permission_user')->whereIn('user_id', $userIds)->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();
        DB::table('sites')->where('id', $site->id)->delete();
        DB::connection()->beginTransaction();
    }

    private function assignmentWorker(): string
    {
        return <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
touch($argv[5]);
while (! is_file($argv[6])) { usleep(10000); }
try {
    $assigned = $app->make(App\Domain\Shifts\Lifecycle\ShiftLifecycleService::class)->assign(
        App\Models\Shift::query()->findOrFail((int) $argv[2]),
        App\Models\User::query()->findOrFail((int) $argv[3]),
        App\Models\User::query()->findOrFail((int) $argv[4]),
        reservationReason: 'assignment',
    );
    echo json_encode(['outcome' => 'assigned', 'shift_id' => $assigned->id]);
} catch (Throwable $exception) {
    echo json_encode([
        'outcome' => 'rejected',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'status' => method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : null,
    ]);
}
PHP;
    }

    private function rosterSuggestionWorker(): string
    {
        return <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
touch($argv[4]);
while (! is_file($argv[5])) { usleep(10000); }
try {
    $applied = $app->make(App\Domain\Rostering\AutoSchedule\RosterSuggestionApplier::class)->applyOne(
        App\Models\RosterSuggestion::query()->findOrFail((int) $argv[2]),
        App\Models\User::query()->findOrFail((int) $argv[3]),
    );
    echo json_encode(['outcome' => 'applied', 'suggestion_id' => $applied->id]);
} catch (Throwable $exception) {
    echo json_encode([
        'outcome' => 'rejected',
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'status' => method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : null,
    ]);
}
PHP;
    }
}
