<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\BehaviourSupportPlan;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\DataBreachLog;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteReferral;
use App\Models\RespiteStay;
use App\Models\RestraintEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $this->admin->roles()->attach(Role::where('name', 'admin')->first());
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->admin->id,
        'primary_site_id' => $this->site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        'created_by' => $this->admin->id,
        'updated_by' => $this->admin->id,
    ]);
});

test('a referral can be declined with a reason', function () {
    $client = Client::factory()->create(['site_id' => $this->site->id]);
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
    $client = Client::factory()->create(['site_id' => $this->site->id]);
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
    $client = Client::factory()->create(['site_id' => $this->site->id]);
    $booking = RespiteBooking::factory()->create([
        'client_id' => $client->id,
        'location_id' => $this->site->id,
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
    $client = Client::factory()->create(['site_id' => $this->site->id]);
    $booking = RespiteBooking::factory()->create([
        'client_id' => $client->id,
        'location_id' => $this->site->id,
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
    expect(ClientIncident::query()->sole()->site_id)->toBe($this->site->id);
    expect(ClientIncident::query()->sole()->hs_event_id)->not->toBeNull();
});

test('recording a within-plan restraint auto-links the clients active behaviour support plan', function () {
    $client = Client::factory()->create(['site_id' => $this->site->id]);
    $booking = RespiteBooking::factory()->create([
        'client_id' => $client->id,
        'location_id' => $this->site->id,
        'status' => 'confirmed',
    ]);
    $stay = RespiteStay::create([
        'booking_id' => $booking->id,
        'client_id' => $client->id,
        'status' => 'active',
        'actual_start' => now()->subDay(),
    ]);
    $plan = BehaviourSupportPlan::create([
        'client_id' => $client->id,
        'title' => 'Active positive behaviour support plan',
        'approved_interventions' => 'Low-arousal physical redirection if agreed triggers occur.',
        'restrictive_practice_type' => 'physical',
        'status' => 'active',
        'developed_by' => $this->admin->id,
        'developed_at' => now()->subMonth(),
        'review_date' => now()->addMonths(5),
        'created_by' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)
        ->post("/respite/stays/{$stay->id}/restraints", [
            'started_at' => now()->subHours(2)->format('Y-m-d H:i:s'),
            'ended_at' => now()->subHour()->format('Y-m-d H:i:s'),
            'restraint_type' => 'physical',
            'severity' => 'medium',
            'trigger_description' => 'Client moved toward an unsafe exit.',
            'de_escalation_attempted' => 'Quiet space and whānau call offered.',
            'restraint_description' => 'Brief redirection using approved support-plan method.',
            'within_support_plan' => true,
            'authorised_by' => $this->admin->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(RestraintEvent::firstOrFail()->behaviour_support_plan_id)->toBe($plan->id);
});

test('creating a booking request from a referral links it and advances the referral', function () {
    $client = Client::factory()->create(['site_id' => $this->site->id]);
    $referral = RespiteReferral::create([
        'client_id' => $client->id,
        'referrer_name' => 'NASC — Waitematā',
        'referral_reason' => 'Planned respite for a carer break',
        'urgency' => 'planned',
        'status' => 'triaged',
        'received_at' => now(),
    ]);

    $response = $this->actingAs($this->admin)
        ->from('/respite?tab=referrals')
        ->post('/respite/requests', [
            '_modal' => true,
            'referral_id' => $referral->id,
            'client_id' => $client->id,
            'requested_start' => now()->addDays(7)->toDateString(),
            'requested_end' => now()->addDays(14)->toDateString(),
            'priority' => 'routine',
        ]);

    // _modal posts return to the workspace (lists refresh in place), not the
    // legacy request-detail page.
    $response->assertRedirect('/respite?tab=referrals');
    $response->assertSessionHasNoErrors();

    $request = RespiteBookingRequest::query()->latest('id')->firstOrFail();
    expect($request->referral_id)->toBe($referral->id);
    expect($request->client_id)->toBe($client->id);
    expect($request->status)->toBe('submitted');

    $referral->refresh();
    expect($referral->status)->toBe('accepted');
    expect($referral->linked_booking_request_id)->toBe($request->id);
});

test('the legacy request create still lands on the request detail when not modal', function () {
    $client = Client::factory()->create(['site_id' => $this->site->id]);

    $response = $this->actingAs($this->admin)
        ->post('/respite/requests', [
            'client_id' => $client->id,
            'requested_start' => now()->addDays(3)->toDateString(),
            'requested_end' => now()->addDays(6)->toDateString(),
        ]);

    $request = RespiteBookingRequest::query()->latest('id')->firstOrFail();
    $response->assertRedirect("/respite/requests/{$request->id}");
    expect($request->referral_id)->toBeNull();
});
