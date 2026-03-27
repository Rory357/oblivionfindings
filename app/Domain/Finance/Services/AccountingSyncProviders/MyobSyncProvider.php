<?php

namespace App\Domain\Finance\Services\AccountingSyncProviders;

use App\Domain\Finance\Contracts\AccountingSyncProviderInterface;
use App\Domain\Finance\Models\FinAccountingIntegration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MyobSyncProvider implements AccountingSyncProviderInterface
{
    private const API_BASE = 'https://api.myob.com/accountright';

    /**
     * {@inheritDoc}
     */
    public function pushAccounts(FinAccountingIntegration $integration, Collection $accounts): array
    {
        $this->ensureValidToken($integration);
        $companyUri = $this->getCompanyFileUri($integration);

        Log::info('MYOB: pushing accounts', [
            'integration_id' => $integration->id,
            'count' => $accounts->count(),
        ]);

        $success = 0;
        $errors = [];

        foreach ($accounts as $account) {
            try {
                $myobClassification = $this->mapAccountTypeToMyob($account->type);

                $payload = [
                    'Number' => $account->code,
                    'Name' => $account->name,
                    'Classification' => $myobClassification,
                    'Type' => $this->mapSubTypeToMyob($account->type, $account->sub_type),
                    'Description' => $account->description ?? '',
                    'IsActive' => $account->is_active,
                ];

                if ($account->myob_account_id) {
                    $payload['UID'] = $account->myob_account_id;
                }

                // Actual API call:
                // PUT {companyUri}/GeneralLedger/Account
                throw new RuntimeException('MYOB API integration pending configuration.');

                $success++;
            } catch (RuntimeException $e) {
                throw $e;
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
        $this->ensureValidToken($integration);
        $companyUri = $this->getCompanyFileUri($integration);

        Log::info('MYOB: pulling accounts', [
            'integration_id' => $integration->id,
            'company_file' => $integration->tenant_id,
        ]);

        // Actual API call:
        // GET {companyUri}/GeneralLedger/Account
        // Headers: Authorization: Bearer {token}, x-myobapi-key: {api_key}
        throw new RuntimeException('MYOB API integration pending configuration.');
    }

    /**
     * {@inheritDoc}
     */
    public function pushJournals(FinAccountingIntegration $integration, Collection $journals): array
    {
        $this->ensureValidToken($integration);
        $companyUri = $this->getCompanyFileUri($integration);

        Log::info('MYOB: pushing journals', [
            'integration_id' => $integration->id,
            'count' => $journals->count(),
        ]);

        $success = 0;
        $errors = [];

        foreach ($journals as $journal) {
            try {
                $journal->loadMissing('lines.account');

                $journalLines = $journal->lines->map(function ($line) use ($integration) {
                    $externalAccountId = $integration->getAccountMappingForLocal($line->account_id);

                    if (! $externalAccountId) {
                        throw new RuntimeException("No MYOB mapping for account #{$line->account_id}");
                    }

                    return [
                        'Account' => ['UID' => $externalAccountId],
                        'Memo' => $line->description ?? $journal->description ?? '',
                        'DebitAmount' => (float) $line->debit,
                        'CreditAmount' => (float) $line->credit,
                    ];
                });

                $payload = [
                    'DateOccurred' => $journal->journal_date->format('Y-m-d\TH:i:s'),
                    'Memo' => $journal->description ?? "Journal {$journal->journal_number}",
                    'Lines' => $journalLines->toArray(),
                ];

                if ($journal->myob_journal_id) {
                    $payload['UID'] = $journal->myob_journal_id;
                }

                // Actual API call:
                // PUT {companyUri}/GeneralLedger/GeneralJournal
                throw new RuntimeException('MYOB API integration pending configuration.');

                $success++;
            } catch (RuntimeException $e) {
                throw $e;
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
        $this->ensureValidToken($integration);
        $companyUri = $this->getCompanyFileUri($integration);

        Log::info('MYOB: pulling journals', [
            'integration_id' => $integration->id,
            'since' => $since,
        ]);

        // Actual API call:
        // GET {companyUri}/GeneralLedger/GeneralJournal?$filter=DateOccurred ge datetime'{since}'
        throw new RuntimeException('MYOB API integration pending configuration.');
    }

    /**
     * {@inheritDoc}
     */
    public function pushInvoices(FinAccountingIntegration $integration, Collection $bills): array
    {
        $this->ensureValidToken($integration);
        $companyUri = $this->getCompanyFileUri($integration);

        Log::info('MYOB: pushing invoices (bills)', [
            'integration_id' => $integration->id,
            'count' => $bills->count(),
        ]);

        $success = 0;
        $errors = [];

        foreach ($bills as $bill) {
            try {
                $bill->loadMissing(['vendor', 'lines.account']);

                $myobContactId = $bill->vendor?->myob_contact_id;
                if (! $myobContactId) {
                    throw new RuntimeException("No MYOB contact mapping for vendor #{$bill->vendor_id}");
                }

                $lineItems = $bill->lines->map(function ($line) use ($integration) {
                    $externalAccountId = $integration->getAccountMappingForLocal($line->account_id);

                    return [
                        'Description' => $line->description ?? '',
                        'ShipQuantity' => (float) $line->quantity,
                        'UnitPrice' => (float) $line->unit_price,
                        'Total' => (float) ($line->quantity * $line->unit_price),
                        'Account' => ['UID' => $externalAccountId],
                        'TaxCode' => null,
                    ];
                });

                $payload = [
                    'Date' => $bill->bill_date->format('Y-m-d\TH:i:s'),
                    'Supplier' => ['UID' => $myobContactId],
                    'SupplierInvoiceNumber' => $bill->vendor_reference ?? $bill->bill_number,
                    'PromisedDate' => $bill->due_date->format('Y-m-d\TH:i:s'),
                    'Lines' => $lineItems->toArray(),
                ];

                if ($bill->myob_invoice_id) {
                    $payload['UID'] = $bill->myob_invoice_id;
                }

                // Actual API call:
                // PUT {companyUri}/Purchase/Bill/Service
                throw new RuntimeException('MYOB API integration pending configuration.');

                $success++;
            } catch (RuntimeException $e) {
                throw $e;
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
        $this->ensureValidToken($integration);
        $companyUri = $this->getCompanyFileUri($integration);

        Log::info('MYOB: pulling invoices', [
            'integration_id' => $integration->id,
            'since' => $since,
        ]);

        // Actual API call:
        // GET {companyUri}/Purchase/Bill/Service?$filter=Date ge datetime'{since}'
        throw new RuntimeException('MYOB API integration pending configuration.');
    }

    /**
     * {@inheritDoc}
     */
    public function pushContacts(FinAccountingIntegration $integration, Collection $vendors): array
    {
        $this->ensureValidToken($integration);
        $companyUri = $this->getCompanyFileUri($integration);

        Log::info('MYOB: pushing contacts (suppliers)', [
            'integration_id' => $integration->id,
            'count' => $vendors->count(),
        ]);

        $success = 0;
        $errors = [];

        foreach ($vendors as $vendor) {
            try {
                $payload = [
                    'CompanyName' => $vendor->name,
                    'FirstName' => $vendor->trading_name ?? $vendor->name,
                    'IsActive' => $vendor->is_active,
                    'Addresses' => [
                        [
                            'Street' => trim(($vendor->address_line_1 ?? '') . ' ' . ($vendor->address_line_2 ?? '')),
                            'City' => $vendor->city ?? '',
                            'State' => $vendor->region ?? '',
                            'PostCode' => $vendor->postal_code ?? '',
                            'Country' => 'New Zealand',
                            'Email' => $vendor->email ?? '',
                            'Phone1' => $vendor->phone ?? '',
                        ],
                    ],
                ];

                if ($vendor->myob_contact_id) {
                    $payload['UID'] = $vendor->myob_contact_id;
                }

                // Actual API call:
                // PUT {companyUri}/Contact/Supplier
                throw new RuntimeException('MYOB API integration pending configuration.');

                $success++;
            } catch (RuntimeException $e) {
                throw $e;
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
        $this->ensureValidToken($integration);
        $companyUri = $this->getCompanyFileUri($integration);

        Log::info('MYOB: pulling contacts (suppliers)', [
            'integration_id' => $integration->id,
        ]);

        // Actual API call:
        // GET {companyUri}/Contact/Supplier
        throw new RuntimeException('MYOB API integration pending configuration.');
    }

    /**
     * {@inheritDoc}
     */
    public function testConnection(FinAccountingIntegration $integration): bool
    {
        if (! $integration->access_token) {
            return false;
        }

        Log::info('MYOB: testing connection', [
            'integration_id' => $integration->id,
            'company_file' => $integration->tenant_id,
        ]);

        try {
            $response = Http::withToken($integration->access_token)
                ->withHeaders(['x-myobapi-key' => config('services.myob.api_key')])
                ->get(self::API_BASE . '/' . $integration->tenant_id . '/Info');

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('MYOB: connection test failed', [
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
            throw new RuntimeException('No refresh token available for MYOB integration.');
        }

        Log::info('MYOB: refreshing access token', [
            'integration_id' => $integration->id,
        ]);

        try {
            $response = Http::asForm()->post('https://secure.myob.com/oauth2/v1/authorize', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $integration->refresh_token,
                'client_id' => config('services.myob.client_id'),
                'client_secret' => config('services.myob.client_secret'),
            ]);

            if (! $response->successful()) {
                throw new RuntimeException('Failed to refresh MYOB token: ' . $response->body());
            }

            $data = $response->json();

            $integration->update([
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? $integration->refresh_token,
                'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 1200),
            ]);

            Log::info('MYOB: token refreshed successfully', [
                'integration_id' => $integration->id,
            ]);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException('Failed to refresh MYOB token: ' . $e->getMessage(), 0, $e);
        }
    }

    // ── Private Helpers ──────────────────────────────────────────────────

    private function ensureValidToken(FinAccountingIntegration $integration): void
    {
        if (! $integration->access_token) {
            throw new RuntimeException('MYOB integration has no access token configured.');
        }

        if ($integration->isTokenExpired()) {
            $this->refreshToken($integration);
        }
    }

    private function getCompanyFileUri(FinAccountingIntegration $integration): string
    {
        if (! $integration->tenant_id) {
            throw new RuntimeException('MYOB integration has no company file URI configured.');
        }

        return self::API_BASE . '/' . $integration->tenant_id;
    }

    /**
     * Map local account type to MYOB classification.
     */
    private function mapAccountTypeToMyob(string $type): string
    {
        return match ($type) {
            'asset' => 'Asset',
            'liability' => 'Liability',
            'equity' => 'Equity',
            'revenue' => 'Income',
            'expense' => 'Expense',
            default => 'Expense',
        };
    }

    /**
     * Map local sub_type to MYOB account type.
     */
    private function mapSubTypeToMyob(string $type, ?string $subType): string
    {
        $mapping = [
            'asset' => [
                'bank' => 'Bank',
                'accounts_receivable' => 'AccountsReceivable',
                'current_asset' => 'OtherCurrentAsset',
                'fixed_asset' => 'FixedAsset',
                'accumulated_depreciation' => 'FixedAsset',
                'default' => 'OtherCurrentAsset',
            ],
            'liability' => [
                'accounts_payable' => 'AccountsPayable',
                'current_liability' => 'CreditCard',
                'long_term_liability' => 'LongTermLiability',
                'default' => 'OtherCurrentLiability',
            ],
            'equity' => [
                'default' => 'Equity',
            ],
            'revenue' => [
                'default' => 'Income',
            ],
            'expense' => [
                'cost_of_sales' => 'CostOfSales',
                'default' => 'Expense',
            ],
        ];

        $typeMap = $mapping[$type] ?? ['default' => 'Expense'];

        return $typeMap[$subType] ?? $typeMap['default'];
    }
}
