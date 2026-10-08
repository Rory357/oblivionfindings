<?php

namespace App\Services\Medication;

use App\Models\Client;
use App\Models\ClientMedicalProfile;
use App\Models\MedicationIdempotencyResult;
use App\Models\User;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** The shared, audited command path for the person's canonical allergy record. */
final class ClientAllergyRecordCommands
{
    public function __construct(private readonly ClientAllergyRecordService $records) {}

    public function payload(Client $person, User $actor): array
    {
        return $this->records->summary($person) + $this->accessPayload($person, $actor);
    }

    private function accessPayload(Client $person, User $actor): array
    {
        return [
            'can_edit' => Gate::forUser($actor)->allows('update', $person),
            'can_review' => $actor->canDo('medications.orders.manage'),
            'management_url' => $this->records->managementUrl($person, $actor),
        ];
    }

    /** The legacy create endpoint adds one entry without replacing any current entries. */
    public function append(User $actor, int $clientId, array $data): array
    {
        $uuid = $data['request_uuid'] ?? (string) Str::uuid();
        $entry = array_intersect_key($data, array_flip(['allergen', 'severity', 'reaction', 'notes', 'identified_date', 'identified_by']));

        return $this->execute($actor, $clientId, [
            'action' => 'append', 'request_uuid' => $uuid, 'records' => [['key' => $uuid, ...$entry]],
            ...(isset($data['digest']) ? ['digest' => $data['digest']] : []),
        ]);
    }

    /** Input is validated by the supported endpoint before entering this command. */
    public function execute(User $actor, int $clientId, array $data): array
    {
        app(MedicationRecordAccess::class)->client($actor, $clientId);
        $capability = $data['action'] === 'review' ? 'medications.orders.manage' : 'clients.update';

        return DB::transaction(fn () => app(MedicationGovernanceScopeService::class)->forClient($actor, $clientId, $capability, function (Client $person, User $lockedActor) use ($data) {
            app(MedicationRecordAccess::class)->assertReadable($lockedActor, $person);
            if ($data['action'] !== 'review') {
                Gate::forUser($lockedActor)->authorize('update', $person);
            }
            $scope = 'p02-allergy:'.$person->id.':'.$lockedActor->id;
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $receipt = MedicationIdempotencyResult::query()->where('scope', $scope)->where('request_uuid', $data['request_uuid'])->lockForUpdate()->first();
            if ($receipt) {
                abort_unless(hash_equals($receipt->response_payload['fingerprint'], $hash), 409, 'This request was already used for a different change.');

                return [...$receipt->response_payload['result'], ...$this->accessPayload($person, $lockedActor)];
            }
            $before = $this->records->summary($person);
            if (isset($data['digest'])) {
                abort_unless(hash_equals($before['digest'], $data['digest']), 409, 'The allergy list changed. Reload and review the latest list.');
            }
            $profile = $this->records->copyLegacy($person);
            if ($data['action'] === 'review') {
                $entries = $this->records->forClient($person);
                if (($data['no_known'] ?? false) && $entries !== []) {
                    throw ValidationException::withMessages(['no_known' => 'The list contains allergies. Review the recorded list instead.']);
                }
                if ($entries === [] && ! ($data['no_known'] ?? false)) {
                    throw ValidationException::withMessages(['no_known' => 'Confirm no known allergies after checking, or add the missing entries.']);
                }
                $profile->forceFill([
                    'allergies_reviewed_at' => now(), 'allergies_reviewed_by' => $lockedActor->id,
                    'allergies_review_status' => $entries === [] ? 'no_known' : 'reviewed',
                    'allergies_review_method' => trim($data['method']), 'allergies_review_digest' => $this->records->digest($entries),
                ]);
            } else {
                $incoming = $data['records'];
                if ($data['action'] === 'append') {
                    $incoming = [...collect($profile->allergy_records ?? [])->filter(fn ($record) => empty($record['removed_at']))->values()->all(), ...$incoming];
                }
                $this->edit($profile, $lockedActor, $incoming);
            }
            $profile->saveOrFail();
            $result = $this->payload($person, $lockedActor);
            MedicationIdempotencyResult::query()->create([
                'scope' => $scope, 'request_uuid' => $data['request_uuid'],
                'response_payload' => ['fingerprint' => $hash, 'result' => $result], 'expires_at' => now()->addDays(30),
            ]);
            // The chain-head lock is last: recorder failure rolls back the edit and receipt.
            $action = $data['action'] === 'review' ? 'review' : 'edit';
            app(MedicationEventRecorder::class)->append(new MedicationEventData(
                siteId: (int) $person->site_id, kind: 'allergy.'.$action, subjectType: 'health_profile',
                subjectId: (string) $profile->id, actorId: $lockedActor->id, occurredAt: CarbonImmutable::now(),
                summary: 'Allergy record '.$action.' saved',
                facts: ['digest' => $result['digest'], 'status' => $result['status'], 'before' => $before, 'after' => $this->records->summary($person)],
                clientId: (int) $person->id,
            ));

            return $result;
        }), 5);
    }

    private function edit(ClientMedicalProfile $profile, User $actor, array $incoming): void
    {
        $records = $profile->allergy_records ?? [];
        $active = collect($records)->filter(fn ($record) => empty($record['removed_at']))->keyBy('key');
        $incoming = collect($incoming);
        foreach ($incoming as $entry) {
            if (! $active->has($entry['key'])) {
                abort_unless(Str::isUuid($entry['key']), 422, 'Use a fresh entry identifier.');
                abort_if(collect($records)->contains('key', $entry['key']), 422, 'Removed allergy entries are retained as history.');
                $records[] = [...$entry, 'added_at' => now()->toIso8601String(), 'added_by' => $actor->id];
            }
        }
        foreach ($records as &$record) {
            if (! empty($record['removed_at'])) {
                continue;
            }
            $entry = $incoming->firstWhere('key', $record['key']);
            $record = $entry
                ? [...$record, ...$entry]
                : [...$record, 'removed_at' => now()->toIso8601String(), 'removed_by' => $actor->id];
        }
        unset($record);
        $profile->allergy_records = $records;
        $profile->allergies = collect($records)->filter(fn ($entry) => empty($entry['removed_at']))->pluck('allergen')->unique()->values()->all();
        $this->records->clearReview($profile);
    }
}
