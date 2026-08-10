<?php

it('keeps device assignment privacy governance migrations resumable and non-truncating', function (): void {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $privacyMigration = file_get_contents(
        $root.'/database/migrations/2026_07_26_000130_add_privacy_governance_to_device_assignments.php',
    );
    $expansionMigration = file_get_contents(
        $root.'/database/migrations/2026_07_26_000135_expand_device_assignment_tracking_purpose.php',
    );

    expect($privacyMigration)
        ->toContain("Schema::getColumnType('device_assignments', 'tracking_purpose') !== 'text'")
        ->toContain("\$table->text('tracking_purpose')->nullable()")
        ->toContain('Schema::whenTableDoesntHaveIndex(')
        ->not->toContain("\$table->string('tracking_purpose', 160)")
        ->not->toContain('substr(', 'Str::limit(')
        ->and(substr_count($privacyMigration, "! Schema::hasColumn('device_assignments'"))->toBe(8)
        ->and($expansionMigration)
        ->toContain("Schema::getColumnType('device_assignments', 'tracking_purpose') === 'text'")
        ->toContain("\$table->text('tracking_purpose')->nullable()->change()")
        ->toContain('Deliberately do not narrow this narrative field')
        ->not->toContain("\$table->string('tracking_purpose'")
        ->not->toContain('substr(', 'Str::limit(');
});
