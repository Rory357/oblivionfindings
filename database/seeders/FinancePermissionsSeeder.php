<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class FinancePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | 1. Create all finance permissions
        |--------------------------------------------------------------------------
        */
        $permissionDefinitions = [
            // Dashboard
            ['key' => 'finance.dashboard', 'description' => 'View the finance dashboard'],
            ['key' => 'finance.insights.viewAllSites', 'description' => 'View Financial Insights across all active Sites'],
            ['key' => 'finance.payments.viewAllSites', 'description' => 'View payment allocation history across all active Sites'],
            ['key' => 'finance.payments.manageAllSites', 'description' => 'Manage payment settlement across all active Sites'],
            ['key' => 'finance.donorFunds.manageAllSites', 'description' => 'Manage donor-fund bill applications across all active Sites'],

            // General Ledger
            ['key' => 'finance.ledger.view', 'description' => 'View chart of accounts, journals, and ledger entries'],
            ['key' => 'finance.ledger.manage', 'description' => 'Create, edit, and delete accounts, journals, and ledger entries'],

            // Accounts Payable
            ['key' => 'finance.ap.view', 'description' => 'View bills, vendors, and purchase orders'],
            ['key' => 'finance.ap.manage', 'description' => 'Create, edit, and process bills, vendors, and purchase orders'],

            // Accounts Receivable
            ['key' => 'finance.ar.view', 'description' => 'View invoices, credit notes, and receivables'],
            ['key' => 'finance.ar.manage', 'description' => 'Create, edit, and process invoices, credit notes, and receivables'],

            // Banking
            ['key' => 'finance.bank.view', 'description' => 'View bank accounts, transactions, and reconciliations'],
            ['key' => 'finance.bank.manage', 'description' => 'Manage bank accounts, transactions, feeds, and reconciliations'],

            // Tax
            ['key' => 'finance.tax.view', 'description' => 'View GST returns, IRD filings, and tax reports'],
            ['key' => 'finance.tax.manage', 'description' => 'File GST returns, manage IRD filings, and configure tax settings'],

            // Fixed Assets
            ['key' => 'finance.assets.view', 'description' => 'View fixed assets and depreciation schedules'],
            ['key' => 'finance.assets.manage', 'description' => 'Create, edit, and dispose of fixed assets'],

            // Petty Cash
            ['key' => 'finance.petty_cash.view', 'description' => 'View petty cash funds and transactions'],
            ['key' => 'finance.petty_cash.manage', 'description' => 'Manage petty cash funds, record transactions, and reconcile'],

            // Administration
            ['key' => 'finance.admin', 'description' => 'Finance administration including fiscal periods, currencies, and integrations'],

            // Reports
            ['key' => 'finance.reports.view', 'description' => 'View financial reports, budgets, and cash flow forecasts'],
        ];

        $allPermissions = [];

        foreach ($permissionDefinitions as $def) {
            $allPermissions[] = Permission::firstOrCreate(
                ['key' => $def['key']],
                [
                    'description' => $def['description'],
                    'group' => 'finance',
                    'module' => 'finance',
                ]
            );
        }

        $allPermissionIds = collect($allPermissions)->pluck('id')->toArray();

        /*
        |--------------------------------------------------------------------------
        | 2. View-only permission IDs (for auditor role)
        |--------------------------------------------------------------------------
        */
        $viewOnlyKeys = [
            'finance.dashboard',
            'finance.insights.viewAllSites',
            'finance.payments.viewAllSites',
            'finance.ledger.view',
            'finance.ap.view',
            'finance.ar.view',
            'finance.bank.view',
            'finance.tax.view',
            'finance.assets.view',
            'finance.petty_cash.view',
            'finance.reports.view',
        ];

        $viewOnlyIds = collect($allPermissions)
            ->filter(fn ($p) => in_array($p->key, $viewOnlyKeys))
            ->pluck('id')
            ->toArray();

        /*
        |--------------------------------------------------------------------------
        | 3. Assign permissions to roles
        |--------------------------------------------------------------------------
        */
        $financeRole = Role::firstOrCreate(
            ['name' => 'finance'],
            ['label' => 'Finance', 'level' => 50, 'type' => 'system', 'description' => 'Finance department access']
        );

        $adminRole = Role::firstOrCreate(
            ['name' => 'admin'],
            ['label' => 'Administrator', 'level' => 100, 'type' => 'system', 'description' => 'Full system access across all sites']
        );

        $auditorRole = Role::firstOrCreate(
            ['name' => 'auditor'],
            ['label' => 'Auditor (Read only)', 'level' => 45, 'type' => 'system', 'description' => 'Read-only audit and reporting access']
        );

        // Finance role gets ALL finance permissions
        $financeRole->permissions()->syncWithoutDetaching($allPermissionIds);

        // Admin role gets ALL finance permissions
        $adminRole->permissions()->syncWithoutDetaching($allPermissionIds);

        // Auditor role gets view-only finance permissions
        $auditorRole->permissions()->syncWithoutDetaching($viewOnlyIds);
    }
}
