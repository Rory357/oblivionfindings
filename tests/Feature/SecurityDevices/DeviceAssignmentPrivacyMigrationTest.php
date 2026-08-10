<?php

use Illuminate\Support\Facades\Schema;

it('stores full safeguarding tracking purposes as narrative text', function (): void {
    expect(Schema::getColumnType('device_assignments', 'tracking_purpose'))->toBe('text')
        ->and(Schema::hasColumns('device_assignments', [
            'authority_basis',
            'access_audience',
            'retention_days',
            'collection_started_at',
            'collection_stopped_at',
            'collection_stop_reason',
            'withdrawal_outcome',
        ]))->toBeTrue()
        ->and(Schema::hasIndex('device_assignments', 'dev_assign_collection_active_idx'))->toBeTrue();
});
