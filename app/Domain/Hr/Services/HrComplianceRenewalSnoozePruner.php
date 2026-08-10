<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrComplianceRenewalSnooze;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\StaffBackgroundCheck;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Removes renewal snoozes that can no longer suppress a real renewal.
 *
 * The generic entity reference is intentionally not a database foreign key,
 * so lifecycle cleanup is performed by both scheduled renewal sweeps.
 */
class HrComplianceRenewalSnoozePruner
{
    /** @var array<string, class-string<Model>> */
    private const ENTITY_MODELS = [
        HrComplianceRenewalSnooze::TYPE_COMPLIANCE => HrStaffComplianceStatus::class,
        HrComplianceRenewalSnooze::TYPE_VETTING => StaffBackgroundCheck::class,
        HrComplianceRenewalSnooze::TYPE_DRIVER => HrDriverEligibility::class,
    ];

    public function prune(): int
    {
        return DB::transaction(function (): int {
            $deleted = $this->deleteMatching(
                HrComplianceRenewalSnooze::query()->where('snoozed_until', '<=', now()),
            );

            $deleted += $this->deleteMatching(
                HrComplianceRenewalSnooze::query()
                    ->whereNotIn('entity_type', HrComplianceRenewalSnooze::ENTITY_TYPES),
            );

            foreach (self::ENTITY_MODELS as $entityType => $modelClass) {
                $deleted += $this->deleteMatching(
                    HrComplianceRenewalSnooze::query()
                        ->forEntityType($entityType)
                        ->whereNotIn('entity_id', $modelClass::query()->select('id')),
                );
            }

            return $deleted;
        }, 3);
    }

    /** @param Builder<HrComplianceRenewalSnooze> $query */
    private function deleteMatching(Builder $query): int
    {
        $deleted = 0;

        $query->chunkById(200, function ($snoozes) use (&$deleted): void {
            foreach ($snoozes as $snooze) {
                if ($snooze->delete()) {
                    $deleted++;
                }
            }
        });

        return $deleted;
    }
}
