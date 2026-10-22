<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\ReferralPermissions;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Shared transaction boundary for staff mutations; callbacks are internal domain operations. */
final class ReferralCommand
{
    public function run(User $actor, int $referralId, int $expectedVersion, string $action, callable $mutation, array $additionalPermissions = [], ?callable $replay = null): mixed
    {
        try {
            return DB::transaction(function () use ($actor, $referralId, $expectedVersion, $action, $mutation, $additionalPermissions, $replay): mixed {
                // Serialize record changes before checking the current RBAC evidence.
                $referral = CareReferral::query()->lockForUpdate()->findOrFail($referralId);
                $actor = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting(
                    $actor, array_values(array_unique([...array_column(ReferralPermissions::definitions(), 'key'), ...$additionalPermissions])),
                );
                abort_unless($actor->approved_at, 403);

                return CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actor, $referral, $expectedVersion, $action, $mutation, $replay): mixed {
                    app(ReferralAccess::class)->authorize($actor, $referral, $action, $reads);
                    if ($replay && ($result = $replay($referral, $actor, $reads)) !== null) {
                        return $result;
                    }
                    abort_unless($referral->lock_version === $expectedVersion, 409, 'Someone has updated this referral. Review their changes before saving again.');
                    $result = $mutation($referral, $actor, $reads);
                    $referral->lock_version++;
                    $referral->save();

                    return $result;
                });
            }, 3);
        } catch (QueryException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213, 3572], true)) {
                abort(409, 'This referral or its access permissions are being updated. Please try again.');
            }
            throw $exception;
        }
    }

    public function record(CareReferral $referral, User $actor, string $kind, string $audience, array $payload): ReferralEvent
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Referral audit events must be committed with the action they describe.');
        }

        return ReferralEvent::query()->create([
            'referral_id' => $referral->id, 'actor_id' => $actor->id, 'kind' => $kind,
            'audience' => $audience, 'payload' => $payload, 'occurred_at' => now(),
        ]);
    }
}
