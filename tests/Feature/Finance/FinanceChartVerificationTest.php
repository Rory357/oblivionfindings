<?php

use Database\Seeders\FinanceSeeder;
use Illuminate\Support\Facades\DB;

function financeReadinessConfigStringAccountCodes(array $config): array
{
    $codes = [];

    $collect = function (mixed $value) use (&$codes, &$collect): void {
        if (is_string($value) && preg_match('/^\d{3,6}$/', $value) === 1) {
            $codes[] = $value;

            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $childValue) {
            $collect($childValue);
        }
    };

    $collect($config);

    $codes = array_values(array_unique($codes));
    sort($codes, SORT_NATURAL);

    return $codes;
}

test('finance seeder creates accounts referenced by event and payment config', function () {
    $this->seed(FinanceSeeder::class);

    $codes = financeReadinessConfigStringAccountCodes([
        'event_accounts' => config('finance.event_accounts'),
        'payment_type_accounts' => config('finance.payment_type_accounts'),
    ]);

    foreach ($codes as $code) {
        $this->assertDatabaseHas('fin_accounts', [
            'organization_id' => 0,
            'code' => $code,
            'is_active' => true,
        ]);
    }
});

test('finance chart verification passes for the seeded default chart', function () {
    $this->seed(FinanceSeeder::class);

    $this->artisan('finance:verify-chart')
        ->expectsOutputToContain('Finance chart verified')
        ->assertSuccessful();
});

test('finance chart verification accepts an explicit seeded organization', function () {
    app(FinanceSeeder::class)->run(42);

    $this->artisan('finance:verify-chart', ['organization_id' => 42])
        ->expectsOutputToContain('organization #42')
        ->assertSuccessful();
});

test('finance chart verification fails when config references a missing account', function () {
    $this->seed(FinanceSeeder::class);

    DB::table('fin_accounts')
        ->where('organization_id', 0)
        ->where('code', '2310')
        ->delete();

    $this->artisan('finance:verify-chart')
        ->expectsOutputToContain('Missing required finance GL accounts')
        ->expectsOutputToContain('2310')
        ->assertExitCode(1);
});

test('finance chart verification fails when config references an inactive account', function () {
    $this->seed(FinanceSeeder::class);

    DB::table('fin_accounts')
        ->where('organization_id', 0)
        ->where('code', '6210')
        ->update(['is_active' => false]);

    $this->artisan('finance:verify-chart')
        ->expectsOutputToContain('Inactive finance GL accounts referenced by config')
        ->expectsOutputToContain('6210')
        ->assertExitCode(1);
});

test('finance chart verification fails when config references a soft deleted account', function () {
    $this->seed(FinanceSeeder::class);

    DB::table('fin_accounts')
        ->where('organization_id', 0)
        ->where('code', '6200')
        ->update(['deleted_at' => now()]);

    $this->artisan('finance:verify-chart')
        ->expectsOutputToContain('Missing required finance GL accounts')
        ->expectsOutputToContain('6200')
        ->assertExitCode(1);
});
