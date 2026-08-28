<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('mysql-serial')]
class HandoverAuthorizationEvidenceConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_worker_capability_revoked_while_edit_waits_cannot_mutate_handover_audit_or_timeline(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $this->seed(RbacSeeder::class);

        [$site, $foreignSite, $context, $client] = $this->makeContext();
        $owner = $this->makeUser($site);
        $outgoingShift = $this->makeOutgoingShift($client, $site, $context, $owner);
        $handover = ShiftHandover::factory()->draft()->create([
            'outgoing_shift_id' => $outgoingShift->id,
            'incoming_shift_id' => null,
            'client_id' => $client->id,
            'outgoing_staff_id' => $owner->id,
            'incoming_staff_id' => null,
            'handover_notes' => 'Original protected handover.',
            'medications_due' => [['label' => 'Original medication evidence']],
            'version' => 1,
        ]);
        $before = $handover->fresh()->getRawOriginal();
        $permissionId = (int) Permission::query()
            ->where('key', 'shifts.viewAssigned')
            ->value('id');
        $this->assertGreaterThan(0, $permissionId);

        $token = Str::uuid()->toString();
        $readyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."handover-auth-ready-{$token}";
        $goPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."handover-auth-go-{$token}";
        $process = null;
        $connection->commit();

        try {
            $connection->beginTransaction();
            Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $process = $this->startWorker(
                'apply-edit',
                $readyPath,
                $goPath,
                $outgoingShift,
                $handover,
                $owner,
                $connection->getDatabaseName(),
            );
            $this->waitForFile($readyPath);
            touch($goPath);
            usleep(250_000);
            $this->assertTrue(
                $process->isRunning(),
                'The handover edit worker did not wait for the canonical Client lock.',
            );

            DB::table('permission_user')->updateOrInsert(
                [
                    'permission_id' => $permissionId,
                    'user_id' => $owner->id,
                ],
                ['allowed' => false],
            );
            $connection->commit();

            $result = $this->waitForDeniedWorker($process);
            $this->assertSame(403, $result['status'], $result['message']);
            $this->assertSame($before, $handover->fresh()->getRawOriginal());
            $this->assertNoHandoverSideEffects($handover);
        } finally {
            $this->restoreTestTransaction(
                $connection,
                $process,
                [$readyPath, $goPath],
                $handover,
                $outgoingShift,
                $client,
                $context,
                [$site, $foreignSite],
                [$owner],
            );
        }
    }

    public function test_profile_site_move_while_save_waits_cannot_clear_controlled_medication_due_evidence(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $this->seed(RbacSeeder::class);

        [$site, $foreignSite, $context, $client] = $this->makeContext();
        $actor = $this->makeUser($site, [
            'shifts.update',
            'medications.controlled.view',
            'medications.controlled.record',
        ]);
        $outgoingShift = $this->makeOutgoingShift($client, $site, $context, $actor);
        $handover = ShiftHandover::factory()->draft()->create([
            'outgoing_shift_id' => $outgoingShift->id,
            'incoming_shift_id' => null,
            'client_id' => $client->id,
            'outgoing_staff_id' => $actor->id,
            'incoming_staff_id' => null,
            'handover_notes' => 'Controlled medication remains due.',
            'medications_due' => [['label' => 'Controlled medication remains due']],
            'version' => 1,
        ]);
        $before = $handover->fresh()->getRawOriginal();

        $token = Str::uuid()->toString();
        $readyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."handover-site-ready-{$token}";
        $goPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."handover-site-go-{$token}";
        $process = null;
        $connection->commit();

        try {
            $connection->beginTransaction();
            Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $process = $this->startWorker(
                'save-clear-medications',
                $readyPath,
                $goPath,
                $outgoingShift,
                $handover,
                $actor,
                $connection->getDatabaseName(),
            );
            $this->waitForFile($readyPath);
            touch($goPath);
            usleep(250_000);
            $this->assertTrue(
                $process->isRunning(),
                'The handover save worker did not wait for the canonical Client lock.',
            );

            DB::table('hr_employee_profiles')
                ->where('user_id', $actor->id)
                ->update([
                    'primary_site_id' => $foreignSite->id,
                    'secondary_site_ids' => json_encode([], JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            $connection->commit();

            $result = $this->waitForDeniedWorker($process);
            $this->assertSame(403, $result['status'], $result['message']);
            $this->assertSame($before, $handover->fresh()->getRawOriginal());
            $this->assertNoHandoverSideEffects($handover);
        } finally {
            $this->restoreTestTransaction(
                $connection,
                $process,
                [$readyPath, $goPath],
                $handover,
                $outgoingShift,
                $client,
                $context,
                [$site, $foreignSite],
                [$actor],
            );
        }
    }

    /**
     * @return array{0: Site, 1: Site, 2: ServiceContext, 3: Client}
     */
    private function makeContext(): array
    {
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $foreignSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $context = ServiceContext::factory()->create(['type' => 'residential', 'is_active' => true]);
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'status' => 'active',
        ]);

        return [$site, $foreignSite, $context, $client];
    }

    /** @param array<int, string> $permissionKeys */
    private function makeUser(Site $site, array $permissionKeys = []): User
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $role = Role::query()->where('name', 'support_worker')->firstOrFail();
        $user->roles()->syncWithoutDetaching([$role->id]);
        $overrides = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
            ->all();
        $this->assertCount(count($permissionKeys), $overrides);
        $user->permissionOverrides()->syncWithoutDetaching($overrides);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);

        return $user;
    }

    private function makeOutgoingShift(
        Client $client,
        Site $site,
        ServiceContext $context,
        User $owner,
    ): Shift {
        return Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'user_id' => $owner->id,
            'starts_at' => now()->subHours(4),
            'ends_at' => now()->addMinutes(10),
            'actual_starts_at' => now()->subHours(4),
            'status' => 'in_progress',
            'started_by' => $owner->id,
            'created_by' => $owner->id,
        ]);
    }

    private function startWorker(
        string $operation,
        string $readyPath,
        string $goPath,
        Shift $outgoingShift,
        ShiftHandover $handover,
        User $actor,
        string $database,
    ): Process {
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$operation = $argv[2];
$shift = App\Models\Shift::query()->findOrFail((int) $argv[3]);
$handover = App\Models\ShiftHandover::query()->findOrFail((int) $argv[4]);
$actor = App\Models\User::query()->findOrFail((int) $argv[5]);
Illuminate\Support\Facades\Auth::setUser($actor);
file_put_contents($argv[6], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[7])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the handover authorization barrier.');
    }
    usleep(10_000);
}
try {
    $service = $app->make(App\Services\ShiftHandoverService::class);
    if ($operation === 'apply-edit') {
        $service->applyEdit($handover, $actor, [
            'handover_notes' => 'Unauthorized assigned-worker rewrite.',
            'expected_version' => (int) $handover->version,
        ]);
    } elseif ($operation === 'save-clear-medications') {
        $service->save($shift, $actor, [
            'client_id' => (int) $handover->client_id,
            'handover_notes' => 'Unauthorized controlled evidence clear.',
            'medications_due' => [],
            'expected_version' => (int) $handover->version,
            'submit' => false,
        ]);
    } else {
        throw new RuntimeException('Unknown handover concurrency operation.');
    }
    echo json_encode(['status' => 200, 'message' => 'Mutation unexpectedly succeeded.'], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    $status = $exception instanceof Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
        ? $exception->getStatusCode()
        : 0;
    echo json_encode([
        'status' => $status,
        'message' => $exception::class.': '.$exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
}
PHP;

        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                $worker,
                base_path(),
                $operation,
                (string) $outgoingShift->id,
                (string) $handover->id,
                (string) $actor->id,
                $readyPath,
                $goPath,
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

    /** @return array{status: int, message: string} */
    private function waitForDeniedWorker(Process $process): array
    {
        $process->wait();
        $this->assertTrue(
            $process->isSuccessful(),
            trim($process->getErrorOutput()) ?: 'Handover authorization worker failed.',
        );

        /** @var array{status: int, message: string} $result */
        $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);

        return $result;
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The handover authorization worker did not become ready.');
            }
            usleep(10_000);
        }
    }

    private function assertNoHandoverSideEffects(ShiftHandover $handover): void
    {
        $this->assertSame(0, DB::table('audit_logs')
            ->where('auditable_type', ShiftHandover::class)
            ->where('auditable_id', $handover->id)
            ->count());
        $this->assertSame(0, TimelineEvent::query()
            ->where('source_type', ShiftHandover::class)
            ->where('source_id', $handover->id)
            ->count());
    }

    /**
     * @param  array<int, string>  $barrierPaths
     * @param  array<int, Site>  $sites
     * @param  array<int, User>  $users
     */
    private function restoreTestTransaction(
        Connection $connection,
        ?Process $process,
        array $barrierPaths,
        ShiftHandover $handover,
        Shift $outgoingShift,
        Client $client,
        ServiceContext $context,
        array $sites,
        array $users,
    ): void {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        if ($process?->isRunning()) {
            $process->stop(1);
        }
        foreach ($barrierPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $userIds = collect($users)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        DB::table('timeline_events')
            ->where('source_type', ShiftHandover::class)
            ->where('source_id', $handover->id)
            ->delete();
        DB::table('audit_logs')
            ->where('auditable_type', ShiftHandover::class)
            ->where('auditable_id', $handover->id)
            ->delete();
        DB::table('shift_handovers')->where('id', $handover->id)->delete();
        DB::table('shifts')->where('id', $outgoingShift->id)->delete();
        DB::table('clients')->where('id', $client->id)->delete();
        DB::table('hr_employee_profiles')->whereIn('user_id', $userIds)->delete();
        DB::table('permission_user')->whereIn('user_id', $userIds)->delete();
        DB::table('role_user')->whereIn('user_id', $userIds)->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();
        DB::table('service_contexts')->where('id', $context->id)->delete();
        DB::table('sites')->whereIn('id', collect($sites)->pluck('id')->all())->delete();
        $connection->beginTransaction();
    }
}
