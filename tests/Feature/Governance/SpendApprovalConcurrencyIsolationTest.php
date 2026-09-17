<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\SpendApproval;
use App\Domain\Governance\Services\SpendApprovalCommandService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SpendApprovalConcurrencyIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private const ISOLATION_TABLES = [
        'audit_logs',
        'governance_audit_log',
        'governance_change_log',
        'spend_approval_decisions',
        'spend_approvals',
        'hr_employee_profiles',
        'permission_user',
        'role_user',
        'role_permission',
        'users',
        'sites',
        'permissions',
        'roles',
        'spend_approval_reference_sequences',
    ];

    public function test_concurrent_approve_and_reject_serialize_to_one_decision_on_mysql(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The two-process lock assertion requires MySQL.');
        }

        // This test must commit its fixtures for the independent workers. Keep
        // them deliberately minimal instead of committing the shared RBAC seed.
        $baseline = $this->isolationTableCounts();
        $existingPermissionIds = Permission::query()->pluck('id')->all();
        $permissions = collect([
            'governance.spend.view',
            'governance.spend.request',
            'governance.spend.approve',
        ])->mapWithKeys(function (string $key): array {
            $permission = Permission::firstOrCreate(
                ['key' => $key],
                ['description' => "Concurrency fixture for {$key}"],
            );

            return [$key => $permission->id];
        });

        $site = Site::factory()->create(['name' => 'Spend decision concurrency Site']);
        $requester = User::factory()->create(['approved_at' => now()]);
        $approver = User::factory()->create(['approved_at' => now()]);
        $rejector = User::factory()->create(['approved_at' => now()]);
        $users = collect([$requester, $approver, $rejector]);

        $this->grantPermissions($requester, $permissions->only([
            'governance.spend.view',
            'governance.spend.request',
        ])->values()->all());
        foreach ([$approver, $rejector] as $decider) {
            $this->grantPermissions($decider, $permissions->only([
                'governance.spend.view',
                'governance.spend.approve',
            ])->values()->all());
        }

        $profiles = $users->map(fn (User $user): HrEmployeeProfile => $this->assignConcurrencySite($user, $site));
        $this->actingAs($requester);
        $approval = SpendApproval::create([
            'reference' => 'SA-CONCURRENCY-'.strtoupper(Str::random(8)),
            'title' => 'Concurrent governed spend',
            'category' => SpendApproval::CATEGORY_OPEX,
            'amount' => 12000,
            'currency' => 'NZD',
            'status' => SpendApproval::STATUS_DRAFT,
            'requested_by' => $requester->id,
            'site_id' => $site->id,
            'version' => 1,
        ]);
        $approval = app(SpendApprovalCommandService::class)->submit($requester, $approval->id, 1);
        $database = $connection->getDatabaseName();
        $connection->commit();

        try {
            $statuses = spendApprovalConcurrentDecisionRound($connection, $database, $approval, [
                [
                    'actor_id' => $approver->id,
                    'outcome' => SpendApproval::STATUS_APPROVED,
                    'decision_key' => (string) Str::uuid(),
                    'decision_notes' => 'Concurrent approval.',
                ],
                [
                    'actor_id' => $rejector->id,
                    'outcome' => SpendApproval::STATUS_REJECTED,
                    'decision_key' => (string) Str::uuid(),
                    'decision_notes' => 'Concurrent rejection.',
                ],
            ]);

            $this->assertSame(['conflict', 'decided'], $statuses);
            $this->assertContains($approval->fresh()->status, [SpendApproval::STATUS_APPROVED, SpendApproval::STATUS_REJECTED]);
            $this->assertSame(3, $approval->fresh()->version);
            $this->assertSame(1, DB::table('spend_approval_decisions')->where('spend_approval_id', $approval->id)->count());
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            auth()->logout();
            DB::table('spend_approval_decisions')->where('spend_approval_id', $approval->id)->delete();
            DB::table('governance_audit_log')
                ->where('resource_type', 'SpendApproval')
                ->where('resource_id', $approval->id)
                ->delete();
            DB::table('governance_change_log')
                ->where('entity_type', 'SpendApproval')
                ->where('entity_id', $approval->id)
                ->delete();
            DB::table('audit_logs')->where(function ($audits) use ($approval, $profiles, $site): void {
                $audits->where(function ($approvalAudit) use ($approval): void {
                    $approvalAudit->where('auditable_type', $approval->getMorphClass())
                        ->where('auditable_id', $approval->id);
                })->orWhere(function ($profileAudits) use ($profiles): void {
                    $profileAudits->where('auditable_type', (new HrEmployeeProfile)->getMorphClass())
                        ->whereIn('auditable_id', $profiles->pluck('id'));
                })->orWhere(function ($siteAudit) use ($site): void {
                    $siteAudit->where('auditable_type', $site->getMorphClass())
                        ->where('auditable_id', $site->id);
                });
            })->delete();
            DB::table('spend_approvals')->where('id', $approval->id)->delete();
            DB::table('hr_employee_profiles')->whereIn('id', $profiles->pluck('id'))->delete();
            DB::table('permission_user')->whereIn('user_id', $users->pluck('id'))->delete();
            DB::table('role_user')->whereIn('user_id', $users->pluck('id'))->delete();
            DB::table('users')->whereIn('id', $users->pluck('id'))->delete();
            DB::table('sites')->where('id', $site->id)->delete();
            Permission::query()->whereNotIn('id', $existingPermissionIds)->whereIn('id', $permissions->values())->delete();

            $connection->beginTransaction();
            $this->assertSame($baseline, $this->isolationTableCounts(), 'The committed concurrency fixture cleanup must restore the exact table counts.');
        }
    }

    /** @param array<int, int> $permissionIds */
    private function grantPermissions(User $user, array $permissionIds): void
    {
        foreach ($permissionIds as $permissionId) {
            $user->permissionOverrides()->syncWithoutDetaching([$permissionId => ['allowed' => true]]);
        }
    }

    private function assignConcurrencySite(User $user, Site $site): HrEmployeeProfile
    {
        return HrEmployeeProfile::create([
            'user_id' => $user->id,
            'employee_number' => 'GOV-CONCURRENCY-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'work_email' => $user->email,
            'position_title' => 'Governance Tester',
            'position_role' => 'governance',
            'employment_type' => 'full_time',
            'contract_type' => 'individual',
            'start_date' => now()->subYear()->toDateString(),
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    /** @return array<string, int> */
    private function isolationTableCounts(): array
    {
        return collect(self::ISOLATION_TABLES)
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
            ->all();
    }
}

/**
 * @param  array<int, array<string, int|string>>  $commands
 * @return array<int, string>
 */
function spendApprovalConcurrentDecisionRound(
    ConnectionInterface $connection,
    string $database,
    SpendApproval $approval,
    array $commands,
): array {
    $token = (string) Str::uuid();
    $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."spend-decision-release-{$token}";
    $readyPaths = [];
    $attemptPaths = [];
    $processes = [];

    $connection->beginTransaction();
    SpendApproval::query()->whereKey($approval->id)->lockForUpdate()->firstOrFail();

    try {
        foreach ($commands as $index => $command) {
            $readyPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."spend-decision-ready-{$index}-{$token}";
            $attemptPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."spend-decision-attempt-{$index}-{$token}";
            $processes[] = startSpendApprovalDecisionWorker(
                $database,
                [
                    ...$command,
                    'approval_id' => $approval->id,
                    'expected_version' => $approval->version,
                    'expected_content_digest' => $approval->content_digest,
                ],
                $readyPaths[$index],
                $attemptPaths[$index],
                $releasePath,
            );
        }

        waitForSpendDecisionFiles($readyPaths, 'Concurrent spend-decision workers did not become ready.');
        touch($releasePath);
        waitForSpendDecisionFiles($attemptPaths, 'Concurrent spend-decision workers did not reach the command.');
        usleep(250_000);
        foreach ($processes as $process) {
            if (! $process->isRunning()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'A spend-decision worker exited before lock release.');
            }
        }

        $connection->commit();
        $statuses = [];
        foreach ($processes as $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'A spend-decision concurrency worker failed.');
            }
            $statuses[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR)['status'];
        }
        sort($statuses);

        return $statuses;
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
    }
}

/** @param array<string, int|string> $command */
function startSpendApprovalDecisionWorker(
    string $database,
    array $command,
    string $readyPath,
    string $attemptPath,
    string $releasePath,
): Process {
    $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$command = json_decode(base64_decode($argv[2]), true, flags: JSON_THROW_ON_ERROR);
Illuminate\Support\Facades\Auth::loginUsingId((int) $command['actor_id']);
file_put_contents($argv[3], (string) Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
$deadline = microtime(true) + 15;
while (! is_file($argv[5])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the spend-decision release barrier.');
    }
    usleep(10_000);
}
file_put_contents($argv[4], 'attempting');
try {
    $service = $app->make(App\Domain\Governance\Services\SpendApprovalCommandService::class);
    $service->decide(
        App\Models\User::findOrFail((int) $command['actor_id']),
        (int) $command['approval_id'],
        (string) $command['outcome'],
        [
            'decision_key' => (string) $command['decision_key'],
            'decision_notes' => (string) $command['decision_notes'],
            'expected_version' => (int) $command['expected_version'],
            'expected_content_digest' => (string) $command['expected_content_digest'],
        ],
    );
    $status = 'decided';
} catch (Illuminate\Validation\ValidationException) {
    $status = 'conflict';
}
echo json_encode(['status' => $status], JSON_THROW_ON_ERROR);
PHP;

    $process = new Process([
        PHP_BINARY,
        '-r',
        $worker,
        base_path(),
        base64_encode(json_encode($command, JSON_THROW_ON_ERROR)),
        $readyPath,
        $attemptPath,
        $releasePath,
    ], base_path(), [
        'APP_ENV' => 'testing',
        'DB_DATABASE' => $database,
    ]);
    $process->setTimeout(30);
    $process->start();

    return $process;
}

/** @param array<int, string> $paths */
function waitForSpendDecisionFiles(array $paths, string $message): void
{
    $deadline = microtime(true) + 15;
    while (collect($paths)->contains(fn (string $path) => ! is_file($path))) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException($message);
        }
        usleep(10_000);
    }
}
