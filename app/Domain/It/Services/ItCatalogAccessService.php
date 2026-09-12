<?php

namespace App\Domain\It\Services;

use App\Models\ItCatalogItem;
use App\Models\Site;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Catalogue audiences use the application's existing approved Site boundary. */
final class ItCatalogAccessService
{
    public function __construct(private readonly ItWorkAccessService $workAccess) {}

    /** The caller supplies the immutable published contract, not the draft. */
    public function canDiscover(User $actor, ItCatalogItem $contract): bool
    {
        if (! $actor->isApproved() || (! $actor->canDo('it.request') && ! $actor->canDo('it.manage'))
            || ($contract->internal_only && ! $actor->canDo('it.manage'))) {
            return false;
        }

        return $contract->site_scope === null
            || array_intersect($this->workAccess->approvedSiteIds($actor), $contract->site_scope) !== [];
    }

    public function allowsSite(ItCatalogItem $contract, ?int $siteId): bool
    {
        return $contract->site_scope === null
            || ($siteId !== null && in_array($siteId, array_map('intval', $contract->site_scope), true));
    }

    public function discoveryPayload(User $actor, ItCatalogItem $contract): array
    {
        abort_unless($this->canDiscover($actor, $contract), 404);
        $ids = array_values(array_filter($this->workAccess->approvedSiteIds($actor), fn (int $id) => $this->allowsSite($contract, $id)));
        $sites = Site::query()->whereKey($ids)->get(['id', 'name'])->keyBy('id');

        return [
            ...$contract->discoveryPayload($actor->canDo('it.manage')),
            'site_options' => collect($ids)->filter(fn (int $id) => $sites->has($id))
                ->map(fn (int $id) => ['id' => $id, 'name' => $sites[$id]->name])->values()->all(),
        ];
    }

    /** Validate again during publication, when a Site may have been withdrawn. */
    public function validateSiteScope(User $actor, mixed $scope): ?array
    {
        if ($scope === null) {
            return null;
        }
        if (! is_array($scope) || $scope === [] || count($scope) > 100) {
            throw ValidationException::withMessages(['site_scope' => 'Choose at least one approved Site, or choose all approved Sites.']);
        }
        $ids = [];
        foreach ($scope as $id) {
            if (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id < 1) {
                throw ValidationException::withMessages(['site_scope' => 'Choose valid approved Sites.']);
            }
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        $operational = Site::query()->whereKey($ids)->where('is_active', true)
            ->where('archived', false)->whereNull('archived_at')->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($operational) !== count($ids)
            || (! $actor->canDo('it.organisationWide') && array_diff($ids, $this->workAccess->approvedSiteIds($actor)) !== [])) {
            throw ValidationException::withMessages(['site_scope' => 'One or more Sites are inactive or outside your approved access. Review the Site selection.']);
        }

        return $ids;
    }
}
