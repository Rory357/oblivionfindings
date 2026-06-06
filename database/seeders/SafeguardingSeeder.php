<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\SafeguardingActionPlan;
use App\Models\SafeguardingAlert;
use App\Models\SafeguardingConcern;
use App\Models\SafeguardingExternalReport;
use App\Models\SafeguardingInvestigation;
use App\Models\SafeguardingRiskAssessment;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;

class SafeguardingSeeder extends Seeder
{
    public function run(): void
    {
        $manager = User::query()->where('role', 'provider_manager')->first();
        $coordinator = User::query()->where('role', 'coordinator')->first();
        $workers = User::query()->where('role', 'support_worker')->get();
        $clients = Client::query()->limit(5)->get();
        $site = Site::query()->first();

        if (!$manager || $workers->isEmpty() || $clients->isEmpty()) {
            return;
        }

        $investigator = $coordinator ?? $manager;

        // Concern 1: Critical - Alleged physical abuse (closed, fully investigated)
        $concern1 = SafeguardingConcern::create([
            'subject_type' => Client::class,
            'subject_id' => $clients[0]->id,
            'concern_type' => 'abuse',
            'abuse_category' => 'physical',
            'severity' => 'critical',
            'description' => 'Staff member observed unexplained bruising on client\'s upper arm during personal care. Client appeared distressed when asked about it.',
            'occurred_at' => now()->subDays(14)->setTime(9, 30),
            'location' => 'Client\'s bedroom during morning routine',
            'reported_by_user_id' => $workers->first()->id,
            'reported_at' => now()->subDays(14)->setTime(10, 15),
            'reporter_notes' => 'Noticed during morning personal care. Client initially reluctant to discuss.',
            'witnesses' => [
                ['name' => 'Jane Smith', 'role' => 'Support Worker', 'contact' => 'On file'],
            ],
            'status' => 'closed',
            'immediate_actions' => 'Photographed injury with consent. Notified manager immediately. Ensured client safety.',
            'subject_informed' => true,
            'subject_informed_at' => now()->subDays(14)->setTime(11, 0),
            'requires_external_referral' => true,
            'current_risk_level' => 'low',
            'protective_measures' => 'Increased monitoring during care visits. Two-person visits implemented.',
            'assigned_to_user_id' => $investigator->id,
            'assigned_at' => now()->subDays(14)->setTime(12, 0),
            'closed_by_user_id' => $manager->id,
            'closed_at' => now()->subDays(2),
            'closure_summary' => 'Investigation completed. Bruising determined to be from accidental fall. Client confirmed. No safeguarding concerns substantiated.',
            'lessons_learned' => 'Ensure all incidents are documented promptly. Review fall prevention measures.',
            'site_id' => $site?->id,
            'created_by' => $workers->first()->id,
            'updated_by' => $manager->id,
        ]);

        // Investigation for Concern 1
        SafeguardingInvestigation::create([
            'safeguarding_concern_id' => $concern1->id,
            'investigation_type' => 'internal',
            'lead_investigator_id' => $investigator->id,
            'investigation_team' => [$manager->id],
            'started_at' => now()->subDays(13),
            'target_completion_date' => now()->subDays(6),
            'completed_at' => now()->subDays(3),
            'status' => 'completed',
            'terms_of_reference' => 'Investigate the circumstances surrounding the unexplained bruising observed on the client.',
            'methodology' => 'Interview client, review care records, interview staff present during care periods, review CCTV if available.',
            'evidence_collected' => [
                'Photographs of injury',
                'Care records for past 7 days',
                'Staff rotas and visit logs',
            ],
            'interviews_conducted' => [
                ['person' => 'Client', 'date' => now()->subDays(12)->toDateString(), 'summary' => 'Client stated they fell in bathroom but did not report it.'],
                ['person' => 'Support Worker A', 'date' => now()->subDays(11)->toDateString(), 'summary' => 'No concerns noted during previous visits.'],
            ],
            'findings' => 'Client confirmed the bruising was from an unreported fall in the bathroom. No evidence of abuse or neglect.',
            'outcome' => 'unsubstantiated',
            'recommendations' => 'Review bathroom safety measures. Remind client about reporting all incidents.',
            'action_plan' => [
                'Install additional grab rails in bathroom',
                'Conduct falls risk reassessment',
            ],
            'report_completed' => true,
            'created_by' => $investigator->id,
            'updated_by' => $investigator->id,
        ]);

        // External report for Concern 1
        SafeguardingExternalReport::create([
            'safeguarding_concern_id' => $concern1->id,
            'authority_type' => 'health_nz',
            'authority_name' => 'Health NZ Safeguarding Team',
            'authority_contact' => 'safeguarding@healthnz.govt.nz',
            'authority_reference' => 'HNZ-2026-00123',
            'reported_at' => now()->subDays(13)->setTime(14, 0),
            'reported_by_user_id' => $manager->id,
            'report_method' => 'online_form',
            'report_summary' => 'Referral made regarding unexplained bruising observed on vulnerable adult during care visit.',
            'acknowledgement_received' => true,
            'acknowledged_at' => now()->subDays(12),
            'acknowledgement_reference' => 'ACK-2026-00456',
            'authority_action' => 'no_action',
            'authority_feedback' => 'Thank you for the referral. Based on the information provided and your internal investigation findings, no further action required from our side.',
            'authority_feedback_at' => now()->subDays(4),
            'created_by' => $manager->id,
            'updated_by' => $manager->id,
        ]);

        // Risk Assessment for Concern 1
        SafeguardingRiskAssessment::create([
            'safeguarding_concern_id' => $concern1->id,
            'assessor_id' => $investigator->id,
            'assessed_at' => now()->subDays(13),
            'risk_factors' => [
                'History of falls',
                'Lives alone',
                'Mobility issues',
            ],
            'protective_factors' => [
                'Regular care visits',
                'Engaged family',
                'Cooperative with care',
            ],
            'risk_to_self' => 'medium',
            'risk_to_others' => 'none',
            'risk_from_others' => 'low',
            'overall_risk_level' => 'medium',
            'capacity_assessed' => true,
            'mental_capacity' => 'has_capacity',
            'capacity_notes' => 'Client has capacity to make decisions about their care and safety.',
            'immediate_actions_required' => 'Review bathroom safety. Ensure two-person visits until investigation complete.',
            'protective_measures' => [
                'Two-person care visits',
                'Daily welfare checks',
                'Family notified',
            ],
            'multi_agency_required' => false,
            'next_review_date' => now()->addMonths(3),
            'assessment_notes' => 'Initial assessment following reported concern. Client cooperative.',
            'created_by' => $investigator->id,
            'updated_by' => $investigator->id,
        ]);

        // Action Plans for Concern 1
        SafeguardingActionPlan::create([
            'safeguarding_concern_id' => $concern1->id,
            'action_description' => 'Install additional grab rails in client bathroom',
            'action_type' => 'protective_measure',
            'assigned_to_user_id' => $manager->id,
            'due_date' => now()->subDays(5),
            'status' => 'completed',
            'priority' => 1,
            'completion_notes' => 'Two grab rails installed by approved contractor.',
            'completed_at' => now()->subDays(6),
            'completed_by_user_id' => $manager->id,
            'created_by' => $investigator->id,
            'updated_by' => $manager->id,
        ]);

        SafeguardingActionPlan::create([
            'safeguarding_concern_id' => $concern1->id,
            'action_description' => 'Conduct falls risk reassessment',
            'action_type' => 'support_service',
            'assigned_to_user_id' => $workers->first()->id,
            'due_date' => now()->subDays(3),
            'status' => 'completed',
            'priority' => 2,
            'completion_notes' => 'Falls risk assessment completed. Updated support plan with recommendations.',
            'completed_at' => now()->subDays(4),
            'completed_by_user_id' => $workers->first()->id,
            'created_by' => $investigator->id,
            'updated_by' => $workers->first()->id,
        ]);

        // Alert for Client 1
        SafeguardingAlert::create([
            'alertable_type' => Client::class,
            'alertable_id' => $clients[0]->id,
            'safeguarding_concern_id' => $concern1->id,
            'alert_type' => 'requires_monitoring',
            'alert_summary' => 'Falls risk - enhanced monitoring',
            'alert_details' => 'Client has history of unreported falls. Ensure all visits include falls prevention check.',
            'severity' => 'medium',
            'active' => true,
            'expires_at' => now()->addMonths(6),
            'last_reviewed_at' => now()->subDays(2),
            'last_reviewed_by' => $manager->id,
            'next_review_date' => now()->addMonths(3),
            'created_by' => $investigator->id,
            'updated_by' => $manager->id,
        ]);

        // Concern 2: High - Financial abuse (under investigation)
        if (isset($clients[1])) {
            $concern2 = SafeguardingConcern::create([
                'subject_type' => Client::class,
                'subject_id' => $clients[1]->id,
                'concern_type' => 'abuse',
                'abuse_category' => 'financial',
                'severity' => 'high',
                'description' => 'Client reported that a family member has been withdrawing money from their account without permission. Client states they are "too scared" to say no.',
                'occurred_at' => now()->subDays(5),
                'location' => 'Client\'s home',
                'alleged_perpetrator_name' => 'Family member (name withheld)',
                'alleged_perpetrator_details' => 'Adult child who visits weekly. Client reports feeling pressured.',
                'reported_by_user_id' => $workers->skip(1)->first()?->id ?? $workers->first()->id,
                'reported_at' => now()->subDays(5)->setTime(14, 30),
                'reporter_notes' => 'Client disclosed during routine visit. Appeared anxious when discussing.',
                'status' => 'investigating',
                'immediate_actions' => 'Advised client of their rights. Offered to contact bank on their behalf. Documented disclosure.',
                'subject_informed' => true,
                'subject_informed_at' => now()->subDays(5)->setTime(15, 0),
                'requires_external_referral' => true,
                'current_risk_level' => 'high',
                'protective_measures' => 'Monitoring financial situation. Regular check-ins about wellbeing.',
                'assigned_to_user_id' => $manager->id,
                'assigned_at' => now()->subDays(5)->setTime(16, 0),
                'site_id' => $site?->id,
                'created_by' => $workers->skip(1)->first()?->id ?? $workers->first()->id,
                'updated_by' => $manager->id,
            ]);

            // Investigation for Concern 2 (ongoing)
            SafeguardingInvestigation::create([
                'safeguarding_concern_id' => $concern2->id,
                'investigation_type' => 'joint',
                'lead_investigator_id' => $manager->id,
                'investigation_team' => [$investigator->id],
                'started_at' => now()->subDays(4),
                'target_completion_date' => now()->addDays(10),
                'status' => 'in_progress',
                'terms_of_reference' => 'Investigate allegations of financial abuse by family member.',
                'methodology' => 'Work with Health NZ and police as appropriate. Interview client, review financial records with consent.',
                'evidence_collected' => [
                    'Client disclosure statement',
                    'Bank statements (pending client consent)',
                ],
                'interviews_conducted' => [
                    ['person' => 'Client', 'date' => now()->subDays(3)->toDateString(), 'summary' => 'Client confirmed ongoing withdrawals. Estimates approximately $500 over past 3 months.'],
                ],
                'created_by' => $manager->id,
                'updated_by' => $manager->id,
            ]);

            // External report for Concern 2
            SafeguardingExternalReport::create([
                'safeguarding_concern_id' => $concern2->id,
                'authority_type' => 'health_nz',
                'authority_name' => 'Health NZ Safeguarding Team',
                'authority_contact' => 'safeguarding@healthnz.govt.nz',
                'reported_at' => now()->subDays(4)->setTime(10, 0),
                'reported_by_user_id' => $manager->id,
                'report_method' => 'phone',
                'report_summary' => 'Referral regarding alleged financial abuse of vulnerable adult by family member.',
                'acknowledgement_received' => true,
                'acknowledged_at' => now()->subDays(4)->setTime(11, 30),
                'acknowledgement_reference' => 'HNZ-2026-00789',
                'authority_action' => 'investigating',
                'created_by' => $manager->id,
                'updated_by' => $manager->id,
            ]);

            // Risk Assessment for Concern 2
            SafeguardingRiskAssessment::create([
                'safeguarding_concern_id' => $concern2->id,
                'assessor_id' => $manager->id,
                'assessed_at' => now()->subDays(4),
                'risk_factors' => [
                    'Financial dependency on family',
                    'Cognitive decline affecting decision-making',
                    'Social isolation',
                    'Fear of family member',
                ],
                'protective_factors' => [
                    'Regular care visits provide oversight',
                    'Client willing to engage with services',
                    'Has other supportive family members',
                ],
                'risk_to_self' => 'medium',
                'risk_to_others' => 'none',
                'risk_from_others' => 'high',
                'overall_risk_level' => 'high',
                'capacity_assessed' => true,
                'mental_capacity' => 'fluctuating',
                'capacity_notes' => 'Client has fluctuating capacity regarding financial decisions. Best interests assessment may be required.',
                'immediate_actions_required' => 'Support client to secure bank account. Consider referral to advocacy service.',
                'protective_measures' => [
                    'Daily welfare calls',
                    'Support with financial management',
                    'Advocacy referral',
                ],
                'multi_agency_required' => true,
                'agencies_involved' => [
                    ['name' => 'Health NZ Safeguarding Team', 'role' => 'Lead agency'],
                    ['name' => 'NZ Police Financial Crime Group', 'role' => 'Advisory'],
                ],
                'next_review_date' => now()->addDays(7),
                'assessment_notes' => 'High risk case requiring multi-agency approach.',
                'created_by' => $manager->id,
                'updated_by' => $manager->id,
            ]);

            // Action Plans for Concern 2 (ongoing)
            SafeguardingActionPlan::create([
                'safeguarding_concern_id' => $concern2->id,
                'action_description' => 'Support client to contact bank and review account security',
                'action_type' => 'protective_measure',
                'assigned_to_user_id' => $workers->first()->id,
                'due_date' => now()->addDays(2),
                'status' => 'in_progress',
                'priority' => 1,
                'created_by' => $manager->id,
                'updated_by' => $manager->id,
            ]);

            SafeguardingActionPlan::create([
                'safeguarding_concern_id' => $concern2->id,
                'action_description' => 'Refer to independent advocacy service',
                'action_type' => 'referral',
                'assigned_to_user_id' => $investigator->id,
                'due_date' => now()->addDays(3),
                'status' => 'pending',
                'priority' => 2,
                'created_by' => $manager->id,
                'updated_by' => $manager->id,
            ]);

            // Alert for Client 2
            SafeguardingAlert::create([
                'alertable_type' => Client::class,
                'alertable_id' => $clients[1]->id,
                'safeguarding_concern_id' => $concern2->id,
                'alert_type' => 'vulnerable_adult',
                'alert_summary' => 'Active safeguarding concern - financial abuse',
                'alert_details' => 'Client subject to ongoing safeguarding investigation regarding financial abuse. Monitor for signs of distress or pressure from visitors.',
                'severity' => 'high',
                'active' => true,
                'next_review_date' => now()->addDays(7),
                'created_by' => $manager->id,
                'updated_by' => $manager->id,
            ]);
        }

        // Concern 3: Medium - Self-neglect (monitoring)
        if (isset($clients[2])) {
            $concern3 = SafeguardingConcern::create([
                'subject_type' => Client::class,
                'subject_id' => $clients[2]->id,
                'concern_type' => 'self-neglect',
                'abuse_category' => 'self-neglect',
                'severity' => 'medium',
                'description' => 'Staff have noticed gradual decline in client\'s self-care. Home environment becoming cluttered. Client refusing some personal care support.',
                'occurred_at' => now()->subDays(10),
                'location' => 'Client\'s home',
                'reported_by_user_id' => $workers->last()->id,
                'reported_at' => now()->subDays(10)->setTime(16, 0),
                'reporter_notes' => 'Pattern noticed over past few weeks. Client becoming more withdrawn.',
                'status' => 'monitoring',
                'immediate_actions' => 'Discussed concerns with client. Explored reasons for decline. GP referral suggested.',
                'subject_informed' => true,
                'subject_informed_at' => now()->subDays(10)->setTime(16, 30),
                'requires_external_referral' => false,
                'current_risk_level' => 'medium',
                'protective_measures' => 'Increased visit frequency. Mental health assessment requested.',
                'assigned_to_user_id' => $investigator->id,
                'assigned_at' => now()->subDays(9),
                'site_id' => $site?->id,
                'created_by' => $workers->last()->id,
                'updated_by' => $investigator->id,
            ]);

            // Risk Assessment for Concern 3
            SafeguardingRiskAssessment::create([
                'safeguarding_concern_id' => $concern3->id,
                'assessor_id' => $investigator->id,
                'assessed_at' => now()->subDays(9),
                'risk_factors' => [
                    'Declining self-care',
                    'Social withdrawal',
                    'Possible depression',
                    'Refusing support',
                ],
                'protective_factors' => [
                    'Accepting some care visits',
                    'No immediate health crisis',
                    'Previously engaged well with services',
                ],
                'risk_to_self' => 'medium',
                'risk_to_others' => 'none',
                'risk_from_others' => 'none',
                'overall_risk_level' => 'medium',
                'capacity_assessed' => true,
                'mental_capacity' => 'has_capacity',
                'capacity_notes' => 'Client has capacity but making choices that increase risk.',
                'immediate_actions_required' => 'GP referral for mental health assessment. Increase monitoring.',
                'protective_measures' => [
                    'Twice-daily welfare checks',
                    'GP involvement',
                    'Family engagement',
                ],
                'multi_agency_required' => false,
                'next_review_date' => now()->addDays(14),
                'assessment_notes' => 'Monitoring situation. Client making capacitated choices but risk increasing.',
                'created_by' => $investigator->id,
                'updated_by' => $investigator->id,
            ]);

            // Action Plans for Concern 3
            SafeguardingActionPlan::create([
                'safeguarding_concern_id' => $concern3->id,
                'action_description' => 'Arrange GP home visit for mental health assessment',
                'action_type' => 'referral',
                'assigned_to_user_id' => $investigator->id,
                'due_date' => now()->subDays(5),
                'status' => 'completed',
                'priority' => 1,
                'completion_notes' => 'GP visited. Mild depression diagnosed. Medication prescribed.',
                'completed_at' => now()->subDays(6),
                'completed_by_user_id' => $investigator->id,
                'created_by' => $investigator->id,
                'updated_by' => $investigator->id,
            ]);

            SafeguardingActionPlan::create([
                'safeguarding_concern_id' => $concern3->id,
                'action_description' => 'Review support plan and increase visit frequency',
                'action_type' => 'support_service',
                'assigned_to_user_id' => $workers->last()->id,
                'due_date' => now()->addDays(5),
                'status' => 'in_progress',
                'priority' => 2,
                'created_by' => $investigator->id,
                'updated_by' => $investigator->id,
            ]);
        }

        // Concern 4: Low - Near miss/potential concern (triaged, no action needed)
        if (isset($clients[3])) {
            SafeguardingConcern::create([
                'subject_type' => Client::class,
                'subject_id' => $clients[3]->id,
                'concern_type' => 'organizational',
                'severity' => 'low',
                'description' => 'Medication was delivered to wrong address. No harm caused as error identified before any medication taken.',
                'occurred_at' => now()->subDays(3),
                'location' => 'External - pharmacy delivery',
                'reported_by_user_id' => $workers->random()->id,
                'reported_at' => now()->subDays(3)->setTime(11, 0),
                'reporter_notes' => 'Pharmacy contacted and confirmed error. Correct delivery made same day.',
                'status' => 'closed',
                'immediate_actions' => 'Contacted pharmacy. Verified client received correct medication. No harm caused.',
                'subject_informed' => true,
                'subject_informed_at' => now()->subDays(3)->setTime(12, 0),
                'requires_external_referral' => false,
                'current_risk_level' => 'low',
                'closed_by_user_id' => $manager->id,
                'closed_at' => now()->subDays(2),
                'closure_summary' => 'Near miss incident. No harm caused. Pharmacy has implemented additional checks.',
                'lessons_learned' => 'Review medication delivery process. Consider additional verification steps.',
                'site_id' => $site?->id,
                'created_by' => $workers->random()->id,
                'updated_by' => $manager->id,
            ]);
        }

        // Concern 5: Staff-related concern (reported)
        if (isset($clients[4]) && $workers->count() > 2) {
            $staffWorker = $workers->skip(2)->first() ?? $workers->last();

            $concern5 = SafeguardingConcern::create([
                'subject_type' => User::class,
                'subject_id' => $staffWorker->id,
                'subject_name' => $staffWorker->name,
                'concern_type' => 'organizational',
                'severity' => 'medium',
                'description' => 'Concern raised about staff member\'s conduct. Allegations of inappropriate comments made to colleagues.',
                'occurred_at' => now()->subDays(2),
                'location' => 'Office',
                'reported_by_user_id' => $manager->id,
                'reported_by_role' => 'Manager',
                'reported_at' => now()->subDays(2)->setTime(9, 0),
                'reporter_notes' => 'Multiple colleagues have raised concerns. HR investigation initiated.',
                'status' => 'triaged',
                'immediate_actions' => 'Staff member informed of concern. Temporarily reassigned pending investigation.',
                'requires_external_referral' => false,
                'current_risk_level' => 'medium',
                'assigned_to_user_id' => $manager->id,
                'assigned_at' => now()->subDays(2)->setTime(10, 0),
                'created_by' => $manager->id,
                'updated_by' => $manager->id,
            ]);

            // Alert on staff member
            SafeguardingAlert::create([
                'alertable_type' => User::class,
                'alertable_id' => $staffWorker->id,
                'safeguarding_concern_id' => $concern5->id,
                'alert_type' => 'requires_monitoring',
                'alert_summary' => 'HR investigation pending',
                'alert_details' => 'Staff member subject to HR investigation. Temporarily on restricted duties.',
                'severity' => 'medium',
                'active' => true,
                'next_review_date' => now()->addDays(14),
                'created_by' => $manager->id,
                'updated_by' => $manager->id,
            ]);
        }
    }
}
