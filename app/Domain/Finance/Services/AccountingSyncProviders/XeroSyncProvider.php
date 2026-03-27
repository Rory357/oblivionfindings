<?php

namespace App\Domain\Finance\Services\AccountingSyncProviders;

use App\Domain\Finance\Contracts\AccountingSyncProviderInterface;
use App\Domain\Finance\Models\FinAccountingIntegration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class XeroSyncProvider implements AccountingSyncProviderInterface
{
    private const API_BASE = 'https://api.xero.com/api.xro/2.0';

    /**
     * {@inheritDoc}
     */
    public function pushAccounts(FinAccountingIntegration $integration, Collection $accounts): array
    {
        $this->ensureValidToken($integration);

        Log::info('Xero: pushing accounts', [
            'integration_id' => $integration->id,
            'count' => $accounts->count(),
        ]);

        $success = 0;
        $errors = [];

        foreach ($accounts as $account) {
            try {
                // Map local account type to Xero account type
                $xeroType = $this->mapAccountTypeToXero($account->type, $account->sub_type);

                $payload = [
                    'Code' => $account->code,
                    'Name' => $account->name,
                    'Type' => $xeroType,
                    'Description' => $account->description ?? '',
                    'EnablePaymentsToAccount' => in_array($account->sub_type, ['bank', 'accounts_payable']),
                ];

                if ($account->xero_account_id) {
                    $payload['AccountID'] = $account->xero_account_id;
                }

                // Actual API call would go here
                throw new RuntimeException('Xero API integration pending configuration.');

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

        Log::info('Xero: pulling accounts', [
            'integration_id' => $integration->id,
            'tenant_id' => $integration->tenant_id,
        ]);

        // Actual API call:
        // GET https://api.xero.com/api.xro/2.0/Accounts
        // Headers: Authorization: Bearer {token}, Xero-tenant-id: {tenant_id}
        throw new RuntimeException('Xero API integration pending configuration.');

        // Expected return format:
        // return [
        //     'accounts' => [
        //         ['external_id' => 'abc-123', 'code' => '200', 'name' => 'Sales', 'type' => 'revenue'],
        //     ],
        //     'errors' => [],
        // ];
    }

    /**
     * {@inheritDoc}
     */
    public function pushJournals(FinAccountingIntegration $integration, Collection $journals): array
    {
        $this->ensureValidToken($integration);

        Log::info('Xero: pushing journals', [
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
                        throw new RuntimeException("No Xero mapping for account #{$line->account_id}");
                    }

                    return [
                        'AccountID' => $externalAccountId,
                        'Description' => $line->description ?? $journal->description ?? '',
                        'LineAmount' => (float) $line->debit > 0 ? (float) $line->debit : -((float) $line->credit),
                    ];
                });

                $payload = [
                    'Date' => $journal->journal_date->format('Y-m-d'),
                    'Narration' => $journal->description ?? "Journal {$journal->journal_number}",
                    'JournalLines' => $journalLines->toArray(),
                ];

                // Actual API call:
                // PUT https://api.xero.com/api.xro/2.0/ManualJournals
                throw new RuntimeException('Xero API integration pending configuration.');

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

        Log::info('Xero: pulling journals', [
            'integration_id' => $integration->id,
            'since' => $since,
        ]);

        // Actual API call:
        // GET https://api.xero.com/api.xro/2.0/ManualJournals?where=Date>=DateTime({since})
        throw new RuntimeException('Xero API integration pending configuration.');
    }

    /**
     * {@inheritDoc}
     */
    public function pushInvoices(FinAccountingIntegration $integration, Collection $bills): array
    {
        $this->ensureValidToken($integration);

        Log::info('Xero: pushing invoices (bills)', [
            'integration_id' => $integration->id,
            'count' => $bills->count(),
        ]);

        $success = 0;
        $errors = [];

        foreach ($bills as $bill) {
            try {
                $bill->loadMissing(['vendor', 'lines.account']);

                $xeroContactId = $bill->vendor?->xero_contact_id;
                if (! $xeroContactId) {
                    throw new RuntimeException("No Xero contact mapping for vendor #{$bill->vendor_id}");
                }

                $lineItems = $bill->lines->map(function ($line) use ($integration) {
                    $externalAccountId = $integration->getAccountMappingForLocal($line->account_id);

                    return [
                        'Description' => $line->description ?? '',
                        'Quantity' => (float) $line->quantity,
                        'UnitAmount' => (float) $line->unit_price,
                        'AccountCode' => $externalAccountId,
                        'TaxAmount' => (float) ($line->tax_amount ?? 0),
                    ];
                });

                $payload = [
                    'Type' => 'ACCPAY',
                    'Contact' => ['ContactID' => $xeroContactId],
                    'Date' => $bill->bill_date->format('Y-m-d'),
                    'DueDate' => $bill->due_date->format('Y-m-d'),
                    'Reference' => $bill->vendor_reference ?? $bill->bill_number,
                    'LineItems' => $lineItems->toArray(),
                ];

                if ($bill->xero_invoice_id) {
                    $payload['InvoiceID'] = $bill->xero_invoice_id;
                }

                // Actual API call:
                // PUT https://api.xero.com/api.xro/2.0/Invoices
                throw new RuntimeException('Xero API integration pending configuration.');

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

        Log::info('Xero: pulling invoices', [
            'integration_id' => $integration->id,
            'since' => $since,
        ]);

        // Actual API call:
        // GET https://api.xero.com/api.xro/2.0/Invoices?where=Type=="ACCPAY"&&Date>=DateTime({since})
        throw new RuntimeException('Xero API integration pending configuration.');
    }

    /**
     * {@inheritDoc}
     */
    public function pushContacts(FinAccountingIntegration $integration, Collection $vendors): array
    {
        $this->ensureValidToken($integration);

        Log::info('Xero: pushing contacts', [
            'integration_id' => $integration->id,
            'count' => $vendors->count(),
        ]);

        $success = 0;
        $errors = [];

        foreach ($vendors as $vendor) {
            try {
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

                // Actual API call:
                // PUT https://api.xero.com/api.xro/2.0/Contacts
                throw new RuntimeException('Xero API integration pending configuration.');

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

        Log::info('Xero: pulling contacts', [
            'integration_id' => $integration->id,
        ]);

        // Actual API call:
        // GET https://api.xero.com/api.xro/2.0/Contacts?where=IsSupplier==true
        throw new RuntimeException('Xero API integration pending configuration.');
    }

    /**
     * {@inheritDoc}
     */
    public function testConnection(FinAccountingIntegration $integration): bool
    {
        if (! $integration->access_token) {
            return false;
        }

        Log::info('Xero: testing connection', [
            'integration_id' => $integration->id,
            'tenant_id' => $integration->tenant_id,
        ]);

        try {
            // Actual API call:
            // GET https://api.xero.com/api.xro/2.0/Organisation
            // Headers: Authorization: Bearer {token}, Xero-tenant-id: {tenant_id}
            $response = Http::withToken($integration->access_token)
                ->withHeaders(['Xero-tenant-id' => $integration->tenant_id])
                ->get(self::API_BASE . '/Organisation');

            return $response->successful();
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

        Log::info('Xero: refreshing access token', [
            'integration_id' => $integration->id,
        ]);

        try {
            $response = Http::asForm()->post('https://identity.xero.com/connect/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $integration->refresh_token,
                'client_id' => config('services.xero.client_id'),
                'client_secret' => config('services.xero.client_secret'),
            ]);

            if (! $response->successful()) {
                throw new RuntimeException('Failed to refresh Xero token: ' . $response->body());
            }

            $data = $response->json();

            $integration->update([
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? $integration->refresh_token,
                'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 1800),
            ]);

            Log::info('Xero: token refreshed successfully', [
                'integration_id' => $integration->id,
            ]);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException('Failed to refresh Xero token: ' . $e->getMessage(), 0, $e);
        }
    }

    // ── Private Helpers ──────────────────────────────────────────────────

    private function ensureValidToken(FinAccountingIntegration $integration): void
    {
        if (! $integration->access_token) {
            throw new RuntimeException('Xero integration has no access token configured.');
        }

        if ($integration->isTokenExpired()) {
            $this->refreshToken($integration);
        }
    }

    /**
     * Map local account type/sub_type to Xero's account type string.
     */
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
}
