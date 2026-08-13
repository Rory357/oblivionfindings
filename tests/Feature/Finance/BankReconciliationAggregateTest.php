<?php

use App\Domain\Finance\Exceptions\BankReconciliationConflict;
use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankReconciliation;
use App\Domain\Finance\Models\FinBankReconciliationEvent;
use App\Domain\Finance\Models\FinBankReconciliationLine;
use App\Domain\Finance\Models\FinBankStatementImport;
use App\Domain\Finance\Models\FinBankTransaction;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinJournalLine;
use App\Domain\Finance\Services\BankReconciliationService;
use App\Domain\Finance\Services\JournalPostingService;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->actor = User::factory()->create(['organization_id' => 1]);
    $this->actingAs($this->actor);
    $this->bankGl = FinAccount::factory()->create([
        'organization_id' => 1,
        'code' => '1000',
        'name' => 'Operating bank',
        'type' => 'asset',
        'is_active' => true,
    ]);
    $this->counterAccount = FinAccount::factory()->create([
        'organization_id' => 1,
        'code' => '4000',
        'name' => 'Reconciliation counter account',
        'type' => 'revenue',
        'is_active' => true,
    ]);
    $this->feeAccount = FinAccount::factory()->create([
        'organization_id' => 1,
        'code' => '6000',
        'name' => 'Bank fees',
        'type' => 'expense',
        'is_active' => true,
    ]);
    FinFiscalPeriod::create([
        'organization_id' => 1,
        'name' => 'Current test period',
        'start_date' => now()->startOfYear()->toDateString(),
        'end_date' => now()->endOfYear()->toDateString(),
        'status' => 'open',
    ]);
    $this->bankAccount = FinBankAccount::factory()->create([
        'organization_id' => 1,
        'gl_account_id' => $this->bankGl->id,
        'opening_balance' => '0.00',
        'current_balance' => '0.00',
        'is_active' => true,
    ]);
    $this->service = app(BankReconciliationService::class);

    $this->startReconciliation = function (string $balance = '0.00', array $extra = []): FinBankReconciliation {
        return $this->service->startReconciliation(1, $this->bankAccount->id, [
            'statement_date' => now()->toDateString(),
            'statement_balance' => $balance,
            'created_by' => $this->actor->id,
            ...$extra,
        ]);
    };

    $this->makeTransaction = function (string $amount, ?FinBankAccount $account = null, array $extra = []): FinBankTransaction {
        $account ??= $this->bankAccount;

        return FinBankTransaction::create([
            'organization_id' => $account->organization_id,
            'bank_account_id' => $account->id,
            'transaction_date' => now()->toDateString(),
            'amount' => $amount,
            'description' => 'Statement effect '.$amount,
            'reference' => 'BANK-'.$amount,
            'source' => 'manual',
            'status' => 'unreconciled',
            ...$extra,
        ]);
    };

    $this->postBankJournalLine = function (string $amount, ?FinAccount $bankGl = null, int $organizationId = 1): FinJournalLine {
        $bankGl ??= $this->bankGl;
        $value = number_format(abs((float) $amount), 2, '.', '');
        $positive = (float) $amount >= 0;
        $journal = app(JournalPostingService::class)->createAndPost($organizationId, [
            'journal_date' => now()->toDateString(),
            'type' => 'standard',
            'reference' => 'TEST-'.uniqid(),
            'description' => 'Bank reconciliation fixture',
            'lines' => [
                [
                    'account_id' => $bankGl->id,
                    'description' => 'Bank side',
                    'debit' => $positive ? $value : 0,
                    'credit' => $positive ? 0 : $value,
                ],
                [
                    'account_id' => $this->counterAccount->id,
                    'description' => 'Counter side',
                    'debit' => $positive ? 0 : $value,
                    'credit' => $positive ? $value : 0,
                ],
            ],
        ])->load('lines');

        return $journal->lines->firstWhere('account_id', $bankGl->id);
    };
});

it('imports duplicate files and duplicate statement rows exactly once', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'fin-bank-recon-');
    file_put_contents($path, implode("\n", [
        'Date,Amount,Description,Reference',
        now()->toDateString().',25.50,Resident contribution,RC-100',
        now()->toDateString().',25.50,Resident contribution,RC-100',
    ]));

    try {
        $first = $this->service->importTransactions(1, $this->bankAccount->id, $path, 'csv', $this->actor->id, 'statement.csv');
        $retry = $this->service->importTransactions(1, $this->bankAccount->id, $path, 'csv', $this->actor->id, 'renamed-statement.csv');
    } finally {
        @unlink($path);
    }

    expect($first)->toMatchArray(['imported' => 1, 'skipped' => 1, 'duplicate' => false])
        ->and($retry)->toMatchArray(['imported' => 0, 'skipped' => 2, 'duplicate' => true])
        ->and(FinBankStatementImport::query()->count())->toBe(1)
        ->and(FinBankTransaction::query()->where('source', 'import')->count())->toBe(1)
        ->and(FinBankTransaction::query()->whereNotNull('import_row_fingerprint')->count())->toBe(1);
});

it('binds a reconciliation to its statement import and denies an unrelated account row', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'fin-bank-recon-bind-');
    file_put_contents($path, implode("\n", [
        'Date,Amount,Description,Reference',
        now()->toDateString().',40.00,Imported receipt,IMP-40',
    ]));
    try {
        $import = $this->service->importTransactions(1, $this->bankAccount->id, $path, 'csv', $this->actor->id, 'bound.csv');
    } finally {
        @unlink($path);
    }

    $reconciliation = ($this->startReconciliation)('40.00');
    $unrelated = ($this->makeTransaction)('40.00');
    $journalLine = ($this->postBankJournalLine)('40.00');

    expect($reconciliation->statement_import_id)->toBe($import['statement_import_id']);
    expect(fn () => $this->service->matchTransaction(
        $reconciliation->id,
        $unrelated->id,
        $journalLine->id,
        null,
        $this->actor->id,
        1,
    ))->toThrow(BankReconciliationConflict::class)
        ->and($unrelated->fresh()->status)->toBe('unreconciled')
        ->and($reconciliation->fresh()->version)->toBe(1);
});

it('denies foreign transaction journal and reconciliation ids without partial effects', function (): void {
    $otherBankGl = FinAccount::factory()->create([
        'organization_id' => 1,
        'code' => '1010',
        'name' => 'Other bank GL',
        'type' => 'asset',
        'is_active' => true,
    ]);
    $otherBank = FinBankAccount::factory()->create([
        'organization_id' => 1,
        'gl_account_id' => $otherBankGl->id,
        'is_active' => true,
    ]);
    $reconciliation = ($this->startReconciliation)('10.00');
    $foreignTransaction = ($this->makeTransaction)('10.00', $otherBank);
    $localLine = ($this->postBankJournalLine)('10.00');

    expect(fn () => $this->service->matchTransaction(
        $reconciliation->id,
        $foreignTransaction->id,
        $localLine->id,
        null,
        $this->actor->id,
        1,
    ))->toThrow(BankReconciliationConflict::class);

    $localTransaction = ($this->makeTransaction)('10.00');
    $foreignJournalLine = FinJournalLine::create([
        'journal_id' => $localLine->journal_id,
        'account_id' => $otherBankGl->id,
        'description' => 'Foreign account line',
        'debit' => '10.00',
        'credit' => '0.00',
    ]);
    expect(fn () => $this->service->matchTransaction(
        $reconciliation->id,
        $localTransaction->id,
        $foreignJournalLine->id,
        null,
        $this->actor->id,
        1,
    ))->toThrow(BankReconciliationConflict::class)
        ->and($reconciliation->fresh()->version)->toBe(1)
        ->and($foreignTransaction->fresh()->status)->toBe('unreconciled')
        ->and($localTransaction->fresh()->status)->toBe('unreconciled')
        ->and(FinBankReconciliationLine::query()->count())->toBe(0);
});

it('makes match retries idempotent and rejects stale competing versions', function (): void {
    $reconciliation = ($this->startReconciliation)('15.00');
    $transaction = ($this->makeTransaction)('15.00');
    $journalLine = ($this->postBankJournalLine)('15.00');
    $key = 'browser-request-1';

    $first = $this->service->matchTransaction(
        $reconciliation->id, $transaction->id, $journalLine->id, null, $this->actor->id, 1, $key,
    );
    $retry = $this->service->matchTransaction(
        $reconciliation->id, $transaction->id, $journalLine->id, null, $this->actor->id, 1, $key,
    );

    expect($retry->id)->toBe($first->id)
        ->and(FinBankReconciliationLine::query()->count())->toBe(1)
        ->and(FinBankReconciliationEvent::query()->where('event_type', 'matched')->count())->toBe(1)
        ->and($reconciliation->fresh()->version)->toBe(2);

    $secondTransaction = ($this->makeTransaction)('5.00');
    $secondJournalLine = ($this->postBankJournalLine)('5.00');
    expect(fn () => $this->service->matchTransaction(
        $reconciliation->id, $secondTransaction->id, $secondJournalLine->id, null, $this->actor->id, 1,
    ))->toThrow(BankReconciliationConflict::class)
        ->and($secondTransaction->fresh()->status)->toBe('unreconciled');
});

it('rolls back an adjustment journal and aggregate mutation when posting fails after write', function (): void {
    $reconciliation = ($this->startReconciliation)('-5.00');
    $transaction = ($this->makeTransaction)('-5.00');
    $journalCount = FinJournal::query()->count();
    $delegate = app(JournalPostingService::class);
    $failingPostingService = new class($delegate) extends JournalPostingService
    {
        public function __construct(private readonly JournalPostingService $delegate) {}

        public function createAndPost(?int $orgId, array $data): FinJournal
        {
            $this->delegate->createAndPost($orgId, $data);

            throw new RuntimeException('Injected failure after journal posting.');
        }
    };
    $service = new BankReconciliationService($failingPostingService);

    expect(fn () => $service->matchTransaction(
        $reconciliation->id,
        $transaction->id,
        null,
        $this->feeAccount->id,
        $this->actor->id,
        1,
    ))->toThrow(RuntimeException::class, 'Injected failure');

    expect(FinJournal::query()->count())->toBe($journalCount)
        ->and(FinBankReconciliationLine::query()->count())->toBe(0)
        ->and($transaction->fresh()->status)->toBe('unreconciled')
        ->and($reconciliation->fresh()->version)->toBe(1);
});

it('creates one durable linked reversal when an adjustment match is removed and retried', function (): void {
    $reconciliation = ($this->startReconciliation)('0.00');
    $transaction = ($this->makeTransaction)('-5.00');
    $line = $this->service->matchTransaction(
        $reconciliation->id,
        $transaction->id,
        null,
        $this->feeAccount->id,
        $this->actor->id,
        1,
        'adjustment-match',
    );
    $adjustmentJournal = FinJournal::findOrFail($line->adjustment_journal_id);

    $this->service->unmatchTransaction(
        $reconciliation->id, $line->id, $this->actor->id, 2, 'adjustment-unmatch',
    );
    $this->service->unmatchTransaction(
        $reconciliation->id, $line->id, $this->actor->id, 2, 'adjustment-unmatch',
    );

    $line->refresh();
    $adjustmentJournal->refresh();
    expect($line->is_matched)->toBeFalse()
        ->and($line->reversal_journal_id)->not->toBeNull()
        ->and($adjustmentJournal->reversed_by_journal_id)->toBe($line->reversal_journal_id)
        ->and(FinJournal::query()->where('reference', 'REV-'.$adjustmentJournal->journal_number)->count())->toBe(1)
        ->and(FinBankReconciliationEvent::query()->where('event_type', 'unmatched')->count())->toBe(1)
        ->and($transaction->fresh()->status)->toBe('unreconciled')
        ->and($reconciliation->fresh()->version)->toBe(3);
});

it('requires every statement effect and posted GL link before terminal completion', function (): void {
    $reconciliation = ($this->startReconciliation)('20.00');
    $matched = ($this->makeTransaction)('20.00');
    $unresolved = ($this->makeTransaction)('1.00');
    $journalLine = ($this->postBankJournalLine)('20.00');
    $line = $this->service->matchTransaction(
        $reconciliation->id, $matched->id, $journalLine->id, null, $this->actor->id, 1,
    );

    expect(fn () => $this->service->completeReconciliation($reconciliation, $this->actor->id, 2))
        ->toThrow(BankReconciliationConflict::class, 'Every statement line');

    $unresolvedJournalLine = ($this->postBankJournalLine)('1.00');
    $this->service->matchTransaction(
        $reconciliation->id, $unresolved->id, $unresolvedJournalLine->id, null, $this->actor->id, 2,
    );
    $journalLine->journal()->update(['reversed_by_journal_id' => $unresolvedJournalLine->journal_id]);

    expect(fn () => $this->service->completeReconciliation($reconciliation, $this->actor->id, 3))
        ->toThrow(BankReconciliationConflict::class, 'GL effects')
        ->and($reconciliation->fresh()->status)->toBe('in_progress')
        ->and($matched->fresh()->status)->toBe('matched')
        ->and($line->fresh()->is_matched)->toBeTrue();
});

it('keeps completion terminal and permits correction only through a linked evidence-backed amendment', function (): void {
    $reconciliation = ($this->startReconciliation)('30.00');
    $transaction = ($this->makeTransaction)('30.00');
    $journalLine = ($this->postBankJournalLine)('30.00');
    $line = $this->service->matchTransaction(
        $reconciliation->id, $transaction->id, $journalLine->id, null, $this->actor->id, 1,
    );
    $completed = $this->service->completeReconciliation($reconciliation, $this->actor->id, 2, 'complete-once');

    expect($completed->status)->toBe('completed')
        ->and($completed->version)->toBe(3)
        ->and($transaction->fresh()->status)->toBe('reconciled');
    expect(fn () => $this->service->unmatchTransaction(
        $completed->id, $line->id, $this->actor->id, 3,
    ))->toThrow(BankReconciliationConflict::class, 'completed');
    expect(fn () => $completed->fresh()->update(['notes' => 'silent edit']))
        ->toThrow(BankReconciliationConflict::class);
    expect(fn () => $transaction->fresh()->update(['status' => 'unreconciled']))
        ->toThrow(BankReconciliationConflict::class);

    $amendment = $this->service->createAmendment(
        $completed,
        $this->actor->id,
        'The bank supplied a corrected authoritative statement.',
        'signed-bank-letter-2026-08-14',
        3,
        'amend-once',
    );

    expect($completed->fresh()->status)->toBe('completed')
        ->and($completed->fresh()->integrity_state)->toBe('amended')
        ->and($amendment->status)->toBe('in_progress')
        ->and($amendment->amends_reconciliation_id)->toBe($completed->id)
        ->and($transaction->fresh()->reconciliation_id)->toBe($amendment->id)
        ->and(FinBankReconciliationEvent::query()->where('event_type', 'amendment_created')->count())->toBe(1);
});

it('serializes concurrent match and complete commands without duplicate or partial effects on MySQL', function (): void {
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');

    $reconciliation = ($this->startReconciliation)('30.00');
    $firstTransaction = ($this->makeTransaction)('10.00');
    $secondTransaction = ($this->makeTransaction)('20.00');
    $firstJournalLine = ($this->postBankJournalLine)('10.00');
    $secondJournalLine = ($this->postBankJournalLine)('20.00');
    $database = $connection->getDatabaseName();

    // Independent workers must see the fixtures. Each concurrent round creates
    // and releases its own account-row barrier transaction.
    $connection->commit();

    try {
        $firstRound = finBankReconConcurrentRound($connection, $database, $this->bankAccount->id, [
            [
                'action' => 'match',
                'reconciliation_id' => $reconciliation->id,
                'transaction_id' => $firstTransaction->id,
                'journal_line_id' => $firstJournalLine->id,
                'actor_id' => $this->actor->id,
                'expected_version' => 1,
                'key' => 'concurrent-first-a',
            ],
            [
                'action' => 'match',
                'reconciliation_id' => $reconciliation->id,
                'transaction_id' => $firstTransaction->id,
                'journal_line_id' => $firstJournalLine->id,
                'actor_id' => $this->actor->id,
                'expected_version' => 1,
                'key' => 'concurrent-first-b',
            ],
        ]);
        expect($firstRound)->toBe(['conflict', 'matched'])
            ->and(FinBankReconciliationLine::query()->count())->toBe(1)
            ->and($reconciliation->fresh()->version)->toBe(2);

        $matchCompleteRound = finBankReconConcurrentRound($connection, $database, $this->bankAccount->id, [
            [
                'action' => 'match',
                'reconciliation_id' => $reconciliation->id,
                'transaction_id' => $secondTransaction->id,
                'journal_line_id' => $secondJournalLine->id,
                'actor_id' => $this->actor->id,
                'expected_version' => 2,
                'key' => 'concurrent-second-match',
            ],
            [
                'action' => 'complete',
                'reconciliation_id' => $reconciliation->id,
                'actor_id' => $this->actor->id,
                'expected_version' => 2,
                'key' => 'concurrent-early-complete',
            ],
        ]);
        expect($matchCompleteRound)->toBe(['conflict', 'matched'])
            ->and($reconciliation->fresh()->status)->toBe('in_progress')
            ->and($reconciliation->fresh()->version)->toBe(3)
            ->and(FinBankReconciliationLine::query()->count())->toBe(2);

        $completionRound = finBankReconConcurrentRound($connection, $database, $this->bankAccount->id, [
            [
                'action' => 'complete',
                'reconciliation_id' => $reconciliation->id,
                'actor_id' => $this->actor->id,
                'expected_version' => 3,
                'key' => 'concurrent-complete-a',
            ],
            [
                'action' => 'complete',
                'reconciliation_id' => $reconciliation->id,
                'actor_id' => $this->actor->id,
                'expected_version' => 3,
                'key' => 'concurrent-complete-b',
            ],
        ]);
        expect($completionRound)->toBe(['completed', 'conflict'])
            ->and($reconciliation->fresh()->status)->toBe('completed')
            ->and($reconciliation->fresh()->version)->toBe(4)
            ->and(FinBankReconciliationEvent::query()->where('event_type', 'completed')->count())->toBe(1)
            ->and(FinBankReconciliationLine::query()->count())->toBe(2)
            ->and(FinBankTransaction::query()->where('status', 'reconciled')->count())->toBe(2);
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        DB::table('fin_bank_reconciliation_events')->delete();
        DB::table('fin_bank_reconciliation_lines')->delete();
        DB::table('fin_bank_reconciliation_integrity_reviews')->delete();
        DB::table('fin_bank_transactions')->delete();
        DB::table('fin_bank_reconciliations')->delete();
        DB::table('fin_bank_statement_imports')->delete();
        DB::table('fin_journal_lines')->delete();
        DB::table('fin_journals')->delete();
        DB::table('fin_fiscal_periods')->delete();
        DB::table('fin_bank_accounts')->delete();
        DB::table('fin_accounts')->delete();
        DB::table('audit_logs')->delete();
        DB::table('users')->where('id', $this->actor->id)->delete();
        $connection->beginTransaction();
    }
});

it('backfills ambiguous legacy reconciliation links into review without deleting financial records', function (): void {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $path = database_path('migrations/2026_08_14_000060_harden_bank_reconciliation_aggregate.php');
    /** @var Migration $migration */
    $migration = require $path;
    $migration->down();

    $reconciliationId = DB::table('fin_bank_reconciliations')->insertGetId([
        'organization_id' => 1,
        'bank_account_id' => $this->bankAccount->id,
        'statement_date' => now()->toDateString(),
        'statement_balance' => '12.00',
        'status' => 'in_progress',
        'created_by' => $this->actor->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $legacyRows = [
        [
            'organization_id' => 1,
            'bank_account_id' => $this->bankAccount->id,
            'transaction_date' => now()->toDateString(),
            'amount' => '12.00',
            'description' => 'Ambiguous legacy import',
            'reference' => 'LEGACY-12',
            'source' => 'import',
            'status' => 'unreconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'organization_id' => 1,
            'bank_account_id' => $this->bankAccount->id,
            'transaction_date' => now()->toDateString(),
            'amount' => '12.00',
            'description' => 'Ambiguous legacy import',
            'reference' => 'LEGACY-12',
            'source' => 'import',
            'status' => 'unreconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ];
    DB::table('fin_bank_transactions')->insert($legacyRows);
    $legacyTransactionId = DB::table('fin_bank_transactions')->min('id');
    DB::table('fin_bank_reconciliation_lines')->insert([
        'reconciliation_id' => $reconciliationId,
        'bank_transaction_id' => $legacyTransactionId,
        'journal_line_id' => null,
        'is_matched' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    expect(DB::table('fin_bank_transactions')->where('reference', 'LEGACY-12')->count())->toBe(2)
        ->and(DB::table('fin_bank_reconciliation_lines')->where('reconciliation_id', $reconciliationId)->count())->toBe(1)
        ->and(DB::table('fin_bank_reconciliation_integrity_reviews')
            ->where('issue_type', 'ambiguous_legacy_import_duplicates')->count())->toBe(1)
        ->and(DB::table('fin_bank_reconciliation_integrity_reviews')
            ->where('issue_type', 'missing_gl_link')->count())->toBe(1)
        ->and(DB::table('fin_bank_reconciliations')->where('id', $reconciliationId)->value('integrity_state'))
        ->toBe('review_required')
        ->and(DB::table('fin_bank_reconciliation_lines')
            ->where('reconciliation_id', $reconciliationId)->value('active_bank_transaction_id'))
        ->toBeNull();
});

/**
 * @param  array<int, array<string, int|string>>  $commands
 * @return array<int, string>
 */
function finBankReconConcurrentRound(
    ConnectionInterface $connection,
    string $database,
    int $bankAccountId,
    array $commands,
): array {
    $token = (string) Str::uuid();
    $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."fin-bank-recon-release-{$token}";
    $readyPaths = [];
    $attemptPaths = [];
    $processes = [];

    $connection->beginTransaction();
    FinBankAccount::query()->whereKey($bankAccountId)->lockForUpdate()->firstOrFail();

    try {
        foreach ($commands as $index => $command) {
            $readyPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."fin-bank-recon-ready-{$index}-{$token}";
            $attemptPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."fin-bank-recon-attempt-{$index}-{$token}";
            $processes[] = finBankReconStartWorker(
                $database,
                $command,
                $readyPaths[$index],
                $attemptPaths[$index],
                $releasePath,
            );
        }

        finBankReconWaitForFiles($readyPaths, 'Concurrent bank-reconciliation workers did not become ready.');
        touch($releasePath);
        finBankReconWaitForFiles($attemptPaths, 'Concurrent bank-reconciliation workers did not reach the command.');
        usleep(250_000);
        foreach ($processes as $process) {
            if (! $process->isRunning()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'A bank-reconciliation worker exited before lock release.');
            }
        }

        $connection->commit();
        $statuses = [];
        foreach ($processes as $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'A bank-reconciliation concurrency worker failed.');
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
function finBankReconStartWorker(
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
file_put_contents($argv[3], (string) Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
$deadline = microtime(true) + 15;
while (! is_file($argv[5])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the bank-reconciliation release barrier.');
    }
    usleep(10_000);
}
file_put_contents($argv[4], 'attempting');
try {
    $service = $app->make(App\Domain\Finance\Services\BankReconciliationService::class);
    if ($command['action'] === 'match') {
        $service->matchTransaction(
            (int) $command['reconciliation_id'],
            (int) $command['transaction_id'],
            (int) $command['journal_line_id'],
            null,
            (int) $command['actor_id'],
            (int) $command['expected_version'],
            (string) $command['key'],
        );
        $status = 'matched';
    } elseif ($command['action'] === 'complete') {
        $service->completeReconciliation(
            App\Domain\Finance\Models\FinBankReconciliation::findOrFail((int) $command['reconciliation_id']),
            (int) $command['actor_id'],
            (int) $command['expected_version'],
            (string) $command['key'],
        );
        $status = 'completed';
    } else {
        throw new RuntimeException('Unsupported bank-reconciliation worker action.');
    }
} catch (App\Domain\Finance\Exceptions\BankReconciliationConflict) {
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
function finBankReconWaitForFiles(array $paths, string $message): void
{
    $deadline = microtime(true) + 15;
    while (collect($paths)->contains(fn (string $path) => ! is_file($path))) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException($message);
        }
        usleep(10_000);
    }
}
