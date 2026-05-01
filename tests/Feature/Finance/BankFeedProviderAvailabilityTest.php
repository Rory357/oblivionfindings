<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankFeed;
use App\Domain\Finance\Services\BankFeedService;
use App\Models\User;
use Database\Seeders\FinanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BankFeedProviderAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(FinanceSeeder::class)->run(1);
    }

    public function test_bank_feed_page_exposes_disabled_provider_setup_and_csv_support(): void
    {
        config(['finance.bank_feeds.provider_setup_enabled' => false]);

        $user = User::factory()->create(['organization_id' => 1]);
        $this->bankAccount();

        $this->actingAs($user)
            ->withoutMiddleware()
            ->get(route('finance.bank-feeds.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('finance/bank-feeds/Index')
                ->where('providerSetupEnabled', false)
                ->where('csvImportSupported', true)
                ->where('csvImportUrl', route('finance.bank-transactions.index'))
            );
    }

    public function test_disabled_provider_setup_rejects_new_bank_feed_connections(): void
    {
        config(['finance.bank_feeds.provider_setup_enabled' => false]);

        $user = User::factory()->create(['organization_id' => 1]);
        $bankAccount = $this->bankAccount();

        $this->actingAs($user)
            ->withoutMiddleware()
            ->from(route('finance.bank-feeds.index'))
            ->post(route('finance.bank-feeds.store'), [
                'bank_account_id' => $bankAccount->id,
                'provider' => 'asb',
                'sync_from_date' => now()->subMonth()->toDateString(),
            ])
            ->assertRedirect(route('finance.bank-feeds.index'))
            ->assertSessionHasErrors('provider');

        $this->assertDatabaseMissing('fin_bank_feeds', [
            'organization_id' => 1,
            'bank_account_id' => $bankAccount->id,
        ]);
    }

    public function test_unimplemented_provider_sync_fails_instead_of_returning_empty_success(): void
    {
        config(['finance.bank_feeds.provider_setup_enabled' => true]);

        $feed = FinBankFeed::create([
            'organization_id' => 1,
            'bank_account_id' => $this->bankAccount()->id,
            'provider' => 'asb',
            'sync_from_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
            'last_sync_status' => 'pending',
        ]);

        $log = app(BankFeedService::class)->syncFeed($feed);

        $this->assertSame('failed', $log->status);
        $this->assertSame(0, $log->transactions_fetched);
        $this->assertStringContainsString('not yet supported', $log->error_message);
        $this->assertSame('failed', $feed->refresh()->last_sync_status);
        $this->assertStringContainsString('CSV import', $feed->last_error);
    }

    private function bankAccount(): FinBankAccount
    {
        $account = FinAccount::forOrganization(1)
            ->where('code', '1000')
            ->firstOrFail();

        return FinBankAccount::factory()->create([
            'organization_id' => 1,
            'gl_account_id' => $account->id,
            'bank_name' => 'ASB',
            'account_type' => 'cheque',
        ]);
    }
}
