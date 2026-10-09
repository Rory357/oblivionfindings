<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\Recording\OfflineReplayReview;
use App\Services\UserSiteAccessService;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * eMAR audit Lane A3 (EA-049, EA-050): the device reports a dose it saved
 * that the server refused or could not take (no access any more, the order
 * changed, someone else recorded it), so a lead gets a follow-up. Only the
 * worker who captured it may report it, only for a person at a house in
 * their access, and only once per request; a dose that is on the chart is
 * answered "recorded" and nothing is created. The answer never says more.
 */
class OfflineReplayReviewController extends Controller
{
    public function __construct(
        private readonly OfflineReplayReview $review,
        private readonly UserSiteAccessService $sites,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->canDo('medications.administer.record'), 403);

        $data = $request->validate([
            'client_request_uuid' => ['required', 'uuid'],
            'client_medication_id' => ['required', 'integer', 'min:1'],
            'captured_by_user_id' => ['required', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                if ((int) $value !== (int) $user->id) {
                    $fail('Only the worker who saved this dose on the device can report it.');
                }
            }],
            'captured_offline_at' => ['nullable', 'string', 'max:64'],
            'scheduled_for' => ['nullable', 'string', 'max:64'],
            'administered_at' => ['nullable', 'string', 'max:64'],
            'status' => ['required', Rule::in(['given', 'refused', 'withheld'])],
            'http_status' => ['nullable', 'integer', 'min:100', 'max:599'],
            'error_field' => ['nullable', 'string', 'max:60'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $recorded = ClientMedicationAdministration::withTrashed()
            ->where('client_request_uuid', $data['client_request_uuid'])
            ->where('client_medication_id', $data['client_medication_id'])
            ->exists();
        if ($recorded) {
            return response()->json(['status' => 'recorded']);
        }

        $snapshot = ClientMedication::withTrashed()->find($data['client_medication_id'], ['id', 'client_id']);
        abort_unless($snapshot !== null, 404);

        return DB::transaction(function () use ($data, $user, $snapshot): JsonResponse {
            $client = Client::query()->whereKey($snapshot->client_id)->lockForUpdate()->first();
            abort_unless($client !== null && in_array(
                (int) $client->site_id,
                $this->sites->accessibleSiteIds($user, MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS),
                true,
            ), 404);
            $medication = ClientMedication::withTrashed()->whereKey($snapshot->id)
                ->where('client_id', $client->id)->lockForUpdate()->firstOrFail();

            $reasonKey = OfflineReplayReview::reasonForStatus(
                isset($data['http_status']) ? (int) $data['http_status'] : null,
                $data['error_field'] ?? null,
            );
            $followup = $this->review->raise(
                $client,
                $medication,
                $user,
                $data,
                $reasonKey,
                $data['reason'] ?? 'Saved on a device but not recorded.',
            );
            if ($followup->wasRecentlyCreated) {
                AuditLogger::logOrFail('medications.offline_review.reported', $followup, [
                    'actor_id' => $user->id,
                    'client_id' => $client->id,
                    'client_medication_id' => $medication->id,
                    'client_request_uuid' => $data['client_request_uuid'],
                    'reason_key' => $reasonKey,
                    'http_status' => $data['http_status'] ?? null,
                ]);
            }

            return response()->json(['status' => 'reported']);
        });
    }
}
