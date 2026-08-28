<?php

test('attendance time-entry uniqueness migration fails closed instead of deleting duplicate evidence', function (): void {
    $root = dirname(__DIR__, 2);
    $source = file_get_contents(
        $root.'/database/migrations/2026_06_27_120000_add_unique_attendance_session_id_to_hr_time_entries.php',
    );

    expect($source)->not->toBeFalse()
        ->and(strtoupper($source))->not->toContain('DELETE T1')
        ->and($source)->toContain("havingRaw('COUNT(*) > 1')")
        ->and($source)->toContain('Cannot add the attendance time-entry unique index')
        ->and($source)->toContain('throw new RuntimeException');
});

test('attendance backfill has a post-payroll-mutex replay migration', function (): void {
    $root = dirname(__DIR__, 2);
    $source = file_get_contents(
        $root.'/database/migrations/2026_08_23_000215_retry_attendance_time_entry_backfill.php',
    );

    expect($source)->not->toBeFalse()
        ->and($source)->toContain("hasTable('hr_payroll_run_mutexes')")
        ->and($source)->toContain('whereNotExists')
        ->and($source)->toContain('skipped_sessions');
});
