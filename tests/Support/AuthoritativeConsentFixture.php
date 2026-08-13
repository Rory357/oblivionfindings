<?php

namespace Tests\Support;

use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentRequest;
use App\Models\ConsentType;
use App\Models\ConsentTypeVersion;
use App\Models\User;
use Carbon\Carbon;

final class AuthoritativeConsentFixture
{
    /** @param array<string, mixed> $attributes */
    public static function manualSelf(
        Client $client,
        ConsentType $type,
        User $recorder,
        array $attributes = [],
    ): ClientConsent {
        if (! is_numeric($type->version) || (int) $type->version < 1) {
            $type->forceFill(['version' => 1])->save();
        }

        $version = ConsentTypeVersion::query()->firstOrCreate(
            [
                'consent_type_id' => $type->id,
                'version' => (int) $type->version,
            ],
            [
                'description' => $type->description,
                'purpose' => $type->purpose,
                'legal_basis' => $type->legal_basis,
                'changes_summary' => ['source' => 'test_authoritative_self_fixture'],
                'effective_from' => now()->subDay(),
                'created_by' => $recorder->id,
            ],
        );

        $record = array_merge([
            'status' => 'given',
            'given_at' => now()->subMinute(),
            'expires_at' => now()->addMonth(),
            'created_by' => $recorder->id,
            'updated_by' => $recorder->id,
        ], $attributes);
        $decisionAt = Carbon::parse($record['given_at'])->startOfSecond();
        $decisionExpiresAt = isset($record['expires_at'])
            ? Carbon::parse($record['expires_at'])->startOfSecond()
            : null;

        return ClientConsent::query()->create(array_merge($record, [
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
                'decision_actor_kind' => 'identified_client_self',
                'decision_client_id' => $client->id,
                'decision_actor_user_id' => $client->user_id,
                'authority_basis' => ClientConsent::BASIS_SELF,
                'recorder_user_id' => $recorder->id,
                'decision_at' => $decisionAt->toISOString(),
                'decision_expires_at' => $decisionExpiresAt?->toISOString(),
                'test_fixture' => true,
            ],
            'gate_satisfying' => true,
            'given_at' => $decisionAt,
            'expires_at' => $decisionExpiresAt,
            'given_by_user_id' => $recorder->id,
            'given_by_relationship' => 'self',
            'given_method' => 'written',
        ]));
    }
}
