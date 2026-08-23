<?php

namespace Tests\Feature\Safeguarding;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\SafeguardingConcern;
use App\Models\SafeguardingDeclassificationReview;
use App\Models\Site;
use App\Models\User;
use App\Services\Safeguarding\SafeguardingSensitivityService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SafeguardingSensitivityAtomicityTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_audit_write_failure_rolls_back_the_entire_declassification_request(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        [$concern, $requester] = $this->context();
        $preview = app(SafeguardingSensitivityService::class)->audiencePreview($concern);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER safe_sensitivity_test_audit_failure
            BEFORE INSERT ON audit_logs
            FOR EACH ROW
            BEGIN
                IF NEW.action = 'safeguarding.declassification.requested' THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Injected safeguarding audit failure.';
                END IF;
            END
            SQL);

        try {
            app(SafeguardingSensitivityService::class)->requestDeclassification(
                $concern,
                $requester,
                'A complete request reason that must roll back with the failed audit.',
                $preview['hash'],
                $concern->sensitivity_version,
                (string) Str::uuid(),
            );
            $this->fail('Expected the injected audit failure.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Injected safeguarding audit failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS safe_sensitivity_test_audit_failure');
        }

        $this->assertTrue($concern->fresh()->is_sensitive);
        $this->assertDatabaseCount('safeguarding_declassification_reviews', 0);
        $this->assertSame(0, AuditLog::query()
            ->where('action', 'safeguarding.declassification.requested')
            ->count());
    }

    public function test_audit_write_failure_rolls_back_declassification_and_terminal_review(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        [$concern, $requester] = $this->context();
        $approver = $this->userWith([
            'safeguarding.viewAny',
            'safeguarding.declassification.approve',
            'reports.viewAny',
        ]);
        $service = app(SafeguardingSensitivityService::class);
        $preview = $service->audiencePreview($concern);
        $review = $service->requestDeclassification(
            $concern,
            $requester,
            'A complete request whose approval must remain atomic with its audit record.',
            $preview['hash'],
            $concern->sensitivity_version,
            (string) Str::uuid(),
        );

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER safe_sensitivity_test_approval_audit_failure
            BEFORE INSERT ON audit_logs
            FOR EACH ROW
            BEGIN
                IF NEW.action = 'safeguarding.declassification.approved' THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Injected safeguarding approval audit failure.';
                END IF;
            END
            SQL);

        try {
            $service->approve(
                $concern,
                $review,
                $approver,
                'Independent approval that must roll back if its audit record cannot be written.',
                (string) Str::uuid(),
            );
            $this->fail('Expected the injected approval audit failure.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Injected safeguarding approval audit failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS safe_sensitivity_test_approval_audit_failure');
        }

        $this->assertTrue($concern->fresh()->is_sensitive);
        $this->assertSame(1, $concern->fresh()->sensitivity_version);
        $this->assertSame(SafeguardingDeclassificationReview::STATUS_PENDING, $review->fresh()->status);
        $this->assertSame($concern->id, $review->fresh()->active_concern_id);
        $this->assertNull($review->fresh()->reviewed_by_user_id);
        $this->assertSame(0, AuditLog::query()
            ->where('action', 'safeguarding.declassification.approved')
            ->count());
    }

    public function test_parallel_approval_serializes_to_one_declassification_and_one_terminal_audit(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        [$concern, $requester] = $this->context();
        $approver = $this->userWith([
            'safeguarding.viewAny',
            'safeguarding.declassification.approve',
            'reports.viewAny',
        ]);
        $service = app(SafeguardingSensitivityService::class);
        $preview = $service->audiencePreview($concern);
        $review = $service->requestDeclassification(
            $concern,
            $requester,
            'A concurrency-tested request to remove the need-to-know restriction safely.',
            $preview['hash'],
            $concern->sensitivity_version,
            (string) Str::uuid(),
        );

        $token = (string) Str::uuid();
        $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."safe-sensitivity-release-{$token}";
        $readyPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."safe-sensitivity-ready-a-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."safe-sensitivity-ready-b-{$token}",
        ];
        $attemptPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."safe-sensitivity-attempt-a-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."safe-sensitivity-attempt-b-{$token}",
        ];
        $processes = [];

        try {
            $connection->beginTransaction();
            SafeguardingConcern::query()->whereKey($concern->id)->lockForUpdate()->firstOrFail();

            foreach ([0, 1] as $index) {
                $processes[] = $this->startApprovalWorker(
                    $connection->getDatabaseName(),
                    $concern->id,
                    $review->id,
                    $approver->id,
                    (string) Str::uuid(),
                    $readyPaths[$index],
                    $attemptPaths[$index],
                    $releasePath,
                );
            }

            $this->waitForFiles($readyPaths, 'Both declassification workers did not connect.');
            touch($releasePath);
            $this->waitForFiles($attemptPaths, 'Both declassification workers did not reach approval.');
            usleep(250_000);

            foreach ($processes as $process) {
                $this->assertTrue(
                    $process->isRunning(),
                    trim($process->getErrorOutput()) ?: 'A declassification worker exited before lock release.',
                );
            }

            $connection->commit();
            $statuses = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    trim($process->getErrorOutput()) ?: 'A declassification concurrency worker failed.',
                );
                $statuses[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'];
            }
            sort($statuses);

            $this->assertSame(['approved', 'http-409'], $statuses);
            $this->assertFalse($concern->fresh()->is_sensitive);
            $this->assertSame(2, $concern->fresh()->sensitivity_version);
            $this->assertSame(SafeguardingDeclassificationReview::STATUS_APPROVED, $review->fresh()->status);
            $this->assertSame(1, AuditLog::query()
                ->where('action', 'safeguarding.declassification.approved')
                ->where('auditable_id', $concern->id)
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
            foreach ([...$readyPaths, ...$attemptPaths, $releasePath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /** @return array{SafeguardingConcern, User} */
    private function context(): array
    {
        $site = Site::factory()->create(['name' => 'SAFE sensitivity atomic Site']);
        $requester = $this->userWith(['safeguarding.viewAny', 'safeguarding.update']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $requester->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $concern = SafeguardingConcern::factory()->create([
            'site_id' => $site->id,
            'is_sensitive' => true,
            'status' => 'reported',
            'subject_type' => null,
            'subject_id' => null,
            'related_incident_id' => null,
            'assigned_to_user_id' => $requester->id,
            'reported_by_user_id' => User::factory()->create()->id,
        ]);

        return [$concern, $requester];
    }

    /** @param array<int, string> $permissions */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        $permissionIds = collect($permissions)->map(function (string $key): int {
            return Permission::query()->firstOrCreate(
                ['key' => $key],
                [
                    'description' => str_replace('.', ' ', $key),
                    'group' => explode('.', $key)[0],
                    'module' => 'Compliance',
                ],
            )->id;
        });
        $user->permissionOverrides()->sync(
            $permissionIds->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]]),
        );

        return $user;
    }

    private function startApprovalWorker(
        string $database,
        int $concernId,
        int $reviewId,
        int $approverId,
        string $replayKey,
        string $readyPath,
        string $attemptPath,
        string $releasePath,
    ): Process {
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$concern = App\Models\SafeguardingConcern::query()->findOrFail((int) $argv[2]);
$review = App\Models\SafeguardingDeclassificationReview::query()->findOrFail((int) $argv[3]);
$approver = App\Models\User::query()->findOrFail((int) $argv[4]);
file_put_contents($argv[6], (string) Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
$deadline = microtime(true) + 60;
while (! is_file($argv[8])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the safeguarding sensitivity release barrier.');
    }
    usleep(10_000);
}
file_put_contents($argv[7], 'attempting');
try {
    $result = $app->make(App\Services\Safeguarding\SafeguardingSensitivityService::class)->approve(
        $concern,
        $review,
        $approver,
        'Independent concurrent approval with complete decision provenance.',
        (string) $argv[5],
    );
    $status = $result->status;
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
    $status = 'http-'.$exception->getStatusCode();
}
echo json_encode(['status' => $status], JSON_THROW_ON_ERROR);
PHP;

        $process = new Process([
            PHP_BINARY,
            '-r',
            $worker,
            base_path(),
            (string) $concernId,
            (string) $reviewId,
            (string) $approverId,
            $replayKey,
            $readyPath,
            $attemptPath,
            $releasePath,
        ], base_path(), [
            'APP_ENV' => 'testing',
            'DB_DATABASE' => $database,
        ]);
        $process->setTimeout(90);
        $process->start();

        return $process;
    }

    /** @param array<int, string> $paths */
    private function waitForFiles(array $paths, string $message): void
    {
        $deadline = microtime(true) + 60;
        while (collect($paths)->contains(fn (string $path): bool => ! is_file($path))) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException($message);
            }
            usleep(10_000);
        }
    }
}
