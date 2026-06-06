<?php

use App\Models\Client;
use App\Models\DataBreachLog;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteReferral;
use App\Models\RespiteStay;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $this->admin->roles()->attach(Role::where('name', 'admin')->first());
});

test('a referral can be declined with a reason', function () {
    $client = Client::factory()->create();
    $referral = RespiteReferral::create([
        'client_id' => $client->id,
        'referrer_name' => 'GP — Dr Patel',
        'referral_reason' => 'Respite needed',
        'urgency' => 'planned',
        'status' => 'received',
        'received_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->put("/respite/referrals/{$referral->id}", ['status' => 'declined', 'triage_notes' => 'Out of catchment'])
        ->assertRedirect();

    expect($referral->fresh()->status)->toBe('declined');
    expect($referral->fresh()->triage_notes)->toBe('Out of catchment');
});

test('a booking request can be rejected with decision notes', function () {
    $client = Client::factory()->create();
    $request = RespiteBookingRequest::create([
        'client_id' => $client->id,
        'requested_start' => now()->addDays(5),
        'requested_end' => now()->addDays(10),
        'requirements' => [],
        'status' => 'submitted',
    ]);

    $this->actingAs($this->admin)
        ->put("/respite/requests/{$request->id}", ['status' => 'rejected', 'decision_notes' => 'No capacity this period'])
        ->assertRedirect();

    expect($request->fresh()->status)->toBe('rejected');
});

test('a stay can record cultural leave bed hold context', function () {
    $client = Client::factory()->create();
    $booking = RespiteBooking::factory()->create([
        'client_id' => $client->id,
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(2),
    ]);
    $stay = RespiteStay::create([
        'booking_id' => $booking->id,
        'client_id' => $client->id,
        'status' => 'active',
        'actual_start' => now()->subDay(),
    ]);

    $this->actingAs($this->admin)
        ->post("/respite/stays/{$stay->id}/bed-hold", [
            'bed_hold_status' => 'held',
            'bed_hold_reason' => 'cultural_leave',
            'bed_hold_until' => now()->addDay()->toISOString(),
            'absence_record' => [
                'type' => 'cultural_leave',
                'notes' => 'Whānau visit with bed held.',
            ],
        ])
        ->assertRedirect();

    $stay->refresh();

    expect($stay->bed_hold_status)->toBe('held');
    expect($stay->bed_hold_reason)->toBe('cultural_leave');
    expect($stay->absence_records)->toHaveCount(1);
    expect($stay->absence_records[0]['type'])->toBe('cultural_leave');
});

test('a privacy breach incident on a respite stay creates a breach log', function () {
    $client = Client::factory()->create();
    $booking = RespiteBooking::factory()->create([
        'client_id' => $client->id,
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(2),
    ]);
    $stay = RespiteStay::create([
        'booking_id' => $booking->id,
        'client_id' => $client->id,
        'status' => 'active',
        'actual_start' => now()->subDay(),
    ]);

    $this->actingAs($this->admin)
        ->post("/respite/stays/{$stay->id}/incidents", [
            'type' => 'privacy',
            'severity' => 'high',
            'title' => 'Wrong whānau recipient',
            'description' => 'A respite update was sent to the wrong portal contact.',
            'immediate_action_taken' => 'Message removed and privacy lead notified.',
            'is_notifiable' => true,
            'notification_authority' => 'privacy_commissioner',
            'incident_type' => 'privacy_breach',
        ])
        ->assertRedirect();

    $breach = DataBreachLog::query()->latest('id')->firstOrFail();

    expect($breach->breach_type)->toBe('respite_stay');
    expect($breach->requires_authority_notification)->toBeTrue();
    expect($breach->affected_data_categories)->toContain('respite_record');
});
