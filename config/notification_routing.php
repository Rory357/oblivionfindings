<?php

return [
    // Default routing rules. Fine-tune via Settings → Notifications (role defaults).
    // Per-user overrides always take precedence.
    'routes' => [
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

        'incidents.draft_created' => [
            'target_groups' => ['entity_user'],
        ],
        'incidents.submitted' => [
            // Severity can override these defaults inside NotificationService
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

        'breakglass.daily_report' => [
            'target_groups' => ['managers_core', 'auditors'],
        ],
        'incidents.high_unreviewed_reminder' => [
            'target_groups' => ['managers_core', 'coordinators'],
        ],
    ],
];
