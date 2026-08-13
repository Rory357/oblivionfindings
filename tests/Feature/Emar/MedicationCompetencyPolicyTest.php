<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationCompetencyExemption;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\EnhancedMarService;
use App\Services\Medication\MedicationAdministratorCompetencyPolicy;
use App\Services\Medication\MedicationCompetencyExemptionService;
use App\Services\MedicationSafetyService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MedicationCompetencyPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private ClientMedication $medication;

    private User $worker;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $this->worker = $this->makeActor('support_worker', $this->site);
        $this->approver = $this->makeActor('provider_manager', $this->site);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);
        $this->client->supportWorkers()->syncWithoutDetaching([$this->worker->id]);
        $this->medication = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Paracetamol',
            'dosage' => '500mg',
            'frequency' => 'PRN',
            'is_prn' => true,
            'prn_reason' => 'Pain',
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
    }

    public function test_explicit_exemption_is_independently_approved_scoped_expiring_and_audited(): void
    {
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $this->worker->id,
            'assessment_type' => 'remedial',
            'status' => 'failed',
            'assessment_date' => now()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->actingAs($this->approver);
        $exemption = app(MedicationCompetencyExemptionService::class)->approve(
            $this->worker,
            $this->site,
            $this->approver,
            'Time-limited supervised cover approved after documented clinical review.',
            now()->subMinute(),
            now()->addHours(4),
        );

        $decision = app(MedicationAdministratorCompetencyPolicy::class)->evaluate(
            $this->worker,
            $this->site->id,
            now(),
        );

        $this->assertTrue($decision['allowed']);
        $this->assertSame('exempt', $decision['state']);
        $this->assertSame($exemption->id, $decision['exemption_id']);
        $this->assertSame(MedicationCompetencyExemption::SCOPE_ADMINISTRATION, $exemption->scope);
        $this->assertSame($this->site->id, $exemption->site_id);
        $this->assertSame($this->approver->id, $exemption->approved_by);
        $this->assertNotNull($exemption->approved_at);
        $this->assertNotNull($exemption->expires_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'medications.competency.exemption.approved',
            'auditable_id' => $exemption->id,
            'user_id' => $this->approver->id,
        ]);

        $result = $this->recordDose();
        $this->assertTrue($result['success'], $result['error'] ?? '');

        $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $wrongSite = app(MedicationAdministratorCompetencyPolicy::class)->evaluate(
            $this->worker,
            $otherSite->id,
            now(),
        );
        $this->assertFalse($wrongSite['allowed']);
        $this->assertSame('failed', $wrongSite['state']);
    }

    public function test_unauthorized_or_self_approval_cannot_create_an_exemption(): void
    {
        $ordinaryActor = $this->makeActor('coordinator', $this->site);
        $service = app(MedicationCompetencyExemptionService::class);

        try {
            $service->approve(
                $this->worker,
                $this->site,
                $ordinaryActor,
                'Coordinator attempted to create an unauthorized competency exemption.',
                now(),
                now()->addHour(),
            );
            $this->fail('Expected an unauthorized exemption approval to be rejected.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('medication_competency_exemptions', 0);
        }

        $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $wrongSiteApprover = $this->makeActor('clinical_lead', $otherSite);
        try {
            $service->approve(
                $this->worker,
                $this->site,
                $wrongSiteApprover,
                'Clinical approver attempted to authorize outside their approved site scope.',
                now(),
                now()->addHour(),
            );
            $this->fail('Expected an out-of-site exemption approval to be rejected.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('medication_competency_exemptions', 0);
        }

        $this->expectException(AuthorizationException::class);
        $service->approve(
            $this->approver,
            $this->site,
            $this->approver,
            'Self-approval is not an independent medication competency decision.',
            now(),
            now()->addHour(),
        );
    }

    public function test_strict_audit_failure_rolls_back_exemption_approval(): void
    {
        $eventName = 'eloquent.creating: '.AuditLog::class;
        Event::listen($eventName, static function (): never {
            throw new RuntimeException('Simulated competency exemption audit failure.');
        });

        $caught = null;
        try {
            $this->actingAs($this->approver);
            app(MedicationCompetencyExemptionService::class)->approve(
                $this->worker,
                $this->site,
                $this->approver,
                'Clinical review supports only a short and fully audited exemption.',
                now(),
                now()->addHour(),
            );
        } catch (RuntimeException $exception) {
            $caught = $exception;
        } finally {
            Event::forget($eventName);
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertSame('Simulated competency exemption audit failure.', $caught?->getMessage());
        $this->assertDatabaseCount('medication_competency_exemptions', 0);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medications.competency.exemption.approved',
        ]);
    }

    public function test_expiry_between_initial_check_and_persist_is_rejected_without_partial_write(): void
    {
        Carbon::setTestNow(now()->startOfMinute());
        try {
            $this->actingAs($this->approver);
            app(MedicationCompetencyExemptionService::class)->approve(
                $this->worker,
                $this->site,
                $this->approver,
                'Brief supervised exemption used to prove persistence-time expiry checking.',
                now()->subMinute(),
                now()->addMinute(),
            );

            $this->partialMock(MedicationSafetyService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('performSafetyCheck')
                    ->once()
                    ->andReturnUsing(function (): array {
                        Carbon::setTestNow(now()->addMinutes(2));

                        return [
                            'blocked' => false,
                            'block_reason' => null,
                            'overall_level' => 'safe',
                            'warnings' => [],
                            'alerts' => [],
                        ];
                    });
            });

            $result = $this->recordDose();

            $this->assertFalse($result['success']);
            $this->assertSame('unassessed', $result['competency_state']);
            $this->assertDatabaseCount('client_medication_administrations', 0);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_concurrent_revocation_wins_before_server_authoritative_administration(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());

        $this->actingAs($this->approver);
        $exemption = app(MedicationCompetencyExemptionService::class)->approve(
            $this->worker,
            $this->site,
            $this->approver,
            'Concurrent revocation proof for a finite supervised medication exemption.',
            now()->subMinute(),
            now()->addHour(),
        );

        $database = $connection->getDatabaseName();
        $token = Str::uuid()->toString();
        $readyPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."med-comp-ready-{$token}";
        $process = null;
        $cleanupUserIds = [$this->worker->id, $this->approver->id];

        // Publish fixtures to the independent connection, then hold the shared
        // staff lock while its administration request begins. Revocation commits
        // first; the waiting request must then re-read and fail closed.
        $connection->commit();

        try {
            $connection->beginTransaction();
            User::query()->whereKey($this->worker->id)->lockForUpdate()->firstOrFail();
            $process = $this->startAdministrationWorker($readyPath, $database);
            $this->waitForFile($readyPath, 'The medication competency worker did not start.');
            usleep(250_000);
            $this->assertTrue($process->isRunning(), 'Administration finished before the competency lock was released.');

            app(MedicationCompetencyExemptionService::class)->revoke(
                $exemption,
                $this->approver,
                'Clinical authority withdrew the temporary exemption before administration.',
            );
            $connection->commit();

            $process->wait();
            $this->assertTrue(
                $process->isSuccessful(),
                trim($process->getErrorOutput()) ?: 'The competency concurrency worker failed.',
            );
            $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            $this->assertFalse($result['success']);
            $this->assertSame('unassessed', $result['competency_state']);
            $this->assertDatabaseCount('client_medication_administrations', 0);
            $this->assertNotNull($exemption->fresh()->revoked_at);
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            if ($process?->isRunning()) {
                $process->stop(1);
            }
            if (is_file($readyPath)) {
                unlink($readyPath);
            }

            try {
                DB::table('audit_logs')
                    ->whereIn('auditable_type', [
                        (new MedicationCompetencyExemption)->getMorphClass(),
                        (new ClientMedicationAdministration)->getMorphClass(),
                    ])
                    ->delete();
                DB::table('medication_competency_exemptions')->whereIn('user_id', $cleanupUserIds)->delete();
                DB::table('client_medication_administrations')->where('client_id', $this->client->id)->delete();
                DB::table('client_user')->where('client_id', $this->client->id)->delete();
                DB::table('client_medications')->where('client_id', $this->client->id)->delete();
                DB::table('clients')->where('id', $this->client->id)->delete();
                DB::table('permission_user')->whereIn('user_id', $cleanupUserIds)->delete();
                DB::table('role_user')->whereIn('user_id', $cleanupUserIds)->delete();
                DB::table('hr_employee_profiles')->whereIn('user_id', $cleanupUserIds)->delete();
                DB::table('users')->whereIn('id', $cleanupUserIds)->delete();
                DB::table('sites')->where('id', $this->site->id)->delete();
            } finally {
                $connection->beginTransaction();
            }
        }
    }

    private function recordDose(): array
    {
        return app(EnhancedMarService::class)->recordAdministration(
            $this->client,
            $this->medication,
            [
                'status' => 'given',
                'reason' => 'Pain',
                'dose_given' => '500mg',
            ],
            $this->worker->id,
        );
    }

    private function makeActor(string $roleName, Site $site): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return $user;
    }

    private function startAdministrationWorker(string $readyPath, string $database): Process
    {
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($argv): void {
    foreach (['App\\' => 'app', 'Database\\' => 'database'] as $prefix => $directory) {
        if (! str_starts_with($class, $prefix)) {
            continue;
        }

        $path = $argv[1].'/'.$directory.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) {
            require $path;
        }

        return;
    }
}, true, true);
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$client = App\Models\Client::query()->findOrFail((int) $argv[2]);
$medication = App\Models\ClientMedication::query()->findOrFail((int) $argv[3]);
file_put_contents($argv[5], 'ready');
$result = $app->make(App\Services\EnhancedMarService::class)->recordAdministration(
    $client,
    $medication,
    ['status' => 'given', 'reason' => 'Pain', 'dose_given' => '500mg'],
    (int) $argv[4],
);
echo json_encode([
    'success' => (bool) ($result['success'] ?? false),
    'competency_state' => $result['competency_state'] ?? null,
    'error' => $result['error'] ?? null,
], JSON_THROW_ON_ERROR);
PHP;

        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                $worker,
                base_path(),
                (string) $this->client->id,
                (string) $this->medication->id,
                (string) $this->worker->id,
                $readyPath,
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

    private function waitForFile(string $path, string $message): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException($message);
            }

            usleep(10_000);
        }
    }
}
