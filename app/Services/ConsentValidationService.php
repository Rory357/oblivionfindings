<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentAuthorityScope;
use App\Models\ConsentRequest;
use App\Models\ConsentType;
use App\Models\ConsentTypeVersion;
use App\Services\Consents\ConsentConsumabilityDecision;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Throwable;

class ConsentValidationService
{
    private const TRACKING_CONSENT_TYPE_NAMES = [
        'Personal Tracker (Wandering Risk)',
        'Asset Location Tracking (Safety)',
        'Fleet Tracking',
    ];

    private const RESIDENT_LOCATION_CONSENT_TYPE_NAMES = [
        'Personal Tracker (Wandering Risk)',
    ];

    public static function isConsumable(
        ClientConsent $consent,
        Client|int|null $client = null,
        ?int $expectedConsentTypeId = null,
        ?string $expectedPurpose = null,
    ): bool {
        return self::consumabilityDecision(
            $consent,
            $client,
            $expectedConsentTypeId,
            $expectedPurpose,
        )->allowed;
    }

    public static function consumabilityDecision(
        ClientConsent $consent,
        Client|int|null $client = null,
        ?int $expectedConsentTypeId = null,
        ?string $expectedPurpose = null,
    ): ConsentConsumabilityDecision {
        $consent = self::resolveConsent($consent);
        if (! $consent) {
            return ConsentConsumabilityDecision::deny('missing_or_deleted_consent');
        }

        $client = self::resolveClient($consent, $client);

        if (! $client || (int) $consent->client_id !== (int) $client->id) {
            return ConsentConsumabilityDecision::deny('wrong_client');
        }

        $consent->loadMissing([
            'consentType',
            'consentTypeVersion',
            'sourceConsentRequest',
            'authorityScope.nextOfKin',
            'authorityScope.capacityEvidenceConsent',
        ]);
        $type = $consent->consentType;
        $version = $consent->consentTypeVersion;

        if (! is_numeric($client->site_id)
            || ! is_numeric($consent->site_id)
            || (int) $consent->site_id !== (int) $client->site_id) {
            return ConsentConsumabilityDecision::deny('wrong_or_stale_site');
        }

        if ((int) $consent->decision_client_id !== (int) $client->id) {
            return ConsentConsumabilityDecision::deny('wrong_decision_person');
        }

        if ($consent->decision_state !== ClientConsent::DECISION_AUTHORITATIVE
            || ! $consent->gate_satisfying) {
            return ConsentConsumabilityDecision::deny(
                $consent->decision_state === ClientConsent::DECISION_INFORMATIONAL
                    ? 'informational_acknowledgement'
                    : 'governance_review_required',
            );
        }

        if ($consent->status !== 'given'
            || $consent->withdrawn_at !== null
            || $consent->superseded_by_consent_id !== null
            || $consent->given_at === null
            || $consent->given_at->isFuture()
            || ($consent->expires_at !== null && ! $consent->expires_at->isFuture())) {
            return ConsentConsumabilityDecision::deny('inactive_or_expired');
        }

        if (! $type instanceof ConsentType || ! $type->active) {
            return ConsentConsumabilityDecision::deny('inactive_or_missing_type');
        }

        if ($expectedConsentTypeId !== null && (int) $type->id !== $expectedConsentTypeId) {
            return ConsentConsumabilityDecision::deny('wrong_consent_type');
        }

        if (! $version instanceof ConsentTypeVersion
            || (int) $version->consent_type_id !== (int) $type->id
            || trim((string) $consent->decision_purpose) === ''
            || ! hash_equals(self::normalisePurpose($version->purpose), self::normalisePurpose($consent->decision_purpose))) {
            return ConsentConsumabilityDecision::deny('unbound_type_version_or_purpose');
        }

        if ($expectedPurpose !== null
            && ! hash_equals(self::normalisePurpose($expectedPurpose), self::normalisePurpose($consent->decision_purpose))) {
            return ConsentConsumabilityDecision::deny('wrong_purpose');
        }

        if ((int) $consent->decision_contract_version !== ConsentRequest::DECISION_CONTRACT_VERSION) {
            return ConsentConsumabilityDecision::deny('unsupported_decision_contract');
        }

        $sourceDecision = self::sourceDecisionMatches($consent, $client, $type, $version);
        if (! $sourceDecision->allowed) {
            return $sourceDecision;
        }

        return match ($consent->decision_basis) {
            ClientConsent::BASIS_SELF => self::selfDecision($consent, $client),
            ClientConsent::BASIS_SUBSTITUTE => self::substituteDecision($consent, $client, $type),
            default => ConsentConsumabilityDecision::deny('unsupported_authority_basis'),
        };
    }

    public static function hasValidConsent(Client $client, string $consentTypeName): bool
    {
        return self::candidateQuery($client)
            ->whereHas('consentType', fn ($query) => $query->where('name', $consentTypeName))
            ->get()
            ->contains(fn (ClientConsent $consent): bool => self::isConsumable(
                $consent,
                $client,
                $consent->consent_type_id,
                $consent->consentTypeVersion?->purpose,
            ));
    }

    public static function hasValidConsentById(Client $client, int $consentTypeId): bool
    {
        return self::candidateQuery($client)
            ->where('consent_type_id', $consentTypeId)
            ->get()
            ->contains(fn (ClientConsent $consent): bool => self::isConsumable(
                $consent,
                $client,
                $consentTypeId,
                $consent->consentTypeVersion?->purpose,
            ));
    }

    public static function getActiveConsents(Client $client): Collection
    {
        return self::candidateQuery($client)
            ->latest('given_at')
            ->latest('id')
            ->get()
            ->filter(fn (ClientConsent $consent): bool => self::isConsumable(
                $consent,
                $client,
                $consent->consent_type_id,
                $consent->consentTypeVersion?->purpose,
            ))
            ->values();
    }

    public static function latestValidTrackingConsentForClient(Client $client): ?ClientConsent
    {
        $candidates = self::candidateQuery($client)
            ->whereHas('consentType', function ($query): void {
                $query->whereIn('name', self::TRACKING_CONSENT_TYPE_NAMES)
                    ->orWhere('name', 'like', '%Tracking%')
                    ->orWhere('name', 'like', '%Tracker%')
                    ->orWhere('name', 'like', '%Location%');
            })
            ->latest('given_at')
            ->latest('id')
            ->get();

        return $candidates->first(
            fn (ClientConsent $consent): bool => self::isValidTrackingConsent($consent, $client),
        );
    }

    public static function isTrackingConsent(ClientConsent $consent): bool
    {
        $current = self::resolveConsent($consent);
        $name = Str::lower((string) $current?->consentType?->name);

        return Str::contains($name, ['tracking', 'tracker', 'location']);
    }

    public static function isValidTrackingConsent(
        ClientConsent $consent,
        Client|int|null $client = null,
    ): bool {
        $current = self::resolveConsent($consent);
        if (! $current) {
            return false;
        }

        return self::isTrackingConsent($current)
            && self::isConsumable(
                $current,
                $client,
                $current->consent_type_id,
                $current->consentTypeVersion?->purpose,
            );
    }

    public static function isValidResidentLocationConsent(
        ClientConsent $consent,
        Client|int|null $client = null,
    ): bool {
        $current = self::resolveConsent($consent);
        if (! $current) {
            return false;
        }

        return in_array((string) $current->consentType?->name, self::RESIDENT_LOCATION_CONSENT_TYPE_NAMES, true)
            && self::isConsumable(
                $current,
                $client,
                $current->consent_type_id,
                $current->consentTypeVersion?->purpose,
            );
    }

    public static function latestValidResidentLocationConsentForClient(Client $client): ?ClientConsent
    {
        return self::candidateQuery($client)
            ->whereHas('consentType', fn ($query) => $query
                ->whereIn('name', self::RESIDENT_LOCATION_CONSENT_TYPE_NAMES)
                ->where('active', true))
            ->latest('given_at')
            ->latest('id')
            ->get()
            ->first(fn (ClientConsent $consent): bool => self::isValidResidentLocationConsent($consent, $client));
    }

    public static function getExpiredConsents(Client $client): Collection
    {
        return ClientConsent::query()
            ->where('client_id', $client->id)
            ->where('status', 'given')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with('consentType')
            ->get();
    }

    public static function getExpiringConsents(Client $client, int $days = 30): Collection
    {
        return self::candidateQuery($client)
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays($days)])
            ->get()
            ->filter(fn (ClientConsent $consent): bool => self::isConsumable(
                $consent,
                $client,
                $consent->consent_type_id,
                $consent->consentTypeVersion?->purpose,
            ))
            ->values();
    }

    public static function hasAllMandatoryConsents(Client $client): bool
    {
        $mandatoryTypeIds = ConsentType::query()
            ->where('is_mandatory', true)
            ->where('active', true)
            ->pluck('id');

        if ($mandatoryTypeIds->isEmpty()) {
            return true;
        }

        return $mandatoryTypeIds->diff(self::getActiveConsents($client)->pluck('consent_type_id'))->isEmpty();
    }

    public static function getMissingMandatoryConsents(Client $client): Collection
    {
        return ConsentType::query()
            ->where('is_mandatory', true)
            ->where('active', true)
            ->whereNotIn('id', self::getActiveConsents($client)->pluck('consent_type_id'))
            ->get();
    }

    private static function sourceDecisionMatches(
        ClientConsent $consent,
        Client $client,
        ConsentType $type,
        ConsentTypeVersion $version,
    ): ConsentConsumabilityDecision {
        $evidence = $consent->decision_evidence;
        if (! is_array($evidence)
            || (int) ($evidence['client_id'] ?? 0) !== (int) $client->id
            || (int) ($evidence['decision_client_id'] ?? 0) !== (int) $consent->decision_client_id
            || (int) ($evidence['site_id'] ?? 0) !== (int) $client->site_id
            || (int) ($evidence['consent_type_id'] ?? 0) !== (int) $type->id
            || (int) ($evidence['consent_type_version_id'] ?? 0) !== (int) $version->id
            || ! hash_equals(
                self::normalisePurpose($evidence['consent_type_purpose'] ?? null),
                self::normalisePurpose($consent->decision_purpose),
            )
            || ($evidence['authority_basis'] ?? null) !== $consent->decision_basis
            || (int) ($evidence['decision_actor_user_id'] ?? 0)
                !== (int) $consent->decision_actor_user_id
            || ! self::timestampMatches($evidence['decision_at'] ?? null, $consent->given_at)
            || ! self::timestampMatches($evidence['decision_expires_at'] ?? null, $consent->expires_at)) {
            return ConsentConsumabilityDecision::deny('decision_evidence_binding_mismatch');
        }

        if ($consent->source_consent_request_id === null) {
            $source = $evidence['source'] ?? null;

            return $source === 'operations_manual'
                ? ConsentConsumabilityDecision::allow()
                : ConsentConsumabilityDecision::deny('unbound_decision_source');
        }

        $request = $consent->sourceConsentRequest;
        if (! $request
            || $request->status !== ConsentRequest::STATUS_APPROVED
            || $request->decision_kind !== ConsentRequest::DECISION_AUTHORITATIVE
            || (int) $request->resulting_consent_id !== (int) $consent->id
            || (int) $request->client_id !== (int) $client->id
            || (int) $request->site_id !== (int) $client->site_id
            || (int) $request->consent_type_id !== (int) $type->id
            || (int) $request->consent_type_version_id !== (int) $version->id
            || (int) $request->decision_contract_version !== ConsentRequest::DECISION_CONTRACT_VERSION
            || (int) ($consent->decision_evidence['consent_request_id'] ?? 0) !== (int) $request->id
            || (int) ($consent->decision_evidence['client_id'] ?? 0) !== (int) $client->id
            || (int) ($consent->decision_evidence['site_id'] ?? 0) !== (int) $client->site_id
            || (int) ($consent->decision_evidence['consent_type_id'] ?? 0) !== (int) $type->id
            || (int) ($consent->decision_evidence['consent_type_version_id'] ?? 0) !== (int) $version->id
            || (int) ($consent->decision_evidence['decision_actor_user_id'] ?? 0)
                !== (int) $consent->decision_actor_user_id
            || ! hash_equals(
                self::normalisePurpose($consent->decision_evidence['request_purpose'] ?? null),
                self::normalisePurpose($request->purpose),
            )) {
            return ConsentConsumabilityDecision::deny('request_binding_mismatch');
        }

        return ConsentConsumabilityDecision::allow();
    }

    private static function selfDecision(
        ClientConsent $consent,
        Client $client,
    ): ConsentConsumabilityDecision {
        if ($consent->authority_scope_id !== null || $consent->capacity_evidence_consent_id !== null) {
            return ConsentConsumabilityDecision::deny('self_decision_has_substitute_authority');
        }

        if ($consent->source_consent_request_id !== null) {
            $request = $consent->sourceConsentRequest;
            if (! $request
                || $request->recipient_relationship !== ConsentRequest::RELATION_SELF
                || (int) $request->recipient_user_id !== (int) $consent->decision_actor_user_id
                || ! is_numeric($client->user_id)
                || (int) $client->user_id !== (int) $consent->decision_actor_user_id
                || (int) $consent->given_by_user_id !== (int) $consent->decision_actor_user_id) {
                return ConsentConsumabilityDecision::deny('wrong_self_decision_actor');
            }
        } else {
            if (($consent->decision_evidence['identity_source'] ?? null) !== 'canonical_client_record'
                || ($consent->decision_evidence['decision_actor_kind'] ?? null) !== 'identified_client_self'
                || (int) ($consent->decision_evidence['decision_client_id'] ?? 0) !== (int) $client->id
                || ($client->user_id !== null
                    ? (int) $consent->decision_actor_user_id !== (int) $client->user_id
                    : $consent->decision_actor_user_id !== null)
                || ! in_array($consent->given_by_relationship, ['self', 'client'], true)
                || ! in_array($consent->given_method, ['written', 'verbal', 'electronic'], true)
                || ! is_numeric($consent->decision_evidence['recorder_user_id'] ?? null)
                || (int) $consent->given_by_user_id
                    !== (int) $consent->decision_evidence['recorder_user_id']) {
                return ConsentConsumabilityDecision::deny('missing_self_identity_evidence');
            }
        }

        return ConsentConsumabilityDecision::allow();
    }

    private static function substituteDecision(
        ClientConsent $consent,
        Client $client,
        ConsentType $type,
    ): ConsentConsumabilityDecision {
        $scope = $consent->authorityScope;
        $authority = $scope?->nextOfKin;

        if (! $scope instanceof ConsentAuthorityScope
            || ! $authority
            || ! $scope->isCurrent()
            || ! $scope->authorityEvidenceIsCurrent()
            || ! $authority->hasVerifiedLegalAuthority($scope->authority_type)
            || (int) $scope->next_of_kin_id !== (int) $authority->id
            || (int) $scope->client_id !== (int) $client->id
            || (int) $authority->client_id !== (int) $client->id
            || (int) $scope->site_id !== (int) $client->site_id
            || (int) $scope->representative_user_id !== (int) $authority->user_id
            || (int) $scope->representative_user_id !== (int) $consent->decision_actor_user_id
            || (int) $consent->given_by_user_id !== (int) $consent->decision_actor_user_id
            || (int) $scope->consent_type_id !== (int) $type->id
            || ! hash_equals(
                self::normalisePurpose($scope->purpose),
                self::normalisePurpose($consent->decision_evidence['request_purpose'] ?? null),
            )
            || (int) ($consent->decision_evidence['authority_scope_version'] ?? 0) !== (int) $scope->version
            || (int) ($consent->decision_evidence['authority_next_of_kin_id'] ?? 0) !== (int) $authority->id) {
            return ConsentConsumabilityDecision::deny('stale_or_mismatched_substitute_authority');
        }

        if ($consent->source_consent_request_id !== null) {
            $request = $consent->sourceConsentRequest;
            if (! $request
                || (int) $request->authority_scope_id !== (int) $scope->id
                || (int) $request->authority_next_of_kin_id !== (int) $authority->id
                || $request->recipient_relationship !== $scope->authority_type
                || (int) $request->recipient_user_id !== (int) $scope->representative_user_id) {
                return ConsentConsumabilityDecision::deny('request_authority_binding_mismatch');
            }
        }

        if (! $type->requiresCapacityAssessment()) {
            return ConsentConsumabilityDecision::allow();
        }

        $capacity = $scope->capacityEvidenceConsent;
        if (! $capacity
            || ! $scope->capacityEvidenceIsCurrent()
            || (int) $scope->capacity_evidence_consent_id !== (int) $consent->capacity_evidence_consent_id
            || (int) $capacity->client_id !== (int) $client->id
            || (int) $capacity->site_id !== (int) $client->site_id
            || (int) $capacity->consent_type_id !== (int) $type->id
            || $capacity->decision_state === ClientConsent::DECISION_INFORMATIONAL
            || $capacity->status !== 'given'
            || ! $capacity->capacity_assessed
            || $capacity->capacity_outcome !== 'lacks_capacity'
            || $capacity->capacity_assessor_id === null
            || $capacity->capacity_assessed_at === null
            || $capacity->capacity_assessed_at->isFuture()
            || $capacity->withdrawn_at !== null
            || ($capacity->expires_at !== null && ! $capacity->expires_at->isFuture())
            || (int) ($consent->decision_evidence['capacity_evidence_consent_id'] ?? 0)
                !== (int) $capacity->id
            || ($consent->decision_evidence['capacity_outcome'] ?? null)
                !== $capacity->capacity_outcome
            || (int) ($consent->decision_evidence['capacity_assessor_user_id'] ?? 0)
                !== (int) $capacity->capacity_assessor_id
            || ! hash_equals(
                (string) ($consent->decision_evidence['capacity_assessed_at'] ?? ''),
                (string) $capacity->capacity_assessed_at?->toISOString(),
            )) {
            return ConsentConsumabilityDecision::deny('missing_or_stale_capacity_evidence');
        }

        return ConsentConsumabilityDecision::allow();
    }

    private static function resolveClient(ClientConsent $consent, Client|int|null $client): ?Client
    {
        $clientId = $client instanceof Client ? $client->id : ($client ?? $consent->client_id);

        return Client::query()->find($clientId, ['id', 'site_id', 'user_id']);
    }

    private static function resolveConsent(ClientConsent $consent): ?ClientConsent
    {
        if (! $consent->exists || ! is_numeric($consent->getKey())) {
            return null;
        }

        return ClientConsent::query()
            ->with([
                'consentType',
                'consentTypeVersion',
                'sourceConsentRequest',
                'authorityScope.nextOfKin',
                'authorityScope.capacityEvidenceConsent',
            ])
            ->find($consent->getKey());
    }

    private static function candidateQuery(Client $client): Builder
    {
        return ClientConsent::query()
            ->where('client_id', $client->id)
            ->where('decision_state', ClientConsent::DECISION_AUTHORITATIVE)
            ->where('gate_satisfying', true)
            ->where('status', 'given')
            ->whereNull('withdrawn_at')
            ->whereNull('superseded_by_consent_id')
            ->where('given_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->with([
                'consentType',
                'consentTypeVersion',
                'sourceConsentRequest',
                'authorityScope.nextOfKin',
                'authorityScope.capacityEvidenceConsent',
            ]);
    }

    private static function normalisePurpose(?string $purpose): string
    {
        return Str::of((string) $purpose)->squish()->lower()->toString();
    }

    private static function timestampMatches(mixed $snapshot, ?CarbonInterface $current): bool
    {
        if ($snapshot === null || $snapshot === '') {
            return $current === null;
        }

        if ($current === null || ! is_string($snapshot)) {
            return false;
        }

        try {
            return Carbon::parse($snapshot)->equalTo($current);
        } catch (Throwable) {
            return false;
        }
    }
}
