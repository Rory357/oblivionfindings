<?php

use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankFeed;
use App\Domain\Finance\Services\BankFeedProviders\AnzBankFeedProvider;
use App\Domain\Finance\Services\BankFeedProviders\AsbBankFeedProvider;
use App\Domain\Finance\Services\BankFeedProviders\BnzBankFeedProvider;
use App\Domain\Finance\Services\BankFeedProviders\WestpacBankFeedProvider;
use App\Models\User;

it('blocks bank-feed setup when provider setup is disabled', function () {
    config(['finance.bank_feeds.provider_setup_enabled' => false]);

    $this->withoutMiddleware();

    $user = User::factory()->create(['organization_id' => 1]);
    $bankAccount = FinBankAccount::factory()->create(['organization_id' => 1]);

    $this->actingAs($user)
        ->post(route('finance.bank-feeds.store'), [
            'bank_account_id' => $bankAccount->id,
            'provider' => 'anz',
            'sync_from_date' => '2026-05-01',
        ])
        ->assertSessionHasErrors('provider');

    expect(FinBankFeed::count())->toBe(0);
});

it('does not run existing bank-feed syncs while provider setup is disabled', function () {
    config(['finance.bank_feeds.provider_setup_enabled' => false]);

    $this->withoutMiddleware();

    $user = User::factory()->create(['organization_id' => 1]);
    $bankAccount = FinBankAccount::factory()->create(['organization_id' => 1]);
    $feed = FinBankFeed::create([
        'organization_id' => 1,
        'bank_account_id' => $bankAccount->id,
        'provider' => 'anz',
        'is_active' => true,
        'last_sync_status' => 'pending',
        'created_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->post(route('finance.bank-feeds.sync', $feed))
        ->assertSessionHas('error', config('finance.bank_feeds.provider_setup_message'));

    expect($feed->logs()->count())->toBe(0);
});

it('fails explicitly instead of returning empty provider transactions', function (string $providerClass, string $providerName) {
    $provider = app($providerClass);

    expect(fn () => $provider->fetchTransactions(new FinBankFeed(['provider' => $providerName]), '2026-05-01', '2026-05-02'))
        ->toThrow(RuntimeException::class, 'CSV import workflow');
})->with([
    [AnzBankFeedProvider::class, 'anz'],
    [AsbBankFeedProvider::class, 'asb'],
    [BnzBankFeedProvider::class, 'bnz'],
    [WestpacBankFeedProvider::class, 'westpac'],
]);
