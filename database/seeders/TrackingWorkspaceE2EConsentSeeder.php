<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentRequest;
use App\Models\ConsentType;
use App\Models\ConsentTypeVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TrackingWorkspaceE2EConsentSeeder extends Seeder
{
    public const MARKER = 'PW-TRACK-WORKSPACE-CONSENT';

    /**
     * The browser fixture owns these two identified synthetic Clients. This
     * prepares their recorded self-decisions, without changing assignments,
     * stopped collection, unrelated evidence, or anyone's permissions.
     */
    public function seedConsent(Client $client, ConsentType $type, User $recorder, string $status): ClientConsent
    {
        return DB::transaction(function () use ($client, $type, $recorder, $status): ClientConsent {
            $client = Client::query()->lockForUpdate()->findOrFail($client->id);
            $type = ConsentType::query()->lockForUpdate()->findOrFail($type->id);
            $recorder = User::query()->findOrFail($recorder->id);
            $expected = match ($client->first_name) {
                'Playwright Active' => ['name' => 'Mere Active', 'status' => 'given'],
                'Playwright Withdrawn' => ['name' => 'Ria Withdrawn', 'status' => 'withdrawn'],
                default => null,
            };
            if ($expected === null
                || $client->last_name !== 'Tracking'
                || $client->preferred_name !== $expected['name']
                || $client->status !== 'active'
                || $status !== $expected['status']
                || ! $client->site_id
                || ! $client->site?->is_active
                || $client->site->archived
                || $recorder->email !== 'admin@demo.test'
                || $type->name !== 'Personal Tracker (Wandering Risk)'
                || trim((string) $type->purpose) === ''
                || trim((string) $type->legal_basis) === ''
                || ! $type->active
                || ! is_numeric($type->version)
                || (int) $type->version < 1) {
                throw new InvalidArgumentException('The exact synthetic tracking Client, purpose, recorder and decision are required.');
            }

            $existing = ClientConsent::withTrashed()
                ->where('client_id', $client->id)
                ->where('consent_type_id', $type->id)
                ->lockForUpdate()
                ->get();
            $consent = $existing->first();
            if ($existing->count() > 1
                || ($consent && ($consent->trashed()
                    || $consent->source_consent_request_id !== null
                    || $consent->consent_request_id !== null
                    || $consent->authority_scope_id !== null
                    || $consent->capacity_evidence_consent_id !== null
                    || ! in_array($consent->decision_basis, [null, ClientConsent::BASIS_SELF], true)))) {
                throw new InvalidArgumentException('Existing tracking decision provenance cannot be replaced by this fixture.');
            }

            $version = ConsentTypeVersion::query()->firstOrCreate([
                'consent_type_id' => $type->id,
                'version' => (int) $type->version,
            ], [
                'description' => $type->description,
                'purpose' => $type->purpose,
                'legal_basis' => $type->legal_basis,
                'changes_summary' => ['source' => self::MARKER],
                'effective_from' => now()->subDay(),
                'created_by' => $recorder->id,
            ]);
            if ($version->purpose !== $type->purpose || $version->legal_basis !== $type->legal_basis) {
                throw new InvalidArgumentException('The current tracking consent version must match its recorded purpose.');
            }

            $consent ??= new ClientConsent;
            $givenAt = Carbon::parse($consent->given_at ?? now()->subDay())->startOfSecond();
            if ($givenAt->isFuture()) {
                throw new InvalidArgumentException('A future tracking self-decision cannot be repaired by this fixture.');
            }
            $expiresAt = Carbon::parse($consent->expires_at?->isFuture()
                ? $consent->expires_at : now()->addMonth())->startOfSecond();
            $withdrawnAt = $status === 'withdrawn'
                ? Carbon::parse($consent->withdrawn_at ?? now()->subHour())->startOfSecond()
                : null;
            if ($withdrawnAt && ($withdrawnAt->isFuture() || $withdrawnAt->lessThan($givenAt))) {
                throw new InvalidArgumentException('Tracking withdrawal must follow its recorded self-decision.');
            }
            $recordedAt = $consent->decision_evidence['recorded_at'] ?? now()->startOfSecond()->toISOString();
            $originalEvidence = $consent->decision_evidence;

            $consent->forceFill([
                'client_id' => $client->id,
                'site_id' => $client->site_id,
                'consent_type_id' => $type->id,
                'consent_type_version_id' => $version->id,
                'decision_state' => ClientConsent::DECISION_AUTHORITATIVE,
                'decision_basis' => ClientConsent::BASIS_SELF,
                'decision_client_id' => $client->id,
                'decision_actor_user_id' => $client->user_id,
                'decision_purpose' => $version->purpose,
                'decision_contract_version' => ConsentRequest::DECISION_CONTRACT_VERSION,
                'decision_evidence' => [
                    'source' => 'operations_manual',
                    'identity_source' => 'canonical_client_record',
                    'client_id' => $client->id,
                    'site_id' => $client->site_id,
                    'consent_type_id' => $type->id,
                    'consent_type_version_id' => $version->id,
                    'consent_type_purpose' => $version->purpose,
                    'decision_client_id' => $client->id,
                    'decision_actor_user_id' => $client->user_id,
                    'decision_actor_kind' => 'identified_client_self',
                    'authority_basis' => ClientConsent::BASIS_SELF,
                    'recorder_user_id' => $recorder->id,
                    'assertion_method' => 'written',
                    'decision_at' => $givenAt->toISOString(),
                    'decision_expires_at' => $expiresAt->toISOString(),
                    'recorded_at' => $recordedAt,
                    'fixture' => self::MARKER,
                ],
                'gate_satisfying' => true,
                'governance_review_reason' => null,
                'status' => $status,
                'given_at' => $givenAt,
                'given_by_user_id' => $recorder->id,
                'given_by_relationship' => 'self',
                'given_method' => 'written',
                'given_notes' => self::MARKER,
                'withdrawn_at' => $withdrawnAt,
                'withdrawn_by_user_id' => $withdrawnAt ? $recorder->id : null,
                'expires_at' => $expiresAt,
                'created_by' => $consent->created_by ?? $recorder->id,
                'updated_by' => $recorder->id,
            ]);
            // MySQL orders JSON object keys on storage. Preserve that order for
            // identical evidence so Eloquent does not audit a no-op replay.
            if (is_array($originalEvidence)
                && Arr::sortRecursive($originalEvidence) === Arr::sortRecursive($consent->decision_evidence)) {
                $consent->setAttribute('decision_evidence', $originalEvidence);
            }
            if (! $consent->exists || $consent->isDirty()) {
                $consent->save();
            }

            return $consent;
        }, 3);
    }
}
