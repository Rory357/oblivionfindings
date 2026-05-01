<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinAccountingIntegration;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinJournalLine;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Finance\Services\AccountingSyncProviders\MyobSyncProvider;
use App\Domain\Finance\Services\AccountingSyncProviders\XeroSyncProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class XeroAccountingSyncProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.xero.client_id' => 'xero-client-id',
            'services.xero.client_secret' => 'xero-client-secret',
        ]);
    }

    public function test_refresh_token_updates_xero_oauth_tokens(): void
    {
        Http::fake([
            'https://identity.xero.com/connect/token' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 1800,
            ]),
        ]);

        $integration = $this->xeroIntegration([
            'access_token' => 'expired-access-token',
            'refresh_token' => 'old-refresh-token',
            'token_expires_at' => now()->subMinute(),
        ]);

        app(XeroSyncProvider::class)->refreshToken($integration);

        $integration->refresh();

        $this->assertSame('new-access-token', $integration->access_token);
        $this->assertSame('new-refresh-token', $integration->refresh_token);
        $this->assertTrue($integration->token_expires_at->isFuture());

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://identity.xero.com/connect/token'
            && $request->method() === 'POST'
            && $request->data()['grant_type'] === 'refresh_token'
            && $request->data()['refresh_token'] === 'old-refresh-token');
    }

    public function test_push_accounts_sends_xero_payload_and_stores_external_id(): void
    {
        Http::fake([
            'https://api.xero.com/api.xro/2.0/Accounts' => Http::response([
                'Accounts' => [
                    ['AccountID' => 'xero-account-123', 'Code' => '5000', 'Name' => 'Wages', 'Type' => 'OVERHEADS'],
                ],
            ]),
        ]);

        $integration = $this->xeroIntegration();
        $account = FinAccount::factory()->create([
            'organization_id' => 1,
            'code' => '5000',
            'name' => 'Wages',
            'type' => 'expense',
            'sub_type' => 'expense',
            'description' => 'Payroll wages',
        ]);

        $result = app(XeroSyncProvider::class)->pushAccounts($integration, collect([$account]));

        $this->assertSame(1, $result['success']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('xero-account-123', $account->refresh()->xero_account_id);
        $this->assertSame('xero-account-123', $integration->refresh()->account_mapping[(string) $account->id]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.xero.com/api.xro/2.0/Accounts'
            && $request->method() === 'PUT'
            && $request->hasHeader('Xero-tenant-id', 'tenant-123')
            && $request->data()['Accounts'][0]['Code'] === '5000'
            && $request->data()['Accounts'][0]['Type'] === 'OVERHEADS');
    }

    public function test_push_journals_sends_manual_journal_and_stores_external_id(): void
    {
        Http::fake([
            'https://api.xero.com/api.xro/2.0/ManualJournals' => Http::response([
                'ManualJournals' => [
                    ['ManualJournalID' => 'xero-journal-123', 'Narration' => 'Payroll accrual'],
                ],
            ]),
        ]);

        $integration = $this->xeroIntegration();
        $expenseAccount = FinAccount::factory()->create([
            'organization_id' => 1,
            'code' => '5000',
            'type' => 'expense',
        ]);
        $liabilityAccount = FinAccount::factory()->create([
            'organization_id' => 1,
            'code' => '2100',
            'type' => 'liability',
        ]);
        $journal = FinJournal::factory()->create([
            'organization_id' => 1,
            'journal_number' => 'JNL-XERO-001',
            'journal_date' => '2026-05-02',
            'status' => 'posted',
            'description' => 'Payroll accrual',
            'reference' => 'PAY-001',
        ]);
        FinJournalLine::create([
            'journal_id' => $journal->id,
            'account_id' => $expenseAccount->id,
            'description' => 'Gross wages',
            'debit' => 100,
            'credit' => 0,
        ]);
        FinJournalLine::create([
            'journal_id' => $journal->id,
            'account_id' => $liabilityAccount->id,
            'description' => 'Accrued wages',
            'debit' => 0,
            'credit' => 100,
        ]);

        $result = app(XeroSyncProvider::class)->pushJournals($integration, collect([$journal]));

        $this->assertSame(1, $result['success']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('xero-journal-123', $journal->refresh()->xero_journal_id);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data()['ManualJournals'][0];

            return $request->url() === 'https://api.xero.com/api.xro/2.0/ManualJournals'
                && $request->method() === 'PUT'
                && $payload['Date'] === '2026-05-02'
                && $payload['Status'] === 'POSTED'
                && $payload['JournalLines'][0]['AccountCode'] === '5000'
                && $payload['JournalLines'][0]['LineAmount'] === 100.0
                && $payload['JournalLines'][1]['AccountCode'] === '2100'
                && $payload['JournalLines'][1]['LineAmount'] === -100.0;
        });
    }

    public function test_push_contacts_sends_supplier_payload_and_stores_external_id(): void
    {
        Http::fake([
            'https://api.xero.com/api.xro/2.0/Contacts' => Http::response([
                'Contacts' => [
                    ['ContactID' => 'xero-contact-123', 'Name' => 'Acme Supplies'],
                ],
            ]),
        ]);

        $integration = $this->xeroIntegration();
        $vendor = FinVendor::factory()->create([
            'organization_id' => 1,
            'name' => 'Acme Supplies',
            'email' => 'accounts@acme.test',
            'gst_number' => '123-456-789',
        ]);

        $result = app(XeroSyncProvider::class)->pushContacts($integration, collect([$vendor]));

        $this->assertSame(1, $result['success']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('xero-contact-123', $vendor->refresh()->xero_contact_id);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.xero.com/api.xro/2.0/Contacts'
            && $request->method() === 'PUT'
            && $request->data()['Contacts'][0]['Name'] === 'Acme Supplies'
            && $request->data()['Contacts'][0]['EmailAddress'] === 'accounts@acme.test'
            && $request->data()['Contacts'][0]['IsSupplier'] === true);
    }

    public function test_myob_provider_reports_explicitly_unsupported_service_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MYOB accounting sync is not supported yet.');

        app(MyobSyncProvider::class)->pullAccounts($this->myobIntegration());
    }

    private function xeroIntegration(array $overrides = []): FinAccountingIntegration
    {
        return FinAccountingIntegration::create(array_merge([
            'organization_id' => 1,
            'provider' => 'xero',
            'tenant_id' => 'tenant-123',
            'access_token' => 'valid-access-token',
            'refresh_token' => 'valid-refresh-token',
            'token_expires_at' => now()->addHour(),
            'sync_direction' => 'bidirectional',
            'is_active' => true,
        ], $overrides));
    }

    private function myobIntegration(): FinAccountingIntegration
    {
        return FinAccountingIntegration::create([
            'organization_id' => 1,
            'provider' => 'myob',
            'tenant_id' => 'company-file',
            'sync_direction' => 'bidirectional',
            'is_active' => true,
        ]);
    }
}
