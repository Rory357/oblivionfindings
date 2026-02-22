<?php

namespace Database\Seeders;

use App\Domain\Governance\Models\ComplianceObligation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class NzComplianceObligationsSeeder extends Seeder
{
    public function run(): void
    {
        $ownerId = User::query()->value('id');
        if (!$ownerId) {
            $this->command?->warn('No users found. Run SystemUsersSeeder first; skipping NzComplianceObligationsSeeder.');
            return;
        }

        $obligations = [
            // Charities Services
            [
                'framework' => 'charities',
                'obligation_code' => 'CHAR-001',
                'obligation_title' => 'Annual Return to Charities Services',
                'description' => 'File annual return with Charities Services within 6 months of balance date. Includes financial statements and officer details.',
                'frequency' => 'annual',
                'due_date' => now()->addMonths(3),
            ],
            [
                'framework' => 'charities',
                'obligation_code' => 'CHAR-002',
                'obligation_title' => 'Officer Change Notifications',
                'description' => 'Notify Charities Services of any changes to officers (board members) within 3 months of the change.',
                'frequency' => 'event_driven',
                'due_date' => null,
            ],
            [
                'framework' => 'charities',
                'obligation_code' => 'CHAR-003',
                'obligation_title' => 'Rules/Constitution Changes',
                'description' => 'Notify Charities Services of any amendments to the constitution or rules within 3 months.',
                'frequency' => 'event_driven',
                'due_date' => null,
            ],
            [
                'framework' => 'charities',
                'obligation_code' => 'CHAR-004',
                'obligation_title' => 'Governance Policy Review',
                'description' => 'Annual board review of governance policies including conflicts of interest, delegations, and financial controls.',
                'frequency' => 'annual',
                'due_date' => now()->addMonths(6),
            ],

            // Nga Paerewa NZS 8134:2021
            [
                'framework' => 'nga_paerewa',
                'obligation_code' => 'NP-001',
                'obligation_title' => 'Certification Audit Preparation',
                'description' => 'Prepare for certification audit against Nga Paerewa standards. Evidence of quality and safety systems, consumer rights, and continuous improvement.',
                'frequency' => 'annual',
                'due_date' => now()->addYear(),
            ],
            [
                'framework' => 'nga_paerewa',
                'obligation_code' => 'NP-002',
                'obligation_title' => 'Consumer Rights and Informed Consent Review',
                'description' => 'Review consumer rights processes including informed consent, complaints, and advocacy access.',
                'frequency' => 'annual',
                'due_date' => now()->addMonths(4),
            ],
            [
                'framework' => 'nga_paerewa',
                'obligation_code' => 'NP-003',
                'obligation_title' => 'Quality Improvement Plan Update',
                'description' => 'Update continuous quality improvement plan with corrective actions from incidents, audits, and consumer feedback.',
                'frequency' => 'quarterly',
                'due_date' => now()->addMonth(),
            ],
            [
                'framework' => 'nga_paerewa',
                'obligation_code' => 'NP-004',
                'obligation_title' => 'Cultural Safety and Te Tiriti Obligations Review',
                'description' => 'Review policies and practices for cultural safety, equity, and Te Tiriti o Waitangi partnership obligations.',
                'frequency' => 'annual',
                'due_date' => now()->addMonths(5),
            ],
            [
                'framework' => 'nga_paerewa',
                'obligation_code' => 'NP-005',
                'obligation_title' => 'Infection Prevention and Control Programme',
                'description' => 'Review infection prevention and control programme, including outbreak preparedness and PPE protocols.',
                'frequency' => 'annual',
                'due_date' => now()->addMonths(2),
            ],

            // Health and Disability Services (Safety) Act
            [
                'framework' => 'hdsa_safety',
                'obligation_code' => 'HDSA-001',
                'obligation_title' => 'Serious Adverse Event Reporting',
                'description' => 'Report serious adverse events to the relevant authorities within required timeframes.',
                'frequency' => 'event_driven',
                'due_date' => null,
            ],
            [
                'framework' => 'hdsa_safety',
                'obligation_code' => 'HDSA-002',
                'obligation_title' => 'Reportable Event Review Process',
                'description' => 'Maintain and review process for identifying and reporting events that meet reporting thresholds.',
                'frequency' => 'quarterly',
                'due_date' => now()->addMonths(2),
            ],

            // Privacy Act 2020
            [
                'framework' => 'privacy_act',
                'obligation_code' => 'PRIV-001',
                'obligation_title' => 'Privacy Impact Assessment Review',
                'description' => 'Conduct PIAs for new systems or processes handling personal or health information.',
                'frequency' => 'event_driven',
                'due_date' => null,
            ],
            [
                'framework' => 'privacy_act',
                'obligation_code' => 'PRIV-002',
                'obligation_title' => 'Privacy Breach Response Plan Review',
                'description' => 'Review and test privacy breach response plan and reporting obligations.',
                'frequency' => 'annual',
                'due_date' => now()->addMonths(3),
            ],
            [
                'framework' => 'privacy_act',
                'obligation_code' => 'PRIV-003',
                'obligation_title' => 'Staff Privacy Training',
                'description' => 'Ensure all staff complete privacy training on handling client data and breach reporting.',
                'frequency' => 'annual',
                'due_date' => now()->addMonths(2),
            ],
            [
                'framework' => 'privacy_act',
                'obligation_code' => 'PRIV-004',
                'obligation_title' => 'Notifiable Privacy Breach Reporting',
                'description' => 'Report notifiable privacy breaches to the Privacy Commissioner as soon as practicable.',
                'frequency' => 'event_driven',
                'due_date' => null,
            ],

            // HSWA (Health and Safety at Work Act 2015)
            [
                'framework' => 'hswa',
                'obligation_code' => 'HSWA-001',
                'obligation_title' => 'Officer Due Diligence Evidence',
                'description' => 'Board members must evidence due diligence in health and safety oversight.',
                'frequency' => 'quarterly',
                'due_date' => now()->addMonth(),
            ],
            [
                'framework' => 'hswa',
                'obligation_code' => 'HSWA-002',
                'obligation_title' => 'Notifiable Event Reporting to WorkSafe',
                'description' => 'Immediately report notifiable events to WorkSafe NZ.',
                'frequency' => 'event_driven',
                'due_date' => null,
            ],
            [
                'framework' => 'hswa',
                'obligation_code' => 'HSWA-003',
                'obligation_title' => 'Health and Safety Policy and Risk Register Review',
                'description' => 'Annual review of the health and safety policy and workplace risk register.',
                'frequency' => 'annual',
                'due_date' => now()->addMonths(4),
            ],
            [
                'framework' => 'hswa',
                'obligation_code' => 'HSWA-004',
                'obligation_title' => 'Worker Health and Safety Representative Elections',
                'description' => 'Ensure health and safety representative elections are current and representatives are trained.',
                'frequency' => 'annual',
                'due_date' => now()->addMonths(6),
            ],

            // Employment
            [
                'framework' => 'employment',
                'obligation_code' => 'EMP-001',
                'obligation_title' => 'Employment Agreement Compliance Review',
                'description' => 'Review employment agreements for compliance with NZ employment legislation.',
                'frequency' => 'annual',
                'due_date' => now()->addMonths(3),
            ],
            [
                'framework' => 'employment',
                'obligation_code' => 'EMP-002',
                'obligation_title' => 'Holidays Act Compliance Audit',
                'description' => 'Audit leave and holiday pay calculations for Holidays Act compliance.',
                'frequency' => 'annual',
                'due_date' => now()->addMonths(5),
            ],
            [
                'framework' => 'employment',
                'obligation_code' => 'EMP-003',
                'obligation_title' => 'Pay Equity and Equal Pay Review',
                'description' => 'Review pay practices for pay equity and equal pay compliance.',
                'frequency' => 'annual',
                'due_date' => now()->addMonths(6),
            ],

            // Funding / Contract Obligations
            [
                'framework' => 'funding_moh',
                'obligation_code' => 'FUND-MOH-001',
                'obligation_title' => 'Health NZ Contract Reporting',
                'description' => 'Submit contracted reporting to Health NZ per service agreement schedules.',
                'frequency' => 'quarterly',
                'due_date' => now()->addMonth(),
            ],
            [
                'framework' => 'funding_msd',
                'obligation_code' => 'FUND-MSD-001',
                'obligation_title' => 'MSD Contract Compliance Review',
                'description' => 'Review compliance with MSD service agreements, including reporting and outcomes tracking.',
                'frequency' => 'quarterly',
                'due_date' => now()->addMonths(2),
            ],
            [
                'framework' => 'funding_moh',
                'obligation_code' => 'FUND-MOH-002',
                'obligation_title' => 'Funding Audit Preparation',
                'description' => 'Prepare for funder audit including service delivery and financial stewardship evidence.',
                'frequency' => 'annual',
                'due_date' => now()->addMonths(4),
            ],
            [
                'framework' => 'funding_moh',
                'obligation_code' => 'FUND-MOH-003',
                'obligation_title' => 'Service Level Agreement Performance Review',
                'description' => 'Review performance against contracted service levels and address shortfalls.',
                'frequency' => 'quarterly',
                'due_date' => now()->addMonths(2),
            ],
        ];

        foreach ($obligations as $obligation) {
            $dueDate = $obligation['due_date'] instanceof Carbon
                ? $obligation['due_date']->copy()->startOfDay()
                : now()->addYear()->startOfDay();

            ComplianceObligation::updateOrCreate(
                [
                    'framework' => $obligation['framework'],
                    'obligation_title' => $obligation['obligation_title'],
                ],
                [
                    'obligation_code' => $obligation['obligation_code'],
                    'description' => $obligation['description'],
                    'frequency' => $obligation['frequency'],
                    'due_date' => $dueDate,
                    'next_due_date' => $dueDate,
                    'reminder_days' => [30, 14, 7],
                    'owner_id' => $ownerId,
                    'backup_owner_id' => null,
                    'status' => 'not_due',
                    'evidence_required' => 'Evidence of compliance completion is required.',
                    'evidence_provided' => false,
                    'sign_off_required' => false,
                    'sign_off_role' => null,
                    'notes' => null,
                ]
            );
        }

        $this->command?->info('NZ Compliance Obligations seeded: ' . count($obligations) . ' obligations');
    }
}

