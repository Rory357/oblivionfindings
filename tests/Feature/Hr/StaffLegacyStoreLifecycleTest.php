<?php

namespace Tests\Feature\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StaffLegacyStoreLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_employee_profile_takes_strict_precedence_over_legacy_staff_table(): void
    {
        $site = Site::factory()->create();
        $admin = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $admin->id,
            'employee_number' => 'EMP-' . $admin->id,
            'work_email' => $admin->email,
            'primary_site_id' => $site->id,
            'is_active' => true,
        ]);
        $permission = Permission::query()->firstOrCreate(['key' => 'staff.viewAny'], ['description' => 'View all staff', 'group' => 'test', 'module' => 'Test']);
        $admin->permissionOverrides()->attach($permission, ['allowed' => true]);

        $employee = User::factory()->create(['name' => 'Jane Doe']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $employee->id,
            'employee_number' => 'EMP-' . $employee->id,
            'work_email' => $employee->email,
            'position_title' => 'Senior Support Worker',
            'department' => 'Care Services',
            'employment_type' => 'full_time',
            'primary_site_id' => $site->id,
            'is_active' => true,
        ]);

        if (Schema::hasTable('staff')) {
            DB::table('staff')->insert([
                'user_id' => $employee->id,
                'job_title' => 'Outdated Legacy Title',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $response = $this->actingAs($admin)
            ->get("/staff/{$employee->id}");

        $response->assertOk();
        $response->assertSee('Senior Support Worker');
        $response->assertDontSee('Outdated Legacy Title');
    }

    public function test_legacy_staff_acts_as_safe_read_only_fallback_when_profile_absent(): void
    {
        if (! Schema::hasTable('staff')) {
            $this->markTestSkipped('Legacy staff table absent in this migration snapshot.');
        }

        $site = Site::factory()->create();
        $admin = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $admin->id,
            'employee_number' => 'EMP-' . $admin->id,
            'work_email' => $admin->email,
            'primary_site_id' => $site->id,
            'is_active' => true,
        ]);
        $permission = Permission::query()->firstOrCreate(['key' => 'staff.viewAny'], ['description' => 'View all staff', 'group' => 'test', 'module' => 'Test']);
        $admin->permissionOverrides()->attach($permission, ['allowed' => true]);

        $employee = User::factory()->create(['name' => 'Legacy John']);

        DB::table('staff')->insert([
            'user_id' => $employee->id,
            'job_title' => 'Legacy Position',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->get("/staff/{$employee->id}");

        $response->assertOk();
        $response->assertSee('Legacy Position');
    }
}
