<?php

use App\Models\Client;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteDailyNote;
use App\Models\RespiteReferral;
use App\Models\RespiteStay;
use App\Models\RespiteTask;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $this->admin->roles()->attach(Role::where('name', 'admin')->first());
});

test('the respite workspace renders one payload with pipeline lists, homes and counts', function () {
    $client = Client::factory()->create();

    RespiteReferral::create([
        'client_id' => $client->id,
        'referrer_name' => 'NASC Coordinator',
        'referral_reason' => 'Planned respite block',
        'urgency' => 'planned',
        'status' => 'received',
        'received_at' => now(),
    ]);

    $request = RespiteBookingRequest::create([
        'client_id' => $client->id,
        'requested_start' => now()->addDays(5),
        'requested_end' => now()->addDays(12),
        'requirements' => [],
        'status' => 'approved',
    ]);

    // An approved request with a still-pending booking is NOT yet onboarded.
    $booking = RespiteBooking::factory()->create([
        'client_id' => $client->id,
        'booking_request_id' => $request->id,
        'status' => 'pending',
    ]);

    RespiteTask::factory()->create(['title' => 'Set up eMAR', 'status' => 'pending']);

    $stay = RespiteStay::create([
        'booking_id' => $booking->id,
        'client_id' => $client->id,
        'status' => 'active',
        'actual_start' => now(),
    ]);
    RespiteDailyNote::create([
        'stay_id' => $stay->id,
        'client_id' => $client->id,
        'note_date' => now(),
        'shift_period' => 'morning',
        'observations' => 'Settled well overnight',
    ]);

    $this->actingAs($this->admin)
        ->get('/respite')
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('respite/index')
                ->has('referrals', 1)
                ->has('requests', 1)
                ->has('bookings')
                ->has('stays')
                ->has('tasks', 1)
                ->has('records', 1)
                ->has('homes')
                ->has('stats')
                ->where('tasks.0.title', 'Set up eMAR')
                ->where('records.0.type', 'daily_note')
                ->where('requests.0.bookingId', $booking->id)
                ->where('requests.0.onboarded', false),
        );
});

test('legacy respite index routes redirect into the workspace tabs', function () {
    $this->actingAs($this->admin)->get('/respite/requests')->assertRedirect('/respite?tab=requests');
    $this->actingAs($this->admin)->get('/respite/bookings')->assertRedirect('/respite?tab=bookings');
    $this->actingAs($this->admin)->get('/respite/stays')->assertRedirect('/respite?tab=stays');
    $this->actingAs($this->admin)->get('/respite/calendar')->assertRedirect('/respite?tab=calendar');
});
