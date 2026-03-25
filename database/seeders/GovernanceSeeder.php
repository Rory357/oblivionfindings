<?php

namespace Database\Seeders;

use App\Domain\Governance\Models\BoardCommittee;
use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\ClinicalGovernanceIndicator;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\GovernancePolicy;
use App\Domain\Governance\Models\RiskAppetiteSetting;
use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Domain\Governance\Models\TeTiritiObligation;
use App\Domain\Governance\Services\ComplianceEngineService;
use App\Domain\Governance\Services\RiskScoringService;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class GovernanceSeeder extends Seeder
{
    public function run(): void
    {
        $service = new ComplianceEngineService();
        $riskService = new RiskScoringService();
        
        // Get or create admin user
        $admin = User::firstOrCreate(
            ['email' => 'admin@oblivion.test'],
            [
                'name' => 'System Administrator',
                'password' => bcrypt('password'),
            ]
        );
        
        // Create board committees
        $committees = [
            ['type' => 'audit_risk', 'name' => 'Audit & Risk Committee', 'frequency' => 'quarterly'],
            ['type' => 'people', 'name' => 'People Committee', 'frequency' => 'quarterly'],
            ['type' => 'finance', 'name' => 'Finance Committee', 'frequency' => 'quarterly'],
        ];
        
        foreach ($committees as $committee) {
            BoardCommittee::firstOrCreate(
                ['committee_type' => $committee['type']],
                [
                    'name' => $committee['name'],
                    'meeting_frequency' => $committee['frequency'],
                ]
            );
        }
        
        // Create board member for admin
        $boardMember = BoardMember::firstOrCreate(
            ['user_id' => $admin->id],
            [
                'board_role' => 'chair',
                'term_start' => now(),
                'term_end' => now()->addYears(3),
                'is_independent' => true,
                'is_active' => true,
            ]
        );
        
        // Add to audit committee
        $auditCommittee = BoardCommittee::where('committee_type', 'audit_risk')->first();
        if ($auditCommittee && !$auditCommittee->members()->where('board_member_id', $boardMember->id)->exists()) {
            $auditCommittee->members()->attach($boardMember->id, [
                'role' => 'chair',
                'appointed_at' => now(),
                'is_active' => true,
            ]);
        }
        
        // Create sample risks
        $sampleRisks = [
            [
                'category' => 'client_safety',
                'title' => 'Serious injury to supported person',
                'description' => 'Risk of serious injury occurring to a supported person due to inadequate care or environmental hazards.',
                'likelihood' => 2,
                'impact' => 5,
                'controls' => 'moderate',
            ],
            [
                'category' => 'workforce',
                'title' => 'Staff burnout and high turnover',
                'description' => 'Excessive workload and stress leading to staff burnout and increased turnover rates.',
                'likelihood' => 3,
                'impact' => 4,
                'controls' => 'weak',
            ],
            [
                'category' => 'it_cyber',
                'title' => 'Data breach of client information',
                'description' => 'Unauthorized access to or disclosure of sensitive client health and personal information.',
                'likelihood' => 2,
                'impact' => 5,
                'controls' => 'moderate',
            ],
            [
                'category' => 'financial',
                'title' => 'Funding shortfall',
                'description' => 'Reduction in funding or contract changes leading to operational budget shortfall.',
                'likelihood' => 3,
                'impact' => 4,
                'controls' => 'weak',
            ],
            [
                'category' => 'legal_compliance',
                'title' => 'Regulatory non-compliance',
                'description' => 'Failure to meet regulatory requirements resulting in sanctions or loss of license.',
                'likelihood' => 2,
                'impact' => 5,
                'controls' => 'strong',
            ],
        ];
        
        foreach ($sampleRisks as $riskData) {
            $exists = RiskRegisterEntry::where('title', $riskData['title'])->exists();
            
            if (!$exists) {
                $inherentScore = $riskData['likelihood'] * $riskData['impact'];
                $threshold = $riskService->getAppetiteThreshold($riskData['category']);
                
                RiskRegisterEntry::create([
                    'category' => $riskData['category'],
                    'title' => $riskData['title'],
                    'description' => $riskData['description'],
                    'likelihood_score' => $riskData['likelihood'],
                    'impact_score' => $riskData['impact'],
                    'inherent_score' => $inherentScore,
                    'control_effectiveness' => $riskData['controls'],
                    'residual_score' => $riskService->calculateResidualScore($inherentScore, $riskData['controls']),
                    'appetite_threshold' => $threshold,
                    'within_appetite' => true,
                    'risk_owner_id' => $admin->id,
                    'review_frequency' => 'quarterly',
                    'next_review_date' => now()->addMonths(3),
                    'status' => 'active',
                    'mitigation_strategy' => 'treat',
                    'identified_at' => now(),
                    'identified_by' => $admin->id,
                ]);
            }
        }
        
        // Seed compliance obligations
        // Create board roles if they don't exist
        $boardRoles = [
            ['name' => 'board_chair', 'label' => 'Board Chair'],
            ['name' => 'board_secretary', 'label' => 'Board Secretary'],
            ['name' => 'board_member', 'label' => 'Board Member'],
            ['name' => 'board_observer', 'label' => 'Board Observer'],
            ['name' => 'ceo', 'label' => 'CEO'],
            ['name' => 'cfo', 'label' => 'CFO'],
            ['name' => 'coo', 'label' => 'COO'],
            ['name' => 'compliance_lead', 'label' => 'Compliance Lead'],
            ['name' => 'risk_lead', 'label' => 'Risk Lead'],
        ];

        foreach ($boardRoles as $roleData) {
            Role::firstOrCreate(
                ['name' => $roleData['name']],
                ['label' => $roleData['label']]
            );
        }

        // Seed governance permissions
        $this->call(GovernancePermissionsSeeder::class);
        
        // Create upcoming meeting
        $meeting = GovernanceMeeting::firstOrCreate(
            [
                'title' => 'Monthly Board Meeting - ' . now()->format('F Y'),
                'scheduled_at' => now()->addWeek(),
            ],
            [
                'meeting_type' => 'full_board',
                'duration_minutes' => 180,
                'status' => 'scheduled',
                'quorum_required' => 50,
                'chair_id' => $boardMember->id,
                'created_by' => $admin->id,
            ]
        );
        
        // Seed risk appetite settings from constants
        foreach (RiskScoringService::DEFAULT_APPETITE_THRESHOLDS as $category => $threshold) {
            RiskAppetiteSetting::firstOrCreate(
                ['category' => $category],
                [
                    'threshold' => $threshold,
                    'rationale' => ucfirst(str_replace('_', ' ', $category)) . ' risk appetite threshold',
                    'approved_by' => $admin->id,
                    'approved_at' => now(),
                ]
            );
        }

        // Seed Te Tiriti o Waitangi obligations
        $teTiritiObligations = [
            ['principle' => 'partnership', 'title' => 'Māori representation on Board', 'description' => 'Ensure Māori representation at governance level reflecting partnership principles.', 'order' => 1],
            ['principle' => 'partnership', 'title' => 'Tikanga Māori in governance', 'description' => 'Incorporate tikanga Māori practices in board meetings and decision-making.', 'order' => 2],
            ['principle' => 'participation', 'title' => 'Whānau engagement in care planning', 'description' => 'Enable whānau participation in service design and delivery decisions.', 'order' => 1],
            ['principle' => 'participation', 'title' => 'Māori consumer feedback', 'description' => 'Actively seek and incorporate feedback from Māori consumers and communities.', 'order' => 2],
            ['principle' => 'protection', 'title' => 'Cultural safety training', 'description' => 'Ensure all staff complete cultural safety and Te Tiriti training.', 'order' => 1],
            ['principle' => 'protection', 'title' => 'Te Reo Māori accessibility', 'description' => 'Make key documents and communications accessible in Te Reo Māori.', 'order' => 2],
            ['principle' => 'equity', 'title' => 'Equity in service access', 'description' => 'Monitor and address disparities in service access and outcomes for Māori.', 'order' => 1],
            ['principle' => 'equity', 'title' => 'Māori health outcomes tracking', 'description' => 'Track and report on health equity outcomes for Māori consumers.', 'order' => 2],
            ['principle' => 'options', 'title' => 'Kaupapa Māori service options', 'description' => 'Provide culturally appropriate service delivery options for Māori.', 'order' => 1],
        ];

        foreach ($teTiritiObligations as $obligation) {
            TeTiritiObligation::firstOrCreate(
                ['principle' => $obligation['principle'], 'title' => $obligation['title']],
                [
                    'description' => $obligation['description'],
                    'status' => 'not_started',
                    'progress_pct' => 0,
                    'owner_id' => $admin->id,
                ]
            );
        }

        // Seed default governance policies
        $defaultPolicies = [
            ['title' => 'Board Charter', 'category' => 'governance', 'content' => 'The Board of Directors is responsible for the overall governance and strategic direction of the organisation.'],
            ['title' => 'Conflicts of Interest Policy', 'category' => 'governance', 'content' => 'All board members must declare any actual, potential, or perceived conflicts of interest.'],
            ['title' => 'Delegations of Authority Policy', 'category' => 'financial', 'content' => 'This policy outlines the delegated authority levels for financial and operational decisions.'],
            ['title' => 'Risk Management Policy', 'category' => 'governance', 'content' => 'The Board oversees the risk management framework to ensure risks are identified, assessed, and managed appropriately.'],
            ['title' => 'Health & Safety Governance Policy', 'category' => 'health_safety', 'content' => 'The Board fulfils its officer duties under the Health and Safety at Work Act 2015.'],
            ['title' => 'Privacy & Data Protection Policy', 'category' => 'privacy', 'content' => 'This policy ensures compliance with the Privacy Act 2020 and Health Information Privacy Code 2020.'],
        ];

        foreach ($defaultPolicies as $index => $policy) {
            GovernancePolicy::firstOrCreate(
                ['title' => $policy['title']],
                [
                    'policy_code' => 'GOV-' . str_pad($index + 1, 3, '0', STR_PAD_LEFT),
                    'category' => $policy['category'],
                    'content' => $policy['content'],
                    'version_number' => 1,
                    'status' => 'draft',
                    'owner_id' => $admin->id,
                    'effective_from' => now(),
                    'next_review_date' => now()->addYear(),
                    'created_by' => $admin->id,
                ]
            );
        }

        // Seed clinical governance indicators
        $clinicalIndicators = [
            ['indicator_code' => 'CGI-001', 'name' => 'Medication Error Rate', 'category' => 'medication_errors', 'target_value' => 0, 'unit' => 'rate', 'frequency' => 'monthly'],
            ['indicator_code' => 'CGI-002', 'name' => 'Falls Rate', 'category' => 'falls', 'target_value' => 5, 'unit' => 'rate', 'frequency' => 'monthly'],
            ['indicator_code' => 'CGI-003', 'name' => 'Restraint Usage', 'category' => 'restraint', 'target_value' => 0, 'unit' => 'count', 'frequency' => 'monthly'],
            ['indicator_code' => 'CGI-004', 'name' => 'Incident Rate', 'category' => 'infections', 'target_value' => 10, 'unit' => 'rate', 'frequency' => 'monthly'],
            ['indicator_code' => 'CGI-005', 'name' => 'Infection Control Compliance', 'category' => 'infections', 'target_value' => 95, 'unit' => 'percentage', 'frequency' => 'monthly'],
            ['indicator_code' => 'CGI-006', 'name' => 'Client Satisfaction Score', 'category' => 'complaints', 'target_value' => 80, 'unit' => 'percentage', 'frequency' => 'quarterly'],
        ];

        foreach ($clinicalIndicators as $indicator) {
            ClinicalGovernanceIndicator::firstOrCreate(
                ['indicator_code' => $indicator['indicator_code']],
                [
                    ...$indicator,
                    'is_active' => true,
                    'is_automated' => false,
                ]
            );
        }

        $this->command->info('Governance seeder completed successfully!');
        $this->command->info('Created:');
        $this->command->info('  - 3 Board Committees');
        $this->command->info('  - 1 Board Member (Chair)');
        $this->command->info('  - 5 Sample Risk Register Entries');
        $this->command->info('  - Default Compliance Obligations');
        $this->command->info('  - 1 Sample Meeting');
        $this->command->info('  - 8 Risk Appetite Settings');
        $this->command->info('  - 9 Te Tiriti Obligations');
        $this->command->info('  - 6 Default Governance Policies');
        $this->command->info('  - 6 Clinical Governance Indicators');
    }
}
