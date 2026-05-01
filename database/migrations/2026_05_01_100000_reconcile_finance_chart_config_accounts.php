<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fin_accounts')) {
            return;
        }

        $organizationIds = DB::table('fin_accounts')
            ->distinct()
            ->pluck('organization_id')
            ->filter(fn ($organizationId): bool => $organizationId !== null)
            ->values();

        if ($organizationIds->isEmpty()) {
            $organizationIds = collect([0]);
        }

        foreach ($organizationIds as $organizationId) {
            foreach ($this->accounts() as $account) {
                DB::table('fin_accounts')->updateOrInsert(
                    [
                        'organization_id' => (int) $organizationId,
                        'code' => $account['code'],
                    ],
                    array_merge($account, [
                        'organization_id' => (int) $organizationId,
                        'is_system' => true,
                        'is_active' => true,
                        'opening_balance' => 0,
                        'deleted_at' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]),
                );
            }
        }
    }

    public function down(): void
    {
        // Data reconciliation only; do not remove chart accounts on rollback.
    }

    private function accounts(): array
    {
        return [
            $this->account('1000', 'Bank - Operating', 'asset', 'bank'),
            $this->account('2000', 'Accounts Payable', 'liability', 'accounts_payable'),
            $this->account('2310', 'Expense Claims Payable', 'liability', 'current_liability'),
            $this->account('2400', 'Accrued Leave Liability', 'liability', 'current_liability'),
            $this->account('2500', 'Client Trust Funds', 'liability', 'current_liability'),
            $this->account('2510', 'Provision for Claims', 'liability', 'current_liability'),
            $this->account('4100', 'Funding Income', 'revenue', 'revenue', true),
            $this->account('4200', 'House Income', 'revenue', 'revenue', true),
            $this->account('4210', 'Resident Contributions', 'revenue', 'revenue', true),
            $this->account('4220', 'Donations Received', 'revenue', 'revenue'),
            $this->account('4300', 'Interest Income', 'revenue', 'revenue'),
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
        ];
    }

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
};
