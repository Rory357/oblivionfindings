<?php

namespace App\Services\Fleet;

use App\Models\FleetDrivingEventReview;
use App\Models\FleetTrip;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Human review of a recorded driving event: confirmed, dismissed or
 * disputed, with the evidence, an owner and the policy version it was
 * reviewed under. Reviews are only added; the recorded telemetry and the
 * trip are never changed. Reviewers are fleet or trip managers, the same
 * people who confirm a trip's driver.
 */
final class VehicleDrivingReviewService
{
    public function __construct(
        private readonly VehicleTripHistoryService $trips,
        private readonly VehicleDrivingInsightsService $insights,
        private readonly VehicleStaffDirectory $staff,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed> The trip's review state after the save.
     */
    public function record(User $actor, int $assetId, int $tripId, array $data, string $requestKey): array
    {
        DrivingScorePolicyStore::assertKey($requestKey);
        Validator::make($data, [
            'event_key' => ['required', 'string', 'max:120'],
            'outcome' => ['required', 'in:'.implode(',', FleetDrivingEventReview::OUTCOMES)],
            'reason' => ['required', 'string', 'max:2000'],
            'review_owner_user_id' => ['required', 'integer', 'min:1'],
            'confirmed' => ['accepted'],
            'expected_version' => ['required', 'integer', 'min:0'],
        ], [
            'outcome.required' => 'Choose the review outcome.',
            'reason.required' => 'Record the evidence and the reason for this outcome.',
            'review_owner_user_id.required' => 'Choose the review owner.',
            'confirmed.accepted' => 'Confirm that you reviewed the event evidence and scoring impact.',
        ], ['review_owner_user_id' => 'review owner'])->validate();
        $eventKey = (string) $data['event_key'];
        $fingerprint = MaintenanceFingerprint::of([
            'actor' => (int) $actor->id, 'asset' => $assetId, 'trip' => $tripId, 'event' => $eventKey,
            'outcome' => (string) $data['outcome'], 'reason' => trim((string) $data['reason']),
            'owner' => (int) $data['review_owner_user_id'],
        ]);

        try {
            $reviewedTripId = DB::transaction(function () use ($actor, $assetId, $tripId, $data, $eventKey, $requestKey, $fingerprint): int {
                $current = User::query()->findOrFail($actor->id);
                abort_unless($this->insights->canReview($current), 403);
                // Trip Site rule, then lock order: vehicle, trip, reviews.
                $vehicle = $this->trips->vehicle($current, $assetId, true);
                $prior = FleetDrivingEventReview::query()->where('asset_id', $vehicle->id)
                    ->where('request_key', $requestKey)->lockForUpdate()->first();
                if ($prior) {
                    abort_unless(hash_equals((string) $prior->request_fingerprint, $fingerprint), 409,
                        'This request was already used for a different review.');

                    return (int) $prior->fleet_trip_id;
                }
                $trip = FleetTrip::query()->whereKey($tripId)->where('asset_id', $vehicle->id)
                    ->where(fn (Builder $status) => $status->whereNull('status')->orWhere('status', '!=', 'cancelled'))
                    ->lockForUpdate()->first() ?? abort(404);
                // Personal and consent-blocked trips are never scored or reviewed.
                abort_if($trip->is_personal || $trip->consent_blocked, 404);
                $event = $this->insights->reviewableEvent($current, $vehicle, $trip, $eventKey);
                if ($event === null) {
                    throw ValidationException::withMessages([
                        'event_key' => 'This event is no longer in the trip\'s recorded data. Reload the trip.',
                    ]);
                }
                // A locking read sees reviews committed since this transaction began.
                $version = FleetDrivingEventReview::query()->where('fleet_trip_id', $trip->id)
                    ->where('event_key', $eventKey)->lockForUpdate()->count();
                abort_unless($version === (int) $data['expected_version'], 409,
                    'This event was reviewed by someone else while you were working. Reload the trip before saving.');
                if (! $this->staff->isCandidate($vehicle, (int) $data['review_owner_user_id'])) {
                    throw ValidationException::withMessages([
                        'review_owner_user_id' => "Choose a current staff member at this vehicle's site.",
                    ]);
                }
                $policyVersion = (int) (TripBehaviourAnalyzer::policy()['version'] ?? 1);
                $review = FleetDrivingEventReview::query()->create([
                    'asset_id' => $vehicle->id,
                    'fleet_trip_id' => $trip->id,
                    'event_key' => $eventKey,
                    'event_type' => $event['type'],
                    'event_title' => mb_substr((string) $event['title'], 0, 120),
                    'event_at' => CarbonImmutable::parse((string) $event['at'])->utc(),
                    'outcome' => (string) $data['outcome'],
                    'reason' => trim((string) $data['reason']),
                    'review_owner_user_id' => (int) $data['review_owner_user_id'],
                    'recorded_by_user_id' => $current->id,
                    'policy_version' => $policyVersion,
                    'sequence' => $version + 1,
                    'request_key' => $requestKey,
                    'request_fingerprint' => $fingerprint,
                ]);
                AuditLogger::logOrFail('fleet.driving.event_reviewed', $trip, [
                    'actor_id' => $current->id,
                    'asset_id' => $vehicle->id,
                    'trip_id' => $trip->id,
                    'review_id' => $review->id,
                    'event_key' => $eventKey,
                    'event_type' => $event['type'],
                    'outcome' => $review->outcome,
                    'previous_outcome' => $event['review']['outcome'] ?? null,
                    'policy_version' => $policyVersion,
                ]);

                return (int) $trip->id;
            }, 3);
        } catch (QueryException $exception) {
            // The same event version saved concurrently: the loser reloads.
            abort_if((int) ($exception->errorInfo[1] ?? 0) === 1062, 409,
                'This event was reviewed by someone else while you were working. Reload the trip before saving.');

            throw $exception;
        }

        $viewer = User::query()->findOrFail($actor->id);

        return $this->insights->tripReview($viewer, $this->trips->vehicle($viewer, $assetId), $reviewedTripId);
    }
}
