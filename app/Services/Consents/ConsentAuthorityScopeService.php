<?php

namespace App\Services\Consents;

use App\Domain\SecurityDevices\Services\PersonalTrackingPrivacyService;
use App\Models\ClientConsent;
use App\Models\ConsentAuthorityScope;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ConsentAuthorityScopeService
{
    public function __construct(
        private readonly PersonalTrackingPrivacyService $trackingPrivacy,
    ) {}

    public function revoke(ConsentAuthorityScope $scope, User $actor, string $reason): void
    {
        DB::transaction(function () use ($scope, $actor, $reason): void {
            $locked = ConsentAuthorityScope::query()
                ->lockForUpdate()
                ->findOrFail($scope->id);

            if ($locked->revoked_at !== null) {
                if ($locked->revoked_by_user_id === $actor->id
                    && hash_equals((string) $locked->revocation_reason, $reason)) {
                    return;
                }

                throw new ConflictHttpException('This authority scope has already been revoked with different details.');
            }

            $locked->update([
                'revoked_at' => now(),
                'revoked_by_user_id' => $actor->id,
                'revocation_reason' => $reason,
            ]);

            AuditLogger::logOrFail('consent.authority_scope.revoked', $locked, [
                'actor_id' => $actor->id,
                'authority_scope_id' => $locked->id,
                'client_id' => $locked->client_id,
                'site_id' => $locked->site_id,
                'representative_user_id' => $locked->representative_user_id,
                'consent_type_id' => $locked->consent_type_id,
                'authority_type' => $locked->authority_type,
                'authority_scope_version' => $locked->version,
                'prior_state' => 'verified_current',
                'new_state' => 'revoked',
                'reason' => $reason,
                'revoked_at' => $locked->revoked_at?->toISOString(),
            ]);

            ClientConsent::query()
                ->where('authority_scope_id', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->each(function (ClientConsent $consent) use ($actor): void {
                    if ($consent->status === 'revoked' && ! $consent->gate_satisfying) {
                        return;
                    }

                    $consent->update([
                        'status' => 'revoked',
                        'gate_satisfying' => false,
                        'governance_review_reason' => 'decision_authority_revoked',
                        'updated_by' => $actor->id,
                    ]);
                    $this->trackingPrivacy->stopForConsent($consent, $actor->id);
                });
        });
    }
}
