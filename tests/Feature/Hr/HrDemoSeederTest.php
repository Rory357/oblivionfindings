<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\User;
use Database\Seeders\HrDemoSeeder;
use Illuminate\Support\Facades\DB;

test('hr demo seeder fills production-readiness demo workflows without duplicates', function () {
    $admin = User::factory()->create([
        'name' => 'Demo Admin',
        'email' => 'admin@demo.test',
        'organization_id' => 1,
        'role' => 'admin',
    ]);
    $manager = User::factory()->create([
        'name' => 'Demo Manager',
        'email' => 'manager@demo.test',
        'organization_id' => 1,
        'role' => 'hr',
    ]);

    foreach (range(1, 3) as $index) {
        $user = User::factory()->create([
            'name' => "Demo Worker {$index}",
            'email' => "demo.worker{$index}@example.test",
            'organization_id' => 1,
            'role' => 'support_worker',
        ]);

        HrEmployeeProfile::factory()->create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'employee_number' => sprintf('DEMO-%03d', $index),
            'position_role' => 'support_worker',
            'position_title' => 'Support Worker',
            'manager_user_id' => $manager->id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
    }

    $this->seed(HrDemoSeeder::class);

    $expectedCounts = [
        'hr_leave_requests' => 6,
        'hr_job_requisitions' => 2,
        'hr_candidates' => 5,
        'hr_applications' => 5,
        'hr_cases' => 1,
        'hr_time_entries' => 30,
        'hr_expense_claims' => 2,
        'hr_expense_items' => 2,
        'hr_payroll_runs' => 1,
        'hr_documents' => 4,
        'hr_performance_reviews' => 2,
        'hr_supervision_notes' => 1,
        'hr_assets' => 3,
        'hr_asset_assignments' => 1,
        'hr_courses' => 4,
        'hr_course_enrollments' => 4,
        'hr_announcements' => 2,
    ];

    foreach ($expectedCounts as $table => $count) {
        expect(DB::table($table)->count())->toBe($count, "Expected {$table} count");
    }

    expect(DB::table('hr_leave_requests')->where('status', 'pending')->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('hr_leave_requests')->where('status', 'approved')->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('hr_leave_requests')->where('status', 'rejected')->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('hr_payroll_runs')->where('status', 'locked')->whereNotNull('locked_at')->exists())->toBeTrue()
        ->and(DB::table('hr_asset_assignments')->whereNull('returned_at')->exists())->toBeTrue();

    $firstRunCounts = collect($expectedCounts)
        ->mapWithKeys(fn (int $count, string $table) => [$table => DB::table($table)->count()]);

    $this->seed(HrDemoSeeder::class);

    foreach ($firstRunCounts as $table => $count) {
        expect(DB::table($table)->count())->toBe($count, "Expected {$table} to stay idempotent");
    }

    $demoTeams = HrEmployeeProfile::query()
        ->whereHas('user', fn ($query) => $query->whereIn('email', [
            'hrdemo.worker1@example.test',
            'hrdemo.worker2@example.test',
            'hrdemo.worker3@example.test',
        ]))
        ->with('user:id,email')
        ->get()
        ->pluck('team', 'user.email');

    expect($demoTeams)->toHaveCount(3)
        ->and($demoTeams->get('hrdemo.worker1@example.test'))->toBe('Community Support')
        ->and($demoTeams->get('hrdemo.worker2@example.test'))->toBe('Community Support')
        ->and($demoTeams->get('hrdemo.worker3@example.test'))->toBe('Operations');
});
