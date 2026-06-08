<?php

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationRound;
use App\Models\Site;
use App\Models\User;
use App\Notifications\MedicationOverdueNotification;
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
    $worker = User::factory()->frontlineWorker()->create();
    $site = Site::factory()->create();
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
        fn (MedicationOverdueNotification $notification) =>
            $notification->medication === $medication->name
            && $notification->clientName === 'Mere Wilson'
            && $notification->scheduledTime === '09:00'
            && $notification->clientId === $client->id,
    );
});
