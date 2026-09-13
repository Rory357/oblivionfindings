<?php

namespace Tests\Concurrency\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItServiceIdentityCredentialService;
use App\Models\ItApiRequest;
use App\Models\ItServiceIdentity;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Run alone through run-isolated-it-tests.ps1; this test owns its committed disposable schema. */
final class ItApiAuthorizationConcurrencyTest extends TestCase
{
    private User $settingsAdministrator;

    public function createApplication()
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'oblivion_it_support_test'
            || preg_match('/^it_[a-f0-9]{16}$/D', (string) getenv('TEST_TOKEN')) !== 1 || getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('Use the isolated IT test wrapper for standalone API authorization concurrency verification.');
        }

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertMatchesRegularExpression('/^oblivion_it_support_test_it_[a-f0-9]{16}$/', DB::connection()->getDatabaseName());
        Http::preventStrayRequests();
        Notification::fake();
        $this->seed(RbacSeeder::class);

        $this->settingsAdministrator = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->settingsAdministrator->roles()->sync(Role::query()->where('name', 'admin')->pluck('id'));
    }

    public function test_authenticated_api_commands_serialize_with_role_and_site_authorization_writers(): void
    {
        $this->assertRoleRevocationWaitsForAnAuthorizedApiCreate();
        $this->assertSiteDeactivationWaitsForAnAuthorizedApiComment();
    }

    private function assertRoleRevocationWaitsForAnAuthorizedApiCreate(): void
    {
        [$actor, $role, $site, $credential] = $this->apiActor('role');
        $key = (string) Str::uuid();
        $beforeTickets = ItTicket::query()->count();
        $beforeReceipts = ItApiRequest::query()->count();
        $barrier = $this->barrier('role');
        $paths = $this->racePaths($barrier);
        $api = null;
        $writer = null;

        try {
            $api = $this->startWorker('role-api', [
                $paths['api_ready'], $paths['api_release'],
            ], [
                'IT_API_AUTHORIZATION_TOKEN' => $credential['token'],
                'IT_API_AUTHORIZATION_KEY' => $key,
                'IT_API_AUTHORIZATION_PAYLOAD' => json_encode($this->createPayload($site, 'Role evidence race'), JSON_THROW_ON_ERROR),
            ]);
            $this->waitForFiles([$paths['api_ready']], [$api]);

            $writer = $this->startWorker('role-writer', [
                (string) $this->settingsAdministrator->id,
                (string) $role->id,
                $paths['writer_ready'], $paths['writer_attempt'], $paths['writer_go'],
            ]);
            $this->waitForFiles([$paths['writer_ready']], [$api, $writer]);
            touch($paths['writer_go']);
            $this->waitForFiles([$paths['writer_attempt']], [$api, $writer]);
            usleep(250_000);
            $this->assertTrue($writer->isRunning(), 'RolesController::update must remain blocked on the API-held role evidence lock.');

            touch($paths['api_release']);
            $apiResult = $this->waitForResult($api);
            $writerResult = $this->waitForResult($writer);

            $this->assertSame(201, $apiResult['status']);
            $this->assertSame('revoked', $writerResult['outcome']);
            $this->assertSame($beforeTickets + 1, ItTicket::query()->count());
            $this->assertSame($beforeReceipts + 1, ItApiRequest::query()->count());
            $this->assertSame(1, ItApiRequest::query()->where('idempotency_key', $key)->count());
            $ticketId = (int) $apiResult['id'];
            $this->assertSame(1, ItTicketCommandReceipt::query()->where('it_ticket_id', $ticketId)
                ->where('channel', 'service_api')->where('operation', ItTicketCommandReceipt::CREATE_OPERATION)->count());
            $this->assertFalse($actor->fresh()->canDo('it.manage'));

            $this->withHeaders($this->apiHeaders($credential['token'], (string) Str::uuid()))
                ->postJson('/api/v1/it/work-items', $this->createPayload($site, 'Role revocation must take effect'))
                ->assertUnauthorized()
                ->assertJsonPath('code', 'identity_inactive');
            $this->assertSame($beforeTickets + 1, ItTicket::query()->count());
            $this->assertSame($beforeReceipts + 1, ItApiRequest::query()->count());
        } finally {
            $this->stopWorkers([$api, $writer]);
            $this->removePaths($paths);
        }
    }

    private function assertSiteDeactivationWaitsForAnAuthorizedApiComment(): void
    {
        [$actor, , $site, $credential] = $this->apiActor('site');
        $ticket = $this->createApiTicket($credential['token'], $site, 'Site evidence race setup');
        // Prepare all authorization fixtures before the API owns any evidence
        // locks; fixture inserts must not wait on the command's locked gaps.
        $siteWriter = $this->siteWriter($site);
        $key = (string) Str::uuid();
        $beforeComments = $ticket->comments()->count();
        $barrier = $this->barrier('site');
        $paths = $this->racePaths($barrier);
        $api = null;
        $writer = null;

        try {
            $api = $this->startWorker('site-api', [
                (string) $ticket->id, $paths['api_ready'], $paths['api_release'],
            ], [
                'IT_API_AUTHORIZATION_TOKEN' => $credential['token'],
                'IT_API_AUTHORIZATION_KEY' => $key,
            ]);
            $this->waitForFiles([$paths['api_ready']], [$api]);

            $writer = $this->startWorker('site-writer', [
                (string) $siteWriter->id, (string) $site->id,
                $paths['writer_ready'], $paths['writer_attempt'], $paths['writer_go'],
            ]);
            $this->waitForFiles([$paths['writer_ready']], [$api, $writer]);
            touch($paths['writer_go']);
            $this->waitForFiles([$paths['writer_attempt']], [$api, $writer]);
            usleep(250_000);
            $this->assertTrue($writer->isRunning(), 'SiteController::toggleActive must remain blocked on the API-held Site evidence lock.');

            touch($paths['api_release']);
            $apiResult = $this->waitForResult($api);
            $writerResult = $this->waitForResult($writer);

            $this->assertSame(201, $apiResult['status']);
            $this->assertGreaterThan(0, (int) $apiResult['id']);
            $this->assertSame('deactivated', $writerResult['outcome']);
            $this->assertSame($beforeComments + 1, $ticket->fresh()->comments()->count());
            $this->assertSame(1, ItApiRequest::query()->where('idempotency_key', $key)->count());
            $this->assertSame(1, ItTicketCommandReceipt::query()
                ->where('it_ticket_comment_id', (int) $apiResult['id'])
                ->where('channel', 'service_api')->where('operation', ItTicketCommandReceipt::COMMENT_OPERATION)->count());
            $this->assertFalse($site->fresh()->is_active);

            $this->withHeaders($this->apiHeaders($credential['token'], (string) Str::uuid()))
                ->postJson("/api/v1/it/work-items/{$ticket->id}/comments", [
                    'body' => 'This must be denied after the Site becomes inactive.',
                ])
                ->assertNotFound();
            $this->assertSame($beforeComments + 1, $ticket->fresh()->comments()->count());
            $this->assertTrue($actor->fresh()->canDo('it.manage'));
        } finally {
            $this->stopWorkers([$api, $writer]);
            $this->removePaths($paths);
        }
    }

    /** @return array{0: User, 1: Role, 2: Site, 3: array{identity: ItServiceIdentity, token: string}} */
    private function apiActor(string $case): array
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $role = $this->role('api_authorization_'.$case, ['it.request', 'it.view', 'it.manage']);
        $actor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $actor->roles()->sync([$role->id]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id,
            'employee_number' => 'API-AUTH-'.$case.'-'.$actor->id,
            'work_email' => $actor->email,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $credential = app(ItServiceIdentityCredentialService::class)->create($actor, [
            'name' => 'Authorization race '.$case.' connector',
            'description' => 'Isolated authenticated authorization evidence race.',
            'actor_user_id' => $actor->id,
            'abilities' => ['work:create', 'work:comment'],
            'allowed_work_types' => ['incident'],
            'allowed_site_ids' => [$site->id],
            'allowed_fields' => [
                'create' => ['title', 'description', 'category', 'priority', 'work_type', 'site_id'],
                'read' => [],
            ],
            'require_signature' => false,
            'rate_limit_per_minute' => 60,
        ]);

        return [$actor->fresh(), $role, $site, $credential];
    }

    private function siteWriter(Site $site): User
    {
        $role = $this->role('api_authorization_site_writer', ['sites.update']);
        $writer = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $writer->roles()->sync([$role->id]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $writer->id,
            'employee_number' => 'API-AUTH-SITE-WRITER-'.$writer->id,
            'work_email' => $writer->email,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
            'created_by' => $writer->id,
            'updated_by' => $writer->id,
        ]);

        return $writer;
    }

    /** @param list<string> $permissionKeys */
    private function role(string $name, array $permissionKeys): Role
    {
        $role = Role::query()->create([
            'name' => $name,
            'label' => 'API authorization '.$name,
            'level' => 10,
            'type' => 'custom',
            'landing_route' => null,
            'description' => 'Disposable isolated authorization concurrency fixture.',
        ]);
        $permissionIds = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
        $this->assertCount(count($permissionKeys), $permissionIds);
        $role->permissions()->sync($permissionIds);

        return $role;
    }

    private function createApiTicket(string $token, Site $site, string $title): ItTicket
    {
        $key = (string) Str::uuid();
        $response = $this->withHeaders($this->apiHeaders($token, $key))
            ->postJson('/api/v1/it/work-items', $this->createPayload($site, $title))
            ->assertCreated();

        return ItTicket::query()->findOrFail((int) $response->json('data.id'));
    }

    /** @return array<string, mixed> */
    private function createPayload(Site $site, string $title): array
    {
        return [
            'title' => $title,
            'description' => 'An isolated authenticated authorization concurrency fixture.',
            'category' => 'network',
            'priority' => 'high',
            'work_type' => 'incident',
            'site_id' => $site->id,
        ];
    }

    /** @return array<string, string> */
    private function apiHeaders(string $token, string $key): array
    {
        return [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            'Idempotency-Key' => $key,
        ];
    }

    private function barrier(string $case): string
    {
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        return $directory.DIRECTORY_SEPARATOR.getenv('TEST_TOKEN').'-api-authorization-'.$case.'-'.Str::uuid();
    }

    /** @return array{api_ready: string, api_release: string, writer_ready: string, writer_attempt: string, writer_go: string} */
    private function racePaths(string $barrier): array
    {
        return [
            'api_ready' => $barrier.'-api.ready',
            'api_release' => $barrier.'-api.release',
            'writer_ready' => $barrier.'-writer.ready',
            'writer_attempt' => $barrier.'-writer.attempt',
            'writer_go' => $barrier.'-writer.go',
        ];
    }

    /** @param list<string> $arguments @param array<string, string> $environment */
    private function startWorker(string $mode, array $arguments, array $environment = []): Process
    {
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/It/api-authorization-concurrency-worker.php'),
            $mode,
            ...$arguments,
        ], base_path(), $environment, timeout: 45);
        $process->start();

        return $process;
    }

    /** @param list<string> $paths @param list<Process|null> $processes */
    private function waitForFiles(array $paths, array $processes): void
    {
        $deadline = microtime(true) + 30;
        while (count(array_filter($paths, 'is_file')) !== count($paths)) {
            foreach ($processes as $process) {
                if ($process !== null && ! $process->isRunning()) {
                    throw new RuntimeException('An API authorization concurrency worker stopped before its owned barrier.');
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('An API authorization concurrency worker barrier timed out.');
            }
            usleep(10_000);
        }
    }

    /** @return array<string, mixed> */
    private function waitForResult(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), 'An API authorization concurrency worker failed.');
        $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($result);

        return $result;
    }

    /** @param list<Process|null> $processes */
    private function stopWorkers(array $processes): void
    {
        foreach ($processes as $process) {
            if ($process?->isRunning()) {
                $process->stop(1);
            }
        }
    }

    /** @param array<string, string> $paths */
    private function removePaths(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
