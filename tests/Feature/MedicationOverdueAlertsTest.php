<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationRound;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\MedicationOverdueNotification;
use App\Notifications\MedicationStockLowNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow(Carbon::parse('2026-06-08 11:15:00', 'Pacific/Auckland'));
    Notification::fake();
});

afterEach(function () {
    Cache::flush();
    Carbon::setTestNow();
});

it('sends overdue medication alerts from missed scheduled slots without pending administration rows', function () {
    $this->seed(RbacSeeder::class);

    $worker = User::factory()->frontlineWorker()->create();
    $site = Site::factory()->create();
    HrEmployeeProfile::factory()->create([
        'user_id' => $worker->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
        'is_active' => true,
    ]);
    $viewPermission = Permission::query()->where('key', 'medications.view')->firstOrFail();
    $worker->permissionOverrides()->syncWithoutDetaching([
        $viewPermission->id => ['allowed' => true],
    ]);
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'Mere',
        'last_name' => 'Wilson',
        'suppress_med_admin_alerts' => false,
    ]);
    $medication = ClientMedication::factory()->create([
        'client_id' => $client->id,
        'name' => 'Morning tablets',
        'frequency' => 'Once daily',
        'dose_times' => ['09:00'],
        'is_prn' => false,
        'active' => true,
        'state' => 'active',
        'start_date' => '2026-05-01',
        'end_date' => null,
        'approval_status' => 'verified',
    ]);

    MedicationRound::query()->create([
        'site_id' => $site->id,
        'name' => 'Morning round',
        'round_type' => 'morning',
        'scheduled_time' => '09:00',
        'window_minutes' => 60,
        'round_date' => '2026-06-08',
        'status' => 'pending',
        'assigned_to' => $worker->id,
    ]);

    $this->artisan('emar:send-alerts')->assertExitCode(0);

    Notification::assertSentTo(
        $worker,
        MedicationOverdueNotification::class,
        fn (MedicationOverdueNotification $notification) => $notification->medication === $medication->name
            && $notification->clientName === 'Mere Wilson'
            && $notification->scheduledTime === '09:00'
            && $notification->clientId === $client->id,
    );
});

it('site-scopes low-stock alerts: explicit all-site recipients get all, site-restricted recipients only their site', function () {
    $this->seed(RbacSeeder::class);

    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();

    // The admin role holds the explicit medication Site bypass.
    $admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $admin->roles()->syncWithoutDetaching([Role::where('name', 'admin')->first()->id]);

    // Site-restricted recipient: pinned to Site A via their HR profile, granted
    // medications.view. (There is no users.site_id column — site access is
    // resolved from the employee profile.)
    $siteAWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $siteAWorker->id,
        'employee_number' => 'EMP-'.$siteAWorker->id,
        'work_email' => $siteAWorker->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'primary_site_id' => $siteA->id,
        'is_active' => true,
    ]);
    $viewPerm = Permission::where('key', 'medications.view')->first();
    $siteAWorker->permissionOverrides()->syncWithoutDetaching([$viewPerm->id => ['allowed' => true]]);

    // Low stock for a client at Site B.
    $clientB = Client::factory()->create(['site_id' => $siteB->id, 'first_name' => 'Hone', 'last_name' => 'Rewa']);
    $medB = ClientMedication::factory()->create([
        'client_id' => $clientB->id, 'name' => 'Paracetamol', 'active' => true, 'state' => 'active',
    ]);
    ClientMedicationStock::create([
        'client_medication_id' => $medB->id,
        'on_hand' => 2,
        'reorder_level' => 10,
        'unit' => 'tablets',
    ]);

    $this->artisan('emar:send-alerts')->assertExitCode(0);

    // Org-wide admin hears about Site B's low stock…
    Notification::assertSentTo($admin, MedicationStockLowNotification::class);
    // …the Site-A-only worker does not.
    Notification::assertNotSentTo($siteAWorker, MedicationStockLowNotification::class);
});

it('fails closed when a low-stock recipient has no accessible Site even with report permission', function () {
    $this->seed(RbacSeeder::class);

    $site = Site::factory()->create();
    $recipient = User::factory()->create(['approved_at' => now()]);
    $permissionIds = Permission::query()
        ->whereIn('key', ['medications.view', 'reports.viewAny'])
        ->pluck('id');
    $recipient->permissionOverrides()->sync(
        $permissionIds->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all(),
    );

    $client = Client::factory()->create(['site_id' => $site->id]);
    $medication = ClientMedication::factory()->create([
        'client_id' => $client->id,
        'active' => true,
        'state' => 'active',
    ]);
    ClientMedicationStock::create([
        'client_medication_id' => $medication->id,
        'on_hand' => 1,
        'reorder_level' => 10,
        'unit' => 'tablets',
    ]);

    $this->artisan('emar:send-alerts')->assertExitCode(0);

    Notification::assertNotSentTo($recipient, MedicationStockLowNotification::class);
});

it('conceals controlled low-stock notifications without exact controlled-view permission', function () {
    $this->seed(RbacSeeder::class);

    $site = Site::factory()->create();
    $ordinaryRecipient = User::factory()->create(['approved_at' => now()]);
    $controlledRecipient = User::factory()->create(['approved_at' => now()]);

    foreach ([$ordinaryRecipient, $controlledRecipient] as $recipient) {
        HrEmployeeProfile::query()->create([
            'tenant_id' => 1,
            'user_id' => $recipient->id,
            'employee_number' => 'EMP-'.$recipient->id,
            'work_email' => $recipient->email,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'start_date' => now()->subYear()->toDateString(),
            'primary_site_id' => $site->id,
            'is_active' => true,
        ]);
    }

    $viewPermission = Permission::where('key', 'medications.view')->firstOrFail();
    $controlledPermission = Permission::where('key', 'medications.controlled.view')->firstOrFail();
    $ordinaryRecipient->permissionOverrides()->sync([$viewPermission->id => ['allowed' => true]]);
    $controlledRecipient->permissionOverrides()->sync([
        $viewPermission->id => ['allowed' => true],
        $controlledPermission->id => ['allowed' => true],
    ]);

    $client = Client::factory()->create(['site_id' => $site->id]);
    $medication = ClientMedication::factory()->create([
        'client_id' => $client->id,
        'name' => 'Controlled stock',
        'controlled_drug' => true,
        'active' => true,
        'state' => 'active',
    ]);
    ClientMedicationStock::create([
        'client_medication_id' => $medication->id,
        'on_hand' => 1,
        'reorder_level' => 10,
        'unit' => 'tablets',
    ]);

    $this->artisan('emar:send-alerts')->assertExitCode(0);

    Notification::assertNotSentTo($ordinaryRecipient, MedicationStockLowNotification::class);
    Notification::assertSentTo($controlledRecipient, MedicationStockLowNotification::class);
});
