<?php

namespace Database\Seeders;

use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Domain\Hr\Models\HrPolicy;
use App\Domain\Hr\Models\HrPolicyAttestation;
use App\Domain\Hr\Models\HrPolicyVersion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class HrSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('hr_compliance_requirements')) {
            $this->command?->warn('HR tables not found — skipping HrSeeder.');
            return;
        }

        $tenantId = (int) (HrEmployeeProfile::query()->orderBy('id')->value('tenant_id') ?? 1);
        $adminUser = User::first();
        if (!$adminUser) {
            $this->command?->warn('No users found — skipping HrSeeder.');
            return;
        }

        $this->seedComplianceRequirements($tenantId, $adminUser->id);
        $this->seedPolicies($tenantId, $adminUser->id);
        $this->seedLeaveBalances($tenantId, $adminUser->id);
        $this->seedOnboardingTemplates($tenantId, $adminUser->id);
        $this->seedEmployeeProfiles($tenantId, $adminUser->id);

        $this->command?->info('HR seed data created.');
    }

    private function seedComplianceRequirements(int $tenantId, int $createdBy): void
    {
        $requirements = [
            [
                'code' => 'POLICE_VET',
                'name' => 'NZ Police Vetting',
                'description' => 'Current NZ Police vetting clearance required',
                'category' => 'background_check',
                'check_type' => 'background_check',
                'validity_months' => 36,
                'renewal_reminder_days' => 60,
                'hard_stop' => true,
            ],
            [
                'code' => 'FIRST_AID',
                'name' => 'First Aid Certificate',
                'description' => 'Current first aid certificate (Level 2 minimum)',
                'category' => 'training',
                'check_type' => 'credential',
                'validity_months' => 24,
                'renewal_reminder_days' => 60,
                'hard_stop' => false,
            ],
            [
                'code' => 'MED_COMP',
                'name' => 'Medication Competency',
                'description' => 'Medication administration competency assessment',
                'category' => 'training',
                'check_type' => 'training_course',
                'validity_months' => 12,
                'renewal_reminder_days' => 30,
                'hard_stop' => true,
            ],
            [
                'code' => 'RESTRAINT',
                'name' => 'De-escalation & Restraint Training',
                'description' => 'Approved de-escalation and safe restraint training',
                'category' => 'training',
                'check_type' => 'training_course',
                'validity_months' => 12,
                'renewal_reminder_days' => 30,
                'hard_stop' => false,
            ],
            [
                'code' => 'PRIVACY_ATT',
                'name' => 'Privacy Act Attestation',
                'description' => 'Annual attestation of Privacy Act 2020 policy',
                'category' => 'policy',
                'check_type' => 'policy_attestation',
                'validity_months' => 12,
                'renewal_reminder_days' => 30,
                'hard_stop' => false,
            ],
            [
                'code' => 'DRIVER_LIC',
                'name' => 'Valid NZ Driver Licence',
                'description' => 'Valid NZ driver licence (Class 1 minimum)',
                'category' => 'credential',
                'check_type' => 'credential',
                'validity_months' => null,
                'renewal_reminder_days' => 60,
                'hard_stop' => false,
            ],
        ];

        foreach ($requirements as $req) {
            $requirement = HrComplianceRequirement::firstOrCreate(
                ['tenant_id' => $tenantId, 'code' => $req['code']],
                [...$req, 'tenant_id' => $tenantId, 'is_active' => true, 'created_by' => $createdBy, 'updated_by' => $createdBy]
            );

            // Assign to roles
            $roles = match ($req['code']) {
                'POLICE_VET' => ['support_worker', 'team_lead', 'coordinator'],
                'FIRST_AID' => ['support_worker', 'team_lead'],
                'MED_COMP' => ['support_worker'],
                'RESTRAINT' => ['support_worker'],
                'PRIVACY_ATT' => ['support_worker', 'team_lead', 'coordinator'],
                'DRIVER_LIC' => ['support_worker', 'team_lead'],
                default => [],
            };

            foreach ($roles as $role) {
                HrComplianceMatrix::firstOrCreate([
                    'tenant_id' => $tenantId,
                    'requirement_id' => $requirement->id,
                    'role' => $role,
                ], [
                    'site_type' => null,
                    'is_mandatory' => $req['hard_stop'],
                ]);
            }
        }
    }

    private function seedPolicies(int $tenantId, int $createdBy): void
    {
        $policies = [
            [
                'title' => 'Privacy Act 2020 Policy',
                'slug' => 'privacy-act-2020',
                'category' => 'privacy',
                'requires_attestation' => true,
                'attestation_frequency_months' => 12,
                'content' => 'This policy outlines our obligations under the Privacy Act 2020 including collection, storage, and disclosure of personal information.',
            ],
            [
                'title' => 'Code of Conduct',
                'slug' => 'code-of-conduct',
                'category' => 'conduct',
                'requires_attestation' => true,
                'attestation_frequency_months' => 12,
                'content' => 'This code of conduct sets out the expected standards of behaviour for all employees.',
            ],
            [
                'title' => 'Health & Safety Policy',
                'slug' => 'health-safety',
                'category' => 'health_safety',
                'requires_attestation' => true,
                'attestation_frequency_months' => 12,
                'content' => 'This policy describes our commitment to maintaining a safe and healthy workplace under the Health and Safety at Work Act 2015.',
            ],
        ];

        foreach ($policies as $p) {
            $policy = HrPolicy::firstOrCreate(
                ['tenant_id' => $tenantId, 'slug' => $p['slug']],
                [
                    'tenant_id' => $tenantId,
                    'title' => $p['title'],
                    'category' => $p['category'],
                    'is_active' => true,
                    'requires_attestation' => $p['requires_attestation'],
                    'attestation_frequency_months' => $p['attestation_frequency_months'],
                    'created_by' => $createdBy,
                    'updated_by' => $createdBy,
                ]
            );

            HrPolicyVersion::firstOrCreate(
                ['policy_id' => $policy->id, 'version_number' => 1],
                [
                    'content_summary' => $p['content'],
                    'document_path' => "seed/hr-policies/{$p['slug']}.pdf",
                    'effective_from' => now()->subMonths(3),
                    'is_current' => true,
                    'published_by' => $createdBy,
                    'created_at' => now(),
                ]
            );
        }
    }

    private function seedLeaveBalances(int $tenantId, int $updatedBy): void
    {
        $userIds = HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->pluck('user_id')
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($userIds->isEmpty()) {
            $userIds = User::staff()->limit(20)->pluck('id');
        }

        $users = User::staff()->whereIn('id', $userIds->all())->limit(20)->get();
        $year = now()->year;

        foreach ($users as $user) {
            foreach (['annual' => 160, 'sick' => 80, 'family_violence' => 80] as $type => $entitlement) {
                $taken = $type === 'family_violence' ? 0 : rand(0, (int) ($entitlement * 0.6));
                $remaining = max($entitlement - $taken, 0);
                HrLeaveBalance::firstOrCreate(
                    ['tenant_id' => $tenantId, 'user_id' => $user->id, 'leave_type' => $type, 'year' => $year],
                    [
                        'balance_hours' => $remaining,
                        'accrued_hours' => $entitlement,
                        'used_hours' => $taken,
                        'pending_hours' => 0,
                        'source' => 'system',
                        'last_synced_at' => now(),
                        'updated_by' => $updatedBy,
                    ]
                );
            }
        }
    }

    private function seedOnboardingTemplates(int $tenantId, int $createdBy): void
    {
        HrOnboardingTemplate::firstOrCreate(
            ['tenant_id' => $tenantId, 'role' => 'support_worker', 'site_type' => 'all'],
            [
                'tasks' => [
                    ['label' => 'Complete NZ Police Vetting consent form', 'category' => 'compliance', 'days_due' => 1],
                    ['label' => 'Provide certified copies of qualifications', 'category' => 'compliance', 'days_due' => 3],
                    ['label' => 'Complete First Aid training (if not current)', 'category' => 'training', 'days_due' => 14],
                    ['label' => 'Complete Medication Competency assessment', 'category' => 'training', 'days_due' => 14],
                    ['label' => 'Complete De-escalation training', 'category' => 'training', 'days_due' => 30],
                    ['label' => 'Review and attest to Privacy Act policy', 'category' => 'policy', 'days_due' => 3],
                    ['label' => 'Review and attest to Code of Conduct', 'category' => 'policy', 'days_due' => 3],
                    ['label' => 'Review and attest to H&S policy', 'category' => 'policy', 'days_due' => 3],
                    ['label' => 'Complete IT induction (email, systems access)', 'category' => 'admin', 'days_due' => 1],
                    ['label' => 'Complete site orientation at primary site', 'category' => 'orientation', 'days_due' => 7],
                    ['label' => 'Meet team lead and complete buddy shift', 'category' => 'orientation', 'days_due' => 7],
                    ['label' => 'Provide bank account details for payroll', 'category' => 'admin', 'days_due' => 3],
                    ['label' => 'Provide IRD number and tax code', 'category' => 'admin', 'days_due' => 3],
                    ['label' => 'Set up KiwiSaver preferences', 'category' => 'admin', 'days_due' => 7],
                ],
                'is_active' => true,
                'created_by' => $createdBy,
                'updated_by' => $createdBy,
            ]
        );

        HrOnboardingTemplate::firstOrCreate(
            ['tenant_id' => $tenantId, 'role' => 'team_lead', 'site_type' => 'all'],
            [
                'tasks' => [
                    ['label' => 'Complete NZ Police Vetting consent form', 'category' => 'compliance', 'days_due' => 1],
                    ['label' => 'Provide certified copies of qualifications', 'category' => 'compliance', 'days_due' => 3],
                    ['label' => 'Complete First Aid training', 'category' => 'training', 'days_due' => 14],
                    ['label' => 'Complete leadership orientation', 'category' => 'orientation', 'days_due' => 7],
                    ['label' => 'Review all HR policies and attest', 'category' => 'policy', 'days_due' => 5],
                    ['label' => 'Complete IT induction (email, systems, admin access)', 'category' => 'admin', 'days_due' => 1],
                    ['label' => 'Meet with HR for employment paperwork', 'category' => 'admin', 'days_due' => 1],
                    ['label' => 'Tour all assigned sites', 'category' => 'orientation', 'days_due' => 14],
                ],
                'is_active' => true,
                'created_by' => $createdBy,
                'updated_by' => $createdBy,
            ]
        );
    }

    private function seedEmployeeProfiles(int $tenantId, int $createdBy): void
    {
        $users = User::staff()->limit(20)->get();

        foreach ($users as $i => $user) {
            HrEmployeeProfile::firstOrCreate(
                ['tenant_id' => $tenantId, 'user_id' => $user->id],
                [
                    'employee_number' => 'EMP' . str_pad($user->id, 4, '0', STR_PAD_LEFT),
                    'work_email' => $user->email ?? ("employee{$user->id}@example.test"),
                    'position_title' => match ($user->role) {
                        'admin' => 'Operations Manager',
                        'provider_manager' => 'Service Manager',
                        'coordinator' => 'Service Coordinator',
                        'team_lead' => 'Team Leader',
                        default => 'Support Worker',
                    },
                    'position_role' => $user->role ?? 'support_worker',
                    'employment_type' => $i % 3 === 0 ? 'part_time' : 'full_time',
                    'contract_type' => 'permanent',
                    'hours_per_week' => $i % 3 === 0 ? 20 : 40,
                    'start_date' => now()->subMonths(rand(1, 36)),
                    'is_active' => true,
                    'created_by' => $createdBy,
                    'updated_by' => $createdBy,
                ]
            );
        }
    }
}
