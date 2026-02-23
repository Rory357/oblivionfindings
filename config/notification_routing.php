<?php

return [
    // Default routing rules. Fine-tune via Settings → Notifications (role defaults).
    // Per-user overrides always take precedence.
    'routes' => [
        // --- Timesheets ---
        'timesheets.created' => [
            'include_managers' => false,
            'include_entity_user' => true,
            'include_assigned_workers' => false,
            'target_groups' => ['entity_user'],
        ],
        'timesheets.updated' => [
            'include_managers' => false,
            'include_entity_user' => true,
            'include_assigned_workers' => false,
            'target_groups' => ['entity_user'],
        ],
        'timesheets.submitted' => [
            'include_managers' => false,
            'include_assigned_workers' => false,
            'include_entity_user' => false,
            'target_groups' => ['approvers'],
        ],
        'timesheets.approved' => [
            'target_groups' => ['entity_user'],
        ],
        'timesheets.rejected' => [
            'target_groups' => ['entity_user'],
        ],
        'timesheets.returned' => [
            'target_groups' => ['entity_user'],
        ],

        // --- Incidents ---
        'incidents.draft_created' => [
            'target_groups' => ['entity_user'],
        ],
        'incidents.submitted' => [
            'include_managers' => false,
            'target_groups' => ['coordinators', 'assigned_workers'],
        ],
        'incidents.reviewed' => [
            'include_managers' => false,
            'target_groups' => ['entity_user', 'assigned_workers'],
        ],
        'incidents.high_severity_alert' => [
            'include_managers' => false,
            'include_assigned_workers' => false,
            'include_entity_user' => false,
            'target_groups' => ['managers_core'],
        ],

        // --- Follow-ups ---
        'followups.created' => [
            'include_managers' => false,
            'target_groups' => ['coordinators', 'assigned_workers', 'entity_user'],
        ],
        'followups.updated' => [
            'include_managers' => false,
            'target_groups' => ['coordinators', 'assigned_workers', 'entity_user'],
        ],
        'followups.completed' => [
            'include_managers' => false,
            'target_groups' => ['coordinators', 'assigned_workers', 'entity_user'],
        ],
        'followups.overdue_reminder' => [
            'include_managers' => false,
            'target_groups' => ['coordinators', 'entity_user'],
        ],

        // --- Audit & Safety ---
        'breakglass.daily_report' => [
            'target_groups' => ['managers_core', 'auditors'],
        ],
        'incidents.high_unreviewed_reminder' => [
            'target_groups' => ['managers_core', 'coordinators'],
        ],

        // --- HR: Leave ---
        'hr.leave.requested' => [
            'target_groups' => ['approvers', 'managers_core'],
        ],
        'hr.leave.approved' => [
            'target_groups' => ['entity_user'],
        ],

        // --- HR: Compliance ---
        'hr.compliance.expiry_reminder' => [
            'target_groups' => ['entity_user', 'managers_core'],
        ],
        'hr.policy.attestation_due' => [
            'target_groups' => ['entity_user'],
        ],

        // --- HR: Onboarding ---
        'hr.onboarding.task_assigned' => [
            'target_groups' => ['entity_user'],
        ],

        // --- HR: Performance & Development ---
        'hr.performance.review_due' => [
            'target_groups' => ['entity_user', 'managers_core'],
        ],
        'hr.development.goal_assigned' => [
            'target_groups' => ['entity_user'],
        ],

        // --- HR: Cases ---
        'hr.cases.updated' => [
            'target_groups' => ['entity_user', 'managers_core'],
        ],

        // --- HR: Wellbeing & Engagement ---
        'hr.engagement.survey_invitation' => [
            'target_groups' => ['all_staff'],
        ],
        'hr.engagement.action_plan_due' => [
            'target_groups' => ['entity_user'],
        ],

        // --- HR: Reports ---
        'hr.reports.scheduled_ready' => [
            'target_groups' => ['entity_user', 'managers_core'],
        ],

        // --- Governance: Board ---
        'governance.board.digest' => [
            'target_groups' => ['board_members'],
        ],
        'governance.board.pack_published' => [
            'target_groups' => ['board_members'],
        ],
        'governance.board.pre_read_reminder' => [
            'target_groups' => ['board_members'],
        ],

        // --- Governance: Resolutions ---
        'governance.resolutions.voting_reminder' => [
            'target_groups' => ['board_members'],
        ],

        // --- Governance: Risk & Compliance ---
        'governance.risk.review_due' => [
            'target_groups' => ['managers_core', 'board_members'],
        ],
        'governance.actions.escalated' => [
            'target_groups' => ['managers_core', 'board_members'],
        ],

        // --- Governance: CEO Reviews ---
        'governance.ceo_review.milestone' => [
            'target_groups' => ['board_members'],
        ],

        // --- Sites: Inspections ---
        'sites.inspections.due' => [
            'target_groups' => ['coordinators', 'managers_core'],
        ],
        'sites.inspections.overdue' => [
            'target_groups' => ['coordinators', 'managers_core'],
        ],

        // --- Sites: Hazards ---
        'sites.hazards.overdue' => [
            'target_groups' => ['coordinators', 'managers_core'],
        ],
        'sites.hazards.escalated' => [
            'target_groups' => ['managers_core'],
        ],

        // --- Sites: Checklists ---
        'sites.checklists.due' => [
            'target_groups' => ['coordinators', 'assigned_workers'],
        ],
        'sites.checklists.overdue' => [
            'target_groups' => ['coordinators', 'managers_core'],
        ],

        // --- Sites: Events ---
        'sites.events.reminder' => [
            'target_groups' => ['entity_user', 'assigned_workers'],
        ],

        // --- Control Room ---
        'controlroom.alert' => [
            'target_groups' => ['managers_core'],
        ],
    ],
];
