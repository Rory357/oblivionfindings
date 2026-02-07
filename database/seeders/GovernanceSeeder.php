<?php

namespace Database\Seeders;

use App\Domain\Governance\Models\BoardCommittee;
use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\RiskRegisterEntry;
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
        
        $this->command->info('Governance seeder completed successfully!');
        $this->command->info('Created:');
        $this->command->info('  - 3 Board Committees');
        $this->command->info('  - 1 Board Member (Chair)');
        $this->command->info('  - 5 Sample Risk Register Entries');
        $this->command->info('  - Default Compliance Obligations');
        $this->command->info('  - 1 Sample Meeting');
    }
}
