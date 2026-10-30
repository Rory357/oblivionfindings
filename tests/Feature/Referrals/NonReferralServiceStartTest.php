<?php

use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Services\InternalSupportPreparation;
use App\Domain\Referrals\Services\ReferralRespiteReview;
use App\Events\Respite\RespiteEvent;
use App\Models\Client;
use App\Models\Permission;
use App\Models\RespiteBooking;
use App\Models\RespiteStay;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/RespiteCommencementFixtures.php';

it('requires the review for direct activation when the organisation opts in, without blocking active service moves', function () {
    requireCommencementReviewForEveryone();
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $context = ServiceContext::factory()->create(['site_id' => $site->id, 'type' => 'planned_respite', 'is_active' => true]);
    $person = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $context->id, 'status' => 'onboarding']);
    expect(ReferralConversion::where('client_id', $person->id)->exists())->toBeFalse();
    expect(fn () => $person->update(['status' => 'active']))->toThrow(HttpException::class);
    expect($person->fresh()->status)->toBe('onboarding');
    // A legacy active record remains editable, and moving an already-active person is not a new activation.
    $legacy = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $context->id, 'status' => 'active']);
    $legacy->update(['preferred_name' => 'Chosen name']);
    expect($legacy->fresh()->preferred_name)->toBe('Chosen name');
    $other = ServiceContext::factory()->create(['site_id' => $site->id, 'type' => 'planned_respite', 'is_active' => true]);
    $legacy->update(['service_context_id' => $other->id]);
    expect($legacy->fresh()->service_context_id)->toBe($other->id)->and($legacy->fresh()->status)->toBe('active');
});

it('blocks an unprepared respite admission without side effects and allows the same person after an independent actual start', function () {
    requireCommencementReviewForEveryone();
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $context = ServiceContext::factory()->create(['site_id' => $site->id, 'type' => 'planned_respite', 'is_active' => true]);
    $person = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $context->id, 'status' => 'onboarding']);
    $actor = referralTestUser($site, ['create', 'viewAssigned']);
    handoverCanonicalPermissions($actor, ['clients.viewAny', 'clients.update', 'respite.viewAny', 'respite.stays.manage']);
    $booking = RespiteBooking::factory()->create(['client_id' => $person->id, 'location_id' => $site->id, 'start_at' => now(), 'end_at' => now()->addDays(2)]);
    $stay = RespiteStay::create(['client_id' => $person->id, 'booking_id' => $booking->id, 'status' => 'admitted', 'actual_start' => null, 'created_by' => $actor->id]);
    Event::fake([RespiteEvent::class]);
    $this->actingAs($actor)->post(route('respite.stays.checkin', $stay))->assertRedirect()->assertSessionHasErrors('service_start');
    expect($stay->fresh()->status)->toBe('admitted')->and($stay->fresh()->actual_start)->toBeNull()
        ->and($booking->fresh()->status)->toBe('confirmed')->and(Shift::where('respite_booking_id', $booking->id)->count())->toBe(0);
    Event::assertNotDispatched(RespiteEvent::class);
    $count = Client::count();
    $f = prepareRespiteCommencement($booking);
    expect($f['referral']->entry_kind)->toBe(InternalSupportPreparation::KIND)
        ->and($f['person']->id)->toBe($person->id)->and(Client::count())->toBe($count)
        ->and($person->fresh()->status)->toBe('active');
    $this->actingAs($actor->fresh())->post(route('respite.stays.checkin', $stay))->assertRedirect()->assertSessionHasNoErrors();
    expect($stay->fresh()->status)->toBe('active')->and($stay->fresh()->actual_start)->not->toBeNull();
});

it('offers respite preparation separately from commencement permission and withdraws it with canonical or destination access', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $context = ServiceContext::factory()->create(['site_id' => $site->id, 'type' => 'planned_respite', 'is_active' => true]);
    $person = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $context->id, 'status' => 'onboarding']);
    $actor = referralTestUser($site, ['create', 'viewAssigned']);
    handoverCanonicalPermissions($actor, ['clients.viewAny', 'clients.update']);
    $booking = RespiteBooking::factory()->create(['client_id' => $person->id, 'location_id' => $site->id]);
    $review = app(ReferralRespiteReview::class);
    expect($review->url($actor, $person, $booking))->toBeNull()
        ->and($review->preparation($actor, $person, $booking))->toBe(['person_id' => $person->id, 'site_id' => $site->id, 'service_context_id' => $context->id]);
    $booking->location_id = Site::factory()->create()->id;
    expect($review->preparation($actor, $person, $booking))->toBeNull();
    $booking->location_id = $site->id;
    $actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'clients.update')->sole()->id, ['allowed' => false]);
    expect($review->preparation($actor->fresh(), $person, $booking))->toBeNull();
});

it('admits and checks in a person outside the commencement review exactly as before', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $context = ServiceContext::factory()->create(['site_id' => $site->id, 'type' => 'planned_respite', 'is_active' => true]);
    $person = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $context->id, 'status' => 'onboarding']);
    $actor = referralTestUser($site, ['create', 'viewAssigned']);
    handoverCanonicalPermissions($actor, ['clients.viewAny', 'clients.update', 'respite.viewAny', 'respite.stays.manage']);
    $booking = RespiteBooking::factory()->create(['client_id' => $person->id, 'location_id' => $site->id, 'start_at' => now(), 'end_at' => now()->addDays(2)]);
    Event::fake([RespiteEvent::class]);
    $this->actingAs($actor)->post(route('respite.stays.store'), ['booking_id' => $booking->id, 'client_id' => $person->id])
        ->assertRedirect()->assertSessionHasNoErrors();
    $stay = RespiteStay::where('booking_id', $booking->id)->sole();
    expect($stay->actual_start)->not->toBeNull();
    $this->post(route('respite.stays.checkin', $stay))->assertRedirect()->assertSessionHasNoErrors();
    expect($stay->fresh()->status)->toBe('active')->and($booking->fresh()->status)->toBe('in_progress');
    Event::assertDispatched(RespiteEvent::class, fn ($event) => $event->name === 'respite.stay.checked_in');
});
