<?php

namespace Database\Seeders;

use App\Models\CompetencyFramework;
use App\Models\CompetencyItem;
use Illuminate\Database\Seeder;

class CompetencyFrameworksSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Support Worker Competency Framework
        $supportWorker = CompetencyFramework::create([
            'name' => 'Support Worker Competency Framework',
            'role' => 'support_worker',
            'description' => 'Core competencies required for support workers providing person-centred care and support.',
            'version' => 1,
            'effective_from' => now(),
            'active' => true,
        ]);

        $supportWorkerCompetencies = [
            [
                'framework_id' => $supportWorker->id,
                'code' => 'SW-COM-001',
                'name' => 'Person-Centred Communication',
                'description' => 'Communicate effectively with service users using appropriate methods, respecting preferences and promoting dignity.',
                'category' => 'Communication',
                'required_proficiency' => 'competent',
                'assessment_criteria' => json_encode([
                    'Uses appropriate verbal and non-verbal communication',
                    'Adapts communication to individual needs',
                    'Listens actively and responds appropriately',
                    'Maintains confidentiality',
                    'Documents interactions accurately',
                ]),
            ],
            [
                'framework_id' => $supportWorker->id,
                'code' => 'SW-CARE-001',
                'name' => 'Personal Care',
                'description' => 'Provide personal care with dignity, respecting privacy and promoting independence.',
                'category' => 'Care Skills',
                'required_proficiency' => 'competent',
                'assessment_criteria' => json_encode([
                    'Assists with washing and bathing',
                    'Supports with dressing and grooming',
                    'Maintains dignity and privacy',
                    'Promotes independence',
                    'Follows care plans',
                ]),
            ],
            [
                'framework_id' => $supportWorker->id,
                'code' => 'SW-SAFE-001',
                'name' => 'Safeguarding Awareness',
                'description' => 'Recognize and respond appropriately to safeguarding concerns, applying Making Safeguarding Personal principles.',
                'category' => 'Safeguarding',
                'required_proficiency' => 'competent',
                'assessment_criteria' => json_encode([
                    'Recognizes signs of abuse and neglect',
                    'Reports concerns promptly',
                    'Follows safeguarding procedures',
                    'Maintains appropriate boundaries',
                    'Documents concerns accurately',
                ]),
            ],
            [
                'framework_id' => $supportWorker->id,
                'code' => 'SW-HEALTH-001',
                'name' => 'Health and Wellbeing Support',
                'description' => 'Support health monitoring, medication administration, and promote physical and mental wellbeing.',
                'category' => 'Clinical Skills',
                'required_proficiency' => 'competent',
                'assessment_criteria' => json_encode([
                    'Monitors health and wellbeing',
                    'Administers medications safely (if trained)',
                    'Recognizes deterioration',
                    'Supports with healthcare appointments',
                    'Follows healthcare plans',
                ]),
            ],
            [
                'framework_id' => $supportWorker->id,
                'code' => 'SW-RISK-001',
                'name' => 'Risk Assessment and Management',
                'description' => 'Identify risks, follow risk assessments, and implement controls while promoting positive risk-taking.',
                'category' => 'Risk Management',
                'required_proficiency' => 'developing',
                'assessment_criteria' => json_encode([
                    'Identifies hazards and risks',
                    'Follows risk assessments',
                    'Implements control measures',
                    'Reports incidents and near misses',
                    'Supports positive risk-taking',
                ]),
            ],
        ];

        foreach ($supportWorkerCompetencies as $competency) {
            CompetencyItem::create($competency);
        }

        // Team Leader Competency Framework
        $teamLeader = CompetencyFramework::create([
            'name' => 'Team Leader Competency Framework',
            'role' => 'team_leader',
            'description' => 'Leadership and management competencies for team leaders overseeing care delivery and staff supervision.',
            'version' => 1,
            'effective_from' => now(),
            'active' => true,
        ]);

        $teamLeaderCompetencies = [
            [
                'framework_id' => $teamLeader->id,
                'code' => 'TL-LEAD-001',
                'name' => 'Team Leadership',
                'description' => 'Lead and motivate teams, delegate effectively, and create positive working environments.',
                'category' => 'Leadership',
                'required_proficiency' => 'proficient',
                'assessment_criteria' => json_encode([
                    'Provides clear direction and expectations',
                    'Delegates tasks appropriately',
                    'Motivates and supports team members',
                    'Manages team performance',
                    'Promotes positive culture',
                ]),
            ],
            [
                'framework_id' => $teamLeader->id,
                'code' => 'TL-SUPER-001',
                'name' => 'Staff Supervision',
                'description' => 'Provide regular supervision, support professional development, and manage performance.',
                'category' => 'Management',
                'required_proficiency' => 'proficient',
                'assessment_criteria' => json_encode([
                    'Conducts regular supervision sessions',
                    'Identifies training and development needs',
                    'Addresses performance issues',
                    'Supports wellbeing',
                    'Maintains supervision records',
                ]),
            ],
            [
                'framework_id' => $teamLeader->id,
                'code' => 'TL-QUAL-001',
                'name' => 'Quality Assurance',
                'description' => 'Monitor and improve service quality, implement audits, and drive continuous improvement.',
                'category' => 'Quality',
                'required_proficiency' => 'proficient',
                'assessment_criteria' => json_encode([
                    'Conducts quality audits',
                    'Analyzes quality data',
                    'Implements improvement actions',
                    'Monitors compliance',
                    'Reports on quality metrics',
                ]),
            ],
            [
                'framework_id' => $teamLeader->id,
                'code' => 'TL-SAFE-001',
                'name' => 'Safeguarding Leadership',
                'description' => 'Lead safeguarding practice, conduct investigations, and ensure robust safeguarding culture.',
                'category' => 'Safeguarding',
                'required_proficiency' => 'proficient',
                'assessment_criteria' => json_encode([
                    'Leads safeguarding investigations',
                    'Makes safeguarding decisions',
                    'Liaises with external agencies',
                    'Promotes safeguarding culture',
                    'Ensures policy compliance',
                ]),
            ],
            [
                'framework_id' => $teamLeader->id,
                'code' => 'TL-COMM-001',
                'name' => 'Multi-Agency Working',
                'description' => 'Collaborate effectively with healthcare, social care, and other external partners.',
                'category' => 'Partnership Working',
                'required_proficiency' => 'competent',
                'assessment_criteria' => json_encode([
                    'Builds effective partnerships',
                    'Represents the organization professionally',
                    'Coordinates multi-agency work',
                    'Shares information appropriately',
                    'Attends meetings and case conferences',
                ]),
            ],
        ];

        foreach ($teamLeaderCompetencies as $competency) {
            CompetencyItem::create($competency);
        }

        // Behaviour Specialist Competency Framework
        $behaviourSpecialist = CompetencyFramework::create([
            'name' => 'Behaviour Specialist Competency Framework',
            'role' => 'behaviour_specialist',
            'description' => 'Specialist competencies for Positive Behaviour Support practitioners.',
            'version' => 1,
            'effective_from' => now(),
            'active' => true,
        ]);

        $behaviourSpecialistCompetencies = [
            [
                'framework_id' => $behaviourSpecialist->id,
                'code' => 'BS-ASSESS-001',
                'name' => 'Functional Assessment',
                'description' => 'Conduct comprehensive functional behavioral assessments using evidence-based approaches.',
                'category' => 'Assessment',
                'required_proficiency' => 'expert',
                'assessment_criteria' => json_encode([
                    'Gathers comprehensive assessment data',
                    'Analyzes behavior patterns and functions',
                    'Identifies triggers and maintaining factors',
                    'Formulates hypotheses',
                    'Produces detailed assessment reports',
                ]),
            ],
            [
                'framework_id' => $behaviourSpecialist->id,
                'code' => 'BS-PLAN-001',
                'name' => 'PBS Plan Development',
                'description' => 'Develop comprehensive, person-centred Positive Behaviour Support plans.',
                'category' => 'Planning',
                'required_proficiency' => 'expert',
                'assessment_criteria' => json_encode([
                    'Develops evidence-based PBS plans',
                    'Includes proactive strategies',
                    'Specifies reactive strategies',
                    'Defines success criteria',
                    'Ensures person-centered approach',
                ]),
            ],
            [
                'framework_id' => $behaviourSpecialist->id,
                'code' => 'BS-TRAIN-001',
                'name' => 'Staff Training and Coaching',
                'description' => 'Train and coach staff in PBS approaches and specific intervention strategies.',
                'category' => 'Training',
                'required_proficiency' => 'proficient',
                'assessment_criteria' => json_encode([
                    'Delivers effective training',
                    'Provides practical coaching',
                    'Gives constructive feedback',
                    'Monitors implementation',
                    'Evaluates training effectiveness',
                ]),
            ],
        ];

        foreach ($behaviourSpecialistCompetencies as $competency) {
            CompetencyItem::create($competency);
        }

        $this->command->info('Competency frameworks seeded successfully.');
    }
}
