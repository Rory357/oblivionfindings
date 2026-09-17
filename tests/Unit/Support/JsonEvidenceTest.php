<?php

use App\Support\JsonEvidence;

/**
 * MySQL's `json` column type normalises object keys by length then
 * lexicographically, so evidence written to one comes back in a different
 * order. Comparing that with `===` against a freshly built array can never
 * match, which silently made every guarded record unusable instead of merely
 * well guarded. These cover the order-insensitive comparison that replaces it —
 * and, just as importantly, everything it must still reject.
 */
it('matches evidence whose keys MySQL reordered on the way out', function () {
    // The exact shape from spend_approval_decisions.parent_evidence['source']:
    // MySQL returns it sorted by key length, then alphabetically.
    $fromMysql = [
        'id' => 1,
        'type' => 'App\Domain\Finance\Models\FinBill',
        'status' => 'draft',
        'site_id' => 1,
        'reference' => 'BILL-1157',
        'vendor_id' => 1,
        'total_amount' => '15000.00',
        'purchase_order_id' => null,
    ];
    $freshlyBuilt = [
        'type' => 'App\Domain\Finance\Models\FinBill',
        'id' => 1,
        'site_id' => 1,
        'reference' => 'BILL-1157',
        'status' => 'draft',
        'total_amount' => '15000.00',
        'vendor_id' => 1,
        'purchase_order_id' => null,
    ];

    expect($fromMysql === $freshlyBuilt)->toBeFalse()
        ->and(JsonEvidence::matches($fromMysql, $freshlyBuilt))->toBeTrue();
});

it('still rejects a tampered value, a changed type, and a missing or extra key', function () {
    $stored = ['id' => 1, 'total_amount' => '15000.00', 'status' => 'draft'];

    expect(JsonEvidence::matches($stored, ['id' => 1, 'total_amount' => '15000.01', 'status' => 'draft']))->toBeFalse()
        // '1' is not 1: the comparison stays as strict about types as === was.
        ->and(JsonEvidence::matches($stored, ['id' => '1', 'total_amount' => '15000.00', 'status' => 'draft']))->toBeFalse()
        ->and(JsonEvidence::matches($stored, ['id' => 1, 'total_amount' => '15000.00']))->toBeFalse()
        ->and(JsonEvidence::matches($stored, [...$stored, 'extra' => true]))->toBeFalse()
        ->and(JsonEvidence::matches($stored, ['id' => 1, 'total_amount' => null, 'status' => 'draft']))->toBeFalse();
});

it('reorders nested objects but never lists', function () {
    expect(JsonEvidence::matches(
        ['site' => ['name' => 'Alpha', 'id' => 2]],
        ['site' => ['id' => 2, 'name' => 'Alpha']],
    ))->toBeTrue();

    // A reordered list is different data, not a different spelling of it.
    expect(JsonEvidence::matches(
        ['attachments' => [['id' => 1], ['id' => 2]]],
        ['attachments' => [['id' => 2], ['id' => 1]]],
    ))->toBeFalse();

    expect(JsonEvidence::matches(
        ['attachments' => [['sha256' => 'a', 'id' => 1]]],
        ['attachments' => [['id' => 1, 'sha256' => 'a']]],
    ))->toBeTrue();
});

it('compares non-array evidence strictly', function () {
    expect(JsonEvidence::matches(null, null))->toBeTrue()
        ->and(JsonEvidence::matches(null, []))->toBeFalse()
        ->and(JsonEvidence::matches('1', 1))->toBeFalse();
});
