<?php

use App\Domain\Referrals\Services\ReferralServiceCommencement;
use App\Events\Respite\RespiteEvent;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteStay;
use App\Models\ServiceAgreement;
use App\Models\ServiceContext;
use App\Models\Shift;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../../Support/ReferralStartFixtures.php';

function referralRespiteStartFixture(): array
{
    $f = referralStartFixture(false, 'planned_respite');
    handoverCanonicalPermissions($f['manager'], ['respite.stays.manage', 'respite.viewAny']);
    $request = RespiteBookingRequest::create(['client_id' => $f['person']->id, 'service_context_id' => $f['person']->service_context_id,
        'requested_start' => now(), 'requested_end' => now()->addDays(2), 'status' => 'approved', 'created_by' => $f['manager']->id]);
    $booking = RespiteBooking::factory()->create(['booking_request_id' => $request->id, 'client_id' => $f['person']->id,
        'location_id' => $f['site']->id, 'service_agreement_id' => $f['agreement']->id, 'start_at' => now(), 'end_at' => now()->addDays(2)]);

    return [...$f, 'booking' => $booking, 'request' => $request];
}

it('keeps admission separate and atomically enforces actual commencement on check-in and extension', function () {
    $f = referralRespiteStartFixture();
    Event::fake([RespiteEvent::class]);
    $this->actingAs($f['manager'])->post(route('respite.stays.store'), ['booking_id' => $f['booking']->id, 'client_id' => $f['person']->id])
        ->assertRedirect()->assertSessionHasNoErrors();
    $stay = RespiteStay::where('booking_id', $f['booking']->id)->sole();
    expect($stay->actual_start)->toBeNull();
    $service = app(ReferralServiceCommencement::class);
    $service->schedule($f['manager'], $f['referral']->id, 5, $f['input']);
    $this->post(route('respite.stays.checkin', $stay))->assertRedirect()->assertSessionHasErrors('service_start');
    expect($stay->fresh()->status)->toBe('admitted')->and($stay->fresh()->actual_start)->toBeNull()
        ->and($f['booking']->fresh()->status)->toBe('confirmed')->and(Shift::where('respite_booking_id', $f['booking']->id)->count())->toBe(0);
    Event::assertNotDispatched(RespiteEvent::class, fn ($event) => $event->name === 'respite.stay.checked_in');
    $this->travel(1)->minutes();
    $service->commence($f['manager'], $f['referral']->id, 6, referralCommenceInput());
    // A legacy admission timestamp is not actual check-in evidence.
    $stay->update(['actual_start' => now()->subDay()]);
    $this->post(route('respite.stays.checkin', $stay))->assertRedirect()->assertSessionHasNoErrors();
    expect($stay->fresh()->status)->toBe('active')->and($stay->fresh()->actual_start->equalTo(now()->startOfSecond()))->toBeTrue()
        ->and($f['booking']->fresh()->status)->toBe('in_progress');
    $this->post(route('respite.stays.checkin', $stay))->assertRedirect()->assertSessionHasErrors('status');
    $before = $f['booking']->fresh()->end_at->toISOString();
    $this->post(route('respite.stays.extend', $stay), ['new_end' => now()->addMonths(3)->toISOString()])
        ->assertRedirect()->assertSessionHasErrors('service_start');
    expect($stay->fresh()->status)->toBe('active')->and($stay->fresh()->actual_end)->toBeNull()
        ->and($f['booking']->fresh()->end_at->toISOString())->toBe($before);
});

it('rejects obsolete or mismatched respite commencement without changing operational records', function (string $change) {
    $f = referralRespiteStartFixture();
    $service = app(ReferralServiceCommencement::class);
    $service->schedule($f['manager'], $f['referral']->id, 5, $f['input']);
    $this->travel(1)->minutes();
    $service->commence($f['manager'], $f['referral']->id, 6, referralCommenceInput());
    $stay = RespiteStay::create(['booking_id' => $f['booking']->id, 'client_id' => $f['person']->id, 'status' => 'admitted']);
    match ($change) {
        'agreement' => $f['booking']->update(['service_agreement_id' => ServiceAgreement::factory()->create(['client_id' => $f['person']->id])->id]),
        'setting' => $f['request']->update(['service_context_id' => ServiceContext::create(['site_id' => $f['site']->id, 'name' => 'Other respite setting', 'type' => 'planned_respite', 'is_active' => true])->id]),
        'dates' => $f['booking']->update(['end_at' => now()->addMonths(3)]),
    };
    Event::fake([RespiteEvent::class]);
    $this->actingAs($f['manager'])->post(route('respite.stays.checkin', $stay))
        ->assertRedirect()->assertSessionHasErrors('service_start');
    expect($stay->fresh()->status)->toBe('admitted')->and($stay->fresh()->actual_start)->toBeNull()
        ->and($f['booking']->fresh()->status)->toBe('confirmed')->and(Shift::where('respite_booking_id', $f['booking']->id)->count())->toBe(0);
    Event::assertNothingDispatched();
})->with(['agreement', 'setting', 'dates']);

it('keeps a recorded commencement valid for respite after routine care-plan or staffing changes', function (string $change) {
    $f = referralRespiteStartFixture();
    $service = app(ReferralServiceCommencement::class);
    $service->schedule($f['manager'], $f['referral']->id, 5, $f['input']);
    $this->travel(1)->minutes();
    $service->commence($f['manager'], $f['referral']->id, 6, referralCommenceInput());
    $stay = RespiteStay::create(['booking_id' => $f['booking']->id, 'client_id' => $f['person']->id, 'status' => 'admitted']);
    match ($change) {
        'care' => $f['plan']->update(['content' => ['domains' => [['key' => 'mobility', 'strategies' => [['text' => 'Changed safety approach']]]]]]),
        'worker' => $f['author']->hrEmployeeProfile->update(['end_date' => today('Pacific/Auckland')->subDay()]),
    };
    Event::fake([RespiteEvent::class]);
    $this->actingAs($f['manager'])->post(route('respite.stays.checkin', $stay))->assertRedirect()->assertSessionHasNoErrors();
    expect($stay->fresh()->status)->toBe('active')->and($stay->fresh()->actual_start)->not->toBeNull();
})->with(['care', 'worker']);
