<?php

namespace Database\Seeders;

use App\Domain\Governance\Models\ComplianceObligation;
use Illuminate\Database\Seeder;

class NzComplianceObligationsSeeder extends Seeder
{
    public function run(): void
    {
        $obligations = [
            // ── Charities Services ──
            [
                'framework' => 'charities',
                'obligation_title' => 'Annual Return to Charities Services',
                'description' => 'File annual return with Charities Services within 6 months of balance date. Includes financial statements and officer details.',
                'review_frequency' => 'annual',
                'due_date' => now()->addMonths(3),
            ],
            [
                'framework' => 'charities',
                'obligation_title' => 'Officer Change Notifications',
                'description' => 'Notify Charities Services of any changes to officers (board members) within 3 months of the change.',
                'review_frequency' => 'as_needed',
                'due_date' => null,
            ],
            [
                'framework' => 'charities',
                'obligation_title' => 'Rules/Constitution Changes',
                'description' => 'Notify Charities Services of any amendments to the constitution or rules within 3 months.',
                'review_frequency' => 'as_needed',
                'due_date' => null,
            ],
            [
                'framework' => 'charities',
                'obligation_title' => 'Governance Policy Review',
                'description' => 'Annual board review of governance policies including conflicts of interest, delegations, and financial controls.',
                'review_frequency' => 'annual',
                'due_date' => now()->addMonths(6),
            ],

            // ── Ngā Paerewa NZS 8134:2021 ──
            [
                'framework' => 'nga_paerewa',
                'obligation_title' => 'Certification Audit Preparation',
                'description' => 'Prepare for triennial certification audit against Ngā Paerewa standards. Evidence of quality and safety systems, consumer rights, and continuous improvement.',
                'review_frequency' => 'triennial',
                'due_date' => now()->addYear(),
            ],
            [
                'framework' => 'nga_paerewa',
                'obligation_title' => 'Consumer Rights & Informed Consent Review',
                'description' => 'Review consumer rights processes including informed consent, complaints, and advocacy access per Ngā Paerewa Right 1-10.',
                'review_frequency' => 'annual',
                'due_date' => now()->addMonths(4),
            ],
            [
                'framework' => 'nga_paerewa',
                'obligation_title' => 'Quality Improvement Plan Update',
                'description' => 'Update continuous quality improvement plan with corrective actions from incidents, audits, and consumer feedback.',
                'review_frequency' => 'quarterly',
                'due_date' => now()->addMonths(1),
            ],
            [
                'framework' => 'nga_paerewa',
                'obligation_title' => 'Cultural Safety & Te Tiriti Obligations Review',
                'description' => 'Review policies and practices for cultural safety, equity, and Te Tiriti o Waitangi partnership obligations.',
                'review_frequency' => 'annual',
                'due_date' => now()->addMonths(5),
            ],
            [
                'framework' => 'nga_paerewa',
                'obligation_title' => 'Infection Prevention & Control Programme',
                'description' => 'Review infection prevention and control programme, including outbreak preparedness and PPE protocols.',
                'review_frequency' => 'annual',
                'due_date' => now()->addMonths(2),
            ],

            // ── Health & Disability Services (Safety) Act ──
            [
                'framework' => 'health_disability_act',
                'obligation_title' => 'Serious Adverse Event Reporting',
                'description' => 'Report serious adverse events to Health Quality & Safety Commission and relevant authorities within required timeframes.',
                'review_frequency' => 'as_needed',
                'due_date' => null,
            ],
            [
                'framework' => 'health_disability_act',
                'obligation_title' => 'Reportable Event Review Process',
                'description' => 'Maintain and review process for identifying and reporting events that meet reporting thresholds under the Act.',
                'review_frequency' => 'quarterly',
                'due_date' => now()->addMonths(2),
            ],

            // ── Privacy Act 2020 + Health Information Privacy Code ──
            [
                'framework' => 'privacy_act',
                'obligation_title' => 'Privacy Impact Assessment Review',
                'description' => 'Conduct PIAs for new systems or processes handling personal or health information.',
                'review_frequency' => 'as_needed',
                'due_date' => null,
            ],
            [
                'framework' => 'privacy_act',
                'obligation_title' => 'Privacy Breach Response Plan Review',
                'description' => 'Review and test privacy breach response plan. Ensure all staff know reporting obligations under Part 6 of Privacy Act 2020.',
                'review_frequency' => 'annual',
                'due_date' => now()->addMonths(3),
            ],
            [
                'framework' => 'privacy_act',
                'obligation_title' => 'Staff Privacy Training',
                'description' => 'Ensure all staff complete privacy training covering Health Information Privacy Code, secure handling of client data, and breach reporting.',
                'review_frequency' => 'annual',
                'due_date' => now()->addMonths(2),
            ],
            [
                'framework' => 'privacy_act',
                'obligation_title' => 'Notifiable Privacy Breach Reporting',
                'description' => 'Report notifiable privacy breaches to the Privacy Commissioner as soon as practicable. Assess harm and notify affected individuals.',
                'review_frequency' => 'as_needed',
                'due_date' => null,
            ],

            // ── HSWA (Health & Safety at Work Act 2015) ──
            [
                'framework' => 'hswa',
                'obligation_title' => 'Officer Due Diligence Evidence',
                'description' => 'Board members (as officers) must evidence due diligence in health and safety oversight. Includes up-to-date knowledge, hazard awareness, and resource allocation.',
                'review_frequency' => 'quarterly',
                'due_date' => now()->addMonth(),
            ],
            [
                'framework' => 'hswa',
                'obligation_title' => 'Notifiable Event Reporting to WorkSafe',
                'description' => 'Immediately report notifiable events (death, serious injury, serious illness, dangerous incident) to WorkSafe NZ.',
                'review_frequency' => 'as_needed',
                'due_date' => null,
            ],
            [
                'framework' => 'hswa',
                'obligation_title' => 'H&S Policy & Risk Register Review',
                'description' => 'Annual review of health and safety policy and workplace risk register. Include worker engagement and participation practices.',
                'review_frequency' => 'annual',
                'due_date' => now()->addMonths(4),
            ],
            [
                'framework' => 'hswa',
                'obligation_title' => 'Worker H&S Representative Elections',
                'description' => 'Ensure H&S representative elections are current and representatives are trained.',
                'review_frequency' => 'biennial',
                'due_date' => now()->addMonths(6),
            ],

            // ── Employment ──
            [
                'framework' => 'employment',
                'obligation_title' => 'Employment Agreement Compliance Review',
                'description' => 'Review all employment agreements for compliance with Employment Relations Act, Holidays Act, and Minimum Wage Act.',
                'review_frequency' => 'annual',
                'due_date' => now()->addMonths(3),
            ],
            [
                'framework' => 'employment',
                'obligation_title' => 'Holidays Act Compliance Audit',
                'description' => 'Audit leave and holiday pay calculations for Holidays Act 2003 compliance. Review OWP/RDP calculations.',
                'review_frequency' => 'annual',
                'due_date' => now()->addMonths(5),
            ],
            [
                'framework' => 'employment',
                'obligation_title' => 'Pay Equity & Equal Pay Review',
                'description' => 'Review pay practices for pay equity compliance (Equal Pay Act 1972, Equal Pay Amendment Act 2020). Particular focus on care and support worker settlements.',
                'review_frequency' => 'annual',
                'due_date' => now()->addMonths(6),
            ],

            // ── Funding / Contract Obligations ──
            [
                'framework' => 'funding',
                'obligation_title' => 'Health NZ Contract Reporting',
                'description' => 'Submit contracted reporting to Health NZ / Te Whatu Ora per service agreement schedules (monthly/quarterly as specified).',
                'review_frequency' => 'quarterly',
                'due_date' => now()->addMonth(),
            ],
            [
                'framework' => 'funding',
                'obligation_title' => 'MSD Contract Compliance Review',
                'description' => 'Review compliance with any MSD service agreements, including reporting, outcomes tracking, and audit readiness.',
                'review_frequency' => 'quarterly',
                'due_date' => now()->addMonths(2),
            ],
            [
                'framework' => 'funding',
                'obligation_title' => 'Funding Audit Preparation',
                'description' => 'Prepare for funder audit including evidence of service delivery, outcomes, financial stewardship, and quality measures.',
                'review_frequency' => 'annual',
                'due_date' => now()->addMonths(4),
            ],
            [
                'framework' => 'funding',
                'obligation_title' => 'Service Level Agreement Performance Review',
                'description' => 'Review actual performance against contracted service levels. Identify and address any shortfalls or corrective actions.',
                'review_frequency' => 'quarterly',
                'due_date' => now()->addMonths(2),
            ],
        ];

        foreach ($obligations as $obligation) {
            ComplianceObligation::firstOrCreate(
                ['obligation_title' => $obligation['obligation_title']],
                array_merge($obligation, [
                    'status' => $obligation['due_date'] ? 'not_due' : 'not_due',
                    'owner_id' => null,
                ])
            );
        }

        $this->command?->info('NZ Compliance Obligations seeded: ' . count($obligations) . ' obligations');
    }
}
