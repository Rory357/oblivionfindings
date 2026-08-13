<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Client money governance
    |--------------------------------------------------------------------------
    |
    | Client-held money is never an ordinary administrative balance. Debits,
    | transfers and reversals always require an independent checker. Credits
    | at or above the threshold do too. A negative balance is prohibited unless
    | a separately governed product workflow has explicitly authorized a limit
    | on that fund; the normal Client Funds UI cannot set that policy state.
    |
    */

    'client_funds' => [
        'currency' => env('FINANCE_CLIENT_FUNDS_CURRENCY', 'NZD'),
        'approval_threshold' => env('FINANCE_CLIENT_FUNDS_APPROVAL_THRESHOLD', '500.00'),
        'sensitive_transaction_types' => ['debit', 'transfer', 'reversal', 'transfer_reversal'],
        'site_bypass_permission' => 'client_funds.viewAllSites',
    ],

    /*
    |--------------------------------------------------------------------------
    | GL Account Code Mappings for Operational Financial Events
    |--------------------------------------------------------------------------
    |
    | These codes map operational event types to debit GL account codes.
    | The credit account is determined by the payment_type, unless
    | explicitly overridden with 'credit' in the event config.
    |
    | Existing flows (payroll, billing, depreciation) use their own hardcoded
    | account codes and are NOT affected by this config.
    |
    */

    'event_accounts' => [

        // ── Fleet ──────────────────────────────────────────────
        'fuel_expense' => [
            'debit' => '6200',   // Fuel & Oil Expense
            // Fuel is paid at the pump (fuel card) — credit Card Clearing, NOT
            // Accounts Payable: an AP credit with no bill can never be settled by
            // a payment run, so 2000 accrued unreconcilable balances. 1180 clears
            // on bank reconciliation when the card statement arrives.
            'credit' => '1180',  // Card Clearing
            'journal_type' => 'standard',
        ],

        'fleet_maintenance_expense' => [
            'debit' => '6210',   // Vehicle Maintenance Expense
            'journal_type' => 'standard',
        ],

        // ── Assets (non-fleet) ─────────────────────────────────
        'asset_maintenance_expense' => [
            'debit' => '6300',   // Equipment Maintenance Expense
            'journal_type' => 'standard',
        ],

        // ── HR ─────────────────────────────────────────────────
        'expense_claim' => [
            'debit' => '6500',   // Staff Expenses
            'journal_type' => 'standard',
        ],

        'training_cost' => [
            'debit' => '6510',   // Training & Development Expense
            'journal_type' => 'standard',
        ],

        'mileage_reimbursement' => [
            'debit' => '6520',   // Travel & Mileage Expense
            'journal_type' => 'standard',
        ],

        'leave_provision' => [
            'debit' => '5050',   // Leave Expense (dedicated — NOT 5020 ACC Employer Levy)
            'credit' => '2400',  // Accrued Leave Liability
            'journal_type' => 'standard',
        ],

        // ── Sites ──────────────────────────────────────────────
        'site_rent_expense' => [
            'debit' => '6400',   // Rent & Lease Expense
            'journal_type' => 'recurring',
        ],

        'site_utilities_expense' => [
            'debit' => '6410',   // Utilities Expense
            'journal_type' => 'standard',
        ],

        'site_utilities_true_up' => [
            'debit' => '6410',   // Utilities Expense (same account — true-up is a correction)
            'journal_type' => 'adjustment',
        ],

        'site_maintenance_expense' => [
            'debit' => '6420',   // Property Maintenance Expense
            'journal_type' => 'standard',
        ],

        // ── House Ledger GL Integration ────────────────────────
        'house_ledger_expense' => [
            'debit' => '6430',   // House Operating Expense (generic fallback)
            'credit' => '1000',  // Bank (cash paid out)
            'journal_type' => 'standard',
        ],

        'house_ledger_income' => [
            'debit' => '1000',   // Bank (cash received)
            'credit' => '4200',  // House Income (generic fallback)
            'journal_type' => 'standard',
        ],

        // ── Client Ledger GL Integration ───────────────────────
        'client_ledger_expense' => [
            'debit' => '6440',   // Client Personal Expenses (generic fallback)
            'credit' => '1000',  // Bank
            'journal_type' => 'standard',
        ],

        'client_ledger_income' => [
            'debit' => '1000',   // Bank
            'credit' => '4210',  // Resident Contributions (generic fallback)
            'journal_type' => 'standard',
        ],

        // ── Incidents (future) ─────────────────────────────────
        'incident_remediation' => [
            'debit' => '6600',   // Incident Remediation Expense
            'credit' => '2510',  // Provision for Claims
            'journal_type' => 'standard',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fixed-asset disposal
    |--------------------------------------------------------------------------
    |
    | The balancing (gain or loss) leg of a fixed-asset disposal journal posts
    | to this account. It MUST be a dedicated account — `8100` was historically
    | hardcoded in FixedAssetService but the seeded chart uses `8100` for Bank
    | Fees, so a disposal at a gain/loss either mis-posted (real orgs) or silently
    | dropped the balancing line and rolled the whole disposal back (demo/unseeded).
    |
    */

    'fixed_asset' => [
        'gain_loss_account' => '8400',   // Gain/Loss on Asset Disposal
    ],

    /*
    |--------------------------------------------------------------------------
    | Account Name Assertions (config-driven name-parity check)
    |--------------------------------------------------------------------------
    |
    | The single source of truth for the *intended name* of each operationally
    | posted GL code. `finance:verify-chart` asserts the seeded account's name
    | contains the keyword below (case-insensitive). This catches a code being
    | seeded under a contradictory name — the bug class where `leave_provision`
    | once debited `5020` "ACC Employer Levy" instead of a real Leave Expense
    | account. Existence + active is checked separately from the codes that
    | actually appear as values elsewhere in this config.
    |
    */

    'account_names' => [
        '1000' => 'Bank',
        '1010' => 'Trust',
        '1100' => 'Accounts Receivable',
        '1110' => 'Whaikaha',
        '1120' => 'ACC',
        '1130' => 'NASC',
        '1180' => 'Card Clearing',
        '2000' => 'Accounts Payable',
        '2150' => 'ESCT',
        '2310' => 'Expense Claims Payable',
        '2400' => 'Accrued Leave',
        '2510' => 'Provision for Claims',
        '4100' => 'Funding Income',
        '4200' => 'House Income',
        '4210' => 'Resident Contributions',
        '4220' => 'Donations',
        '5010' => 'KiwiSaver',
        '5020' => 'ACC Employer Levy',
        '5050' => 'Leave Expense',
        '6200' => 'Fuel',
        '6210' => 'Vehicle Maintenance',
        '6300' => 'Equipment Maintenance',
        '6400' => 'Rent',
        '6410' => 'Utilities',
        '6420' => 'Property Maintenance',
        '6430' => 'House Operating',
        '6431' => 'Groceries',
        '6432' => 'Activities',
        '6433' => 'Petty Cash',
        '6434' => 'Transport',
        '6435' => 'Medical',
        '6436' => 'Clothing',
        '6437' => 'Household',
        '6440' => 'Client Personal',
        '6500' => 'Staff Expenses',
        '6510' => 'Training',
        '6520' => 'Travel',
        '6600' => 'Incident Remediation',
    ],

    /*
    |--------------------------------------------------------------------------
    | House Ledger Category → GL Account Mappings
    |--------------------------------------------------------------------------
    |
    | When a HouseLedgerEntry has a known category, the expense or income
    | is posted to a more specific GL account for granular reporting.
    |
    | If a category is NOT listed here, the generic 6430 (expense) or
    | 4200 (income) account is used as fallback.
    |
    | To add a new category: add the mapping here and ensure the GL account
    | exists in the organisation's chart of accounts.
    |
    */

    'house_ledger_expense_categories' => [
        'groceries' => '6431',  // House Groceries
        'activities' => '6432',  // Resident Activities
        'petty_cash' => '6433',  // Petty Cash Expenditure
        'maintenance' => '6420',  // Property Maintenance (shared with site maintenance)
        'transport' => '6434',  // House Transport Costs
        'medical' => '6435',  // Medical Supplies
        'clothing' => '6436',  // Resident Clothing
        'household' => '6437',  // Household Supplies
        // Unmapped categories → 6430 (generic House Operating Expense)
    ],

    'house_ledger_income_categories' => [
        'resident_contribution' => '4210',  // Resident Contributions
        'donations' => '4220',  // Donations Received
        'funding' => '4100',  // Funding Income
        // Unmapped categories → 4200 (generic House Income)
    ],

    /*
    |--------------------------------------------------------------------------
    | Client Ledger Category → GL Account Mappings
    |--------------------------------------------------------------------------
    |
    | When a ClientLedgerEntry has a category, the expense or income is posted
    | to a specific GL account. Unmapped categories use the generic fallback.
    |
    */

    'client_ledger_expense_categories' => [
        'groceries' => '6431',  // House Groceries (shared with house ledger)
        'clothing' => '6436',  // Resident Clothing
        'activities' => '6432',  // Resident Activities
        'medical' => '6435',  // Medical Supplies
        'transport' => '6434',  // Transport Costs
        'personal' => '6440',  // Client Personal Expenses (generic)
        // Unmapped → 6440
    ],

    'client_ledger_income_categories' => [
        'contribution' => '4210',  // Resident Contributions
        'resident_contribution' => '4210',  // Alias
        'donation' => '4220',  // Donations
        'funding' => '4100',  // Funding Income
        // Unmapped → 4210
    ],

    /*
    |--------------------------------------------------------------------------
    | EFTPOS Settlement Account Mapping
    |--------------------------------------------------------------------------
    |
    | Reconciled EFTPOS batches move settled card funds from the card clearing
    | asset account into the operating bank account.
    |
    */

    'eftpos_settlement_accounts' => [
        'bank' => '1000',
        'card_clearing' => '1180',
    ],

    /*
    |--------------------------------------------------------------------------
    | Bank Feed Provider Availability
    |--------------------------------------------------------------------------
    |
    | Automated NZ bank API feeds are intentionally hidden until provider access
    | and consent flows are production-ready. CSV import remains the supported
    | transaction import path.
    |
    */

    'bank_feeds' => [
        'provider_setup_enabled' => env('FINANCE_BANK_FEED_PROVIDER_SETUP_ENABLED', false),
        'csv_import_supported' => true,
        'provider_setup_message' => 'Automated NZ bank-feed provider setup is not available yet. Use CSV import for bank transactions.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Type → Credit Account Mapping
    |--------------------------------------------------------------------------
    |
    | When a financial event does NOT specify an explicit credit_account_code,
    | the credit account is resolved from the payment_type using this map.
    |
    */

    'payment_type_accounts' => [
        'ap' => '2000',  // Accounts Payable (vendor invoice)
        'cash' => '1000',  // Bank / Cash at Bank
        'reimburse' => '2310',  // Expense Claims Payable (staff reimbursement)
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Accounts Payable Code
    |--------------------------------------------------------------------------
    |
    | Fallback when payment_type is unrecognised.
    |
    */

    'default_ap_code' => '2000',

    /*
    |--------------------------------------------------------------------------
    | Mileage Reimbursement Rate (NZD per km)
    |--------------------------------------------------------------------------
    |
    | NZ IRD standard rate for employee vehicle use. As of 2025-2026 tax year,
    | the IRD Tier 1 rate is $0.95/km for the first 14,000km.
    |
    | Override per tenant: future — tenant-level config not yet implemented.
    |
    */

    'mileage_rate_per_km' => env('FINANCE_MILEAGE_RATE_PER_KM', 0.95),

    /*
    |--------------------------------------------------------------------------
    | Employer On-Cost GL Account Codes
    |--------------------------------------------------------------------------
    |
    | Payroll journal debit lines with these account codes are identified as
    | employer on-costs for cost allocation purposes.
    |
    | DR 5010 = KiwiSaver - Employer Contribution (debit expense in journal)
    |
    | ACC employer levy is also an on-cost, taken from payslip data
    | (acc_levy field) even though it appears only as CR 2110 in the journal.
    |
    */

    'employer_oncost_expense_accounts' => [
        '5010', // KiwiSaver - Employer Contribution
        '5020', // ACC Employer Levy (new in PR10 — posted as debit expense in payroll journal)
    ],

    /*
    |--------------------------------------------------------------------------
    | Financial Insight Thresholds
    |--------------------------------------------------------------------------
    |
    | Configurable thresholds for the FinancialInsightsService.
    | Adjust these to control when warnings and critical alerts fire.
    |
    */

    'insight_thresholds' => [
        // Site cost increase (month-over-month %)
        'site_cost_increase_warning_pct' => 15,
        'site_cost_increase_critical_pct' => 30,

        // Client funding gap (weekly NZD shortfall)
        'client_funding_gap_warning_weekly' => 200,
        'client_funding_gap_critical_weekly' => 500,

        // Utility cost increase (month-over-month %)
        'utility_increase_warning_pct' => 20,

        // Budget variance (% of budget consumed)
        'budget_approaching_pct' => 85,  // Warning when actual ≥ 85% of planned
        'budget_over_pct' => 100,        // Critical when actual ≥ 100% of planned

        // Staffing insights
        'employer_oncost_high_pct' => 12, // Info when employer on-costs ≥ 12% of wages
        'staffing_pct_of_total_warning' => 75, // Warning when staffing ≥ 75% of total site cost
    ],

    /*
    |--------------------------------------------------------------------------
    | Budget Variance Alert Delivery
    |--------------------------------------------------------------------------
    */

    'budget_variance_alerts' => [
        'debounce_hours' => env('FINANCE_BUDGET_VARIANCE_ALERT_DEBOUNCE_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Export Storage
    |--------------------------------------------------------------------------
    */

    'audit_exports' => [
        'disk' => env('FINANCE_AUDIT_EXPORT_DISK', 'local'),
        'retention_years' => env('FINANCE_AUDIT_EXPORT_RETENTION_YEARS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Spend Approval Gate (governance sign-off on large bills)
    |--------------------------------------------------------------------------
    |
    | When `enforce` is on, an accounts-payable bill whose total is at or above
    | `threshold` cannot be approved (and its GL journal posted) unless it is
    | linked to a governance SpendApproval that is APPROVED and covers the full
    | bill amount. Opt-in: enforcement is off by default so existing AP flows
    | are unaffected until an org turns it on. Linking a spend approval to a
    | bill (spend_approval_id) is always permitted regardless of this setting.
    |
    */

    'spend_approval' => [
        'enforce' => env('FINANCE_SPEND_APPROVAL_ENFORCE', false),
        'threshold' => env('FINANCE_SPEND_APPROVAL_THRESHOLD', 10000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Capture-at-source (operational events → canonical finance documents)
    |--------------------------------------------------------------------------
    |
    | Vendor names + GL expense codes used when an operational event in another
    | module is captured as a draft accounts-payable bill. Codes must exist in
    | the chart of accounts (AccountsPayableService resolves them and throws if
    | missing — never invents a code).
    |
    */

    'capture' => [
        'damage_repair_account' => env('FINANCE_CAPTURE_DAMAGE_ACCOUNT', '6420'), // Property Maintenance Expense
        'damage_repair_vendor' => env('FINANCE_CAPTURE_DAMAGE_VENDOR', 'Property Repairs'),
        'respite_revenue_account' => env('FINANCE_CAPTURE_RESPITE_ACCOUNT', '4000'), // Funding Revenue
        'insurance_revenue_account' => env('FINANCE_CAPTURE_INSURANCE_ACCOUNT', '4230'), // Insurance Recoveries
        'maintenance_vendor' => env('FINANCE_CAPTURE_MAINTENANCE_VENDOR', 'Maintenance Contractor'),
        // Operational assets valued at/above this land on the fixed-asset register
        // (WITHOUT GL accounts — finance capitalises explicitly, so no auto journal).
        'asset_capitalisation_threshold' => env('FINANCE_CAPTURE_ASSET_THRESHOLD', 1000),
        'asset_capital_categories' => ['vehicle', 'equipment', 'building', 'furniture', 'it_equipment', 'land'],
        'asset_useful_life_months' => env('FINANCE_CAPTURE_ASSET_LIFE_MONTHS', 60),
    ],

];
