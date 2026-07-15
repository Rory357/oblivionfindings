<?php

namespace Tests\Feature\Contracts;

use Tests\TestCase;

class AttendanceServiceDoesNotWriteShiftStatusDirectlyTest extends TestCase
{
    public function test_attendance_service_delegates_shift_status_changes_to_lifecycle_service(): void
    {
        $source = file_get_contents(app_path('Domain/Hr/Services/AttendanceService.php'));

        $this->assertStringContainsString('ShiftLifecycleService::class', $source);
        $this->assertStringNotContainsString('$shift->update([', $source);
        $this->assertStringNotContainsString('$shift->forceFill([', $source);
        $this->assertStringNotContainsString('->shift->update([', $source);
        $this->assertStringNotContainsString('->shift->forceFill([', $source);
    }
}
