<?php

namespace Tests\Concurrency\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItServiceIdentityCredentialService;
use App\Models\AuditLog;
use App\Models\ItApiRequest;
use App\Models\ItEmailDelivery;
use App\Models\ItServiceIdentity;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketEvent;
use App\Models\ItTicketLink;
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

/** Run alone with run-isolated-it-tests.ps1: this class commits only its disposable schema. */
final class ItApiPublicationConcurrencyTest extends TestCase
{
    private User $actor;

    private Site $site;

    /** @var array{identity: ItServiceIdentity, token: string} */
    private array $credential;

    public function createApplication()
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'oblivion_it_support_test'
            || preg_match('/^it_[a-f0-9]{16}$/', (string) getenv('TEST_TOKEN')) !== 1 || getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('Use the isolated IT test wrapper for standalone API publication concurrency verification.');
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
        $this->actor = User::factory()->create([
            'role' => 'hr',
            'approved_at' => now(),
        ]);
        $this->actor->roles()->sync(Role::query()->where('name', 'hr')->pluck('id'));
        $this->site = Site::factory()->create();
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->actor->id,
            'employee_number' => 'API-PUBLICATION-'.$this->actor->id,
            'work_email' => $this->actor->email,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
        $this->credential = app(ItServiceIdentityCredentialService::class)->create($this->actor, [
            'name' => 'Concurrent publication connector',
            'description' => 'Creates controlled incident work under an isolated concurrency schema.',
            'actor_user_id' => $this->actor->id,
            'abilities' => ['work:create', 'work:update', 'work:link'],
            'allowed_work_types' => ['incident'],
            'allowed_site_ids' => [$this->site->id],
            'allowed_fields' => [
                'create' => ['title', 'description', 'category', 'priority', 'work_type', 'site_id'],
                'update' => ['category', 'subcategory', 'impact', 'urgency', 'priority'],
                'read' => [],
            ],
            'require_signature' => false,
            'rate_limit_per_minute' => 60,
        ]);
    }

    public function test_authenticated_concurrency_and_commit_acknowledgement_recovery_publish_once(): void
    {
        $key = (string) Str::uuid();
        $payload = $this->payload('Two workers must create one canonical work item.');
        $beforeTickets = ItTicket::query()->count();
        $beforeEvents = ItTicketEvent::query()->count();
        $beforeCreatedAudits = AuditLog::query()->where('action', 'it.api.work_item.created')->count();
        $beforeRequestAudits = AuditLog::query()->where('action', 'it.api.request')->count();

        $race = $this->race($key, $payload);
        $this->assertSame([201, 201], array_column($race, 'status'));
        $this->assertSame($race[0]['id'], $race[1]['id']);
        $replays = array_column($race, 'replayed');
        sort($replays);
        $this->assertSame([false, true], $replays);

        $receipt = ItApiRequest::query()->where('idempotency_key', $key)->sole();
        $this->assertSame($beforeTickets + 1, ItTicket::query()->count());
        $this->assertGreaterThan($beforeEvents, ItTicketEvent::query()->count());
        $this->assertSame($beforeCreatedAudits + 1, AuditLog::query()->where('action', 'it.api.work_item.created')->count());
        $this->assertSame($beforeRequestAudits + 1, AuditLog::query()->where('action', 'it.api.request')->count());
        $this->assertSame('committed', $receipt->execution_state);
        $this->assertSame(1, $receipt->attempt_count);
        $this->assertNotNull($receipt->completed_at);
        $this->assertSame($race[0]['id'], $receipt->ticket_id);
        $this->assertSame(1, ItTicketCommandReceipt::query()->where('it_ticket_id', $receipt->ticket_id)
            ->where('channel', 'service_api')->where('operation', ItTicketCommandReceipt::CREATE_OPERATION)->count());
        $this->assertSame(1, ItEmailDelivery::query()->where('it_ticket_id', $receipt->ticket_id)
            ->where('notification_type', 'ticket_created')->where('audience', 'receipt')->count());
        $this->assertDuplicateUpdatePublishesOnce(ItTicket::query()->findOrFail($receipt->ticket_id));
        $this->assertDuplicateRelationshipPublishesOnce(ItTicket::query()->findOrFail($receipt->ticket_id));
        $this->assertAfterCommitRecovery();
    }

    private function assertDuplicateUpdatePublishesOnce(ItTicket $ticket): void
    {
        $beforeVersion = (int) $ticket->lock_version;
        $beforeEvents = ItTicketEvent::query()->where('subject_type', $ticket->getMorphClass())
            ->where('subject_id', $ticket->id)->count();
        $beforeAudits = AuditLog::query()->where('action', 'it.api.work_item.updated')
            ->where('auditable_id', $ticket->id)->count();
        $key = (string) Str::uuid();
        $payload = [
            'expected_version' => $beforeVersion,
            'priority' => 'urgent',
            'priority_reason' => 'Duplicate worker publication requires an explicit urgent priority record.',
        ];

        $race = $this->race($key, $payload, 'PATCH', "/api/v1/it/work-items/{$ticket->id}");
        $this->assertSame([200, 200], array_column($race, 'status'));
        $this->assertSame([$ticket->id, $ticket->id], array_column($race, 'id'));
        $this->assertSame($race[0]['version'], $race[1]['version']);
        $this->assertGreaterThan($beforeVersion, $race[0]['version']);
        $replays = array_column($race, 'replayed');
        sort($replays);
        $this->assertSame([false, true], $replays);

        $saved = $ticket->fresh();
        $this->assertSame($race[0]['version'], $saved->lock_version);
        $this->assertSame('urgent', $saved->priority);
        $this->assertSame('override', $saved->priority_decision['mode']);
        $this->assertGreaterThan($beforeEvents, ItTicketEvent::query()->where('subject_type', $ticket->getMorphClass())
            ->where('subject_id', $ticket->id)->count());
        $this->assertSame($beforeAudits + 1, AuditLog::query()->where('action', 'it.api.work_item.updated')
            ->where('auditable_id', $ticket->id)->count());
        $request = ItApiRequest::query()->where('idempotency_key', $key)->sole();
        $this->assertSame('committed', $request->execution_state);
        $this->assertSame($ticket->id, $request->ticket_id);
        $this->assertSame(1, $request->attempt_count);
        $this->assertSame(1, ItTicketCommandReceipt::query()->where('it_ticket_id', $ticket->id)
            ->where('channel', 'service_api')->where('operation', ItTicketCommandReceipt::UPDATE_OPERATION)->count());
    }

    private function assertDuplicateRelationshipPublishesOnce(ItTicket $source): void
    {
        $target = ItTicket::factory()->create([
            'site_id' => $this->site->id,
            'requester_user_id' => $this->actor->id,
            'requested_for_user_id' => $this->actor->id,
            'status' => 'open',
            'workflow_state' => 'submitted',
            'lock_version' => 1,
            'is_sensitive' => false,
            'is_organisation_wide' => false,
            'work_type' => 'incident',
        ]);
        $sourceVersion = (int) $source->fresh()->lock_version;
        $targetVersion = (int) $target->lock_version;
        $key = (string) Str::uuid();
        $payload = [
            'target_ticket_id' => $target->id,
            'source_version' => $sourceVersion,
            'target_version' => $targetVersion,
            'action' => 'add',
            'relationship' => 'related_ticket',
        ];

        $race = $this->race($key, $payload, 'POST', "/api/v1/it/work-items/{$source->id}/relationships");
        $this->assertSame([200, 200], array_column($race, 'status'));
        $this->assertSame([$source->id, $source->id], array_column($race, 'id'));
        $this->assertSame($race[0]['version'], $race[1]['version']);
        $this->assertGreaterThan($sourceVersion, $race[0]['version']);
        $replays = array_column($race, 'replayed');
        sort($replays);
        $this->assertSame([false, true], $replays);

        $this->assertSame($race[0]['version'], $source->fresh()->lock_version);
        $this->assertSame($sourceVersion + 1, $source->fresh()->lock_version);
        $this->assertSame($targetVersion + 1, $target->fresh()->lock_version);
        $this->assertSame(2, ItTicketLink::query()->whereIn('ticket_id', [$source->id, $target->id])
            ->where('relationship', 'related_ticket')->count());
        $request = ItApiRequest::query()->where('idempotency_key', $key)->sole();
        $this->assertSame('committed', $request->execution_state);
        $this->assertSame($source->id, $request->ticket_id);
        $this->assertSame(1, $request->attempt_count);
        $this->assertSame(2, ItTicketEvent::query()->where('type', 'related_work_linked')
            ->where('subject_type', $source->getMorphClass())->whereIn('subject_id', [$source->id, $target->id])->count());
        $this->assertSame(1, ItTicketCommandReceipt::query()->where('it_ticket_id', $source->id)
            ->where('channel', 'service_api')->where('operation', ItTicketCommandReceipt::RELATIONSHIP_OPERATION)->count());
    }

    private function assertAfterCommitRecovery(): void
    {
        $key = (string) Str::uuid();
        $payload = $this->payload('Commit acknowledgement loss must not duplicate work.');
        $beforeTickets = ItTicket::query()->count();
        $throwAfterCommit = true;
        Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use (&$throwAfterCommit): void {
            if ($throwAfterCommit && $event->connection->transactionLevel() === 0) {
                $throwAfterCommit = false;
                throw new RuntimeException('Synthetic after-commit acknowledgement loss');
            }
        });

        try {
            $failed = $this->withHeaders($this->headers($key))
                ->postJson('/api/v1/it/work-items', $payload);
        } finally {
            $throwAfterCommit = false;
        }
        $failed->assertStatus(500);

        $receipt = ItApiRequest::query()->where('idempotency_key', $key)->sole();
        $this->assertSame('committed', $receipt->execution_state);
        $this->assertSame(1, $receipt->attempt_count);
        $this->assertNotNull($receipt->ticket_id);
        $this->assertSame(1, ItTicket::query()->whereKey($receipt->ticket_id)->count());

        $recovered = $this->withHeaders($this->headers($key))
            ->postJson('/api/v1/it/work-items', $payload)
            ->assertCreated()
            ->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($receipt->ticket_id, $recovered->json('data.id'));
        $this->assertSame($beforeTickets + 1, ItTicket::query()->count());
        $this->assertSame(1, ItApiRequest::query()->where('idempotency_key', $key)->count());
        $this->assertSame(1, $receipt->fresh()->attempt_count);
        $this->assertSame(1, ItTicketCommandReceipt::query()->where('it_ticket_id', $receipt->ticket_id)
            ->where('channel', 'service_api')->where('operation', ItTicketCommandReceipt::CREATE_OPERATION)->count());
        $this->assertSame(1, ItEmailDelivery::query()->where('it_ticket_id', $receipt->ticket_id)
            ->where('notification_type', 'ticket_created')->where('audience', 'receipt')->count());
    }

    /** @param array<string, mixed> $payload @return list<array{status: int, id: int, version: int, replayed: bool, attempted_at: float, completed_at: float}> */
    private function race(
        string $key,
        array $payload,
        string $method = 'POST',
        string $path = '/api/v1/it/work-items',
    ): array {
        $barrier = storage_path('framework/testing/'.getenv('TEST_TOKEN').'-api-publication-'.Str::uuid());
        if (! is_dir(dirname($barrier))) {
            mkdir(dirname($barrier), 0775, true);
        }
        $processes = [];
        $paths = [];
        DB::beginTransaction();
        User::query()->whereKey($this->actor->id)->lockForUpdate()->firstOrFail();
        try {
            foreach ([0, 1] as $index) {
                $ready = $barrier.'-'.$index.'.ready';
                $attempt = $barrier.'-'.$index.'.attempt';
                $paths[] = $ready;
                $paths[] = $attempt;
                $worker = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/It/api-publication-concurrency-worker.php'),
                    $ready,
                    $attempt,
                    $barrier.'.release',
                ], base_path(), [
                    'IT_API_PUBLICATION_TOKEN' => $this->credential['token'],
                    'IT_API_PUBLICATION_KEY' => $key,
                    'IT_API_PUBLICATION_PAYLOAD' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'IT_API_PUBLICATION_METHOD' => $method,
                    'IT_API_PUBLICATION_PATH' => $path,
                ], timeout: 45);
                $worker->start();
                $processes[] = $worker;
            }
            $this->barriers([$barrier.'-0.ready', $barrier.'-1.ready'], $processes);
            $paths[] = $barrier.'.release';
            touch($barrier.'.release');
            $this->barriers([$barrier.'-0.attempt', $barrier.'-1.attempt'], $processes);
            $releasedAt = microtime(true);
            usleep(250_000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Both authenticated workers must reach the actor mutex before release.');
            }
            DB::commit();

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), trim($process->getErrorOutput()));
                $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
                $this->assertIsArray($result);
                $this->assertLessThanOrEqual($releasedAt, $result['attempted_at']);
                $this->assertGreaterThanOrEqual($releasedAt, $result['completed_at']);
                $results[] = $result;
            }

            return $results;
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /** @param list<string> $paths @param list<Process> $processes */
    private function barriers(array $paths, array $processes): void
    {
        $deadline = microtime(true) + 30;
        while (count(array_filter($paths, 'is_file')) !== count($paths)) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    throw new RuntimeException('API publication worker stopped before its barrier: '.trim($process->getErrorOutput()));
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('API publication worker barrier timed out.');
            }
            usleep(10_000);
        }
    }

    /** @return array<string, mixed> */
    private function payload(string $title): array
    {
        return [
            'title' => $title,
            'description' => 'The isolated concurrency harness verifies one durable API publication.',
            'category' => 'network',
            'priority' => 'high',
            'work_type' => 'incident',
            'site_id' => $this->site->id,
        ];
    }

    /** @return array<string, string> */
    private function headers(string $key): array
    {
        return [
            'Authorization' => 'Bearer '.$this->credential['token'],
            'Accept' => 'application/json',
            'Idempotency-Key' => $key,
        ];
    }
}
