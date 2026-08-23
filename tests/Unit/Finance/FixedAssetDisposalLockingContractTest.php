<?php

it('keeps the shared sequence mutex before the canonical fixed-asset lock', function (): void {
    $service = file_get_contents(fadpRepositoryPath('app/Domain/Finance/Services/FixedAssetService.php'));
    $start = strpos($service, 'public function disposeAsset');
    $end = strpos($service, 'private function resolveDisposalReplay', $start);
    $method = substr($service, $start, $end - $start);

    $sequenceLock = strpos($method, '->lockJournalSequence($organizationId)');
    $assetLock = strpos($method, '->lockForUpdate()');
    $journalBranch = strpos(
        $method,
        'if ($postingMode === FinFixedAssetDisposal::POSTING_MODE_JOURNAL)',
    );

    expect($sequenceLock)->not->toBeFalse()
        ->and($assetLock)->not->toBeFalse()
        ->and($journalBranch)->not->toBeFalse()
        ->and($sequenceLock)->toBeLessThan($assetLock)
        ->and($sequenceLock)->toBeLessThan($journalBranch);
});

it('declares migration 000200 as a fail-closed dependency of 000080', function (): void {
    $migration = file_get_contents(fadpRepositoryPath(
        'database/migrations/2026_08_23_000200_create_fin_fixed_asset_disposals_table.php',
    ));

    $dependency = strpos($migration, "Schema::hasTable('fin_journal_sequences')");
    $creation = strpos($migration, "Schema::create('fin_fixed_asset_disposals'");

    expect($migration)->toContain('2026_08_23_000080_enforce_fixed_asset_depreciation_period.php')
        ->and($dependency)->not->toBeFalse()
        ->and($creation)->not->toBeFalse()
        ->and($dependency)->toBeLessThan($creation)
        ->and($migration)->toContain("unique(['fixed_asset_id', 'occurrence_type']")
        ->and($migration)->toContain("unique('journal_id'");
});

it('marks JournalPosted as outermost-commit in-process delivery with an explicit outbox boundary', function (): void {
    $event = file_get_contents(fadpRepositoryPath('app/Domain/Finance/Events/JournalPosted.php'));

    expect($event)->toContain('implements ShouldDispatchAfterCommit')
        ->and($event)->toContain('after the outermost database transaction')
        ->and($event)->toContain('not a durable outbox');
});

function fadpRepositoryPath(string $relativePath): string
{
    return dirname(__DIR__, 3).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
}
