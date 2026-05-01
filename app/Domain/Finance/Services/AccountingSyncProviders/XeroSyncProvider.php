<?php

namespace App\Domain\Finance\Services\AccountingSyncProviders;

use App\Domain\Finance\Contracts\AccountingSyncProviderInterface;
use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinAccountingIntegration;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinJournalLine;
use App\Domain\Finance\Models\FinVendor;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class XeroSyncProvider implements AccountingSyncProviderInterface
{
    private const API_BASE = 'https://api.xero.com/api.xro/2.0';

    private const TOKEN_URL = 'https://identity.xero.com/connect/token';

    /**
     * {@inheritDoc}
     */
    public function pushAccounts(FinAccountingIntegration $integration, Collection $accounts): array
    {
        $this->ensureCanCallApi($integration);

        Log::info('Xero: pushing accounts', [
            'integration_id' => $integration->id,
            'count' => $accounts->count(),
        ]);

        $success = 0;
        $errors = [];

        foreach ($accounts as $account) {
            try {
                $response = $this->apiRequest($integration, 'PUT', '/Accounts', [
                    'Accounts' => [$this->accountPayload($account)],
                ]);
                $xeroAccount = $this->firstEntity($response, 'Accounts');
                $this->assertNoValidationErrors($xeroAccount, 'account', $account->id);

                $externalId = $xeroAccount['AccountID'] ?? null;
                if ($externalId) {
                    $account->forceFill(['xero_account_id' => $externalId])->save();
                    $this->rememberAccountMapping($integration, $account->id, $externalId);
                }

                $success++;
            } catch (\Throwable $e) {
                $errors[] = ['id' => $account->id, 'message' => $e->getMessage()];
            }
        }

        return ['success' => $success, 'errors' => $errors];
    }

    /**
     * {@inheritDoc}
     */
    public function pullAccounts(FinAccountingIntegration $integration): array
    {
        $this->ensureCanCallApi($integration);

        Log::info('Xero: pulling accounts', [
            'integration_id' => $integration->id,
            'tenant_id' => $integration->tenant_id,
        ]);

        $response = $this->apiRequest($integration, 'GET', '/Accounts');

        return [
            'accounts' => collect($response->json('Accounts', []))
                ->map(fn (array $account): array => [
                    'external_id' => (string) ($account['AccountID'] ?? ''),
                    'code' => (string) ($account['Code'] ?? ''),
                    'name' => (string) ($account['Name'] ?? ''),
                    'type' => $this->mapXeroAccountTypeToLocal((string) ($account['Type'] ?? 'EXPENSE')),
                ])
                ->filter(fn (array $account): bool => $account['external_id'] !== '' && $account['code'] !== '')
                ->values()
                ->all(),
            'errors' => [],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function pushJournals(FinAccountingIntegration $integration, Collection $journals): array
    {
        $this->ensureCanCallApi($integration);

        Log::info('Xero: pushing journals', [
            'integration_id' => $integration->id,
            'count' => $journals->count(),
        ]);

        $success = 0;
        $errors = [];

        foreach ($journals as $journal) {
            try {
                $journal->loadMissing('lines.account');

                $response = $this->apiRequest($integration, 'PUT', '/ManualJournals', [
                    'ManualJournals' => [$this->manualJournalPayload($journal)],
                ]);
                $xeroJournal = $this->firstEntity($response, 'ManualJournals');
                $this->assertNoValidationErrors($xeroJournal, 'journal', $journal->id);

                $externalId = $xeroJournal['ManualJournalID'] ?? null;
                if ($externalId) {
                    $journal->forceFill(['xero_journal_id' => $externalId])->save();
                }

                $success++;
            } catch (\Throwable $e) {
                $errors[] = ['id' => $journal->id, 'message' => $e->getMessage()];
            }
        }

        return ['success' => $success, 'errors' => $errors];
    }

    /**
     * {@inheritDoc}
     */
    public function pullJournals(FinAccountingIntegration $integration, string $since): array
    {
        $this->ensureCanCallApi($integration);

        Log::info('Xero: pulling journals', [
            'integration_id' => $integration->id,
            'since' => $since,
        ]);

        $response = $this->apiRequest($integration, 'GET', '/ManualJournals', query: [
            'where' => sprintf('Date>=DateTime(%s)', $since),
        ]);

        return [
            'journals' => collect($response->json('ManualJournals', []))
                ->map(fn (array $journal): array => [
                    'external_id' => (string) ($journal['ManualJournalID'] ?? ''),
                    'date' => (string) ($journal['Date'] ?? ''),
                    'reference' => $journal['Reference'] ?? $journal['Narration'] ?? null,
                    'lines' => $journal['JournalLines'] ?? [],
                ])
                ->filter(fn (array $journal): bool => $journal['external_id'] !== '')
                ->values()
                ->all(),
            'errors' => [],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function pushInvoices(FinAccountingIntegration $integration, Collection $bills): array
    {
        $this->ensureCanCallApi($integration);

        Log::info('Xero: pushing invoices (bills)', [
            'integration_id' => $integration->id,
            'count' => $bills->count(),
        ]);

        $success = 0;
        $errors = [];

        foreach ($bills as $bill) {
            try {
                $bill->loadMissing(['vendor', 'lines.account']);

                $response = $this->apiRequest($integration, 'PUT', '/Invoices', [
                    'Invoices' => [$this->billPayload($bill)],
                ]);
                $xeroInvoice = $this->firstEntity($response, 'Invoices');
                $this->assertNoValidationErrors($xeroInvoice, 'invoice', $bill->id);

                $externalId = $xeroInvoice['InvoiceID'] ?? null;
                if ($externalId) {
                    $bill->forceFill(['xero_invoice_id' => $externalId])->save();
                }

                $success++;
            } catch (\Throwable $e) {
                $errors[] = ['id' => $bill->id, 'message' => $e->getMessage()];
            }
        }

        return ['success' => $success, 'errors' => $errors];
    }

    /**
     * {@inheritDoc}
     */
    public function pullInvoices(FinAccountingIntegration $integration, string $since): array
    {
        $this->ensureCanCallApi($integration);

        Log::info('Xero: pulling invoices', [
            'integration_id' => $integration->id,
            'since' => $since,
        ]);

        $response = $this->apiRequest($integration, 'GET', '/Invoices', query: [
            'where' => sprintf('Type=="ACCPAY"&&Date>=DateTime(%s)', $since),
        ]);

        return [
            'invoices' => collect($response->json('Invoices', []))
                ->map(fn (array $invoice): array => [
                    'external_id' => (string) ($invoice['InvoiceID'] ?? ''),
                    'date' => (string) ($invoice['Date'] ?? ''),
                    'total' => (float) ($invoice['Total'] ?? 0),
                    'contact_id' => $invoice['Contact']['ContactID'] ?? null,
                ])
                ->filter(fn (array $invoice): bool => $invoice['external_id'] !== '')
                ->values()
                ->all(),
            'errors' => [],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function pushContacts(FinAccountingIntegration $integration, Collection $vendors): array
    {
        $this->ensureCanCallApi($integration);

        Log::info('Xero: pushing contacts', [
            'integration_id' => $integration->id,
            'count' => $vendors->count(),
        ]);

        $success = 0;
        $errors = [];

        foreach ($vendors as $vendor) {
            try {
                $response = $this->apiRequest($integration, 'PUT', '/Contacts', [
                    'Contacts' => [$this->contactPayload($vendor)],
                ]);
                $xeroContact = $this->firstEntity($response, 'Contacts');
                $this->assertNoValidationErrors($xeroContact, 'contact', $vendor->id);

                $externalId = $xeroContact['ContactID'] ?? null;
                if ($externalId) {
                    $vendor->forceFill(['xero_contact_id' => $externalId])->save();
                }

                $success++;
            } catch (\Throwable $e) {
                $errors[] = ['id' => $vendor->id, 'message' => $e->getMessage()];
            }
        }

        return ['success' => $success, 'errors' => $errors];
    }

    /**
     * {@inheritDoc}
     */
    public function pullContacts(FinAccountingIntegration $integration): array
    {
        $this->ensureCanCallApi($integration);

        Log::info('Xero: pulling contacts', [
            'integration_id' => $integration->id,
        ]);

        $response = $this->apiRequest($integration, 'GET', '/Contacts', query: [
            'where' => 'IsSupplier==true',
        ]);

        return [
            'contacts' => collect($response->json('Contacts', []))
                ->map(fn (array $contact): array => [
                    'external_id' => (string) ($contact['ContactID'] ?? ''),
                    'name' => (string) ($contact['Name'] ?? ''),
                    'email' => $contact['EmailAddress'] ?? null,
                ])
                ->filter(fn (array $contact): bool => $contact['external_id'] !== '')
                ->values()
                ->all(),
            'errors' => [],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function testConnection(FinAccountingIntegration $integration): bool
    {
        try {
            $this->ensureCanCallApi($integration);

            return $this->apiRequest($integration, 'GET', '/Organisation')->successful();
        } catch (\Throwable $e) {
            Log::warning('Xero: connection test failed', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function refreshToken(FinAccountingIntegration $integration): void
    {
        if (! $integration->refresh_token) {
            throw new RuntimeException('No refresh token available for Xero integration.');
        }

        $clientId = config('services.xero.client_id');
        $clientSecret = config('services.xero.client_secret');

        if (! $clientId || ! $clientSecret) {
            throw new RuntimeException('Xero OAuth client credentials are not configured.');
        }

        Log::info('Xero: refreshing access token', [
            'integration_id' => $integration->id,
        ]);

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $integration->refresh_token,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to refresh Xero token: '.$response->body());
        }

        $data = $response->json();

        if (! isset($data['access_token'])) {
            throw new RuntimeException('Failed to refresh Xero token: response did not include an access token.');
        }

        $integration->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $integration->refresh_token,
            'token_expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 1800)),
        ]);

        $integration->refresh();

        Log::info('Xero: token refreshed successfully', [
            'integration_id' => $integration->id,
        ]);
    }

    // -- Payload builders -------------------------------------------------

    private function accountPayload(FinAccount $account): array
    {
        $payload = [
            'Code' => $account->code,
            'Name' => $account->name,
            'Type' => $this->mapAccountTypeToXero($account->type, $account->sub_type),
            'Description' => $account->description ?? '',
            'EnablePaymentsToAccount' => in_array($account->sub_type, ['bank', 'accounts_payable'], true),
        ];

        if ($account->xero_account_id) {
            $payload['AccountID'] = $account->xero_account_id;
        }

        return $payload;
    }

    private function manualJournalPayload(FinJournal $journal): array
    {
        $payload = [
            'Date' => $journal->journal_date->format('Y-m-d'),
            'Status' => 'POSTED',
            'Narration' => $journal->description ?? "Journal {$journal->journal_number}",
            'JournalLines' => $journal->lines
                ->map(fn (FinJournalLine $line): array => $this->manualJournalLinePayload($line, $journal))
                ->values()
                ->all(),
        ];

        if ($journal->reference) {
            $payload['Reference'] = $journal->reference;
        }

        if ($journal->xero_journal_id) {
            $payload['ManualJournalID'] = $journal->xero_journal_id;
        }

        return $payload;
    }

    private function manualJournalLinePayload(FinJournalLine $line, FinJournal $journal): array
    {
        $account = $line->account;

        if (! $account) {
            throw new RuntimeException("Journal line #{$line->id} has no account.");
        }

        $debit = (float) $line->debit;
        $credit = (float) $line->credit;

        return [
            'AccountCode' => $account->code,
            'Description' => $line->description ?? $journal->description ?? "Journal {$journal->journal_number}",
            'LineAmount' => $debit > 0 ? $debit : -$credit,
        ];
    }

    private function billPayload(FinBill $bill): array
    {
        if (! $bill->vendor) {
            throw new RuntimeException("Bill #{$bill->id} has no vendor.");
        }

        $payload = [
            'Type' => 'ACCPAY',
            'Contact' => $bill->vendor->xero_contact_id
                ? ['ContactID' => $bill->vendor->xero_contact_id]
                : ['Name' => $bill->vendor->name],
            'Date' => $bill->bill_date->format('Y-m-d'),
            'DueDate' => $bill->due_date->format('Y-m-d'),
            'Reference' => $bill->vendor_reference ?? $bill->bill_number,
            'LineAmountTypes' => 'Exclusive',
            'LineItems' => $bill->lines
                ->map(fn ($line): array => [
                    'Description' => $line->description ?? $bill->bill_number,
                    'Quantity' => (float) $line->quantity,
                    'UnitAmount' => (float) $line->unit_price,
                    'AccountCode' => $line->account?->code,
                    'TaxAmount' => (float) ($line->gst_amount ?? 0),
                ])
                ->values()
                ->all(),
        ];

        if ($bill->xero_invoice_id) {
            $payload['InvoiceID'] = $bill->xero_invoice_id;
        }

        return $payload;
    }

    private function contactPayload(FinVendor $vendor): array
    {
        $payload = [
            'Name' => $vendor->name,
            'EmailAddress' => $vendor->email ?? '',
            'IsSupplier' => true,
            'TaxNumber' => $vendor->gst_number ?? '',
            'Addresses' => [
                [
                    'AddressType' => 'POBOX',
                    'AddressLine1' => $vendor->address_line_1 ?? '',
                    'AddressLine2' => $vendor->address_line_2 ?? '',
                    'City' => $vendor->city ?? '',
                    'Region' => $vendor->region ?? '',
                    'PostalCode' => $vendor->postal_code ?? '',
                    'Country' => 'NZ',
                ],
            ],
            'Phones' => [
                [
                    'PhoneType' => 'DEFAULT',
                    'PhoneNumber' => $vendor->phone ?? '',
                ],
            ],
        ];

        if ($vendor->xero_contact_id) {
            $payload['ContactID'] = $vendor->xero_contact_id;
        }

        return $payload;
    }

    // -- HTTP helpers -----------------------------------------------------

    private function apiRequest(
        FinAccountingIntegration $integration,
        string $method,
        string $path,
        array $payload = [],
        array $query = []
    ): Response {
        $this->ensureCanCallApi($integration);

        $response = $this->sendApiRequest($integration, $method, $path, $payload, $query);

        if ($response->status() === 401 && $integration->refresh_token) {
            $this->refreshToken($integration);
            $response = $this->sendApiRequest($integration->refresh(), $method, $path, $payload, $query);
        }

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Xero %s %s failed (%s): %s',
                strtoupper($method),
                $path,
                $response->status(),
                $response->body()
            ));
        }

        return $response;
    }

    private function sendApiRequest(
        FinAccountingIntegration $integration,
        string $method,
        string $path,
        array $payload,
        array $query
    ): Response {
        $options = [];

        if ($query !== []) {
            $options['query'] = $query;
        }

        if ($payload !== []) {
            $options['json'] = $payload;
        }

        return Http::withToken($integration->access_token)
            ->acceptJson()
            ->withHeaders([
                'Xero-tenant-id' => $this->tenantId($integration),
            ])
            ->send(strtoupper($method), self::API_BASE.$path, $options);
    }

    private function ensureCanCallApi(FinAccountingIntegration $integration): void
    {
        $this->tenantId($integration);

        if (! $integration->access_token && ! $integration->refresh_token) {
            throw new RuntimeException('Xero integration has no access token or refresh token configured.');
        }

        if (! $integration->access_token || $integration->isTokenExpired()) {
            $this->refreshToken($integration);
        }
    }

    private function tenantId(FinAccountingIntegration $integration): string
    {
        if (! $integration->tenant_id) {
            throw new RuntimeException('Xero integration has no tenant ID configured.');
        }

        return $integration->tenant_id;
    }

    private function firstEntity(Response $response, string $key): array
    {
        $entity = $response->json("{$key}.0");

        if (! is_array($entity)) {
            throw new RuntimeException("Xero response did not include {$key}.");
        }

        return $entity;
    }

    private function assertNoValidationErrors(array $entity, string $entityType, int $entityId): void
    {
        if (($entity['HasValidationErrors'] ?? false) !== true) {
            return;
        }

        $messages = collect($entity['ValidationErrors'] ?? [])
            ->map(fn (array $error): string => (string) ($error['Message'] ?? 'Unknown validation error'))
            ->filter()
            ->implode('; ');

        throw new RuntimeException(sprintf(
            'Xero rejected %s #%d: %s',
            $entityType,
            $entityId,
            $messages !== '' ? $messages : 'validation failed'
        ));
    }

    private function rememberAccountMapping(FinAccountingIntegration $integration, int $localAccountId, string $externalId): void
    {
        $mapping = $integration->account_mapping ?? [];
        $mapping[(string) $localAccountId] = $externalId;

        $integration->update(['account_mapping' => $mapping]);
        $integration->refresh();
    }

    // -- Type mapping -----------------------------------------------------

    private function mapAccountTypeToXero(string $type, ?string $subType): string
    {
        $mapping = [
            'asset' => [
                'bank' => 'BANK',
                'accounts_receivable' => 'CURRENT',
                'current_asset' => 'CURRENT',
                'fixed_asset' => 'FIXED',
                'accumulated_depreciation' => 'FIXED',
                'default' => 'CURRENT',
            ],
            'liability' => [
                'accounts_payable' => 'CURRLIAB',
                'current_liability' => 'CURRLIAB',
                'long_term_liability' => 'TERMLIAB',
                'default' => 'CURRLIAB',
            ],
            'equity' => [
                'default' => 'EQUITY',
            ],
            'revenue' => [
                'default' => 'REVENUE',
            ],
            'expense' => [
                'cost_of_sales' => 'DIRECTCOSTS',
                'default' => 'OVERHEADS',
            ],
        ];

        $typeMap = $mapping[$type] ?? ['default' => 'EXPENSE'];

        return $typeMap[$subType] ?? $typeMap['default'];
    }

    private function mapXeroAccountTypeToLocal(string $xeroType): string
    {
        return match ($xeroType) {
            'BANK', 'CURRENT', 'FIXED', 'INVENTORY', 'PREPAYMENT' => 'asset',
            'CURRLIAB', 'TERMLIAB', 'LIABILITY' => 'liability',
            'EQUITY' => 'equity',
            'REVENUE', 'SALES' => 'revenue',
            default => 'expense',
        };
    }
}
