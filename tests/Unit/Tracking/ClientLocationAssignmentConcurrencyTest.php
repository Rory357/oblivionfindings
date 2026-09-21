<?php

namespace Tests\Unit\Tracking;

use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\ClientGeofenceRule;
use App\Models\ClientGeofenceRuleVersion;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\Tracking\ClientLocationAccessService;
use App\Services\Tracking\ClientLocationZoneDraftService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ClientLocationWorkspaceFixture;
use Tests\Support\CommittedDatabaseTestCase;

class ClientLocationAssignmentConcurrencyTest extends CommittedDatabaseTestCase
{
    // Committed fixtures remain visible to both PDO connections. The support
    // base restores test data before the next ordinary suite case executes.
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Notification::fake();
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        config(['database.connections.zone_interleaving' => DB::connection()->getConfig()]);
        $other = DB::connection('zone_interleaving');
        $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $this->assertSame(DB::connection()->getDatabaseName(), $other->getDatabaseName());
        $this->assertNotSame(DB::connection()->getPdo(), $other->getPdo());
    }

    protected function tearDown(): void
    {
        DB::purge('zone_interleaving');
        parent::tearDown();
    }

    private function removeCareAssignment(Connection $connection, Client $client, User $actor): int
    {
        // Same pivot DELETE used by supportWorkers()->sync when removing this worker.
        return $connection->table('client_user')->where('client_id', $client->id)->where('user_id', $actor->id)->delete();
    }

    public function test_a_committed_removal_after_the_snapshot_prevents_a_draft_save(): void
    {
        extract(ClientLocationWorkspaceFixture::make(assignedOnly: true));
        $this->actingAs($actor);
        $other = DB::connection('zone_interleaving');
        $afterLocks = function () use ($other, $client, $actor): void {
            // The ordinary read fixes a REPEATABLE READ snapshot containing the pivot.
            $pivot = fn (Connection $connection) => $connection->table('client_user')->where('client_id', $client->id)->where('user_id', $actor->id);
            $this->assertTrue($pivot(DB::connection())->exists());
            $this->assertSame(1, $this->removeCareAssignment($other, $client, $actor));
            $this->assertFalse($pivot($other)->exists());
            $this->assertTrue($pivot(DB::connection())->exists(), 'An ordinary recheck still sees the stale snapshot.');
        };
        $this->app->instance(AuthorizationEvidenceLockService::class, new class($afterLocks) extends AuthorizationEvidenceLockService
        {
            public function __construct(private Closure $afterLocks) {}

            public function lockForUser(User|int $user, array $permissionKeys): User
            {
                $locked = parent::lockForUser($user, $permissionKeys);
                ($this->afterLocks)();

                return $locked;
            }
        });
        try {
            app(ClientLocationZoneDraftService::class)->save($actor, $client, $payload);
            $this->fail('A worker removed before the current relationship read must not save a draft.');
        } catch (AuthorizationException|HttpException $error) {
            $this->assertSame(403, $error instanceof AuthorizationException ? ($error->status() ?? 403) : $error->getStatusCode());
        }
        $this->assertSame(0, ClientGeofenceRule::where('client_id', $client->id)->count());
        $this->assertSame(0, ClientGeofenceRuleVersion::where('actor_id', $actor->id)->count());
    }

    public function test_a_removal_cannot_complete_while_the_save_holds_relationship_evidence(): void
    {
        extract(ClientLocationWorkspaceFixture::make(assignedOnly: true));
        $this->actingAs($actor);
        $other = DB::connection('zone_interleaving');
        $attempted = false;
        $afterRead = function () use ($other, $client, $actor, &$attempted): void {
            if ($attempted) {
                return;
            }
            $attempted = true;
            try {
                $this->removeCareAssignment($other, $client, $actor);
                $this->fail('The care pivot must remain locked until the save commits.');
            } catch (QueryException $error) {
                $this->assertSame(1205, (int) ($error->errorInfo[1] ?? 0));
            }
        };
        $access = new class($afterRead) extends ClientLocationAccessService
        {
            public function __construct(private Closure $afterRead) {}

            public function recheck(User $actor, Client $client, string $fingerprint, bool $manage = false, bool $lockedActor = false): DeviceAssignment
            {
                $assignment = parent::recheck($actor, $client, $fingerprint, $manage, $lockedActor);
                if ($lockedActor) {
                    ($this->afterRead)();
                }

                return $assignment;
            }
        };
        $service = new ClientLocationZoneDraftService($access);
        $saved = $service->save($actor, $client, $payload);
        $this->assertTrue($attempted);
        $this->assertSame(1, $saved['revision']);
        $this->assertSame(1, $this->removeCareAssignment($other, $client, $actor), 'Removal succeeds after the save commits.');
        try {
            $service->read($actor, $client);
            $this->fail('The removed worker must not read the saved draft.');
        } catch (AuthorizationException|HttpException $error) {
            $this->assertSame(403, $error instanceof AuthorizationException ? ($error->status() ?? 403) : $error->getStatusCode());
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
