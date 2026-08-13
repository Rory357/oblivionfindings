<?php

namespace App\Services\Medication;

use App\Models\MedicationCompetencyExemption;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserSiteAccessService;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MedicationCompetencyExemptionService
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function approve(
        User $subject,
        Site $site,
        User $approver,
        string $reason,
        CarbonInterface $startsAt,
        CarbonInterface $expiresAt,
    ): MedicationCompetencyExemption {
        $reason = trim($reason);
        $this->authorize($approver, $subject, $site);

        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages([
                'reason' => 'Explain the clinical basis for this competency exemption.',
            ]);
        }

        if ($expiresAt->lte($startsAt) || $expiresAt->lte(now())) {
            throw ValidationException::withMessages([
                'expires_at' => 'The competency exemption must have a future expiry.',
            ]);
        }

        return DB::transaction(function () use ($subject, $site, $approver, $reason, $startsAt, $expiresAt) {
            $this->lockUsers([$subject->id, $approver->id]);
            $lockedApprover = User::query()->findOrFail($approver->id);
            $lockedSubject = User::query()->findOrFail($subject->id);
            $this->authorize($lockedApprover, $lockedSubject, $site);

            $exemption = MedicationCompetencyExemption::query()->create([
                'user_id' => $lockedSubject->id,
                'site_id' => $site->id,
                'scope' => MedicationCompetencyExemption::SCOPE_ADMINISTRATION,
                'reason' => $reason,
                'approved_by' => $lockedApprover->id,
                'approved_at' => now(),
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
            ]);

            AuditLogger::logOrFail('medications.competency.exemption.approved', $exemption, [
                'actor_id' => $lockedApprover->id,
                'user_id' => $lockedSubject->id,
                'site_id' => $site->id,
                'scope' => $exemption->scope,
                'starts_at' => $exemption->starts_at?->toIso8601String(),
                'expires_at' => $exemption->expires_at?->toIso8601String(),
            ]);

            return $exemption;
        });
    }

    public function revoke(
        MedicationCompetencyExemption $exemption,
        User $actor,
        string $reason,
    ): MedicationCompetencyExemption {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages([
                'reason' => 'Explain why this competency exemption is being revoked.',
            ]);
        }

        return DB::transaction(function () use ($exemption, $actor, $reason) {
            $this->lockUsers([$exemption->user_id, $actor->id]);
            $locked = MedicationCompetencyExemption::query()
                ->whereKey($exemption->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedActor = User::query()->findOrFail($actor->id);
            $subject = User::query()->findOrFail($locked->user_id);
            $site = Site::query()->findOrFail($locked->site_id);
            $this->authorize($lockedActor, $subject, $site);

            if ($locked->revoked_at === null) {
                $locked->forceFill([
                    'revoked_at' => now(),
                    'revoked_by' => $lockedActor->id,
                    'revocation_reason' => $reason,
                ])->save();

                AuditLogger::logOrFail('medications.competency.exemption.revoked', $locked, [
                    'actor_id' => $lockedActor->id,
                    'user_id' => $subject->id,
                    'site_id' => $site->id,
                    'scope' => $locked->scope,
                ]);
            }

            return $locked;
        });
    }

    private function authorize(User $approver, User $subject, Site $site): void
    {
        if ((int) $approver->id === (int) $subject->id
            || ! $approver->canDo('medications.competency.exempt')
            || ! in_array(
                (int) $site->id,
                $this->siteAccess->accessibleSiteIds($approver, ['sites.viewAll']),
                true,
            )) {
            throw new AuthorizationException('You are not authorized to approve medication competency exemptions for this site.');
        }
    }

    /** @param array<int, int> $userIds */
    private function lockUsers(array $userIds): void
    {
        User::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $userIds))))
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }
}
