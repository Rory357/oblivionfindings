<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\ReferralPermissions;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;

final class ReferralDuplicateClosure
{
    public function __construct(private readonly ReferralAccess $access) {}

    public function preview(User $actor, CareReferral $source, string $reference): array
    {
        $this->access->authorize($actor, $source, 'close');
        $this->access->authorize($actor, $source, 'duplicates.manage');
        $target = $this->target($actor, $source, $reference);

        return $this->summary($target);
    }

    public function record(User $actor, CareReferral $source, array $data, CurrentAuthorizationReads $reads): void
    {
        $this->access->authorize($actor, $source, 'duplicates.manage', $reads);
        $target = $this->target($actor, $source, $data['duplicate_reference'], $reads);
        abort_unless($target->lock_version === (int) $data['duplicate_version'], 409, 'The original referral changed. Review it again before confirming this duplicate.');
        $source->intake = [...$source->intake, 'duplicate_closures' => [...($source->intake['duplicate_closures'] ?? []), [
            'target_id' => $target->id, 'target_version' => $target->lock_version, 'closure_version' => $source->lock_version + 1,
            'reviewed_by' => $actor->id, 'closed_at' => now()->toISOString(), 'reopened_at' => null, 'basis' => $data['duplicate_basis'],
        ]]];
    }

    private function target(User $actor, CareReferral $source, string $reference, ?CurrentAuthorizationReads $reads = null): CareReferral
    {
        $target = $this->access->query($actor, $reads)->where('reference', $reference)->first();
        abort_unless($target, 404, 'Original referral unavailable.');
        abort_if($source->id === $target->id, 422, 'Choose a different original referral.');
        abort_if($target->stage === 'closed', 422, 'Choose an open or handed-over original referral. Reopen a closed original before relying on it.');
        abort_unless($target->owner_id, 422, 'Assign an accountable owner to the original referral first.');
        $owner = $reads ? app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($target->owner_id, array_column(ReferralPermissions::definitions(), 'key')) : $target->owner;
        abort_unless($owner && $this->access->eligibleForSite($owner, $target->site_id, $reads) && (! $target->restricted || $this->access->can($owner, 'restricted')), 422, 'The original referral needs a current eligible owner.');

        return $target;
    }

    public function present(User $actor, CareReferral $source): array
    {
        $history = $source->intake['duplicate_closures'] ?? [];
        $targets = $this->access->can($actor, 'duplicates.manage')
            ? $this->access->query($actor)->whereIn('id', array_column($history, 'target_id'))->with('owner:id,name')->get()->keyBy('id') : collect();

        return array_map(function ($entry) use ($targets) {
            $target = $targets->get($entry['target_id']);

            return ['closed_at' => $entry['closed_at'], 'reopened_at' => $entry['reopened_at'], 'accessible' => (bool) $target,
                ...($target ? ['target' => $this->summary($target), 'basis' => $entry['basis']] : [])];
        }, $history);
    }

    private function summary(CareReferral $target): array
    {
        $name = trim(($target->identity['first_name'] ?? '').' '.($target->identity['last_name'] ?? ''));

        return ['reference' => $target->reference, 'version' => $target->lock_version, 'name' => $name ?: 'Process enquiry', 'stage' => $target->stage, 'owner_name' => $target->owner?->name];
    }

    public function reopened(CareReferral $source): void
    {
        if ($source->closure_reason !== 'duplicate') {
            return;
        }
        $history = $source->intake['duplicate_closures'] ?? [];
        foreach ($history as &$entry) {
            if (! $entry['reopened_at']) {
                $entry['reopened_at'] = now()->toISOString();
            }
        }
        $source->intake = [...$source->intake, 'duplicate_closures' => $history];
    }
}
