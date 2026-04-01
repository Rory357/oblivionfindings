<?php

return [
    // Used for preferences UI + routing defaults.
    // Keys should remain stable (audit/readability).
    'groups' => [
        'Timesheets' => [
            'timesheets.created',
            'timesheets.updated',
            'timesheets.submitted',
            'timesheets.approved',
            'timesheets.rejected',
            'timesheets.returned',
        ],
        'Incidents' => [
            'incidents.draft_created',
            'incidents.submitted',
            'incidents.reviewed',
            'incidents.high_severity_alert',
        ],
        'Follow-ups' => [
            'followups.created',
            'followups.updated',
            'followups.completed',
            'followups.overdue_reminder',
        ],
        'Audit & Safety' => [
            'breakglass.daily_report',
            'incidents.high_unreviewed_reminder',
        ],

        // --- HR Module ---
        'Leave' => [
            'hr.leave.requested',
            'hr.leave.approved',
        ],
        'HR Compliance' => [
            'hr.compliance.expiry_reminder',
            'hr.policy.attestation_due',
        ],
        'Onboarding' => [
            'hr.onboarding.task_assigned',
        ],
        'Performance & Development' => [
            'hr.performance.review_due',
            'hr.development.goal_assigned',
        ],
        'HR Cases' => [
            'hr.cases.updated',
        ],
        'Wellbeing & Engagement' => [
            'hr.engagement.survey_invitation',
            'hr.engagement.action_plan_due',
        ],
        'HR Reports' => [
            'hr.reports.scheduled_ready',
        ],
        'HR Recruitment' => [
            'hr.recruitment.application_received',
            'hr.recruitment.posting_approval_requested',
            'hr.recruitment.posting_closing_soon',
        ],

        // --- Governance Module ---
        'Governance - Board' => [
            'governance.board.digest',
            'governance.board.pack_published',
            'governance.board.pre_read_reminder',
        ],
        'Governance - Resolutions' => [
            'governance.resolutions.voting_reminder',
        ],
        'Governance - Risk & Compliance' => [
            'governance.risk.review_due',
            'governance.actions.escalated',
        ],
        'Governance - CEO Reviews' => [
            'governance.ceo_review.milestone',
        ],

        // --- Sites Module ---
        'Sites - Inspections' => [
            'sites.inspections.due',
            'sites.inspections.overdue',
        ],
        'Sites - Hazards' => [
            'sites.hazards.overdue',
            'sites.hazards.escalated',
        ],
        'Sites - Checklists' => [
            'sites.checklists.due',
            'sites.checklists.overdue',
        ],
        'Sites - Events' => [
            'sites.events.reminder',
        ],

        // --- Control Room ---
        'Control Room' => [
            'controlroom.alert',
        ],

        // --- Cross-Domain ---
        'Cross-Module Notifications' => [
            'cross_domain.hr_compliance_concern_created',
            'cross_domain.finance_budget_threshold_exceeded',
            'cross_domain.roadmap_initiative_requires_governance_decision',
        ],
    ],

    'cross_domain' => [
        'label' => 'Cross-Module Notifications',
        'events' => [
            'hr_compliance_concern_created' => [
                'label' => 'HR compliance concern escalated to governance',
                'description' => 'When an HR compliance issue requires governance attention',
                'channels' => ['database', 'mail'],
            ],
            'finance_budget_threshold_exceeded' => [
                'label' => 'Budget threshold exceeded',
                'description' => 'When spending exceeds budget threshold, notify governance',
                'channels' => ['database', 'mail'],
            ],
            'roadmap_initiative_requires_governance_decision' => [
                'label' => 'Initiative requires governance decision',
                'description' => 'When a roadmap initiative needs board/governance approval',
                'channels' => ['database', 'mail'],
            ],
        ],
    ],
];
