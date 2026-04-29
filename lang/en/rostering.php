<?php

return [
    'publish' => [
        'draft' => 'Draft',
        'validating' => 'Validating',
        'ready_to_publish' => 'Ready to publish',
        'published' => 'Published',
        'changed_after_publish' => 'Changed after publish',
        'archived' => 'Archived',
        'review_title' => 'Publish review',
        'diff_title' => 'Publish diff',
        'review_ready' => 'Roster reviewed and ready to publish.',
        'review_blocked' => 'Roster reviewed. Resolve publish blockers before publishing.',
        'published_message' => 'Roster published. Assigned frontline staff can now see their shifts.',
        'republished_message' => 'Roster republished as version :version. Previous version archived.',
        'unpublished_message' => 'Roster unpublished. Frontline visibility has been paused for this period.',
    ],
    'suggestions' => [
        'generated' => 'Roster suggestions generated for manager review.',
        'queued' => 'Roster suggestion run queued. This can take a moment for larger weeks.',
        'choose_site' => 'Choose a site before generating roster suggestions.',
        'applied' => 'Suggested assignment applied. The roster period remains draft.',
        'accepted' => 'Accepted suggestion :id.',
        'dismissed' => 'Dismissed suggestion :id.',
        'bulk_applied' => 'Applied :applied accepted suggestions. Stale: :stale. Failed: :failed.',
        'auto_schedule_disabled' => 'Auto-scheduling is not configured yet. Please assign open shifts manually.',
    ],
];
