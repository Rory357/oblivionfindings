<?php

use App\Domain\Referrals\Services\ReferralServiceCommencement;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\ServiceContext;

require_once __DIR__.'/ReferralStartFixtures.php';

/** Use the real independent start workflow; never mock the service-start gate for admission tests. */
function prepareRespiteCommencement(RespiteBooking $booking): array
{
    $person = $booking->client;
    $context = ServiceContext::query()->availableToSite($booking->location_id ?: $person->site_id)->where('is_active', true)
        ->whereIn('type', ['planned_respite', 'emergency_respite', 'community_respite'])
        ->find($booking->request?->service_context_id ?: $person->service_context_id);
    $fixture = referralStartFixture(true, 'planned_respite', $person, $context);
    $request = $booking->request ?? RespiteBookingRequest::create(['client_id' => $person->id, 'service_context_id' => $fixture['context']->id,
        'requested_start' => $booking->start_at, 'requested_end' => $booking->end_at, 'status' => 'approved', 'created_by' => $fixture['author']->id]);
    $booking->update(['booking_request_id' => $request->id, 'service_agreement_id' => $fixture['agreement']->id]);
    $service = app(ReferralServiceCommencement::class);
    $service->schedule($fixture['manager'], $fixture['referral']->id, 5, $fixture['input']);
    $service->commence($fixture['manager'], $fixture['referral']->id, 6, referralCommenceInput());

    return $fixture;
}
