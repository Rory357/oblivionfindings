<?php

namespace Tests\Concurrency\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItServiceIdentityCredentialService;
use App\Models\AuditLog;
use App\Models\ItApiRequest;
use App\Models\ItServiceIdentity;
use App\Models\ItServiceIdentityCommandReceipt;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Standalone only: this class owns its wrapper-created schema and barriers. */
final class ItServiceIdentityConcurrencyTest extends TestCase
{
    private User $manager;

    private Site $site;

    public function createApplication()
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'oblivion_it_support_test'
            || preg_match('/^it_[a-f0-9]{16}$/D', (string) getenv('TEST_TOKEN')) !== 1 || getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('Use the isolated IT test wrapper for standalone service identity concurrency verification.');
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

        $this->manager = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
        $this->manager->roles()->sync(Role::query()->where('name', 'hr')->pluck('id'));
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->manager->id,
            'employee_number' => 'IDENTITY-CONCURRENCY-'.$this->manager->id,
            'work_email' => $this->manager->email,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
            'created_by' => $this->manager->id,
            'updated_by' => $this->manager->id,
        ]);
    }

    public function test_identity_commands_publish_once_and_fence_authenticated_old_credentials(): void
    {
        $credential = $this->credential('duplicate-rotate');
        $identity = $credential['identity'];
        $beforeHash = $identity->token_hash;
        $beforeVersion = $identity->configuration_version;
        $beforeAudits = AuditLog::query()->where('action', 'it.api.identity.rotated')->count();
        $requestUuid = (string) Str::uuid();

        $results = $this->rotateRace($identity, $requestUuid, $beforeVersion);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame([$identity->id, $identity->id], array_column($results, 'identity_id'));
        $this->assertSame([$beforeVersion + 1, $beforeVersion + 1], array_column($results, 'configuration_version'));
        $replayed = array_column($results, 'replayed');
        sort($replayed);
        $this->assertSame([false, true], $replayed);
        $credentialPresence = array_column($results, 'credential_present');
        sort($credentialPresence);
        $this->assertSame([false, true], $credentialPresence);

        $identity->refresh();
        $receipt = ItServiceIdentityCommandReceipt::query()
            ->where('actor_user_id', $this->manager->id)
            ->where('request_uuid', $requestUuid)
            ->sole();
        $this->assertNotSame($beforeHash, $identity->token_hash);
        $this->assertSame($beforeVersion + 1, $identity->configuration_version);
        $this->assertSame('rotate', $receipt->operation);
        $this->assertSame('confirmed', $receipt->state);
        $this->assertSame($identity->id, $receipt->service_identity_id);
        $this->assertSame($beforeVersion + 1, $receipt->result_version);
        $this->assertSame($beforeAudits + 1, AuditLog::query()->where('action', 'it.api.identity.rotated')->count());
        $this->assertSame(1, ItServiceIdentityCommandReceipt::query()->where('request_uuid', $requestUuid)->count());
        $this->assertCrossedSiteIssueCommandsUseOneGlobalSiteOrder();
        $this->assertAuthenticatedRequestIsRecheckedBeforePublication('rotate');
        $this->assertAuthenticatedRequestIsRecheckedBeforePublication('revoke');
        $this->assertLostRotationAcknowledgementIsRecoverableWithoutSecret();
    }

    private function assertLostRotationAcknowledgementIsRecoverableWithoutSecret(): void
    {
        $identity = $this->credential('lost-acknowledgement')['identity'];
        $beforeVersion = $identity->configuration_version;
        $beforeHash = $identity->token_hash;
        $beforeAudits = AuditLog::query()->where('action', 'it.api.identity.rotated')->count();
        $uuid = (string) Str::uuid();
        $payload = ['request_uuid' => $uuid, 'viewer_user_id' => $this->manager->id, 'expected_version' => $beforeVersion];
        $throwAfterCommit = true;
        Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use (&$throwAfterCommit): void {
            if ($throwAfterCommit && $event->connection->transactionLevel() === 0) {
                $throwAfterCommit = false;
                throw new RuntimeException('Synthetic lost identity rotation acknowledgement.');
            }
        });
        try {
            $this->actingAs($this->manager)->postJson("/it/setup/api-identities/{$identity->id}/rotate", $payload)->assertStatus(500);
        } finally {
            $throwAfterCommit = false;
        }
        $saved = $identity->fresh();
        $this->assertSame($beforeVersion + 1, $saved->configuration_version);
        $this->assertNotSame($beforeHash, $saved->token_hash);
        $this->assertSame(1, ItServiceIdentityCommandReceipt::query()->where('request_uuid', $uuid)->count());
        $this->actingAs($this->manager)->postJson('/it/setup/api-identities/commands/recover', [
            'request_uuid' => $uuid, 'viewer_user_id' => $this->manager->id,
        ])->assertOk()->assertJsonPath('state', 'confirmed')->assertJsonPath('credential', null)
            ->assertJsonPath('credential_unavailable', true)->assertJsonPath('configuration_version', $saved->configuration_version);
        $this->actingAs($this->manager)->postJson("/it/setup/api-identities/{$identity->id}/rotate", $payload)
            ->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('credential', null)->assertJsonPath('credential_unavailable', true);
        $this->assertSame($saved->token_hash, $identity->fresh()->token_hash);
        $this->assertSame($saved->configuration_version, $identity->fresh()->configuration_version);
        $this->assertSame($beforeAudits + 1, AuditLog::query()->where('action', 'it.api.identity.rotated')->count());
    }

    /**
     * Separate manager/execution pairs intentionally need the opposite Site order.
     * The parent holds Site 1 while both workers begin their current-evidence read:
     * a per-user Site lock would leave one worker holding Site 2 and deadlock after
     * Site 1 is released. The command must prelock the combined Site set in one order.
     */
    private function assertCrossedSiteIssueCommandsUseOneGlobalSiteOrder(): void
    {
        $secondSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
        [$firstManager, $firstExecution] = $this->crossedSitePair('first', $this->site, $secondSite);
        [$secondManager, $secondExecution] = $this->crossedSitePair('second', $secondSite, $this->site);
        $firstUuid = (string) Str::uuid();
        $secondUuid = (string) Str::uuid();
        $beforeIdentities = ItServiceIdentity::query()->count();
        $beforeReceipts = ItServiceIdentityCommandReceipt::query()->count();
        $beforeAudits = AuditLog::query()->where('action', 'it.api.identity.created')->count();
        $barrier = $this->barrier('crossed-site-issue');
        $paths = [
            $barrier.'-a.ready', $barrier.'-a.attempt',
            $barrier.'-b.ready', $barrier.'-b.attempt',
        ];
        $workers = [];
        DB::beginTransaction();
        Site::query()->whereKey($this->site->id)->lockForUpdate()->firstOrFail();

        try {
            $workers[] = $first = $this->startWorker('issue', [
                (string) $firstManager->id, (string) $firstExecution->id,
                $barrier.'-a.ready', $barrier.'-a.attempt',
            ], [
                'IT_SERVICE_IDENTITY_ISSUE_UUID' => $firstUuid,
                'IT_SERVICE_IDENTITY_ISSUE_PAYLOAD' => json_encode(
                    $this->issuePayload($firstManager, $firstExecution, $firstUuid), JSON_THROW_ON_ERROR,
                ),
            ]);
            $this->waitForFiles([$barrier.'-a.ready', $barrier.'-a.attempt'], $workers);

            $workers[] = $second = $this->startWorker('issue', [
                (string) $secondManager->id, (string) $secondExecution->id,
                $barrier.'-b.ready', $barrier.'-b.attempt',
            ], [
                'IT_SERVICE_IDENTITY_ISSUE_UUID' => $secondUuid,
                'IT_SERVICE_IDENTITY_ISSUE_PAYLOAD' => json_encode(
                    $this->issuePayload($secondManager, $secondExecution, $secondUuid), JSON_THROW_ON_ERROR,
                ),
            ]);
            $this->waitForFiles([$barrier.'-b.ready', $barrier.'-b.attempt'], $workers);
            usleep(250_000);
            $this->assertTrue($first->isRunning(), 'The first crossed-Site issue must remain blocked on Site 1.');
            $this->assertTrue($second->isRunning(), 'The second crossed-Site issue must reach the Site lock before release.');
            $releasedAt = microtime(true);
            DB::commit();

            $results = [$this->waitForResult($first), $this->waitForResult($second)];
            $this->assertSame([200, 200], array_column($results, 'status'));
            $this->assertSame([false, false], array_column($results, 'replayed'));
            $this->assertSame([true, true], array_column($results, 'credential_present'));
            foreach ($results as $result) {
                $this->assertLessThanOrEqual($releasedAt, $result['attempted_at']);
                $this->assertGreaterThanOrEqual($releasedAt, $result['completed_at']);
            }
            $identityIds = array_column($results, 'identity_id');
            $this->assertCount(2, array_unique($identityIds));
            $this->assertSame([1, 1], array_column($results, 'configuration_version'));
            $this->assertSame($beforeIdentities + 2, ItServiceIdentity::query()->count());
            $this->assertSame($beforeReceipts + 2, ItServiceIdentityCommandReceipt::query()->count());
            $this->assertSame($beforeAudits + 2, AuditLog::query()->where('action', 'it.api.identity.created')->count());
            $this->assertSame(1, ItServiceIdentityCommandReceipt::query()
                ->where('actor_user_id', $firstManager->id)->where('request_uuid', $firstUuid)->count());
            $this->assertSame(1, ItServiceIdentityCommandReceipt::query()
                ->where('actor_user_id', $secondManager->id)->where('request_uuid', $secondUuid)->count());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->stopWorkers($workers);
            $this->removePaths($paths);
        }
    }

    /** @param 'rotate'|'revoke' $operation */
    private function assertAuthenticatedRequestIsRecheckedBeforePublication(string $operation): void
    {
        $credential = $this->credential('old-token-'.$operation);
        $identity = $credential['identity'];
        $beforeTickets = ItTicket::query()->count();
        $beforeCommands = ItTicketCommandReceipt::query()->count();
        $beforeRequests = ItApiRequest::query()->count();
        $key = (string) Str::uuid();
        $barrier = $this->barrier('old-token-'.$operation);
        $paths = ['ready' => $barrier.'.ready', 'release' => $barrier.'.release'];
        $worker = null;

        try {
            $worker = $this->startWorker('old-token-api', [$paths['ready'], $paths['release']], [
                'IT_SERVICE_IDENTITY_API_TOKEN' => $credential['token'],
                'IT_SERVICE_IDENTITY_API_KEY' => $key,
                'IT_SERVICE_IDENTITY_API_PAYLOAD' => json_encode($this->apiPayload('Old credential '.$operation.' fence'), JSON_THROW_ON_ERROR),
            ]);
            $this->waitForFiles([$paths['ready']], [$worker]);

            $response = $this->actingAs($this->manager)
                ->postJson("/it/setup/api-identities/{$identity->id}/{$operation}", [
                    'request_uuid' => (string) Str::uuid(),
                    'viewer_user_id' => $this->manager->id,
                    'expected_version' => $identity->configuration_version,
                ])
                ->assertOk()
                ->assertJsonPath('operation', $operation)
                ->assertJsonPath('state', 'confirmed');

            touch($paths['release']);
            $result = $this->waitForResult($worker);
            $this->assertSame(401, $result['status']);
            $this->assertSame('credential_invalid', $result['code']);
            $this->assertSame($beforeTickets, ItTicket::query()->count());
            $this->assertSame($beforeCommands, ItTicketCommandReceipt::query()->count());
            $this->assertSame($beforeRequests, ItApiRequest::query()->count());
            $this->assertSame($operation === 'revoke', $identity->fresh()->revoked_at !== null);
        } finally {
            $this->stopWorkers([$worker]);
            $this->removePaths($paths);
        }
    }

    /** @return array{identity: ItServiceIdentity, token: string} */
    private function credential(string $case): array
    {
        return app(ItServiceIdentityCredentialService::class)->create($this->manager, [
            'name' => 'Identity concurrency '.$case,
            'description' => 'Standalone service identity concurrency fixture.',
            'actor_user_id' => $this->manager->id,
            'abilities' => ['work:create', 'work:read'],
            'allowed_work_types' => ['incident'],
            'allowed_site_ids' => [$this->site->id],
            'allowed_fields' => [
                'create' => ['title', 'description', 'category', 'priority', 'work_type', 'site_id'],
                'read' => ['category'],
                'update' => [],
            ],
            'require_signature' => false,
            'rate_limit_per_minute' => 60,
        ]);
    }

    /** @return array{0: User, 1: User} */
    private function crossedSitePair(string $label, Site $managerSite, Site $executionSite): array
    {
        $manager = $this->currentManager("{$label}-manager", $managerSite);
        $execution = $this->currentManager("{$label}-execution", $executionSite);

        return [$manager, $execution];
    }

    private function currentManager(string $label, Site $site): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $role = Role::query()->create([
            'name' => 'identity-concurrency-'.Str::uuid(),
            'label' => 'Identity concurrency '.$label,
            'level' => 50,
            'type' => 'custom',
        ]);
        $role->permissions()->attach(Permission::query()->whereIn('key', ['it.manage', 'it.view'])->pluck('id'));
        $user->roles()->attach($role);
        $this->assertTrue($user->fresh()->canDo('it.manage'));
        $this->assertTrue($user->fresh()->canDo('it.view'));
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'employee_number' => 'IDENTITY-CROSSED-'.$label.'-'.$user->id,
            'work_email' => $user->email,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return $user;
    }

    /** @return array<string, mixed> */
    private function issuePayload(User $manager, User $executionAccount, string $uuid): array
    {
        return [
            'request_uuid' => $uuid,
            'viewer_user_id' => $manager->id,
            'name' => 'Crossed Site identity '.$uuid,
            'description' => 'Exercises ordered Site evidence with disjoint current management pairs.',
            'actor_user_id' => $executionAccount->id,
            'abilities' => ['work:create'],
            'allowed_work_types' => ['incident'],
            'allowed_site_ids' => [],
            'create_fields' => ['title', 'category', 'priority', 'work_type'],
            'read_fields' => [],
            'update_fields' => [],
            'require_signature' => false,
            'rate_limit_per_minute' => 60,
            'expires_at' => null,
        ];
    }

    /** @return list<array{status: int, identity_id: int, configuration_version: int, replayed: bool, credential_present: bool, attempted_at: float, completed_at: float}> */
    private function rotateRace(ItServiceIdentity $identity, string $requestUuid, int $version): array
    {
        $barrier = $this->barrier('rotate');
        $paths = [];
        $workers = [];
        DB::beginTransaction();
        User::query()->whereKey($this->manager->id)->lockForUpdate()->firstOrFail();

        try {
            foreach ([0, 1] as $index) {
                $paths[] = $ready = $barrier."-{$index}.ready";
                $paths[] = $attempt = $barrier."-{$index}.attempt";
                $worker = $this->startWorker('rotate', [
                    (string) $this->manager->id,
                    (string) $identity->id,
                    $ready,
                    $attempt,
                    $barrier.'.release',
                ], [
                    'IT_SERVICE_IDENTITY_COMMAND_UUID' => $requestUuid,
                    'IT_SERVICE_IDENTITY_EXPECTED_VERSION' => (string) $version,
                ]);
                $workers[] = $worker;
            }
            $paths[] = $barrier.'.release';
            $this->waitForFiles([$barrier.'-0.ready', $barrier.'-1.ready'], $workers);
            touch($barrier.'.release');
            $this->waitForFiles([$barrier.'-0.attempt', $barrier.'-1.attempt'], $workers);
            $releasedAt = microtime(true);
            usleep(250_000);
            foreach ($workers as $worker) {
                $this->assertTrue($worker->isRunning(), 'Both identity commands must reach the current manager mutex before release.');
            }
            DB::commit();

            $results = [];
            foreach ($workers as $worker) {
                $result = $this->waitForResult($worker);
                $this->assertLessThanOrEqual($releasedAt, $result['attempted_at']);
                $this->assertGreaterThanOrEqual($releasedAt, $result['completed_at']);
                $results[] = $result;
            }

            return $results;
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->stopWorkers($workers);
            $this->removePaths($paths);
        }
    }

    /** @return array<string, mixed> */
    private function apiPayload(string $title): array
    {
        return [
            'title' => $title,
            'description' => 'This request has authenticated but must recheck the changed identity before publication.',
            'category' => 'network',
            'priority' => 'high',
            'work_type' => 'incident',
            'site_id' => $this->site->id,
        ];
    }

    private function barrier(string $case): string
    {
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        return $directory.DIRECTORY_SEPARATOR.getenv('TEST_TOKEN').'-service-identity-'.$case.'-'.Str::uuid();
    }

    /** @param list<string> $arguments @param array<string, string> $environment */
    private function startWorker(string $mode, array $arguments, array $environment = []): Process
    {
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/It/service-identity-concurrency-worker.php'),
            $mode,
            ...$arguments,
        ], base_path(), $environment, timeout: 45);
        $process->start();

        return $process;
    }

    /** @param list<string> $paths @param list<Process|null> $workers */
    private function waitForFiles(array $paths, array $workers): void
    {
        $deadline = microtime(true) + 30;
        while (count(array_filter($paths, 'is_file')) !== count($paths)) {
            foreach ($workers as $worker) {
                if ($worker !== null && ! $worker->isRunning()) {
                    throw new RuntimeException('A service identity concurrency worker stopped before its barrier.');
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('A service identity concurrency barrier timed out.');
            }
            usleep(10_000);
        }
    }

    /** @return array<string, mixed> */
    private function waitForResult(Process $worker): array
    {
        $worker->wait();
        $this->assertTrue($worker->isSuccessful(), 'A service identity concurrency worker failed.');
        $result = json_decode(trim($worker->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($result);

        return $result;
    }

    /** @param list<Process|null> $workers */
    private function stopWorkers(array $workers): void
    {
        foreach ($workers as $worker) {
            if ($worker?->isRunning()) {
                $worker->stop(1);
            }
        }
    }

    /** @param list<string>|array<string, string> $paths */
    private function removePaths(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
