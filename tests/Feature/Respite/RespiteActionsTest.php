<?php

use App\Models\Client;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteReferral;
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
