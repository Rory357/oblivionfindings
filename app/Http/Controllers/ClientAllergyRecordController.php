<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\MedicationIdempotencyResult;
use App\Models\User;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\ClientAllergyRecordService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ClientAllergyRecordController extends Controller
{
    public function show(Request $request, int $client)
    {
        $person = app(MedicationRecordAccess::class)->client($request->user(), $client);
        return response()->json($this->payload($person, $request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, int $client)
    {
        $actor = $request->user();
        app(MedicationRecordAccess::class)->client($actor, $client);
        $data = $request->validate([
            'request_uuid' => ['required', 'uuid'], 'digest' => ['required', 'string', 'size:64'],
            'action' => ['required', 'in:edit,review'], 'method' => ['required_if:action,review', 'nullable', 'string', 'max:2000'],
            'no_known' => ['sometimes', 'boolean'], 'records' => ['present_if:action,edit', 'array', 'max:100'],
            'records.*.key' => ['required', 'string', 'max:100', 'distinct'],
            'records.*.allergen' => ['required', 'string', 'max:255'],
            'records.*.severity' => ['nullable', 'in:mild,moderate,severe,life_threatening'],
            'records.*.reaction' => ['nullable', 'string', 'max:2000'], 'records.*.notes' => ['nullable', 'string', 'max:5000'],
            'records.*.identified_date' => ['nullable', 'date_format:Y-m-d'], 'records.*.identified_by' => ['nullable', 'string', 'max:255'],
        ]);
        $capability = $data['action'] === 'review' ? 'medications.orders.manage' : 'clients.update';
        $result = DB::transaction(fn () => app(MedicationGovernanceScopeService::class)->forClient($actor, $client, $capability, function (Client $person, User $lockedActor) use ($data) {
            app(MedicationRecordAccess::class)->assertReadable($lockedActor, $person);
            if ($data['action'] === 'edit') Gate::forUser($lockedActor)->authorize('update', $person);
            $scope = 'p02-allergy:'.$person->id.':'.$lockedActor->id;
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $receipt = MedicationIdempotencyResult::query()->where('scope', $scope)->where('request_uuid', $data['request_uuid'])->lockForUpdate()->first();
            if ($receipt) {
                abort_unless(hash_equals($receipt->response_payload['fingerprint'], $hash), 409, 'This request was already used for a different change.');
                return $receipt->response_payload['result'];
            }
            $service = app(ClientAllergyRecordService::class);
            $before = $service->summary($person);
            abort_unless(hash_equals($before['digest'], $data['digest']), 409, 'The allergy list changed. Reload and review the latest list.');
            $profile = $service->copyLegacy($person);
            if ($data['action'] === 'edit') {
                $records = $profile->allergy_records ?? [];
                $active = collect($records)->filter(fn ($record) => empty($record['removed_at']))->keyBy('key');
                $incoming = collect($data['records']);
                foreach ($incoming as $entry) {
                    if (! $active->has($entry['key'])) {
                        abort_unless(\Illuminate\Support\Str::isUuid($entry['key']), 422, 'Use a fresh entry identifier.');
                        abort_if(collect($records)->contains('key', $entry['key']), 422, 'Removed allergy entries are retained as history.');
                        $records[] = [...$entry, 'added_at' => now()->toIso8601String(), 'added_by' => $lockedActor->id];
                    }
                }
                foreach ($records as &$record) {
                    if (! empty($record['removed_at'])) continue;
                    $entry = $incoming->firstWhere('key', $record['key']);
                    if ($entry) $record = [...$record, ...$entry];
                    else $record = [...$record, 'removed_at' => now()->toIso8601String(), 'removed_by' => $lockedActor->id];
                }
                unset($record);
                $profile->allergy_records = $records;
                $profile->allergies = collect($records)->filter(fn ($entry) => empty($entry['removed_at']))->pluck('allergen')->unique()->values()->all();
                $service->clearReview($profile);
            } else {
                $entries = $service->forClient($person);
                if (($data['no_known'] ?? false) && $entries !== []) throw ValidationException::withMessages(['no_known' => 'The list contains allergies. Review the recorded list instead.']);
                if ($entries === [] && ! ($data['no_known'] ?? false)) throw ValidationException::withMessages(['no_known' => 'Confirm no known allergies after checking, or add the missing entries.']);
                $profile->forceFill(['allergies_reviewed_at' => now(), 'allergies_reviewed_by' => $lockedActor->id, 'allergies_review_status' => $entries === [] ? 'no_known' : 'reviewed', 'allergies_review_method' => trim($data['method']), 'allergies_review_digest' => $service->digest($entries)]);
            }
            $profile->saveOrFail();
            $result = $this->payload($person, $lockedActor);
            MedicationIdempotencyResult::query()->create(['scope' => $scope, 'request_uuid' => $data['request_uuid'], 'response_payload' => ['fingerprint' => $hash, 'result' => $result], 'expires_at' => now()->addDays(30)]);
            // The chain-head lock is last: recorder failure rolls back the edit and receipt.
            app(MedicationEventRecorder::class)->append(new MedicationEventData(siteId: (int) $person->site_id, kind: 'allergy.'.$data['action'], subjectType: 'health_profile', subjectId: (string) $profile->id, actorId: $lockedActor->id, occurredAt: CarbonImmutable::now(), summary: 'Allergy record '.$data['action'].' saved', facts: ['digest' => $result['digest'], 'status' => $result['status']], clientId: (int) $person->id));
            return $result;
        }), 5);
        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    private function payload(Client $person, User $actor): array
    {
        return app(ClientAllergyRecordService::class)->summary($person) + ['can_edit' => Gate::forUser($actor)->allows('update', $person), 'can_review' => $actor->canDo('medications.orders.manage')];
    }
}
