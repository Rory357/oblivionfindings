<?php

use Illuminate\Support\Facades\Schema;

test('the orphaned HrCheckIn model file is removed', function () {
    expect(file_exists(app_path('Domain/Hr/Models/HrCheckIn.php')))->toBeFalse();
});

test('the orphaned hr_check_ins table is dropped', function () {
    expect(Schema::hasTable('hr_check_ins'))->toBeFalse();
});
