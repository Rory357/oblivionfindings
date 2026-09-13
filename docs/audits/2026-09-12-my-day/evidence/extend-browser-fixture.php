<?php

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.connections.mysql.host') !== '127.0.0.1' || ! app()->environment('local')) {
    throw new RuntimeException('Only the owned local fixture may be extended.');
}
config(['database.connections.mysql.database' => 'oblivion_findings_codex_test_myday_browser_md_ui_01a094e0',
    'mail.default' => 'array', 'queue.default' => 'sync', 'cache.default' => 'array']);
Illuminate\Support\Facades\DB::purge('mysql');
$worker = App\Models\User::whereKey(1)->where('email', 'myday-worker@demo.test')->where('name', 'Taylor Demo')->firstOrFail();
$siteId = $worker->hrEmployeeProfile->primary_site_id;
$client = App\Models\Client::where('site_id', $siteId)->where('first_name', 'Mere')->where('last_name', 'Demo')->firstOrFail();
foreach (['timesheets.create', 'timesheets.update', 'timesheets.submit'] as $key) {
    $permission = App\Models\Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'timesheets', 'module' => 'Operations']);
    $worker->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
}
$helper = App\Models\User::where('email', 'myday-helper@demo.test')->first();
if (! $helper) {
    $helper = App\Models\User::factory()->frontlineWorker()->withoutTwoFactor()->create([
        'name' => 'Elena Demo', 'email' => 'myday-helper@demo.test', 'password' => Illuminate\Support\Facades\Hash::make('MyDay-demo-482!'),
    ]);
    App\Domain\Hr\Models\HrEmployeeProfile::factory()->create(['user_id' => $helper->id, 'primary_site_id' => $siteId,
        'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subYear(), 'end_date' => null]);
    foreach (['shifts.viewAssigned', 'shifts.tasks.updateSelf', 'clients.viewAssigned'] as $key) {
        $permission = App\Models\Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'shifts', 'module' => 'Operations']);
        $helper->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }
    $client->supportWorkers()->syncWithoutDetaching([$helper->id]);
}
$old = App\Models\Shift::where('user_id', $worker->id)->where('notes', 'my-day-browser-previous-shift')->first();
if (! $old) {
    $start = now()->subHours(8)->startOfMinute();
    $end = now()->subHours(4)->startOfMinute();
    $old = App\Models\Shift::factory()->create(['user_id' => $worker->id, 'client_id' => $client->id, 'site_id' => $siteId,
        'starts_at' => $start, 'ends_at' => $end, 'actual_starts_at' => $start, 'actual_ends_at' => $end,
        'status' => 'in_progress', 'expected_break_minutes' => 0, 'notes' => 'my-day-browser-previous-shift']);
    $session = App\Domain\Hr\Models\HrAttendanceSession::create(['user_id' => $worker->id, 'shift_id' => $old->id, 'site_id' => $siteId,
        'clock_in_at' => $start, 'clock_out_at' => $end, 'break_minutes' => 0, 'status' => 'closed', 'source' => 'web',
        'created_by' => $worker->id, 'closed_by' => $worker->id]);
    app(App\Domain\Shifts\Timesheets\Drafts\DraftTimesheetService::class)->fromAttendanceSession($session, $worker->id);
}
$current = App\Models\Shift::whereKey(1)->where('user_id', $worker->id)->firstOrFail();
$handover = App\Models\ShiftHandover::where('incoming_shift_id', $current->id)->first();
if (! $handover) {
    $outgoing = App\Models\Shift::factory()->published()->create(['user_id' => $helper->id, 'client_id' => $client->id, 'site_id' => $siteId,
        'service_context_id' => $current->service_context_id, 'starts_at' => $current->starts_at->copy()->subHours(4), 'ends_at' => $current->starts_at,
        'actual_starts_at' => $current->starts_at->copy()->subHours(4), 'actual_ends_at' => $current->starts_at, 'status' => 'completed']);
    $source = $outgoing->tasks()->create(['label' => 'Check tomorrow’s activity time', 'task_scope' => 'client', 'client_id' => $client->id, 'is_completed' => false,
        'steps' => [['id' => (string) Illuminate\Support\Str::uuid(), 'label' => 'Check the activity calendar', 'is_completed' => false]]]);
    $handover = App\Models\ShiftHandover::create(['outgoing_shift_id' => $outgoing->id, 'incoming_shift_id' => $current->id, 'client_id' => $client->id,
        'outgoing_staff_id' => $helper->id, 'incoming_staff_id' => $worker->id, 'status' => 'submitted', 'submitted_at' => $current->starts_at,
        'handover_notes' => 'Mere would like to confirm tomorrow’s activity time.', 'tasks_pending' => [['id' => $source->id, 'label' => $source->label]],
        'follow_up_items' => [['label' => 'Prepare a spare activity bag']]]);
}
echo json_encode(['fixture_worker_id' => $worker->id, 'helper_id' => $helper->id, 'previous_shift_id' => $old->id, 'handover_id' => $handover->id]).PHP_EOL;
