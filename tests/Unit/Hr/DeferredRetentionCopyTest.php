<?php

test('calendar removal copy describes reversible archival instead of deletion', function () {
    $root = dirname(__DIR__, 3);
    $index = file_get_contents($root.'/resources/js/pages/hr/calendar/index.tsx');
    $wizard = file_get_contents($root.'/resources/js/components/hr/calendar/event-wizard-dialog.tsx');
    $detail = file_get_contents($root.'/resources/js/components/hr/calendar/calendar-detail-popover.tsx');

    expect($index)->toContain('Archive event?')
        ->and($index)->toContain('retains attendees, reminders, and attachments')
        ->and($index)->toContain('Archived events')
        ->and($index)->toContain('/restore')
        ->and($index)->not->toContain('This permanently removes')
        ->and($wizard)->toContain('Archive event?')
        ->and($wizard)->toContain("toast.success('Event archived')")
        ->and($detail)->toContain('Archive');
});

test('salary band lifecycle copy explains retained history and reactivation', function () {
    $root = dirname(__DIR__, 3);
    $bands = file_get_contents($root.'/resources/js/pages/hr/compensation/bands.tsx');

    expect($bands)->toContain('Deactivate band?')
        ->and($bands)->toContain('Historical pay placement remains available')
        ->and($bands)->toContain('Reactivate band');
});
