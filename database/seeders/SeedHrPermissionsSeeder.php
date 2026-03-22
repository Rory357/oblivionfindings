<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class SeedHrPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Org Chart
            'hr.orgchart.view' => 'View organisation chart',
            'hr.orgchart.manage' => 'Manage reporting structure',

            // Positions
            'hr.positions.view' => 'View positions/jobs',
            'hr.positions.manage' => 'Create and edit positions',

            // Time Tracking
            'hr.time.view' => 'View time tracking',
            'hr.time.viewAny' => 'View all time entries',
            'hr.time.manage' => 'Manage time entries',
            'hr.time.approve' => 'Approve/reject timesheets',

            // Compensation
            'hr.compensation.view' => 'View compensation data',
            'hr.compensation.manage' => 'Manage salary bands and reviews',

            // Benefits
            'hr.benefits.view' => 'View benefit plans and enrollments',
            'hr.benefits.manage' => 'Manage benefit plans and enroll staff',

            // Goals
            'hr.goals.view' => 'View goals and OKRs',
            'hr.goals.manage' => 'Create and manage goals',

            // Training (LMS)
            'hr.training.enroll' => 'Enrol staff in training courses',

            // Assets
            'hr.assets.view' => 'View company assets',
            'hr.assets.manage' => 'Manage assets and assignments',

            // Calendar
            'hr.calendar.view' => 'View company calendar',
            'hr.calendar.manage' => 'Create and manage calendar events',

            // Analytics
            'hr.analytics.view' => 'View workforce analytics dashboard',

            // Surveys
            'hr.surveys.view' => 'View employee surveys',
            'hr.surveys.manage' => 'Create and manage surveys',

            // Expenses
            'hr.expenses.view' => 'View expense claims',
            'hr.expenses.manage' => 'Create and submit expense claims',
            'hr.expenses.approve' => 'Approve/reject expense claims',

            // Skills
            'hr.skills.view' => 'View skills matrix',
            'hr.skills.manage' => 'Manage skills and assessments',

            // Announcements
            'hr.announcements.view' => 'View company announcements',
            'hr.announcements.manage' => 'Create and manage announcements',

            // Exit Interviews
            'hr.exit-interviews.view' => 'View exit interviews',
            'hr.exit-interviews.manage' => 'Conduct and manage exit interviews',

            // Approvals
            'hr.approvals.view' => 'View approval workflows',
            'hr.approvals.manage' => 'Manage approval chain configurations',

            // E-Signatures
            'hr.signatures.view' => 'View document signatures',
            'hr.signatures.manage' => 'Request document signatures',

            // Payslips
            'hr.payslips.view' => 'View payslips',
            'hr.payslips.generate' => 'Generate payslips',

            // Report Builder
            'hr.reports.builder' => 'Use custom report builder',
        ];

        $createdIds = [];

        foreach ($permissions as $key => $description) {
            $perm = Permission::firstOrCreate(
                ['key' => $key],
                ['description' => $description]
            );
            $createdIds[] = $perm->id;
        }

        // Attach all new permissions to the admin role
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $existing = $adminRole->permissions()->pluck('permissions.id')->all();
            $toAttach = array_diff($createdIds, $existing);
            if (!empty($toAttach)) {
                $adminRole->permissions()->attach($toAttach);
            }
            $this->command->info('Attached ' . count($toAttach) . ' new permissions to admin role.');
        } else {
            $this->command->warn('No admin role found. Permissions created but not assigned.');
        }

        $this->command->info('Seeded ' . count($permissions) . ' HR permissions.');
    }
}
