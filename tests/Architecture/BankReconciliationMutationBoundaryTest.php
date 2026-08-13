<?php

it('keeps every HTTP reconciliation mutation behind the canonical aggregate service', function (): void {
    $appPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR;
    $controller = file_get_contents($appPath.'Domain/Finance/Http/Controllers/BankReconciliationController.php');
    $transactionController = file_get_contents($appPath.'Domain/Finance/Http/Controllers/BankTransactionController.php');

    expect($controller)
        ->not->toContain('FinBankReconciliationLine::create(')
        ->not->toContain('FinBankReconciliationLine::findOrFail(')
        ->not->toContain("->update(['status' => 'completed'")
        ->toContain('$this->service->matchTransaction(')
        ->toContain('$this->service->unmatchTransaction(')
        ->toContain('$this->service->completeReconciliation(')
        ->toContain('$this->service->createAmendment(')
        ->and($transactionController)->toContain('$this->service->importTransactions(');
});

it('guards reconciliation records and match history from alternate eloquent mutation paths', function (): void {
    $modelsPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app/Domain/Finance/Models/';
    $reconciliation = file_get_contents($modelsPath.'FinBankReconciliation.php');
    $line = file_get_contents($modelsPath.'FinBankReconciliationLine.php');
    $transaction = file_get_contents($modelsPath.'FinBankTransaction.php');

    expect($reconciliation)->toContain('BankReconciliationMutationGuard::allowsCanonicalMutation()')
        ->and($line)->toContain('BankReconciliationMutationGuard::allowsCanonicalMutation()')
        ->and($line)->toContain('Reconciliation match history is immutable.')
        ->and($transaction)->toContain("'matched_journal_line_id',")
        ->and($transaction)->toContain("'import_row_fingerprint',");
});
