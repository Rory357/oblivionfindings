<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\ReferralPermissions;
use App\Models\Site;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Validation\ValidationException;

/** Published website routing chooses ownership; it never grants access. */
final class ReferralRouting
{
    public function validateCandidates(array $configuration): void
    {
        $routing = $configuration['routing'] ?? [];
        if (! ($routing['enabled'] ?? false)) {
            return;
        }
        $groups = [['owner_ids' => $routing['fallback_owner_ids'], 'site_id' => null, 'key' => 'fallback_owner_ids']];
        foreach ($routing['rules'] as $index => $rule) {
            if ($rule['enabled']) {
                foreach ($rule['pathway_ids'] ?? [] as $id) {
                    $pathway = collect($configuration['pathways'])->firstWhere('id', $id);
                    if (! $pathway || ! $pathway['enabled']) {
                        throw ValidationException::withMessages(['configuration.routing.rules.'.$index.'.pathway_ids' => 'An enabled routing rule must use available funding pathways.']);
                    }
                }
                $groups[] = [...$rule, 'key' => 'rules.'.$index.'.owner_ids'];
            }
        }
        foreach ($groups as $group) {
            $site = $group['site_id'];
            $valid = $site === null || Site::query()->whereKey($site)->where('is_active', true)->where('archived', false)->exists();
            foreach ($group['owner_ids'] as $id) {
                $user = User::find($id);
                $valid = $valid && $user && app(ReferralAccess::class)->eligibleForSite($user, $site);
                if ($user && (! empty($group['funding_situations']) || ! empty($group['pathway_ids']))) {
                    $valid = $valid && app(ReferralAccess::class)->can($user, 'funding.view');
                }
            }
            if (! $valid || $group['owner_ids'] === []) {
                throw ValidationException::withMessages(['configuration.routing.'.$group['key'] => 'Choose current eligible intake staff for the owning site. The fallback needs staff with central-intake access.']);
            }
        }
    }

    public function choose(array $configuration, string $service, ?string $region, ?CurrentAuthorizationReads $reads = null, ?string $fundingSituation = null, ?string $pathwayId = null): array
    {
        $fallback = ['rule_id' => null, 'team' => $configuration['intake']['team'], 'site_id' => null, 'owner_id' => null];
        $routing = $configuration['routing'] ?? [];
        if (! ($routing['enabled'] ?? false)) {
            return [...$fallback, 'reason' => 'Automatic routing is disabled; central intake will assign the enquiry.'];
        }
        $attempts = [];
        foreach ($routing['rules'] as $rule) {
            if (! $rule['enabled'] || ! self::matches($rule, $service, $region, $fundingSituation, $pathwayId)) {
                continue;
            }
            $owner = $this->owner($rule['owner_ids'], $rule['site_id'], $rule['max_open'], $reads, ! empty($rule['funding_situations']) || ! empty($rule['pathway_ids']));
            if ($owner) {
                return ['rule_id' => $rule['id'], 'team' => $rule['team'], 'site_id' => $rule['site_id'], 'owner_id' => $owner,
                    'reason' => 'Matched '.$rule['label'].'. Selected an eligible available owner below the workload limit.', 'skipped' => $attempts];
            }
            $attempts[] = $rule['label'].': no eligible available owner below the workload limit.';
        }
        $owner = $this->owner($routing['fallback_owner_ids'], null, $routing['fallback_max_open'], $reads);

        return [...$fallback, 'owner_id' => $owner, 'skipped' => $attempts, 'reason' => $owner
            ? 'Used the central-intake fallback; no earlier rule could assign this enquiry.'
            : 'No eligible available owner has capacity. Kept in the central-intake queue for manual assignment.'];
    }

    public static function matches(array $rule, string $service, ?string $region, ?string $fundingSituation = null, ?string $pathwayId = null): bool
    {
        $region = mb_strtolower(trim($region ?? ''));

        return ($rule['service_ids'] === [] || in_array($service, $rule['service_ids'], true))
            && ($rule['regions'] === [] || in_array($region, array_map(fn ($value) => mb_strtolower(trim($value)), $rule['regions']), true))
            && (empty($rule['funding_situations']) || in_array($fundingSituation, $rule['funding_situations'], true))
            && (empty($rule['pathway_ids']) || in_array($pathwayId, $rule['pathway_ids'], true));
    }

    private function owner(array $ids, ?int $site, int $limit, ?CurrentAuthorizationReads $reads, bool $funding = false): ?int
    {
        if ($site !== null) {
            $siteQuery = Site::query()->whereKey($site)->where('is_active', true)->where('archived', false);
            if (! ($reads ? $reads->query($siteQuery) : $siteQuery)->exists()) {
                return null;
            }
        }
        $eligible = [];
        sort($ids);
        foreach ($ids as $id) {
            $user = User::find($id);
            if (! $user) {
                continue;
            }
            if ($reads) {
                $user = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($id, array_column(ReferralPermissions::definitions(), 'key'));
            }
            if (! app(ReferralAccess::class)->eligibleForSite($user, $site, $reads)) {
                continue;
            }
            if ($funding && ! app(ReferralAccess::class)->can($user, 'funding.view')) {
                continue;
            }
            $leave = HrLeaveRequest::query()->where('user_id', $id)->where('status', 'approved')->where('starts_at', '<=', now())->where('ends_at', '>=', now());
            if (($reads ? $reads->query($leave) : $leave)->exists()) {
                continue;
            }
            $work = CareReferral::query()->where('owner_id', $id)->whereNotIn('stage', ['closed', 'converted']);
            // Current reads are needed after waiting on the configuration mutex.
            $count = $reads ? $reads->query($work)->get(['id'])->count() : $work->count();
            if ($count < $limit) {
                $eligible[] = ['id' => (int) $id, 'count' => $count];
            }
        }
        usort($eligible, fn ($a, $b) => [$a['count'], $a['id']] <=> [$b['count'], $b['id']]);

        return $eligible[0]['id'] ?? null;
    }
}
