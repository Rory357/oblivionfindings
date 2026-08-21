<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class OperationsPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Operations Dashboard
            ['key' => 'operations.dashboard.view', 'description' => 'View Operations Dashboard'],
            ['key' => 'operations.dashboard.viewAllSites', 'description' => 'View Operations Dashboard Across All Sites'],

            // Care Plans
            ['key' => 'care_plans.viewAny', 'description' => 'View Care Plans'],
            ['key' => 'care_plans.create', 'description' => 'Create Care Plans'],
            ['key' => 'care_plans.update', 'description' => 'Update Care Plans'],
            ['key' => 'care_plans.delete', 'description' => 'Delete Care Plans'],
            ['key' => 'care_plans.goals.manage', 'description' => 'Manage Care Plan Goals'],

            // Progress Notes
            ['key' => 'progress_notes.viewAny', 'description' => 'View Progress Notes'],
            ['key' => 'progress_notes.create', 'description' => 'Create Progress Notes'],
            ['key' => 'progress_notes.update', 'description' => 'Update Progress Notes'],
            ['key' => 'progress_notes.delete', 'description' => 'Delete Progress Notes'],
            ['key' => 'progress_notes.review', 'description' => 'Review flagged Progress Notes'],

            // Service Agreements
            ['key' => 'service_agreements.viewAny', 'description' => 'View Service Agreements'],
            ['key' => 'service_agreements.create', 'description' => 'Create Service Agreements'],
            ['key' => 'service_agreements.update', 'description' => 'Update Service Agreements'],
            ['key' => 'service_agreements.delete', 'description' => 'Delete Service Agreements'],

            // Billing
            ['key' => 'billing.viewAny', 'description' => 'View Billing'],
            ['key' => 'billing.create', 'description' => 'Create Billing Entries'],
            ['key' => 'billing.approve', 'description' => 'Approve Billing Entries'],

            // Invoices
            ['key' => 'invoices.viewAny', 'description' => 'View Invoices'],
            ['key' => 'invoices.create', 'description' => 'Create Invoices'],
            ['key' => 'invoices.send', 'description' => 'Send Invoices'],
            ['key' => 'invoices.update', 'description' => 'Update Invoices'],
            ['key' => 'invoices.void', 'description' => 'Void Invoices'],

            // Funding
            ['key' => 'funding.viewAny', 'description' => 'View Funding'],
            ['key' => 'funding.viewAllSites', 'description' => 'View Funding across all active Sites'],
            ['key' => 'funding.claims.create', 'description' => 'Create Funding Claims'],
            ['key' => 'funding.claims.submit', 'description' => 'Submit Funding Claims'],
            ['key' => 'funding.claims.approve', 'description' => 'Approve Funding Claims'],
            ['key' => 'funding.claims.retryPosting', 'description' => 'Retry failed Funding Claim journal posting'],

            // Messages
            ['key' => 'messages.viewAny', 'description' => 'View Messages'],
            ['key' => 'messages.send', 'description' => 'Send Messages'],

            // Handovers
            ['key' => 'handovers.viewAny', 'description' => 'View Handovers'],
            ['key' => 'handovers.create', 'description' => 'Create Handovers'],

            // Roster Templates
            ['key' => 'roster_templates.viewAny', 'description' => 'View Roster Templates'],
            ['key' => 'roster_templates.create', 'description' => 'Create Roster Templates'],
            ['key' => 'roster_templates.update', 'description' => 'Update Roster Templates'],
            ['key' => 'roster_templates.delete', 'description' => 'Delete Roster Templates'],
            ['key' => 'rostering.autoSchedule', 'description' => 'Auto-schedule Roster'],
            ['key' => 'rostering.publish', 'description' => 'Publish Roster'],

            // Reports
            ['key' => 'operations.reports.view', 'description' => 'View Operations Reports'],

            // Price Books
            ['key' => 'price_books.viewAny', 'description' => 'View Price Books'],
            ['key' => 'price_books.create', 'description' => 'Create Price Books'],
            ['key' => 'price_books.update', 'description' => 'Update Price Books'],

            // Quotes
            ['key' => 'quotes.viewAny', 'description' => 'View Quotes'],
            ['key' => 'quotes.create', 'description' => 'Create Quotes'],
            ['key' => 'quotes.update', 'description' => 'Update Quotes'],

            // Client Funds
            ['key' => 'client_funds.manage', 'description' => 'Manage Client Funds'],
            ['key' => 'client_funds.approve', 'description' => 'Approve Client Fund Transactions'],
            ['key' => 'client_funds.viewAllSites', 'description' => 'View Client Funds Across All Sites'],

            // Custom Forms
            ['key' => 'custom_forms.viewAny', 'description' => 'View Custom Forms'],
            ['key' => 'custom_forms.create', 'description' => 'Create Custom Forms'],
            ['key' => 'custom_forms.update', 'description' => 'Update Custom Forms'],
            ['key' => 'custom_forms.submit', 'description' => 'Submit Custom Forms'],

            // EVV
            ['key' => 'evv.viewAny', 'description' => 'View EVV Records'],
            ['key' => 'evv.record', 'description' => 'Record EVV Check-in/out'],
            ['key' => 'evv.verify', 'description' => 'Verify EVV Records'],

            // Mileage
            ['key' => 'mileage.viewAny', 'description' => 'View All Mileage Claims'],
            ['key' => 'mileage.viewOwn', 'description' => 'View Own Mileage Claims'],
            ['key' => 'mileage.create', 'description' => 'Create Mileage Claims'],
            ['key' => 'mileage.approve', 'description' => 'Approve Mileage Claims'],

            // Care Note Templates
            ['key' => 'care_note_templates.viewAny', 'description' => 'View Care Note Templates'],

            // Payroll Export
            ['key' => 'payroll.export', 'description' => 'Export Payroll Data'],

            // Onboarding
            ['key' => 'onboarding.viewAny', 'description' => 'View Onboarding Workflows'],
            ['key' => 'onboarding.view', 'description' => 'View Onboarding Workflow Detail'],
            ['key' => 'onboarding.create', 'description' => 'Create Onboarding Workflows'],
            ['key' => 'onboarding.edit', 'description' => 'Edit Onboarding Workflows'],

            // Job Board
            ['key' => 'job_board.viewAny', 'description' => 'View Job Board'],
            ['key' => 'job_board.create', 'description' => 'Create Open Positions'],
            ['key' => 'job_board.claim', 'description' => 'Claim Open Positions'],
            ['key' => 'job_board.approve', 'description' => 'Approve Position Claims'],

            // Qualifications
            ['key' => 'qualifications.viewAny', 'description' => 'View Qualification Requirements'],
            ['key' => 'qualifications.create', 'description' => 'Create Qualification Requirements'],
            ['key' => 'qualifications.edit', 'description' => 'Edit Qualification Requirements'],
            ['key' => 'qualifications.delete', 'description' => 'Delete Qualification Requirements'],

            // Geofences
            ['key' => 'geofences.viewAny', 'description' => 'View Geofence Zones'],
            ['key' => 'geofences.create', 'description' => 'Create Geofence Zones'],
            ['key' => 'geofences.edit', 'description' => 'Edit Geofence Zones'],
            ['key' => 'geofences.delete', 'description' => 'Delete Geofence Zones'],

            // Recurring Charges
            ['key' => 'recurring_charges.viewAny', 'description' => 'View Recurring Charges'],
            ['key' => 'recurring_charges.manage', 'description' => 'Manage Recurring Charges'],

            // Note Templates
            ['key' => 'note_templates.viewAny', 'description' => 'View Care Note Templates'],
            ['key' => 'note_templates.manage', 'description' => 'Manage Care Note Templates'],

            // Payroll Exports
            ['key' => 'payroll_exports.viewAny', 'description' => 'View Payroll Exports'],
            ['key' => 'payroll_exports.manage', 'description' => 'Manage Payroll Exports'],

            // Family Portal
            ['key' => 'family_portal.viewAny', 'description' => 'View Family Portal Settings'],
            ['key' => 'family_portal.manage', 'description' => 'Manage Family Portal Settings'],

            // Canonical medication / eMAR access
            ['key' => 'medications.view', 'description' => 'View medications / eMAR module'],
        ];

        $created = 0;
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(
                ['key' => $perm['key']],
                ['description' => $perm['description']]
            );
            $created++;
        }

        // Attach all to admin role
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $allPermissionIds = Permission::pluck('id')->all();
            $adminRole->permissions()->sync($allPermissionIds);
        }

        $this->command->info("Created/verified {$created} Operations permissions. Admin role synced.");
    }
}
