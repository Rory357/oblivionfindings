<?php

namespace Tests\Feature\Tasks;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class EscalateOverdueTasksPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_escalate_overdue_tasks_skips_inactive_employee_profiles(): void
    {
        $activeUser = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $activeUser->id,
            'employee_number' => 'EMP-' . $activeUser->id,
            'work_email' => $activeUser->email,
            'is_active' => true,
        ]);

        $inactiveUser = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $inactiveUser->id,
            'employee_number' => 'EMP-' . $inactiveUser->id,
            'work_email' => $inactiveUser->email,
            'is_active' => false,
        ]);

        $exitCode = Artisan::call('tasks:escalate');
        $this->assertSame(0, $exitCode);
    }
}
