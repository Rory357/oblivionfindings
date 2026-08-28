<?php

it('excludes every medication witness credential from flashed input', function (): void {
    $root = dirname(__DIR__, 2);
    $bootstrap = (string) file_get_contents($root.'/bootstrap/app.php');

    preg_match('/\$exceptions->dontFlash\(\[(.*?)\]\);/s', $bootstrap, $matches);
    $dontFlash = $matches[1] ?? '';

    expect($dontFlash)->toContain(
        "'read_back_witness_credential'",
        "'cd_witness_credential'",
        "'witness_credential'",
        "'witness_1_credential'",
        "'witness_2_credential'",
        "'waiver_approver_credential'",
    );
});

it('keeps immutable prescriber-order classification through batch rollback', function (): void {
    $root = dirname(__DIR__, 2);
    $migration = (string) file_get_contents(
        $root.'/database/migrations/2026_08_27_000100_add_controlled_snapshot_to_medication_prescriber_orders.php',
    );
    $down = explode('public function down(): void', $migration, 2)[1] ?? '';

    expect($down)
        ->toContain('Classification is immutable clinical provenance.')
        ->not->toContain(
            "dropColumn('controlled_drug_snapshot')",
            'throw new RuntimeException',
        );
});
