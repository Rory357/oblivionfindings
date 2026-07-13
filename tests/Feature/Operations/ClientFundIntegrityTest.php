<?php

use App\Domain\Finance\Jobs\PostClientFundJournalJob;
use App\Models\Client;
use App\Models\ClientFund;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function grantClientFundIntegrityPermissions(User $user, array $permissionKeys): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'client_fund_integrity_'.$user->id],
        ['label' => 'Client Fund Integrity', 'level' => 50, 'type' => 'custom'],
    );

    foreach ($permissionKeys as $key) {
        Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'test', 'module' => 'Test'],
        );
    }

    $role->permissions()->sync(
        Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all(),
    );
    $user->roles()->syncWithoutDetaching([$role->id]);
}

function makeClientFundIntegrityFund(int $organizationId = 1, string $balance = '100.00'): ClientFund
{
    $client = Client::factory()->create(['organization_id' => $organizationId]);

    return ClientFund::query()->create([
        'organization_id' => $organizationId,
        'client_id' => $client->id,
        'fund_name' => 'Personal trust',
        'fund_type' => 'trust',
        'balance' => $balance,
        'is_active' => true,
    ]);
}

function clientFundIntegrityTransactionPayload(
    string $type,
    string $amount,
    ?string $idempotencyKey = null,
    string $description = 'Client fund movement',
): array {
    return [
        'type' => $type,
        'amount' => $amount,
        'description' => $description,
        'reference' => 'TEST-'.Str::upper(Str::random(8)),
        'idempotency_key' => $idempotencyKey ?? Str::uuid()->toString(),
    ];
}

function startClientFundIntegrityWorker(
    int $fundId,
    int $actorId,
    array $payload,
    string $readyPath,
    string $attemptPath,
    string $releasePath,
    string $database,
): Process {
    $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$fund = App\Models\ClientFund::query()->findOrFail((int) $argv[2]);
$actor = App\Models\User::query()->findOrFail((int) $argv[3]);
$payload = json_decode(base64_decode($argv[4], true), true, flags: JSON_THROW_ON_ERROR);
$connectionId = Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
file_put_contents($argv[5], (string) $connectionId);
$deadline = microtime(true) + 15;
while (! is_file($argv[7])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the concurrency release barrier.');
    }
    usleep(10_000);
}
file_put_contents($argv[6], 'attempting');
App\Models\ClientFundTransaction::unsetEventDispatcher();
$transaction = $app->make(App\Domain\Finance\Services\ClientFundTransactionService::class)
    ->record($fund, $actor, $payload);
echo json_encode([
    'id' => $transaction->id,
    'running_balance' => (string) $transaction->running_balance,
], JSON_THROW_ON_ERROR);
PHP;

    $process = new Process(
        [
            PHP_BINARY,
            '-r',
            $worker,
            base_path(),
            (string) $fundId,
            (string) $actorId,
            base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            $readyPath,
            $attemptPath,
            $releasePath,
        ],
        base_path(),
        [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => $database,
            'QUEUE_CONNECTION' => 'sync',
        ],
    );
    $process->setTimeout(30);
    $process->start();

    return $process;
}

/**
 * @param  array<int, string>  $readyPaths
 * @return array<int, int>
 */
function waitForClientFundIntegrityWorkers(array $readyPaths): array
{
    $deadline = microtime(true) + 15;

    do {
        if (collect($readyPaths)->every(fn (string $path): bool => is_file($path))) {
            return array_map(
                fn (string $path): int => (int) trim((string) file_get_contents($path)),
                $readyPaths,
            );
        }

        usleep(10_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('Timed out waiting for both client-fund workers to become ready.');
}

/** @param array<int, string> $paths */
function waitForClientFundIntegrityFiles(array $paths, string $message): void
{
    $deadline = microtime(true) + 15;

    do {
        if (collect($paths)->every(fn (string $path): bool => is_file($path))) {
            return;
        }

        usleep(10_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException($message);
}

beforeEach(function () {
    Queue::fake([PostClientFundJournalJob::class]);
});

it('requires the client funds capability instead of broad client visibility', function () {
    $fund = makeClientFundIntegrityFund();
    $viewer = User::factory()->create(['organization_id' => 1]);
    grantClientFundIntegrityPermissions($viewer, ['clients.viewAny']);

    $payload = clientFundIntegrityTransactionPayload('credit', '10.00');

    $this->actingAs($viewer)
        ->post("/operations/client-funds/{$fund->id}/transactions", $payload)
        ->assertForbidden();

    expect($fund->transactions()->count())->toBe(0)
        ->and((string) $fund->fresh()->balance)->toBe('100.00');

    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientFundIntegrityPermissions($manager, ['client_funds.manage']);

    $this->actingAs($manager)
        ->post("/operations/client-funds/{$fund->id}/transactions", $payload)
        ->assertRedirect();

    expect($fund->transactions()->count())->toBe(1)
        ->and((string) $fund->fresh()->balance)->toBe('110.00');
});

it('rejects a client from another organisation when creating a fund', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientFundIntegrityPermissions($manager, ['client_funds.manage']);
    $foreignClient = Client::factory()->create(['organization_id' => 2]);

    $this->actingAs($manager)
        ->from('/operations/client-funds/create')
        ->post('/operations/client-funds', [
            'client_id' => $foreignClient->id,
            'name' => 'Foreign client fund',
            'fund_type' => 'trust',
            'total_budget' => '100.00',
            'balance' => '25.00',
        ])
        ->assertSessionHasErrors('client_id');

    expect(ClientFund::query()
        ->where('client_id', $foreignClient->id)
        ->exists())->toBeFalse();
});

it('does not expose a fund from another organisation by id', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientFundIntegrityPermissions($manager, ['client_funds.manage']);
    $foreignFund = makeClientFundIntegrityFund(2);

    $this->actingAs($manager)
        ->post(
            "/operations/client-funds/{$foreignFund->id}/transactions",
            clientFundIntegrityTransactionPayload('credit', '10.00'),
        )
        ->assertNotFound();

    expect($foreignFund->transactions()->count())->toBe(0)
        ->and((string) $foreignFund->fresh()->balance)->toBe('100.00');
});

it('replays an idempotent transaction without changing the balance twice', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientFundIntegrityPermissions($manager, ['client_funds.manage']);
    $fund = makeClientFundIntegrityFund();
    $key = Str::uuid()->toString();
    $payload = clientFundIntegrityTransactionPayload('credit', '25.00', $key);

    $this->actingAs($manager)
        ->post("/operations/client-funds/{$fund->id}/transactions", $payload)
        ->assertRedirect();
    $this->actingAs($manager)
        ->post("/operations/client-funds/{$fund->id}/transactions", $payload)
        ->assertRedirect();

    $transactions = $fund->transactions()->orderBy('id')->get();

    expect($transactions)->toHaveCount(1)
        ->and((string) $transactions->first()->running_balance)->toBe('125.00')
        ->and((string) $fund->fresh()->balance)->toBe('125.00');
});

it('rejects reuse of an idempotency key with a different payload', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientFundIntegrityPermissions($manager, ['client_funds.manage']);
    $fund = makeClientFundIntegrityFund();
    $key = Str::uuid()->toString();

    $this->actingAs($manager)
        ->post(
            "/operations/client-funds/{$fund->id}/transactions",
            clientFundIntegrityTransactionPayload('credit', '10.00', $key, 'First payload'),
        )
        ->assertRedirect();

    $this->actingAs($manager)
        ->from("/operations/client-funds/{$fund->id}")
        ->post(
            "/operations/client-funds/{$fund->id}/transactions",
            clientFundIntegrityTransactionPayload('credit', '30.00', $key, 'Changed payload'),
        )
        ->assertSessionHasErrors('idempotency_key');

    expect($fund->transactions()->count())->toBe(1)
        ->and((string) $fund->fresh()->balance)->toBe('110.00');
});

it('preserves exact running balances across sequential writes', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientFundIntegrityPermissions($manager, ['client_funds.manage']);
    $fund = makeClientFundIntegrityFund();

    $this->actingAs($manager)
        ->post(
            "/operations/client-funds/{$fund->id}/transactions",
            clientFundIntegrityTransactionPayload('credit', '12.34'),
        )
        ->assertRedirect();
    $this->actingAs($manager)
        ->post(
            "/operations/client-funds/{$fund->id}/transactions",
            clientFundIntegrityTransactionPayload('debit', '5.67'),
        )
        ->assertRedirect();

    $runningBalances = $fund->transactions()
        ->orderBy('id')
        ->get()
        ->map(fn ($transaction) => (string) $transaction->running_balance)
        ->all();

    expect($runningBalances)->toBe(['112.34', '106.67'])
        ->and((string) $fund->fresh()->balance)->toBe('106.67');
});

it('uses exact decimal arithmetic for small currency amounts', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientFundIntegrityPermissions($manager, ['client_funds.manage']);
    $fund = makeClientFundIntegrityFund(balance: '0.00');

    $this->actingAs($manager)
        ->post(
            "/operations/client-funds/{$fund->id}/transactions",
            clientFundIntegrityTransactionPayload('credit', '0.10'),
        )
        ->assertRedirect();
    $this->actingAs($manager)
        ->post(
            "/operations/client-funds/{$fund->id}/transactions",
            clientFundIntegrityTransactionPayload('credit', '0.20'),
        )
        ->assertRedirect();

    $runningBalances = $fund->transactions()
        ->orderBy('id')
        ->get()
        ->map(fn ($transaction) => (string) $transaction->running_balance)
        ->all();

    expect($runningBalances)->toBe(['0.10', '0.30'])
        ->and((string) $fund->fresh()->balance)->toBe('0.30');
});

it('never creates a non-zero opening balance without a matching transaction', function () {
    $manager = User::factory()->create(['organization_id' => 1]);
    grantClientFundIntegrityPermissions($manager, ['client_funds.manage']);
    $client = Client::factory()->create(['organization_id' => 1]);

    $this->actingAs($manager)
        ->post('/operations/client-funds', [
            'client_id' => $client->id,
            'name' => 'New personal trust',
            'fund_type' => 'trust',
            'total_budget' => '100.00',
            'balance' => '25.00',
        ])
        ->assertRedirect();

    $fund = ClientFund::query()
        ->where('client_id', $client->id)
        ->where('fund_name', 'New personal trust')
        ->firstOrFail();
    $transactions = $fund->transactions()->orderBy('id')->get();

    if (bccomp((string) $fund->balance, '0.00', 2) === 0) {
        expect($transactions)->toHaveCount(0);

        return;
    }

    expect($transactions)->toHaveCount(1)
        ->and($transactions->first()->transaction_type)->toBe('credit')
        ->and((string) $transactions->first()->amount)->toBe((string) $fund->balance)
        ->and((string) $transactions->first()->running_balance)->toBe((string) $fund->balance);
});

it('serializes simultaneous fund movements without losing either balance update', function () {
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');

    $actor = User::factory()->create(['organization_id' => 1]);
    $fund = makeClientFundIntegrityFund(balance: '100.00');
    $clientId = $fund->client_id;
    $database = $connection->getDatabaseName();
    $token = Str::uuid()->toString();
    $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."client-fund-release-{$token}";
    $readyPaths = [
        sys_get_temp_dir().DIRECTORY_SEPARATOR."client-fund-ready-a-{$token}",
        sys_get_temp_dir().DIRECTORY_SEPARATOR."client-fund-ready-b-{$token}",
    ];
    $attemptPaths = [
        sys_get_temp_dir().DIRECTORY_SEPARATOR."client-fund-attempt-a-{$token}",
        sys_get_temp_dir().DIRECTORY_SEPARATOR."client-fund-attempt-b-{$token}",
    ];
    $processes = [];

    // RefreshDatabase wraps the test in one transaction. Commit only for this
    // test so independent workers can see the fixtures, then clean up and open
    // a replacement transaction for the framework teardown callback.
    $connection->commit();

    try {
        $connection->beginTransaction();
        ClientFund::query()->whereKey($fund->id)->lockForUpdate()->firstOrFail();

        $processes[] = startClientFundIntegrityWorker(
            $fund->id,
            $actor->id,
            clientFundIntegrityTransactionPayload('credit', '10.00'),
            $readyPaths[0],
            $attemptPaths[0],
            $releasePath,
            $database,
        );
        $processes[] = startClientFundIntegrityWorker(
            $fund->id,
            $actor->id,
            clientFundIntegrityTransactionPayload('credit', '20.00'),
            $readyPaths[1],
            $attemptPaths[1],
            $releasePath,
            $database,
        );

        waitForClientFundIntegrityWorkers($readyPaths);
        touch($releasePath);
        waitForClientFundIntegrityFiles(
            $attemptPaths,
            'Both client-fund workers did not reach the service call.',
        );
        usleep(250_000);
        foreach ($processes as $process) {
            $this->assertTrue(
                $process->isRunning(),
                trim($process->getErrorOutput()) ?: 'A worker exited before the parent row lock was released.',
            );
        }

        // Release both queued requests together. The service-level FOR UPDATE
        // lock must make the second request reload the first request's balance.
        $connection->commit();

        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue(
                $process->isSuccessful(),
                trim($process->getErrorOutput()) ?: 'A client-fund concurrency worker failed.',
            );
        }

        $transactions = ClientFund::query()
            ->findOrFail($fund->id)
            ->transactions()
            ->orderBy('id')
            ->get();
        $amounts = $transactions
            ->map(fn ($transaction): string => (string) $transaction->amount)
            ->sort()
            ->values()
            ->all();

        expect($transactions)->toHaveCount(2)
            ->and($amounts)->toBe(['10.00', '20.00'])
            ->and((string) $transactions->last()->running_balance)->toBe('130.00')
            ->and((string) ClientFund::query()->findOrFail($fund->id)->balance)->toBe('130.00');
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

        try {
            DB::table('client_fund_transactions')->where('client_fund_id', $fund->id)->delete();
            DB::table('client_funds')->where('id', $fund->id)->delete();
            DB::table('clients')->where('id', $clientId)->delete();
            DB::table('users')->where('id', $actor->id)->delete();
        } finally {
            $connection->beginTransaction();
        }
    }
});
