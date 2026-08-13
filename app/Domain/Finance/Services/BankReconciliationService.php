<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Exceptions\BankReconciliationConflict;
use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankReconciliation;
use App\Domain\Finance\Models\FinBankReconciliationEvent;
use App\Domain\Finance\Models\FinBankReconciliationLine;
use App\Domain\Finance\Models\FinBankStatementImport;
use App\Domain\Finance\Models\FinBankTransaction;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinJournalLine;
use App\Domain\Finance\Support\BankReconciliationMutationGuard;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class BankReconciliationService
{
    public function __construct(
        private readonly JournalPostingService $journalPostingService,
    ) {}

    /**
     * Import one statement file exactly once. The file and every normalised row
     * have durable account-scoped fingerprints; the account lock serialises two
     * different files containing the same row.
     */
    public function importTransactions(
        ?int $orgId,
        int $bankAccountId,
        string $filePath,
        string $format = 'csv',
        ?int $actorId = null,
        ?string $originalFilename = null,
    ): array {
        $actorId = $this->actorId($actorId);
        $fileFingerprint = hash_file('sha256', $filePath);
        if ($fileFingerprint === false) {
            throw new \RuntimeException('Unable to read the import file.');
        }

        try {
            [$rows, $invalidRows] = $this->readImportRows($filePath);

            return DB::transaction(function () use (
                $orgId,
                $bankAccountId,
                $format,
                $actorId,
                $originalFilename,
                $fileFingerprint,
                $rows,
                $invalidRows,
            ): array {
                $account = $this->lockAccount($bankAccountId, $orgId);
                $this->assertActorOrganization($actorId, $account->organization_id);

                $statementImport = FinBankStatementImport::query()
                    ->where('bank_account_id', $account->id)
                    ->where('file_fingerprint', $fileFingerprint)
                    ->lockForUpdate()
                    ->first();

                if ($statementImport?->status === 'completed') {
                    return [
                        'imported' => 0,
                        'skipped' => $statementImport->row_count,
                        'duplicate' => true,
                        'statement_import_id' => $statementImport->id,
                    ];
                }

                $statementImport ??= BankReconciliationMutationGuard::run(fn () => FinBankStatementImport::create([
                    'organization_id' => $account->organization_id,
                    'bank_account_id' => $account->id,
                    'file_fingerprint' => $fileFingerprint,
                    'original_filename' => $originalFilename ? basename($originalFilename) : null,
                    'format' => $format,
                    'status' => 'processing',
                    'imported_by' => $actorId,
                    'started_at' => now(),
                ]));

                BankReconciliationMutationGuard::run(fn () => $statementImport->update([
                    'status' => 'processing',
                    'failure_message' => null,
                    'failed_at' => null,
                    'imported_by' => $actorId,
                    'started_at' => $statementImport->started_at ?? now(),
                ]));

                $imported = 0;
                $skipped = $invalidRows;

                foreach ($rows as $row) {
                    $rowFingerprint = $this->rowFingerprint($account->id, $row);
                    $exists = FinBankTransaction::query()
                        ->where('bank_account_id', $account->id)
                        ->where('import_row_fingerprint', $rowFingerprint)
                        ->exists();

                    if ($exists) {
                        $skipped++;

                        continue;
                    }

                    BankReconciliationMutationGuard::run(fn () => FinBankTransaction::create([
                        'organization_id' => $account->organization_id,
                        'bank_account_id' => $account->id,
                        'statement_import_id' => $statementImport->id,
                        'import_row_fingerprint' => $rowFingerprint,
                        'import_row_number' => $row['row_number'],
                        'transaction_date' => $row['date'],
                        'amount' => $row['amount'],
                        'description' => $row['description'],
                        'reference' => $row['reference'] ?: null,
                        'source' => 'import',
                        'status' => 'unreconciled',
                    ]));
                    $imported++;
                }

                BankReconciliationMutationGuard::run(fn () => $statementImport->update([
                    'status' => 'completed',
                    'row_count' => count($rows) + $invalidRows,
                    'imported_count' => $imported,
                    'skipped_count' => $skipped,
                    'completed_at' => now(),
                ]));

                return [
                    'imported' => $imported,
                    'skipped' => $skipped,
                    'duplicate' => false,
                    'statement_import_id' => $statementImport->id,
                ];
            }, 3);
        } catch (Throwable $exception) {
            $ownedAccount = FinBankAccount::query()
                ->whereKey($bankAccountId)
                ->when($orgId !== null, fn ($query) => $query->where('organization_id', $orgId))
                ->first();
            if ($ownedAccount && User::query()
                ->whereKey($actorId)
                ->where('organization_id', $ownedAccount->organization_id)
                ->exists()) {
                BankReconciliationMutationGuard::run(fn () => FinBankStatementImport::query()->updateOrCreate(
                    ['bank_account_id' => $bankAccountId, 'file_fingerprint' => $fileFingerprint],
                    [
                        'organization_id' => $ownedAccount->organization_id,
                        'original_filename' => $originalFilename ? basename($originalFilename) : null,
                        'format' => $format,
                        'status' => 'failed',
                        'imported_by' => $actorId,
                        'started_at' => now(),
                        'failed_at' => now(),
                        'failure_message' => 'The statement import rolled back. No statement rows were applied.',
                    ],
                ));
            }

            throw $exception;
        }
    }

    public function startReconciliation(?int $orgId, int $bankAccountId, array $data): FinBankReconciliation
    {
        $actorId = $this->actorId($data['created_by'] ?? null);

        return DB::transaction(function () use ($orgId, $bankAccountId, $data, $actorId): FinBankReconciliation {
            $account = $this->lockAccount($bankAccountId, $orgId);
            $this->assertActorOrganization($actorId, $account->organization_id);
            $statementImport = $this->lockStatementImport(
                $account,
                isset($data['statement_import_id']) ? (int) $data['statement_import_id'] : null,
            );

            $previous = FinBankReconciliation::query()
                ->where('bank_account_id', $account->id)
                ->where('status', 'completed')
                ->where('statement_date', '<=', $data['statement_date'])
                ->orderByDesc('statement_date')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $reconciliation = BankReconciliationMutationGuard::run(fn () => FinBankReconciliation::create([
                'organization_id' => $account->organization_id,
                'bank_account_id' => $account->id,
                'statement_import_id' => $statementImport?->id,
                'statement_date' => $data['statement_date'],
                'statement_balance' => $data['statement_balance'],
                'starting_balance' => $previous?->statement_balance ?? $account->opening_balance,
                'status' => 'in_progress',
                'version' => 1,
                'integrity_state' => 'verified',
                'created_by' => $actorId,
            ]));

            $this->recordEvent($reconciliation, 'started', $actorId, 1, 'start:'.$reconciliation->id, [
                'statement_import_id' => $statementImport?->id,
                'statement_date' => (string) $data['statement_date'],
            ]);

            return $reconciliation;
        }, 3);
    }

    public function getUnreconciledItems(int $bankAccountId, ?int $reconciliationId = null): array
    {
        $bankAccount = FinBankAccount::findOrFail($bankAccountId);
        $reconciliation = $reconciliationId
            ? FinBankReconciliation::query()->where('bank_account_id', $bankAccountId)->findOrFail($reconciliationId)
            : null;

        $transactions = FinBankTransaction::query()
            ->where('bank_account_id', $bankAccountId)
            ->where('status', 'unreconciled')
            ->when($reconciliation?->statement_import_id, fn ($query, $importId) => $query->where('statement_import_id', $importId))
            ->when($reconciliation, fn ($query, FinBankReconciliation $item) => $query->where('transaction_date', '<=', $item->statement_date))
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $matchedJournalLineIds = FinBankReconciliationLine::query()
            ->where('is_matched', true)
            ->whereNotNull('active_journal_line_id')
            ->pluck('active_journal_line_id');

        $journalLines = FinJournalLine::query()
            ->where('account_id', $bankAccount->gl_account_id)
            ->whereHas('journal', fn ($query) => $query
                ->where('organization_id', $bankAccount->organization_id)
                ->where('status', 'posted')
                ->whereNull('reversed_by_journal_id'))
            ->when($matchedJournalLineIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $matchedJournalLineIds))
            ->with('journal:id,journal_number,journal_date,description,reference,status')
            ->orderBy('id')
            ->get()
            ->sortBy(fn (FinJournalLine $line) => $line->journal->journal_date)
            ->values();

        return ['transactions' => $transactions, 'journal_lines' => $journalLines];
    }

    public function suggestMatches(int $reconciliationId): array
    {
        $reconciliation = FinBankReconciliation::findOrFail($reconciliationId);
        if ($reconciliation->status !== 'in_progress' || $reconciliation->integrity_state !== 'verified') {
            return [];
        }

        $items = $this->getUnreconciledItems($reconciliation->bank_account_id, $reconciliation->id);
        $suggestions = [];
        $usedJournalLineIds = [];

        foreach ($items['transactions'] as $transaction) {
            $bestMatch = null;
            $bestConfidence = null;
            foreach ($items['journal_lines'] as $journalLine) {
                if (in_array($journalLine->id, $usedJournalLineIds, true)) {
                    continue;
                }
                $confidence = $this->calculateMatchConfidence($transaction, $journalLine);
                if ($confidence !== null && ($bestMatch === null || $this->confidenceRank($confidence) > $this->confidenceRank($bestConfidence))) {
                    $bestMatch = $journalLine;
                    $bestConfidence = $confidence;
                }
            }
            if ($bestMatch) {
                $suggestions[] = [
                    'bank_transaction_id' => $transaction->id,
                    'journal_line_id' => $bestMatch->id,
                    'confidence' => $bestConfidence,
                ];
                $usedJournalLineIds[] = $bestMatch->id;
            }
        }

        return $suggestions;
    }

    public function matchTransaction(
        int $reconciliationId,
        int $bankTransactionId,
        ?int $journalLineId,
        ?int $adjustmentAccountId = null,
        ?int $actorId = null,
        ?int $expectedVersion = null,
        ?string $idempotencyKey = null,
    ): FinBankReconciliationLine {
        $actorId = $this->actorId($actorId);

        return DB::transaction(function () use (
            $reconciliationId,
            $bankTransactionId,
            $journalLineId,
            $adjustmentAccountId,
            $actorId,
            $expectedVersion,
            $idempotencyKey,
        ): FinBankReconciliationLine {
            [$account, $reconciliation] = $this->lockAggregate($reconciliationId);
            $this->assertActorOrganization($actorId, $reconciliation->organization_id);
            $key = $this->idempotencyKey($idempotencyKey ?? implode(':', [
                'match', $reconciliationId, $expectedVersion ?? $reconciliation->version,
                $bankTransactionId, $journalLineId ?? 'adjustment', $adjustmentAccountId ?? 'none',
            ]));

            $replayed = FinBankReconciliationLine::query()
                ->where('reconciliation_id', $reconciliation->id)
                ->where('idempotency_key', $key)
                ->first();
            if ($replayed) {
                return $replayed;
            }

            $this->assertMutable($reconciliation, $expectedVersion);

            $transaction = FinBankTransaction::query()->whereKey($bankTransactionId)->lockForUpdate()->first();
            if (! $transaction
                || (int) $transaction->bank_account_id !== (int) $account->id
                || (int) $transaction->organization_id !== (int) $reconciliation->organization_id
                || ($reconciliation->statement_import_id !== null
                    && (int) $transaction->statement_import_id !== (int) $reconciliation->statement_import_id)) {
                throw BankReconciliationConflict::generic();
            }
            if ($transaction->status !== 'unreconciled' || $transaction->reconciliation_id !== null) {
                throw BankReconciliationConflict::generic();
            }

            $adjustmentJournalId = null;
            if ($journalLineId === null) {
                if ($adjustmentAccountId === null) {
                    throw new BankReconciliationConflict('Choose a posted bank journal line or an adjustment account before matching.');
                }
                [$journalLineId, $adjustmentJournalId] = $this->postAdjustmentJournal(
                    $reconciliation,
                    $account,
                    $transaction,
                    $adjustmentAccountId,
                );
            }

            $journalLine = FinJournalLine::query()->whereKey($journalLineId)->lockForUpdate()->first();
            $journal = $journalLine
                ? FinJournal::query()->whereKey($journalLine->journal_id)->lockForUpdate()->first()
                : null;
            if (! $journalLine || ! $journal
                || (int) $journalLine->account_id !== (int) $account->gl_account_id
                || (int) $journal->organization_id !== (int) $reconciliation->organization_id
                || $journal->status !== 'posted'
                || $journal->reversed_by_journal_id !== null
                || FinBankReconciliationLine::query()->where('active_journal_line_id', $journalLine->id)->exists()) {
                throw BankReconciliationConflict::generic();
            }

            $newVersion = (int) $reconciliation->version + 1;
            $line = BankReconciliationMutationGuard::run(fn () => FinBankReconciliationLine::create([
                'reconciliation_id' => $reconciliation->id,
                'bank_account_id' => $account->id,
                'bank_transaction_id' => $transaction->id,
                'journal_line_id' => $journalLine->id,
                'journal_id' => $journal->id,
                'adjustment_journal_id' => $adjustmentJournalId,
                'active_bank_transaction_id' => $transaction->id,
                'active_journal_line_id' => $journalLine->id,
                'is_matched' => true,
                'matched_by' => $actorId,
                'matched_at' => now(),
                'aggregate_version' => $newVersion,
                'idempotency_key' => $key,
            ]));

            if ($adjustmentJournalId !== null) {
                $journal->update([
                    'source_type' => FinBankReconciliationLine::class,
                    'source_id' => $line->id,
                ]);
            }

            BankReconciliationMutationGuard::run(function () use ($transaction, $reconciliation, $journalLine, $newVersion): void {
                $transaction->update([
                    'status' => 'matched',
                    'reconciliation_id' => $reconciliation->id,
                    'matched_journal_line_id' => $journalLine->id,
                ]);
                $reconciliation->update(['version' => $newVersion]);
            });

            $this->recordEvent($reconciliation, 'matched', $actorId, $newVersion, $key, [
                'reconciliation_line_id' => $line->id,
                'bank_transaction_id' => $transaction->id,
                'journal_id' => $journal->id,
                'adjustment_journal_id' => $adjustmentJournalId,
            ]);

            return $line;
        }, 3);
    }

    public function unmatchTransaction(
        int $reconciliationId,
        int $lineId,
        ?int $actorId = null,
        ?int $expectedVersion = null,
        ?string $idempotencyKey = null,
    ): void {
        $actorId = $this->actorId($actorId);

        DB::transaction(function () use ($reconciliationId, $lineId, $actorId, $expectedVersion, $idempotencyKey): void {
            [$account, $reconciliation] = $this->lockAggregate($reconciliationId);
            $this->assertActorOrganization($actorId, $reconciliation->organization_id);
            $key = $this->idempotencyKey($idempotencyKey ?? implode(':', [
                'unmatch', $reconciliationId, $expectedVersion ?? $reconciliation->version, $lineId,
            ]));
            if (FinBankReconciliationEvent::query()
                ->where('reconciliation_id', $reconciliation->id)
                ->where('idempotency_key', $key)->exists()) {
                return;
            }

            $this->assertMutable($reconciliation, $expectedVersion);

            $line = FinBankReconciliationLine::query()
                ->whereKey($lineId)
                ->where('reconciliation_id', $reconciliation->id)
                ->where('bank_account_id', $account->id)
                ->lockForUpdate()
                ->first();
            if (! $line || ! $line->is_matched) {
                throw BankReconciliationConflict::generic();
            }

            $transaction = FinBankTransaction::query()->whereKey($line->bank_transaction_id)->lockForUpdate()->first();
            if (! $transaction
                || (int) $transaction->bank_account_id !== (int) $account->id
                || (int) $transaction->reconciliation_id !== (int) $reconciliation->id
                || (int) $transaction->matched_journal_line_id !== (int) $line->journal_line_id
                || $transaction->status !== 'matched') {
                throw BankReconciliationConflict::generic();
            }

            $reversalJournalId = $line->reversal_journal_id;
            if ($line->adjustment_journal_id) {
                $adjustmentJournal = FinJournal::query()->whereKey($line->adjustment_journal_id)->lockForUpdate()->first();
                if (! $adjustmentJournal
                    || (int) $adjustmentJournal->organization_id !== (int) $reconciliation->organization_id
                    || $adjustmentJournal->source_type !== FinBankReconciliationLine::class
                    || (int) $adjustmentJournal->source_id !== (int) $line->id) {
                    throw BankReconciliationConflict::generic();
                }

                if ($adjustmentJournal->reversed_by_journal_id) {
                    $reversalJournalId = $adjustmentJournal->reversed_by_journal_id;
                } else {
                    $reversalJournalId = $this->journalPostingService
                        ->reverse($adjustmentJournal, 'Bank reconciliation match removed')
                        ->id;
                }
            }

            $newVersion = (int) $reconciliation->version + 1;
            BankReconciliationMutationGuard::run(function () use (
                $line,
                $transaction,
                $reconciliation,
                $actorId,
                $reversalJournalId,
                $newVersion,
            ): void {
                $line->update([
                    'is_matched' => false,
                    'active_bank_transaction_id' => null,
                    'active_journal_line_id' => null,
                    'reversal_journal_id' => $reversalJournalId,
                    'unmatched_by' => $actorId,
                    'unmatched_at' => now(),
                    'aggregate_version' => $newVersion,
                ]);
                $transaction->update([
                    'status' => 'unreconciled',
                    'reconciliation_id' => null,
                    'matched_journal_line_id' => null,
                ]);
                $reconciliation->update(['version' => $newVersion]);
            });

            $this->recordEvent($reconciliation, 'unmatched', $actorId, $newVersion, $key, [
                'reconciliation_line_id' => $line->id,
                'bank_transaction_id' => $transaction->id,
                'journal_id' => $line->journal_id,
                'reversal_journal_id' => $reversalJournalId,
            ]);
        }, 3);
    }

    public function completeReconciliation(
        FinBankReconciliation $reconciliation,
        int $userId,
        ?int $expectedVersion = null,
        ?string $idempotencyKey = null,
    ): FinBankReconciliation {
        return DB::transaction(function () use ($reconciliation, $userId, $expectedVersion, $idempotencyKey): FinBankReconciliation {
            [$account, $locked] = $this->lockAggregate($reconciliation->id);
            $this->assertActorOrganization($userId, $locked->organization_id);
            $key = $this->idempotencyKey($idempotencyKey ?? implode(':', [
                'complete', $locked->id, $expectedVersion ?? $locked->version,
            ]));

            if ($locked->status === 'completed') {
                if (FinBankReconciliationEvent::query()
                    ->where('reconciliation_id', $locked->id)
                    ->where('idempotency_key', $key)->exists()) {
                    return $locked;
                }
                throw BankReconciliationConflict::terminal();
            }
            $this->assertMutable($locked, $expectedVersion);

            $lines = FinBankReconciliationLine::query()
                ->where('reconciliation_id', $locked->id)
                ->where('is_matched', true)
                ->lockForUpdate()
                ->get();

            $matchedTransactions = collect();
            foreach ($lines as $line) {
                $transaction = FinBankTransaction::query()->whereKey($line->bank_transaction_id)->lockForUpdate()->first();
                $journalLine = FinJournalLine::query()->whereKey($line->journal_line_id)->lockForUpdate()->first();
                $journal = $journalLine
                    ? FinJournal::query()->whereKey($journalLine->journal_id)->lockForUpdate()->first()
                    : null;
                if (! $transaction || ! $journalLine || ! $journal
                    || (int) $line->bank_account_id !== (int) $account->id
                    || (int) $transaction->bank_account_id !== (int) $account->id
                    || (int) $transaction->reconciliation_id !== (int) $locked->id
                    || (int) $transaction->matched_journal_line_id !== (int) $journalLine->id
                    || $transaction->status !== 'matched'
                    || (int) $journalLine->account_id !== (int) $account->gl_account_id
                    || (int) $line->journal_id !== (int) $journal->id
                    || (int) $journal->organization_id !== (int) $locked->organization_id
                    || $journal->status !== 'posted'
                    || $journal->reversed_by_journal_id !== null
                    || ($line->adjustment_journal_id && (int) $line->adjustment_journal_id !== (int) $journal->id)) {
                    throw new BankReconciliationConflict('Reconciliation links or GL effects need review before completion.');
                }
                $matchedTransactions->push($transaction);
            }

            if ($locked->statement_import_id) {
                $statementImport = FinBankStatementImport::query()->whereKey($locked->statement_import_id)->lockForUpdate()->first();
                $unresolved = FinBankTransaction::query()
                    ->where('statement_import_id', $locked->statement_import_id)
                    ->where(function ($query) use ($locked): void {
                        $query->where('status', '!=', 'matched')
                            ->orWhere('reconciliation_id', '!=', $locked->id)
                            ->orWhereNull('reconciliation_id');
                    })
                    ->lockForUpdate()
                    ->exists();
                if (! $statementImport
                    || $statementImport->status !== 'completed'
                    || (int) $statementImport->bank_account_id !== (int) $account->id
                    || (int) $statementImport->organization_id !== (int) $locked->organization_id
                    || $unresolved) {
                    throw new BankReconciliationConflict('Every imported statement line must be resolved before completion.');
                }
            } else {
                $unresolved = FinBankTransaction::query()
                    ->where('bank_account_id', $account->id)
                    ->where('transaction_date', '<=', $locked->statement_date)
                    ->where('status', 'unreconciled')
                    ->lockForUpdate()
                    ->exists();
                if ($unresolved) {
                    throw new BankReconciliationConflict('Every statement line through the statement date must be resolved before completion.');
                }
            }

            $calculatedBalance = (float) $locked->starting_balance
                + $matchedTransactions->sum(fn (FinBankTransaction $transaction): float => (float) $transaction->amount);
            $difference = abs($calculatedBalance - (float) $locked->statement_balance);
            if ($difference > 0.01) {
                throw new BankReconciliationConflict(
                    'Reconciliation is not balanced. Statement balance: $'.number_format((float) $locked->statement_balance, 2)
                    .', calculated balance: $'.number_format($calculatedBalance, 2)
                    .', difference: $'.number_format($difference, 2).'.'
                );
            }

            $newVersion = (int) $locked->version + 1;
            BankReconciliationMutationGuard::run(function () use ($locked, $account, $matchedTransactions, $calculatedBalance, $userId, $newVersion): void {
                $locked->update([
                    'calculated_balance' => $calculatedBalance,
                    'status' => 'completed',
                    'completed_at' => now(),
                    'completed_by' => $userId,
                    'version' => $newVersion,
                    'recovery_message' => null,
                ]);
                FinBankTransaction::query()
                    ->whereIn('id', $matchedTransactions->pluck('id'))
                    ->get()
                    ->each(fn (FinBankTransaction $transaction) => $transaction->update(['status' => 'reconciled']));
                $account->update(['current_balance' => $calculatedBalance]);
            });

            $this->recordEvent($locked, 'completed', $userId, $newVersion, $key, [
                'calculated_balance' => number_format($calculatedBalance, 2, '.', ''),
                'matched_line_count' => $lines->count(),
            ]);

            return $locked->fresh();
        }, 3);
    }

    /**
     * Open a linked correction aggregate. The completed record remains terminal;
     * its active matches are superseded and copied to the evidence-backed amendment.
     */
    public function createAmendment(
        FinBankReconciliation $reconciliation,
        int $actorId,
        string $reason,
        string $evidenceReference,
        ?int $expectedVersion = null,
        ?string $idempotencyKey = null,
    ): FinBankReconciliation {
        $reason = trim($reason);
        $evidenceReference = trim($evidenceReference);
        if (mb_strlen($reason) < 10 || mb_strlen($evidenceReference) < 3) {
            throw new BankReconciliationConflict('A correction reason and authoritative evidence reference are required.');
        }

        return DB::transaction(function () use (
            $reconciliation,
            $actorId,
            $reason,
            $evidenceReference,
            $expectedVersion,
            $idempotencyKey,
        ): FinBankReconciliation {
            [$account, $locked] = $this->lockAggregate($reconciliation->id);
            $this->assertActorOrganization($actorId, $locked->organization_id);
            $key = $this->idempotencyKey($idempotencyKey ?? implode(':', [
                'amend', $locked->id, $expectedVersion ?? $locked->version, hash('sha256', $evidenceReference),
            ]));
            $priorEvent = FinBankReconciliationEvent::query()
                ->where('reconciliation_id', $locked->id)
                ->where('idempotency_key', $key)
                ->first();
            if ($priorEvent) {
                $amendmentId = $priorEvent->provenance['amendment_reconciliation_id'] ?? null;

                return FinBankReconciliation::findOrFail($amendmentId);
            }
            if ($locked->status !== 'completed' || $locked->integrity_state !== 'verified') {
                throw BankReconciliationConflict::generic();
            }
            if ($expectedVersion !== null && (int) $locked->version !== $expectedVersion) {
                throw BankReconciliationConflict::stale();
            }
            if (FinBankReconciliation::query()
                ->where('amends_reconciliation_id', $locked->id)
                ->where('status', 'in_progress')
                ->exists()) {
                throw new BankReconciliationConflict('This reconciliation already has a correction in progress.');
            }

            $oldLines = FinBankReconciliationLine::query()
                ->where('reconciliation_id', $locked->id)
                ->where('is_matched', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $transactions = FinBankTransaction::query()
                ->whereIn('id', $oldLines->pluck('bank_transaction_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $amendment = BankReconciliationMutationGuard::run(fn () => FinBankReconciliation::create([
                'organization_id' => $locked->organization_id,
                'bank_account_id' => $account->id,
                'statement_import_id' => $locked->statement_import_id,
                'amends_reconciliation_id' => $locked->id,
                'statement_date' => $locked->statement_date,
                'statement_balance' => $locked->statement_balance,
                'starting_balance' => $locked->starting_balance,
                'status' => 'in_progress',
                'version' => 1,
                'integrity_state' => 'verified',
                'notes' => $reason,
                'created_by' => $actorId,
            ]));

            BankReconciliationMutationGuard::run(function () use ($oldLines, $transactions, $locked, $amendment, $actorId): void {
                foreach ($oldLines as $oldLine) {
                    $oldLine->update([
                        'active_bank_transaction_id' => null,
                        'active_journal_line_id' => null,
                        'aggregate_version' => (int) $locked->version + 1,
                    ]);
                    $newLine = FinBankReconciliationLine::create([
                        'reconciliation_id' => $amendment->id,
                        'bank_account_id' => $oldLine->bank_account_id,
                        'bank_transaction_id' => $oldLine->bank_transaction_id,
                        'journal_line_id' => $oldLine->journal_line_id,
                        'journal_id' => $oldLine->journal_id,
                        'adjustment_journal_id' => $oldLine->adjustment_journal_id,
                        'active_bank_transaction_id' => $oldLine->bank_transaction_id,
                        'active_journal_line_id' => $oldLine->journal_line_id,
                        'is_matched' => true,
                        'matched_by' => $actorId,
                        'matched_at' => now(),
                        'aggregate_version' => 1,
                        'idempotency_key' => hash('sha256', 'amend-copy:'.$amendment->id.':'.$oldLine->id),
                    ]);
                    if ($newLine->adjustment_journal_id) {
                        FinJournal::query()->whereKey($newLine->adjustment_journal_id)->update([
                            'source_type' => FinBankReconciliationLine::class,
                            'source_id' => $newLine->id,
                        ]);
                    }
                    $transactions[$oldLine->bank_transaction_id]->update([
                        'status' => 'matched',
                        'reconciliation_id' => $amendment->id,
                        'matched_journal_line_id' => $newLine->journal_line_id,
                    ]);
                }
                $locked->update([
                    'version' => (int) $locked->version + 1,
                    'integrity_state' => 'amended',
                    'recovery_message' => 'A linked evidence-backed correction is in progress.',
                ]);
            });

            $this->recordEvent($locked, 'amendment_created', $actorId, (int) $locked->version, $key, [
                'amendment_reconciliation_id' => $amendment->id,
                'reason' => $reason,
                'evidence_reference' => $evidenceReference,
            ]);
            $this->recordEvent($amendment, 'started_as_amendment', $actorId, 1, 'amendment-start:'.$amendment->id, [
                'amends_reconciliation_id' => $locked->id,
                'evidence_reference' => $evidenceReference,
            ]);

            return $amendment;
        }, 3);
    }

    private function postAdjustmentJournal(
        FinBankReconciliation $reconciliation,
        FinBankAccount $bankAccount,
        FinBankTransaction $transaction,
        int $adjustmentAccountId,
    ): array {
        $adjustmentAccount = FinAccount::query()->whereKey($adjustmentAccountId)->lockForUpdate()->first();
        if (! $adjustmentAccount
            || (int) $adjustmentAccount->organization_id !== (int) $reconciliation->organization_id
            || ! $adjustmentAccount->is_active
            || ! in_array($adjustmentAccount->type, ['expense', 'income', 'revenue'], true)
            || ! $bankAccount->gl_account_id) {
            throw BankReconciliationConflict::generic();
        }

        $amount = number_format(abs((float) $transaction->amount), 2, '.', '');
        $isOutflow = (float) $transaction->amount < 0;
        $adjustmentLine = ['account_id' => $adjustmentAccount->id, 'description' => $transaction->description ?: 'Bank adjustment'];
        $bankLine = ['account_id' => $bankAccount->gl_account_id, 'description' => $transaction->description ?: 'Bank adjustment'];
        if ($isOutflow) {
            $adjustmentLine += ['debit' => $amount, 'credit' => 0];
            $bankLine += ['debit' => 0, 'credit' => $amount];
        } else {
            $bankLine += ['debit' => $amount, 'credit' => 0];
            $adjustmentLine += ['debit' => 0, 'credit' => $amount];
        }

        // The line does not exist yet, so the provisional source id is the bank
        // transaction. It is replaced with the canonical match id immediately
        // after the line is created by the caller.
        $journal = $this->journalPostingService->createAndPost($reconciliation->organization_id, [
            'journal_date' => $transaction->transaction_date->toDateString(),
            'type' => 'standard',
            'reference' => $transaction->reference ?: "REC-{$reconciliation->id}-{$transaction->id}",
            'description' => 'Bank reconciliation adjustment: '.($transaction->description ?: "transaction #{$transaction->id}"),
            'source_type' => FinBankTransaction::class,
            'source_id' => $transaction->id,
            'lines' => [$adjustmentLine, $bankLine],
        ])->load('lines');

        $journalLine = $journal->lines->firstWhere('account_id', $bankAccount->gl_account_id);
        if (! $journalLine) {
            throw BankReconciliationConflict::generic();
        }

        return [$journalLine->id, $journal->id];
    }

    private function lockAggregate(int $reconciliationId): array
    {
        $snapshot = FinBankReconciliation::query()->find($reconciliationId);
        if (! $snapshot) {
            throw BankReconciliationConflict::generic();
        }
        $account = $this->lockAccount($snapshot->bank_account_id, $snapshot->organization_id);
        $reconciliation = FinBankReconciliation::query()->whereKey($reconciliationId)->lockForUpdate()->first();
        if (! $reconciliation
            || (int) $reconciliation->bank_account_id !== (int) $account->id
            || (int) $reconciliation->organization_id !== (int) $account->organization_id) {
            throw BankReconciliationConflict::generic();
        }

        return [$account, $reconciliation];
    }

    private function lockAccount(int $bankAccountId, ?int $orgId): FinBankAccount
    {
        $account = FinBankAccount::query()
            ->whereKey($bankAccountId)
            ->when($orgId !== null, fn ($query) => $query->where('organization_id', $orgId))
            ->lockForUpdate()
            ->first();
        if (! $account) {
            throw BankReconciliationConflict::generic();
        }

        return $account;
    }

    private function lockStatementImport(FinBankAccount $account, ?int $statementImportId): ?FinBankStatementImport
    {
        $query = FinBankStatementImport::query()
            ->where('organization_id', $account->organization_id)
            ->where('bank_account_id', $account->id)
            ->where('status', 'completed');

        if ($statementImportId !== null) {
            $statementImport = $query->whereKey($statementImportId)->lockForUpdate()->first();
            if (! $statementImport) {
                throw BankReconciliationConflict::generic();
            }

            return $statementImport;
        }

        return $query
            ->whereNotIn('id', FinBankReconciliation::query()->whereNotNull('statement_import_id')->select('statement_import_id'))
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    private function assertMutable(FinBankReconciliation $reconciliation, ?int $expectedVersion): void
    {
        if ($reconciliation->status !== 'in_progress') {
            throw BankReconciliationConflict::terminal();
        }
        if ($reconciliation->integrity_state !== 'verified') {
            throw new BankReconciliationConflict('This reconciliation needs finance integrity review before it can be changed.');
        }
        if ($expectedVersion !== null && (int) $reconciliation->version !== $expectedVersion) {
            throw BankReconciliationConflict::stale();
        }
    }

    private function recordEvent(
        FinBankReconciliation $reconciliation,
        string $eventType,
        ?int $actorId,
        int $version,
        string $idempotencyKey,
        array $provenance,
    ): FinBankReconciliationEvent {
        return FinBankReconciliationEvent::create([
            'organization_id' => $reconciliation->organization_id,
            'bank_account_id' => $reconciliation->bank_account_id,
            'reconciliation_id' => $reconciliation->id,
            'reconciliation_line_id' => $provenance['reconciliation_line_id'] ?? null,
            'statement_import_id' => $reconciliation->statement_import_id,
            'bank_transaction_id' => $provenance['bank_transaction_id'] ?? null,
            'journal_id' => $provenance['journal_id'] ?? null,
            'reversal_journal_id' => $provenance['reversal_journal_id'] ?? null,
            'actor_id' => $actorId,
            'event_type' => $eventType,
            'aggregate_version' => $version,
            'idempotency_key' => $this->idempotencyKey($idempotencyKey),
            'provenance' => $provenance,
            'occurred_at' => now(),
        ]);
    }

    private function actorId(?int $actorId): int
    {
        $actorId ??= Auth::id();
        if (! $actorId) {
            throw new BankReconciliationConflict('A finance actor is required for this reconciliation operation.');
        }

        return $actorId;
    }

    private function assertActorOrganization(int $actorId, int $organizationId): void
    {
        if (! User::query()
            ->whereKey($actorId)
            ->where('organization_id', $organizationId)
            ->exists()) {
            throw BankReconciliationConflict::generic();
        }
    }

    private function idempotencyKey(string $key): string
    {
        return preg_match('/^[a-f0-9]{64}$/', $key) ? $key : hash('sha256', $key);
    }

    private function readImportRows(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open import file.');
        }

        try {
            if (fgetcsv($handle) === false) {
                throw new \RuntimeException('Import file is empty.');
            }
            $rows = [];
            $invalid = 0;
            $rowNumber = 1;
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                if (count($row) < 3 || ($date = $this->parseDate($row[0] ?? '')) === null) {
                    $invalid++;

                    continue;
                }
                $rows[] = [
                    'row_number' => $rowNumber,
                    'date' => $date->toDateString(),
                    'amount' => number_format($this->parseAmount($row[1] ?? '0'), 2, '.', ''),
                    'description' => trim((string) ($row[2] ?? '')),
                    'reference' => trim((string) ($row[3] ?? '')),
                ];
            }

            return [$rows, $invalid];
        } finally {
            fclose($handle);
        }
    }

    private function rowFingerprint(int $bankAccountId, array $row): string
    {
        $normalise = fn (string $value): string => Str::lower(preg_replace('/\s+/u', ' ', trim($value)) ?? '');

        return hash('sha256', implode('|', [
            $bankAccountId,
            $row['date'],
            $row['amount'],
            $normalise($row['description']),
            $normalise($row['reference']),
        ]));
    }

    private function calculateMatchConfidence(FinBankTransaction $transaction, FinJournalLine $journalLine): ?string
    {
        $amountMatches = ((float) $transaction->amount > 0 && abs((float) $transaction->amount - (float) $journalLine->debit) < 0.01)
            || ((float) $transaction->amount < 0 && abs(abs((float) $transaction->amount) - (float) $journalLine->credit) < 0.01);
        if (! $amountMatches) {
            return null;
        }
        $daysDiff = abs(Carbon::parse($journalLine->journal->journal_date)->diffInDays(Carbon::parse($transaction->transaction_date)));
        if ($daysDiff > 3) {
            return null;
        }
        $hasTextMatch = $this->hasPartialTextMatch(
            $transaction->reference.' '.$transaction->description,
            $journalLine->description.' '.($journalLine->journal->reference ?? ''),
        );

        return match (true) {
            $daysDiff === 0 && $hasTextMatch => 'high',
            $daysDiff <= 1 || $hasTextMatch => 'medium',
            default => 'low',
        };
    }

    private function hasPartialTextMatch(string $first, string $second): bool
    {
        $first = Str::lower(trim($first));
        $second = Str::lower(trim($second));
        if ($first === '' || $second === '') {
            return false;
        }
        $firstWords = array_filter(preg_split('/\s+/', $first), fn ($word) => strlen($word) >= 3);
        $secondWords = array_filter(preg_split('/\s+/', $second), fn ($word) => strlen($word) >= 3);
        foreach ($firstWords as $word) {
            foreach ($secondWords as $other) {
                if (Str::contains($other, $word) || Str::contains($word, $other)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function confidenceRank(?string $confidence): int
    {
        return match ($confidence) {
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            default => 0,
        };
    }

    private function parseDate(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->startOfDay();
            } catch (Throwable) {
            }
        }
        try {
            return Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function parseAmount(string $value): float
    {
        return (float) str_replace(['$', ',', ' '], '', trim($value));
    }
}
