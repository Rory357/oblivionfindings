<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteEvidencePack;
use App\Models\RespiteReferral;
use App\Models\RespiteStay;
use App\Models\Role;
use App\Models\ServiceAgreement;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

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

test('booking request inherits referral funding and links an active service agreement', function () {
    $client = Client::factory()->create(['site_id' => $this->site->id]);
    $referral = RespiteReferral::create([
        'client_id' => $client->id,
        'referrer_name' => 'NASC Coordinator',
        'referral_reason' => 'Planned respite block',
        'urgency' => 'planned',
        'status' => 'received',
        'received_at' => now(),
        'funding_source' => 'whaikaha',
        'funding_reference' => 'WK-44213',
    ]);
    $agreement = ServiceAgreement::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
        'title' => 'Whaikaha Residential Support 2026',
        'reference_number' => 'SA-2026-004',
        'total_budget' => 2400,
        'budget_used' => 600,
        'total_hours' => 80,
        'hours_used' => 20,
        'ends_at' => now()->addMonths(4),
    ]);

    $this->actingAs($this->admin)
        ->post(route('respite.requests.store'), [
            'referral_id' => $referral->id,
            'client_id' => $client->id,
            'requested_start' => now()->addDays(14)->setTime(10, 0)->format('Y-m-d H:i:s'),
            'requested_end' => now()->addDays(17)->setTime(10, 0)->format('Y-m-d H:i:s'),
            'service_agreement_id' => $agreement->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $request = RespiteBookingRequest::firstOrFail();

    expect($request->referral_id)->toBe($referral->id);
    expect($request->funding_source)->toBe('whaikaha');
    expect($request->funding_reference)->toBe('WK-44213');
    expect($request->service_agreement_id)->toBe($agreement->id);
    expect($request->funding_status)->toBe('pending_approval');
});

test('approving a request carries funding and service agreement data onto the booking', function () {
    $client = Client::factory()->create(['site_id' => $this->site->id]);
    $agreement = ServiceAgreement::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
        'total_budget' => 1800,
        'budget_used' => 300,
        'total_hours' => 60,
        'hours_used' => 10,
    ]);
    $request = RespiteBookingRequest::create([
        'client_id' => $client->id,
        'requested_start' => now()->addDays(7)->setTime(9, 0),
        'requested_end' => now()->addDays(10)->setTime(9, 0),
        'requirements' => [],
        'status' => 'submitted',
        'funding_source' => 'nasc',
        'funding_reference' => 'NASC-77',
        'funding_status' => 'approved',
        'funding_approved_ref' => 'AUTH-123',
        'funding_approved_at' => now()->subDay(),
        'service_agreement_id' => $agreement->id,
        'created_by' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)
        ->post(route('respite.requests.approve', $request))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $booking = RespiteBooking::where('booking_request_id', $request->id)->firstOrFail();

    expect($booking->funding_source)->toBe('nasc');
    expect($booking->funding_reference)->toBe('NASC-77');
    expect($booking->funding_status)->toBe('approved');
    expect($booking->funding_approved_ref)->toBe('AUTH-123');
    expect($booking->funding_approved_at)->not->toBeNull();
    expect($booking->service_agreement_id)->toBe($agreement->id);
});

test('booking readiness exposes funding as a typed segment', function () {
    $client = Client::factory()->create(['site_id' => $this->site->id]);
    $pending = RespiteBooking::factory()->create([
        'client_id' => $client->id,
        'location_id' => $this->site->id,
        'funding_status' => 'pending_approval',
    ]);

    $pendingReadiness = $pending->readiness();

    expect($pendingReadiness['score'])->toBeLessThan(100);
    expect(collect($pendingReadiness['segments'])->contains(fn (array $segment) => $segment['key'] === 'funding'
        && $segment['status'] === 'attention'
        && $segment['complete'] === false))->toBeTrue();

    $approved = RespiteBooking::factory()->create([
        'client_id' => $client->id,
        'location_id' => $this->site->id,
        'funding_status' => 'approved',
    ]);

    $approvedReadiness = $approved->readiness();

    expect(collect($approvedReadiness['segments'])->contains(fn (array $segment) => $segment['key'] === 'funding'
        && $segment['status'] === 'complete'
        && $segment['complete'] === true))->toBeTrue();
});

test('discharge posts consumed respite nights to the linked service agreement once', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-04 10:00:00'));

    $client = Client::factory()->create(['site_id' => $this->site->id]);
    $agreement = ServiceAgreement::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
        'agreement_type' => 'carer_support',
        'daily_rate' => 200,
        'hours_used' => 10,
        'budget_used' => 50,
        'carer_support_days_allocated' => 10,
        'carer_support_days_used' => 2,
        'carer_support_entitlement_year' => '2026-2027',
    ]);
    $booking = RespiteBooking::factory()->create([
        'client_id' => $client->id,
        'location_id' => $this->site->id,
        'service_agreement_id' => $agreement->id,
        'status' => 'confirmed',
        'start_at' => Carbon::parse('2026-06-01 09:00:00'),
        'end_at' => Carbon::parse('2026-06-04 09:00:00'),
    ]);
    $stay = RespiteStay::create([
        'booking_id' => $booking->id,
        'client_id' => $client->id,
        'status' => 'active',
        'actual_start' => Carbon::parse('2026-06-01 09:30:00'),
        'created_by' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)
        ->post(route('respite.stays.discharge', $stay), [
            'discharge_summary' => 'Returned home with whānau.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $agreement->refresh();

    expect((float) $agreement->hours_used)->toBe(82.0);
    expect((float) $agreement->budget_used)->toBe(650.0);
    expect($agreement->carer_support_days_used)->toBe(5);
    expect($agreement->carer_support_days_remaining)->toBe(5);

    $this->actingAs($this->admin)
        ->post(route('respite.stays.discharge', $stay->refresh()), [
            'discharge_summary' => 'Duplicate discharge call.',
        ])
        ->assertRedirect();

    $agreement->refresh();

    expect((float) $agreement->hours_used)->toBe(82.0);
    expect((float) $agreement->budget_used)->toBe(650.0);
    expect($agreement->carer_support_days_used)->toBe(5);

    Carbon::setTestNow();
});

test('booking readiness gates cultural placement and restrictive setting evidence', function () {
    $client = Client::factory()->create(['site_id' => $this->site->id]);
    $booking = RespiteBooking::factory()->create([
        'client_id' => $client->id,
        'location_id' => $this->site->id,
        'funding_status' => 'approved',
        'agreement_status' => 'waived',
        'cultural_snapshot' => [
            'is_maori' => true,
            'iwi' => 'Ngāti Porou',
            'cultural_dietary_needs' => 'Rongoā storage discussed with whānau.',
        ],
        'cultural_placement_check' => null,
        'setting_restriction' => 'secure_locked',
        'consent_authority_evidence' => [],
    ]);

    $readiness = collect($booking->readiness()['segments'])->keyBy('key');

    expect($readiness['cultural_placement']['complete'])->toBeFalse();
    expect($readiness['setting_restriction']['complete'])->toBeFalse();

    $booking->forceFill([
        'cultural_placement_check' => [
            'confirmed_by' => $this->admin->id,
            'notes' => 'Kaupapa fit, whānau connection, and cultural dietary support confirmed.',
        ],
        'consent_authority_evidence' => [
            'setting_restriction_authorised' => true,
        ],
    ])->save();

    $readiness = collect($booking->fresh()->readiness()['segments'])->keyBy('key');

    expect($readiness['cultural_placement']['complete'])->toBeTrue();
    expect($readiness['setting_restriction']['complete'])->toBeTrue();
});

test('lacks capacity clients require a welfare consent authority before booking confirmation', function () {
    $client = Client::factory()->create(['site_id' => $this->site->id]);
    $consentType = ConsentType::factory()->create([
        'requires_capacity_assessment' => true,
    ]);
    ClientConsent::create([
        'client_id' => $client->id,
        'consent_type_id' => $consentType->id,
        'status' => 'given',
        'given_at' => now(),
        'given_by_relationship' => 'welfare_guardian',
        'given_method' => 'written',
        'capacity_assessed' => true,
        'capacity_outcome' => 'lacks_capacity',
        'capacity_assessor_id' => $this->admin->id,
        'capacity_assessed_at' => now(),
        'best_interests_decision' => true,
        'best_interests_decision_maker_id' => $this->admin->id,
        'best_interests_decision_at' => now(),
        'created_by' => $this->admin->id,
    ]);
    $booking = RespiteBooking::factory()->create([
        'client_id' => $client->id,
        'location_id' => $this->site->id,
        'status' => 'pending',
    ]);
    $booking->forceFill([
        'funding_status' => 'approved',
        'agreement_status' => 'waived',
        'consent_authority' => 'self',
        'eligibility_checks' => ['eligible' => true],
        'pre_arrival_checklist' => ['receiving_home_ready' => true],
        'medications_reconciled' => true,
    ])->save();

    $this->actingAs($this->admin)
        ->post(route('respite.bookings.confirm', $booking))
        ->assertSessionHasErrors('readiness');

    expect($booking->fresh()->status)->toBe('pending');

    $booking->forceFill([
        'consent_authority' => 'welfare_guardian',
        'consent_authority_name' => 'Moana Rangi',
        'consent_authority_contact' => '021000000',
        'code_of_rights_provided' => true,
        'consent_to_respite' => true,
        'consent_capacity_basis' => 'substitute_decision',
        'advocate_offered' => true,
        'rights_format_provided' => 'written',
        'rights_recorded_by' => $this->admin->id,
        'rights_recorded_at' => now(),
    ])->save();

    $this->actingAs($this->admin)
        ->post(route('respite.bookings.confirm', $booking))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($booking->fresh()->status)->toBe('confirmed');
});

test('the welfare consent-authority readiness segment blocks independently of the rights segment', function () {
    $client = Client::factory()->create(['site_id' => $this->site->id]);
    $consentType = ConsentType::factory()->create([
        'requires_capacity_assessment' => true,
    ]);
    ClientConsent::create([
        'client_id' => $client->id,
        'consent_type_id' => $consentType->id,
        'status' => 'given',
        'given_at' => now(),
        'given_by_relationship' => 'welfare_guardian',
        'given_method' => 'written',
        'capacity_assessed' => true,
        'capacity_outcome' => 'lacks_capacity',
        'capacity_assessor_id' => $this->admin->id,
        'capacity_assessed_at' => now(),
        'best_interests_decision' => true,
        'best_interests_decision_maker_id' => $this->admin->id,
        'best_interests_decision_at' => now(),
        'created_by' => $this->admin->id,
    ]);

    $booking = RespiteBooking::factory()->create([
        'client_id' => $client->id,
        'location_id' => $this->site->id,
        'status' => 'pending',
    ]);
    // Everything valid EXCEPT the consent authority — and crucially the HDC rights fields ARE all
    // complete, so the only segment that can fail is the welfare-authority one (isolating A2 from A3).
    $booking->forceFill([
        'funding_status' => 'approved',
        'agreement_status' => 'waived',
        'consent_authority' => 'self',
        'eligibility_checks' => ['eligible' => true],
        'pre_arrival_checklist' => ['receiving_home_ready' => true],
        'medications_reconciled' => true,
        'code_of_rights_provided' => true,
        'consent_to_respite' => true,
        'consent_capacity_basis' => 'substitute_decision',
        'advocate_offered' => true,
        'rights_format_provided' => 'written',
        'rights_recorded_by' => $this->admin->id,
        'rights_recorded_at' => now(),
    ])->save();

    $segments = collect($booking->fresh()->readiness()['segments'])->keyBy('key');

    // Rights segment is satisfied; only the welfare-authority segment blocks for this lacks-capacity client.
    expect($segments['consent_rights']['complete'])->toBeTrue();
    expect($segments['consent']['complete'])->toBeFalse();

    $booking->forceFill(['consent_authority' => 'welfare_guardian'])->save();

    $segments = collect($booking->fresh()->readiness()['segments'])->keyBy('key');
    expect($segments['consent']['complete'])->toBeTrue();
});

test('rights and informed consent are a readiness segment and a required evidence manifest item', function () {
    $client = Client::factory()->create(['site_id' => $this->site->id]);
    $booking = RespiteBooking::factory()->create([
        'client_id' => $client->id,
        'location_id' => $this->site->id,
        'status' => 'pending',
    ]);
    $booking->forceFill([
        'funding_status' => 'approved',
        'agreement_status' => 'waived',
        'consent_authority' => 'self',
        'eligibility_checks' => ['eligible' => true],
        'pre_arrival_checklist' => ['receiving_home_ready' => true],
        'medications_reconciled' => true,
    ])->save();

    $segments = collect($booking->fresh()->readiness()['segments'])->keyBy('key');

    expect($segments)->toHaveKey('consent_rights');
    expect($segments['consent_rights']['complete'])->toBeFalse();

    $stay = RespiteStay::create([
        'booking_id' => $booking->id,
        'client_id' => $client->id,
        'status' => 'active',
        'actual_start' => now()->subDay(),
        'created_by' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)
        ->post(route('respite.evidence-packs.store'), [
            'stay_id' => $stay->id,
            'summary' => 'Consent evidence pack.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $manifest = collect(RespiteEvidencePack::firstOrFail()->items)->keyBy('type');

    expect($manifest['consent_rights']['complete'])->toBeFalse();
});
