<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FinanceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Accepts an optional organization_id parameter. If not provided,
     * seeds for all organizations in the app_settings table.
     */
    public function run(?int $organizationId = null): void
    {
        $organizationIds = $organizationId
            ? [$organizationId]
            : [0];

        foreach ($organizationIds as $orgId) {
            $this->seedTaxRates($orgId);
            $this->seedChartOfAccounts($orgId);
            $this->seedCurrencies($orgId);
        }
    }

    /**
     * Seed default NZ tax rates for an organization.
     */
    private function seedTaxRates(?int $organizationId): void
    {
        $taxRates = [
            [
                'code' => 'GST15',
                'name' => 'GST 15%',
                'rate' => 0.1500,
                'type' => 'gst',
                'is_default' => true,
            ],
            [
                'code' => 'GST0',
                'name' => 'GST 0% Zero-rated',
                'rate' => 0.0000,
                'type' => 'zero_rated',
                'is_default' => false,
            ],
            [
                'code' => 'EXEMPT',
                'name' => 'GST Exempt',
                'rate' => 0.0000,
                'type' => 'exempt',
                'is_default' => false,
            ],
        ];

        foreach ($taxRates as $taxRate) {
            DB::table('fin_tax_rates')->updateOrInsert(
                [
                    'organization_id' => $organizationId,
                    'code' => $taxRate['code'],
                ],
                array_merge($taxRate, [
                    'organization_id' => $organizationId,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }

    /**
     * Seed the default Chart of Accounts for NZ supported living.
     */
    private function seedChartOfAccounts(?int $organizationId): void
    {
        $accounts = $this->getDefaultAccounts();

        foreach ($accounts as $account) {
            DB::table('fin_accounts')->updateOrInsert(
                [
                    'organization_id' => $organizationId,
                    'code' => $account['code'],
                ],
                array_merge($account, [
                    'organization_id' => $organizationId,
                    'is_system' => true,
                    'is_active' => true,
                    'opening_balance' => 0,
                    'gst_applicable' => $account['gst_applicable'] ?? false,
                    'deleted_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }

    /**
     * Return the full default Chart of Accounts.
     */
    private function getDefaultAccounts(): array
    {
        return [
            // ── Assets (1000-1999) ──────────────────────────────────────
            $this->account('1000', 'Bank - Operating', 'asset', 'bank'),
            $this->account('1010', 'Bank - Trust', 'asset', 'bank'),
            $this->account('1100', 'Accounts Receivable', 'asset', 'accounts_receivable'),
            $this->account('1110', 'Whaikaha Receivable', 'asset', 'accounts_receivable'),
            $this->account('1120', 'ACC Receivable', 'asset', 'accounts_receivable'),
            $this->account('1130', 'NASC Receivable', 'asset', 'accounts_receivable'),
            $this->account('1180', 'Card Clearing', 'asset', 'current_asset'),
            $this->account('1200', 'Prepayments', 'asset', 'current_asset'),
            $this->account('1300', 'Petty Cash', 'asset', 'current_asset'),
            $this->account('1500', 'Motor Vehicles - Cost', 'asset', 'fixed_asset'),
            $this->account('1510', 'Equipment - Cost', 'asset', 'fixed_asset'),
            $this->account('1520', 'Furniture & Fittings - Cost', 'asset', 'fixed_asset'),
            $this->account('1530', 'IT Equipment - Cost', 'asset', 'fixed_asset'),
            $this->account('1590', 'Accumulated Depreciation', 'asset', 'accumulated_depreciation'),

            // ── Liabilities (2000-2999) ─────────────────────────────────
            $this->account('2000', 'Accounts Payable', 'liability', 'accounts_payable'),
            $this->account('2100', 'PAYE Payable', 'liability', 'current_liability'),
            $this->account('2110', 'ACC Levy Payable', 'liability', 'current_liability'),
            $this->account('2120', 'KiwiSaver Payable', 'liability', 'current_liability'),
            $this->account('2130', 'Student Loan Payable', 'liability', 'current_liability'),
            $this->account('2140', 'Child Support Payable', 'liability', 'current_liability'),
            $this->account('2200', 'GST Collected', 'liability', 'current_liability', true),
            $this->account('2210', 'GST Paid', 'liability', 'current_liability', true),
            $this->account('2300', 'Accrued Wages', 'liability', 'current_liability'),
            $this->account('2310', 'Expense Claims Payable', 'liability', 'current_liability'),
            $this->account('2400', 'Accrued Leave Liability', 'liability', 'current_liability'),
            $this->account('2500', 'Client Trust Funds', 'liability', 'current_liability'),
            $this->account('2510', 'Provision for Claims', 'liability', 'current_liability'),
            $this->account('2600', 'Income in Advance', 'liability', 'current_liability'),

            // ── Equity (3000-3999) ──────────────────────────────────────
            $this->account('3000', 'Retained Earnings', 'equity', 'equity'),
            $this->account('3100', 'Opening Balance Equity', 'equity', 'equity'),

            // ── Revenue (4000-4999) ─────────────────────────────────────
            $this->account('4000', 'Whaikaha Funding Revenue', 'revenue', 'revenue', true),
            $this->account('4010', 'ACC Revenue', 'revenue', 'revenue', true),
            $this->account('4020', 'NASC Revenue', 'revenue', 'revenue', true),
            $this->account('4030', 'Private Pay Revenue', 'revenue', 'revenue', true),
            $this->account('4100', 'Funding Income', 'revenue', 'revenue', true),
            $this->account('4200', 'House Income', 'revenue', 'revenue', true),
            $this->account('4210', 'Resident Contributions', 'revenue', 'revenue', true),
            $this->account('4220', 'Donations Received', 'revenue', 'revenue'),
            $this->account('4300', 'Interest Income', 'revenue', 'revenue'),

            // ── Expenses (5000-8999) ────────────────────────────────────
            $this->account('5000', 'Wages & Salaries', 'expense', 'expense'),
            $this->account('5010', 'KiwiSaver - Employer', 'expense', 'expense'),
            $this->account('5020', 'ACC Employer Levy', 'expense', 'expense'),
            $this->account('5030', 'Staff Training', 'expense', 'expense', true),
            $this->account('5040', 'Recruitment Costs', 'expense', 'expense', true),
            $this->account('5050', 'Leave Expense', 'expense', 'expense'),
            $this->account('5100', 'Contractor Costs', 'expense', 'expense', true),
            $this->account('6000', 'Rent & Occupancy', 'expense', 'expense', true),
            $this->account('6010', 'Utilities', 'expense', 'expense', true),
            $this->account('6020', 'Insurance', 'expense', 'expense', true),
            $this->account('6030', 'Repairs & Maintenance', 'expense', 'expense', true),
            $this->account('6100', 'Vehicle Running Costs', 'expense', 'expense', true),
            $this->account('6110', 'Vehicle Depreciation', 'expense', 'expense'),
            $this->account('6200', 'Fuel & Oil Expense', 'expense', 'expense', true),
            $this->account('6210', 'Vehicle Maintenance Expense', 'expense', 'expense', true),
            $this->account('6220', 'IT & Communications', 'expense', 'expense', true),
            $this->account('6300', 'Equipment Maintenance Expense', 'expense', 'expense', true),
            $this->account('6310', 'Office Supplies', 'expense', 'expense', true),
            $this->account('6400', 'Rent & Lease Expense', 'expense', 'expense', true),
            $this->account('6410', 'Utilities Expense', 'expense', 'expense', true),
            $this->account('6420', 'Property Maintenance Expense', 'expense', 'expense', true),
            $this->account('6430', 'House Operating Expense', 'expense', 'expense', true),
            $this->account('6431', 'House Groceries', 'expense', 'expense', true),
            $this->account('6432', 'Resident Activities', 'expense', 'expense', true),
            $this->account('6433', 'Petty Cash Expenditure', 'expense', 'expense', true),
            $this->account('6434', 'House Transport Costs', 'expense', 'expense', true),
            $this->account('6435', 'Medical Supplies', 'expense', 'expense', true),
            $this->account('6436', 'Resident Clothing', 'expense', 'expense', true),
            $this->account('6437', 'Household Supplies', 'expense', 'expense', true),
            $this->account('6440', 'Client Personal Expenses', 'expense', 'expense', true),
            $this->account('6450', 'Professional Fees', 'expense', 'expense', true),
            $this->account('6500', 'Staff Expenses', 'expense', 'expense', true),
            $this->account('6510', 'Training & Development Expense', 'expense', 'expense', true),
            $this->account('6520', 'Travel & Mileage Expense', 'expense', 'expense', true),
            $this->account('6530', 'Compliance & Audit', 'expense', 'expense', true),
            $this->account('6600', 'Incident Remediation Expense', 'expense', 'expense', true),
            $this->account('7000', 'Client Support Costs', 'expense', 'expense', true),
            $this->account('7010', 'Client Activities', 'expense', 'expense', true),
            $this->account('7020', 'Client Transport', 'expense', 'expense', true),
            $this->account('8000', 'Depreciation Expense', 'expense', 'expense'),
            $this->account('8100', 'Bank Fees', 'expense', 'expense'),
            $this->account('8200', 'Bad Debts', 'expense', 'expense'),
            $this->account('8300', 'Unrealised FX Gain/Loss', 'expense', 'expense'),
            $this->account('8310', 'Realised FX Gain/Loss', 'expense', 'expense'),
        ];
    }

    /**
     * Seed default currencies for an organization.
     * NZD is the base currency; common trading partners included.
     */
    private function seedCurrencies(?int $organizationId): void
    {
        $currencies = [
            ['code' => 'NZD', 'name' => 'New Zealand Dollar', 'symbol' => '$',  'exchange_rate' => 1.000000, 'is_base' => true],
            ['code' => 'AUD', 'name' => 'Australian Dollar',  'symbol' => 'A$', 'exchange_rate' => 0.920000, 'is_base' => false],
            ['code' => 'USD', 'name' => 'US Dollar',          'symbol' => 'US$', 'exchange_rate' => 0.610000, 'is_base' => false],
            ['code' => 'GBP', 'name' => 'British Pound',      'symbol' => chr(0xC2).chr(0xA3),  'exchange_rate' => 0.480000, 'is_base' => false],
            ['code' => 'EUR', 'name' => 'Euro',               'symbol' => chr(0xE2).chr(0x82).chr(0xAC),  'exchange_rate' => 0.560000, 'is_base' => false],
        ];

        foreach ($currencies as $currency) {
            DB::table('fin_currencies')->updateOrInsert(
                [
                    'organization_id' => $organizationId,
                    'code' => $currency['code'],
                ],
                array_merge($currency, [
                    'organization_id' => $organizationId,
                    'decimal_places' => 2,
                    'is_active' => true,
                    'rate_updated_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }

    /**
     * Helper to build an account definition array.
     */
    private function account(string $code, string $name, string $type, string $subType, bool $gstApplicable = false): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'sub_type' => $subType,
            'gst_applicable' => $gstApplicable,
        ];
    }
}
