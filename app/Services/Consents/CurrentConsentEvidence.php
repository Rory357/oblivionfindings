<?php

namespace App\Services\Consents;

use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentAuthorityScope;
use App\Models\ConsentRequest;
use App\Models\ConsentType;
use App\Models\ConsentTypeVersion;
use App\Models\NextOfKin;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Current graph for a command claim; never substitutes a caller-supplied model. */
final readonly class CurrentConsentEvidence
{
    private function __construct(public ClientConsent $consent) {}

    public static function lock(int $consentId): self
    {
        self::assertTransaction();
        // Discovery establishes no authority. Every referenced row below is a
        // current read; changed pointers fail before acquiring a different graph.
        $hint = ClientConsent::query()->findOrFail($consentId);
        $scopeHint = $hint->authority_scope_id === null ? null : ConsentAuthorityScope::query()->find($hint->authority_scope_id);
        // NOWAIT deliberately avoids adding wait edges to the existing source
        // request / scope-revocation / consent-withdrawal writer lock chains.
        $authority = $scopeHint?->next_of_kin_id === null ? null
            : NextOfKin::query()->whereKey($scopeHint->next_of_kin_id)->lock('for share nowait')->first();
        $scope = $hint->authority_scope_id === null ? null
            : ConsentAuthorityScope::query()->whereKey($hint->authority_scope_id)->lock('for share nowait')->first();
        if ($scope && ((int) $scope->next_of_kin_id !== (int) $scopeHint?->next_of_kin_id
            || (int) $scope->capacity_evidence_consent_id !== (int) $scopeHint?->capacity_evidence_consent_id)) {
            throw new ConflictHttpException('Consent authority changed. Reload and try again.');
        }
        $consents = ClientConsent::query()->whereKey(array_values(array_unique(array_filter([
            $consentId, $hint->capacity_evidence_consent_id, $scope?->capacity_evidence_consent_id,
        ]))))->orderBy('id')->lock('for share nowait')->get()->keyBy('id');
        $consent = $consents->get($consentId);
        if (! $consent || (int) $consent->authority_scope_id !== (int) $hint->authority_scope_id
            || (int) $consent->capacity_evidence_consent_id !== (int) $hint->capacity_evidence_consent_id) {
            throw new ConflictHttpException('Consent evidence changed. Reload and try again.');
        }
        $consent->setRelation('consentType', ConsentType::query()->whereKey($consent->consent_type_id)->lock('for share nowait')->first());
        $consent->setRelation('consentTypeVersion', ConsentTypeVersion::query()->whereKey($consent->consent_type_version_id)->lock('for share nowait')->first());
        $consent->setRelation('sourceConsentRequest', $consent->source_consent_request_id === null ? null
            : ConsentRequest::query()->whereKey($consent->source_consent_request_id)->lock('for share nowait')->first());
        if ($scope) {
            $scope->setRelation('nextOfKin', $authority);
            $scope->setRelation('capacityEvidenceConsent', $consents->get($scope->capacity_evidence_consent_id));
        }
        $consent->setRelation('authorityScope', $scope);

        return new self($consent);
    }

    public function client(Client|int|null $expected = null): ?Client
    {
        self::assertTransaction();
        $clientId = $expected instanceof Client ? $expected->id : ($expected ?? $this->consent->client_id);

        return Client::query()->whereKey($clientId)->lock('for share nowait')->first();
    }

    public static function assertTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Current consent evidence requires an active transaction.');
        }
    }
}
