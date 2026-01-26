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
    ],
];
