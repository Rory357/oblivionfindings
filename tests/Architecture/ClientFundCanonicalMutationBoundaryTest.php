<?php

it('keeps client-money balance and transaction writes inside the canonical service', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $allowed = [
        $root.'/app/Domain/Finance/Services/ClientFundTransactionService.php',
    ];
    $violations = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        if (in_array($path, $allowed, true)) {
            continue;
        }

        $contents = file_get_contents($path);
        if (! str_contains($contents, 'App\\Models\\ClientFund')) {
            continue;
        }

        if (preg_match('/ClientFundTransaction::(?:create|insert|upsert)\s*\(/', $contents)
            || preg_match('/->transactions\(\)->create\s*\(/', $contents)
            || preg_match('/(?:forceFill|update)\s*\(\s*\[\s*[\'\"]balance[\'\"]\s*=>/', $contents)) {
            $violations[] = str_replace($root.'/', '', $path);
        }
    }

    expect($violations)->toBe([]);
});

it('keeps Client Trust GL dimensions owned by the canonical journal bridge', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $journalBridge = file_get_contents($root.'/app/Domain/Finance/Services/ClientFundJournalService.php');
    $genericPosting = file_get_contents($root.'/app/Domain/Finance/Services/JournalPostingService.php');
    $migration = file_get_contents($root.'/database/migrations/2026_08_14_000001_govern_client_fund_lifecycle.php');

    expect($journalBridge)->toContain("findAccountByCode(\$storageContextId, '2500')")
        ->and($journalBridge)->toContain("'client_id' => \$fund->client_id")
        ->and($journalBridge)->toContain("'client_fund_id' => \$fund->id")
        ->and($journalBridge)->toContain("'site_id' => \$fund->client->site_id")
        ->and($genericPosting)->toContain("'client_fund_id' => \$line['client_fund_id'] ?? null")
        ->and($migration)->toContain("unique('reversal_of_id', 'client_fund_transactions_reversal_once_unique')")
        ->and($migration)->toContain("'status' => 'review'")
        ->and($migration)->toContain("bccomp(\$balance, '0.00', 2) < 0 ? '0.00' : \$balance")
        ->and($migration)->not->toContain("'approved_by' =>")
        ->and($migration)->not->toContain("'overdraft_policy_state' => 'authorized'");
});
