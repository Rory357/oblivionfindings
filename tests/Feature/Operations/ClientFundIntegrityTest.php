<?php

use App\Domain\Finance\Jobs\PostClientFundJournalJob;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientFund;
use App\Models\ClientFundTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
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

function makeClientFundIntegrityUser(Site $site, array $permissionKeys = []): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-FUND-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Client Funds Officer',
        'position_role' => 'finance',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
    ]);
    grantClientFundIntegrityPermissions($user, $permissionKeys);

    return $user;
}

function makeClientFundIntegrityFund(?Site $site = null, string $balance = '100.00'): ClientFund
{
    $site ??= Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);

    return ClientFund::query()->create([
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

function startClientFundApprovalWorker(
    int $transactionId,
    int $checkerId,
    string $readyPath,
    string $releasePath,
    string $database,
): Process {
    $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$transaction = App\Models\ClientFundTransaction::query()->findOrFail((int) $argv[2]);
$checker = App\Models\User::query()->findOrFail((int) $argv[3]);
file_put_contents($argv[4], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[5])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the approval release barrier.');
    }
    usleep(10_000);
}
App\Models\ClientFundTransaction::unsetEventDispatcher();
try {
    $approved = $app->make(App\Domain\Finance\Services\ClientFundTransactionService::class)
        ->approve($transaction, $checker, 'Concurrent independent balance check.');
    echo json_encode(['result' => 'approved', 'id' => $approved->id], JSON_THROW_ON_ERROR);
} catch (Illuminate\Validation\ValidationException $exception) {
    echo json_encode(['result' => 'denied', 'errors' => $exception->errors()], JSON_THROW_ON_ERROR);
}
PHP;

    $process = new Process(
        [
            PHP_BINARY,
            '-r',
            $worker,
            base_path(),
            (string) $transactionId,
            (string) $checkerId,
            $readyPath,
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
    $site = Site::factory()->create();
    $fund = makeClientFundIntegrityFund($site);
    $viewer = makeClientFundIntegrityUser($site, ['clients.viewAny']);

    $payload = clientFundIntegrityTransactionPayload('credit', '10.00');

    $this->actingAs($viewer)
        ->post("/operations/client-funds/{$fund->id}/transactions", $payload)
        ->assertForbidden();

    expect($fund->transactions()->count())->toBe(0)
        ->and((string) $fund->fresh()->balance)->toBe('100.00');

    $manager = makeClientFundIntegrityUser($site, ['client_funds.manage']);

    $this->actingAs($manager)
        ->post("/operations/client-funds/{$fund->id}/transactions", $payload)
        ->assertRedirect();

    expect($fund->transactions()->count())->toBe(1)
        ->and((string) $fund->fresh()->balance)->toBe('110.00');
});

it('rejects a Client from another Site when creating a fund', function () {
    $assignedSite = Site::factory()->create();
    $manager = makeClientFundIntegrityUser($assignedSite, ['client_funds.manage']);
    $foreignClient = Client::factory()->create(['site_id' => Site::factory()->create()->id]);

    $this->actingAs($manager)
        ->from('/operations/client-funds/create')
        ->post('/operations/client-funds', [
            'client_id' => $foreignClient->id,
            'name' => 'Foreign client fund',
            'fund_type' => 'trust',
            'total_budget' => '100.00',
            'balance' => '25.00',
        ])
        ->assertForbidden();

    expect(ClientFund::query()
        ->where('client_id', $foreignClient->id)
        ->exists())->toBeFalse();
});

it('does not expose a fund from another Site by id', function () {
    $manager = makeClientFundIntegrityUser(Site::factory()->create(), ['client_funds.manage']);
    $foreignFund = makeClientFundIntegrityFund(Site::factory()->create());

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
    $site = Site::factory()->create();
    $manager = makeClientFundIntegrityUser($site, ['client_funds.manage']);
    $fund = makeClientFundIntegrityFund($site);
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
    $site = Site::factory()->create();
    $manager = makeClientFundIntegrityUser($site, ['client_funds.manage']);
    $fund = makeClientFundIntegrityFund($site);
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
    $site = Site::factory()->create();
    $manager = makeClientFundIntegrityUser($site, ['client_funds.manage']);
    $checker = makeClientFundIntegrityUser($site, ['client_funds.approve']);
    $fund = makeClientFundIntegrityFund($site);

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
    $debit = $fund->transactions()->where('transaction_type', 'debit')->firstOrFail();
    $this->actingAs($checker)
        ->post("/operations/client-funds/{$fund->id}/transactions/{$debit->id}/approve", [
            'reason' => 'Checked against the client receipt.',
        ])
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
    $site = Site::factory()->create();
    $manager = makeClientFundIntegrityUser($site, ['client_funds.manage']);
    $fund = makeClientFundIntegrityFund($site, balance: '0.00');

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
    $site = Site::factory()->create();
    $manager = makeClientFundIntegrityUser($site, ['client_funds.manage']);
    $client = Client::factory()->create(['site_id' => $site->id]);

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

    $site = Site::factory()->create();
    $actor = makeClientFundIntegrityUser($site, ['client_funds.manage']);
    $fund = makeClientFundIntegrityFund($site, balance: '100.00');
    $clientId = $fund->client_id;
    $siteId = $site->id;
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
            DB::table('hr_employee_profiles')->where('user_id', $actor->id)->delete();
            DB::table('users')->where('id', $actor->id)->delete();
            DB::table('sites')->where('id', $siteId)->delete();
        } finally {
            $connection->beginTransaction();
        }
    }
});

it('serializes simultaneous debit approvals so available balance cannot be overdrawn', function () {
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');

    $site = Site::factory()->create();
    $maker = makeClientFundIntegrityUser($site, ['client_funds.manage']);
    $checker = makeClientFundIntegrityUser($site, ['client_funds.approve']);
    $fund = makeClientFundIntegrityFund($site, balance: '100.00');
    $service = app(App\Domain\Finance\Services\ClientFundTransactionService::class);
    $debits = collect([
        $service->record($fund, $maker, clientFundIntegrityTransactionPayload('debit', '80.00')),
        $service->record($fund, $maker, clientFundIntegrityTransactionPayload('debit', '80.00')),
    ]);
    $database = $connection->getDatabaseName();
    $token = Str::uuid()->toString();
    $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."client-fund-approval-release-{$token}";
    $readyPaths = [
        sys_get_temp_dir().DIRECTORY_SEPARATOR."client-fund-approval-ready-a-{$token}",
        sys_get_temp_dir().DIRECTORY_SEPARATOR."client-fund-approval-ready-b-{$token}",
    ];
    $processes = [];
    $clientId = $fund->client_id;
    $siteId = $site->id;

    $connection->commit();

    try {
        foreach ($debits->values() as $index => $debit) {
            $processes[] = startClientFundApprovalWorker(
                $debit->id,
                $checker->id,
                $readyPaths[$index],
                $releasePath,
                $database,
            );
        }

        waitForClientFundIntegrityWorkers($readyPaths);
        touch($releasePath);

        $results = collect($processes)->map(function (Process $process): array {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        });

        expect($results->where('result', 'approved'))->toHaveCount(1)
            ->and($results->where('result', 'denied'))->toHaveCount(1)
            ->and((string) ClientFund::query()->findOrFail($fund->id)->balance)->toBe('20.00')
            ->and((string) ClientFund::query()->findOrFail($fund->id)->available_balance)->toBe('20.00')
            ->and(ClientFundTransaction::query()
                ->whereIn('id', $debits->pluck('id'))
                ->whereNotNull('balance_effect_applied_at')
                ->count())->toBe(1);
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }

        foreach ([...$readyPaths, $releasePath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        try {
            DB::table('client_fund_transactions')->where('client_fund_id', $fund->id)->delete();
            DB::table('client_funds')->where('id', $fund->id)->delete();
            DB::table('clients')->where('id', $clientId)->delete();
            DB::table('hr_employee_profiles')->whereIn('user_id', [$maker->id, $checker->id])->delete();
            DB::table('users')->whereIn('id', [$maker->id, $checker->id])->delete();
            DB::table('sites')->where('id', $siteId)->delete();
        } finally {
            $connection->beginTransaction();
        }
    }
});
