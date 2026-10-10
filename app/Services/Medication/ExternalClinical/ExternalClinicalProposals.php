<?php

namespace App\Services\Medication\ExternalClinical;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationExternalClinician;
use App\Models\MedicationExternalGrant;
use App\Models\MedicationExternalProposal;
use App\Models\MedicationOrderFile;
use App\Models\MedicationOrderVersion;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Medication\Connected\ConnectedCareAlerts;
use App\Services\Medication\MedicationOrderWorkflow;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\Medication\OrderAllergyMatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ExternalClinicalProposals
{
    public function __construct(
        private readonly ExternalClinicalAccess $access,
        private readonly MedicationOrderWorkflow $orders,
        private readonly MedicationRecordAccess $records,
    ) {}

    public function submit(User $actor, int $clientId, array $input, ?UploadedFile $file): MedicationExternalProposal
    {
        $path = null;
        try {
            return $this->access->external($actor, $clientId, function (Client $client, User $locked, MedicationExternalClinician $profile, MedicationExternalGrant $grant) use ($input, $file, &$path) {
                $data = Validator::make($input, ['kind' => 'required|in:start,change,stop',
                    'medication_id' => 'required_unless:kind,start|nullable|integer', 'expected_version' => 'required_unless:kind,start|nullable|integer|min:1',
                    'reason' => 'required|string|max:2000', 'request_key' => 'required|string|max:100|regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/',
                    'prescription' => 'required_unless:kind,stop|nullable|array'])->validate();
                abort_if($data['kind'] === 'start' && ! empty($data['medication_id']), 404);
                $order = $data['kind'] === 'start' ? null : ClientMedication::query()->current()->whereKey($data['medication_id'])
                    ->where('client_id', $client->id)->lockForUpdate()->firstOrFail();
                abort_if($order?->controlled_drug && ! $grant->include_controlled, 404);

                $payload = $data['kind'] === 'stop' ? null : ExternalClinicalPrescription::normalise($this->orders->proposedPrescription($data['prescription'], $order));
                $controlled = (bool) ($order?->controlled_drug || ($payload['controlled_drug'] ?? false));
                abort_if($controlled && ! $grant->include_controlled, 404);
                if ($order !== null && $payload !== null && (OrderAllergyMatcher::normalise($payload['name']) !== OrderAllergyMatcher::normalise($order->name)
                    || (bool) $payload['controlled_drug'] !== (bool) $order->controlled_drug)) {
                    $this->invalid('prescription.name', 'Use a separate new medicine proposal and a separate stop request for a replacement.');
                }
                if ($file !== null) {
                    Validator::make(['source_file' => $file], ['source_file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240'])->validate();
                }
                $hash = hash('sha256', json_encode([$client->id, $profile->id, $data, $payload, $file ? hash_file('sha256', $file->getRealPath()) : null], JSON_THROW_ON_ERROR));
                $replay = MedicationExternalProposal::query()->where('clinician_id', $profile->id)->where('request_key', $data['request_key'])->lockForUpdate()->first();
                if ($replay !== null) {
                    abort_unless((int) $replay->client_id === (int) $client->id && hash_equals($replay->payload_sha256, $hash), 409, 'This request key belongs to different proposal details.');

                    return $replay;
                }
                if ($order !== null && ($order->state === 'ceased' || $order->ceased_at !== null)) {
                    $this->invalid('medication_id', 'This medicine is already stopped.');
                }
                if ($order !== null && (int) $data['expected_version'] !== (int) $order->version) {
                    $this->invalid('expected_version', 'The chart changed. Reload and compare the current medicine.');
                }
                if ($file !== null && $path === null) {
                    $path = $file->store('medication-external-sources/'.$profile->id, 'local');
                    if (! is_string($path)) {
                        throw new \RuntimeException('The proposal source could not be saved.');
                    }
                }
                $proposal = MedicationExternalProposal::query()->create([
                    'clinician_id' => $profile->id, 'grant_id' => $grant->id, 'client_id' => $client->id,
                    'medication_id' => $order?->id, 'expected_version' => $order?->version, 'kind' => $data['kind'],
                    'controlled' => $controlled, 'prescription' => $payload, 'reason' => $data['reason'],
                    'request_key' => $data['request_key'], 'payload_sha256' => $hash, 'submitted_at' => now(),
                    'source_file_path' => $path, 'source_file_name' => $file?->getClientOriginalName(),
                    'source_file_sha256' => $file ? hash_file('sha256', $file->getRealPath()) : null,
                ]);
                AuditLogger::logOrFail('medications.external.proposal_submitted', $proposal, ['actor_id' => $locked->id, 'kind' => $data['kind'], 'grant_id' => $grant->id]);
                // EA-025: a prescriber's request reaches the people who decide it.
                app(ConnectedCareAlerts::class)->prescriberRequest((int) $proposal->id);

                return $proposal;
            }, write: true);
        } finally {
            // A retry can return another request's committed exact replay.
            // Retain only bytes referenced by the final persisted proposal.
            if (is_string($path)) {
                try {
                    if (! MedicationExternalProposal::query()->where('source_file_path', $path)->exists()) {
                        Storage::disk('local')->delete($path);
                    }
                } catch (\Throwable $cleanup) {
                    report($cleanup);
                }
            }
        }
    }

    public function decide(User $actor, int $id, array $input, ?UploadedFile $file): MedicationExternalProposal
    {
        $submitted = MedicationExternalProposal::with('clinician')->findOrFail($id);

        // P04 owns current assignment/shift eligibility and the internal actor.
        return $this->withOrderSourceCleanup(function (array &$paths) use ($actor, $submitted, $id, $input, $file) {
            return DB::transaction(function () use ($actor, $submitted, $id, $input, $file, &$paths) {
                Client::query()->whereKey($submitted->client_id)->lockForUpdate()->firstOrFail();
                $userIds = array_filter([$actor->id, $submitted->clinician->user_id, (int) ($input['source']['witness_id'] ?? 0)]);
                User::query()->whereIn('id', array_values(array_unique($userIds)))->orderBy('id')->lockForUpdate()->get();

                return $this->orders->forClient($actor, $submitted->client_id, 'medications.orders.manage', function (Client $client, User $locked) use ($id, $input, $file, &$paths) {
                    $this->records->assertReadable($locked, $client);
                    $proposal = MedicationExternalProposal::query()->whereKey($id)->where('client_id', $client->id)->lockForUpdate()->firstOrFail();
                    $this->orders->assertControlled($locked, (bool) $proposal->controlled);
                    // EA-084: decision_note is the reply the prescriber sees; internal_note never leaves the organisation.
                    $data = Validator::make($input, ['decision' => 'required|in:accept,reject,link_stop', 'decision_note' => 'required|string|max:2000', 'internal_note' => 'nullable|string|max:2000'])->validate();
                    $sourceForHash = $input['source'] ?? null;
                    if (is_array($sourceForHash)) {
                        unset($sourceForHash['witness_pin']);
                    }
                    $decisionHash = hash('sha256', json_encode([$locked->id, $data, $input['source_confirmed'] ?? null,
                        $sourceForHash, $input['source_reference'] ?? null, $input['ceased_version'] ?? null,
                        $file ? hash_file('sha256', $file->getRealPath()) : null], JSON_THROW_ON_ERROR));
                    if ($proposal->status !== 'submitted') {
                        $evidence = $proposal->decision_evidence ?? [];
                        if (($evidence['decision'] ?? null) === $data['decision'] && hash_equals((string) ($evidence['payload_sha256'] ?? ''), $decisionHash)) {
                            return $proposal;
                        }
                        abort(409, 'This proposal already has a recorded decision.');
                    }
                    $evidence = ['decision' => $data['decision'], 'payload_sha256' => $decisionHash]
                        + (filled($data['internal_note'] ?? null) ? ['internal_note' => trim((string) $data['internal_note'])] : []);
                    if ($data['decision'] !== 'reject') {
                        Validator::make($input, ['source_confirmed' => 'required|accepted'])->validate();
                        $profile = MedicationExternalClinician::query()->whereKey($proposal->clinician_id)->lockForUpdate()->firstOrFail();
                        $external = User::query()->whereKey($profile->user_id)->lockForUpdate()->firstOrFail();
                        $this->access->assertIdentity($profile, $external);
                        $grant = $this->access->namedGrant($profile, $client, true, true);
                        abort_if($proposal->controlled && ! $grant->include_controlled, 404);
                        $evidence += ['source_confirmed' => true, 'clinician_id' => $profile->id,
                            'registration_authority' => $profile->registration_authority, 'registration_number' => $profile->registration_number];
                        if ($data['decision'] === 'link_stop') {
                            if ($proposal->kind !== 'stop') {
                                $this->invalid('decision', 'Only a stop request can link a recorded stop.');
                            }
                            $stop = Validator::make($input, ['ceased_version' => 'required|integer|min:1', 'source_reference' => 'required|string|max:2000'])->validate();
                            $order = ClientMedication::query()->current()->whereKey($proposal->medication_id)->where('client_id', $client->id)->lockForUpdate()->firstOrFail();
                            $version = MedicationOrderVersion::query()->where('client_medication_id', $order->id)->where('client_id', $client->id)
                                ->where('version_number', $stop['ceased_version'])->where('state', 'ceased')->whereNotNull('ceased_at')->lockForUpdate()->firstOrFail();
                            if ($order->state !== 'ceased' || $order->ceased_at === null || (int) $order->version !== (int) $version->version_number
                                || (int) $version->version_number <= (int) $proposal->expected_version || ! $version->ceased_at->equalTo($order->ceased_at)) {
                                $this->invalid('ceased_version', 'Record the current stop in Orders before linking its exact ceased version here.');
                            }
                            $proposal->ceased_version = $version->version_number;
                            $evidence += ['source_reference' => $stop['source_reference'], 'ceased_order_version_id' => $version->id];
                        } else {
                            if ($proposal->kind === 'stop') {
                                $this->invalid('decision', 'Record the stop in Orders, then choose Link recorded stop.');
                            }
                            if (($input['source']['prescriber'] ?? null) !== $external->name) {
                                $this->invalid('source.prescriber', 'Record the verified clinician who submitted this proposal.');
                            }
                            if ($file === null && $proposal->source_file_path !== null && ($input['source']['type'] ?? '') === 'written') {
                                $file = $this->source($proposal);
                            }
                            $source = $input['source'] ?? [];
                            $source['description'] = trim(($source['description'] ?? '').' External clinician proposal '.$proposal->id.'; '.$profile->registration_authority.' '.$profile->registration_number.'.');
                            $revision = $this->orders->enter($locked, $client->id, $proposal->medication_id, [
                                'prescription' => $proposal->prescription, 'source' => $source, 'expected_version' => $proposal->expected_version,
                                'request_key' => 'external-proposal:'.$proposal->id, 'change_reason' => $proposal->reason,
                            ], $file);
                            $paths = array_merge($paths, $revision->files()->pluck('file_path')->all());
                            $proposal->revision_id = $revision->id;
                            $evidence['order_revision_id'] = $revision->id;
                        }
                    }
                    $proposal->forceFill(['status' => $data['decision'] === 'reject' ? 'rejected' : 'accepted',
                        'decided_by' => $locked->id, 'decided_at' => now(), 'decision_note' => $data['decision_note'], 'decision_evidence' => $evidence])->save();
                    AuditLogger::logOrFail('medications.external.proposal_decided', $proposal, ['actor_id' => $locked->id, 'decision' => $data['decision'], 'order_revision_id' => $proposal->revision_id]);
                    app(ConnectedCareAlerts::class)->prescriberRequestDecided((int) $proposal->id);

                    return $proposal;
                });
            }, 3);
        });
    }

    /** An outer proposal rollback must not orphan a P04 source attachment. */
    private function withOrderSourceCleanup(\Closure $callback): mixed
    {
        $paths = [];
        try {
            $result = $callback($paths);
        } catch (\Throwable $failure) {
            $this->cleanupOrderSources($paths);
            throw $failure;
        }
        $this->cleanupOrderSources($paths);

        return $result;
    }

    private function cleanupOrderSources(array $paths): void
    {
        if ($paths === []) {
            return;
        }
        try {
            $retained = MedicationOrderFile::query()->whereIn('file_path', $paths)->pluck('file_path')->all();
            Storage::disk('local')->delete(array_values(array_diff(array_unique($paths), $retained)));
        } catch (\Throwable $cleanup) {
            report($cleanup);
        }
    }

    public function source(MedicationExternalProposal $proposal): UploadedFile
    {
        abort_unless($proposal->source_file_path !== null && Storage::disk('local')->exists($proposal->source_file_path), 404);
        $path = Storage::disk('local')->path($proposal->source_file_path);
        abort_unless(hash_equals((string) $proposal->source_file_sha256, hash_file('sha256', $path)), 409, 'The source attachment needs checking.');

        return new UploadedFile($path, $proposal->source_file_name, null, null, true);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
