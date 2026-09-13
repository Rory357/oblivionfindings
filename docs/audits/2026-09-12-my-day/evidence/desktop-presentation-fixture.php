<?php

// Reuses the guarded, synthetic-only fixture connection and incoming handover.
require __DIR__.'/extend-browser-fixture.php';

$casey = App\Models\Client::where('site_id', $siteId)->where('first_name', 'Casey')->where('last_name', 'Demo')->first();
if (! $casey) {
    $casey = App\Models\Client::factory()->create(['first_name' => 'Casey', 'last_name' => 'Demo', 'site_id' => $siteId, 'status' => 'active']);
    $casey->supportWorkers()->attach($worker->id);
    $current->tasks()->create(['label' => 'Support Casey with laundry', 'task_scope' => 'client', 'client_id' => $casey->id, 'created_by' => $worker->id, 'sort_order' => 4]);
}
$admin = App\Models\User::where('email', 'myday-admin@demo.test')->first();
if (! $admin) {
    $admin = App\Models\User::factory()->withoutTwoFactor()->create([
        'name' => 'Morgan Demo Admin', 'role' => 'admin', 'email' => 'myday-admin@demo.test', 'password' => Illuminate\Support\Facades\Hash::make('MyDay-demo-482!'),
    ]);
    App\Domain\Hr\Models\HrEmployeeProfile::factory()->create(['user_id' => $admin->id, 'primary_site_id' => $siteId, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subYear(), 'end_date' => null]);
    App\Domain\Hr\Models\HrAttendanceSession::create(['user_id' => $admin->id, 'shift_id' => null, 'site_id' => $siteId, 'clock_in_at' => now()->subMinutes(8), 'status' => 'open', 'source' => 'web', 'created_by' => $admin->id]);
}
echo json_encode(['presentation_worker_id' => $worker->id, 'presentation_admin_id' => $admin->id, 'visible_people' => 3]).PHP_EOL;
