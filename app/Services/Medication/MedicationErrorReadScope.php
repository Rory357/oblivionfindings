<?php

namespace App\Services\Medication;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** One scope before register/Tasks counts, filtering, search, detail and export. */
final class MedicationErrorReadScope
{
    public function __construct(private readonly MedicationGovernanceScopeService $governance, private readonly MarLinkService $links) {}

    public function apply(Builder $query, User $actor, array $siteIds): Builder
    {
        $clientIds = $this->links->openableClientIds($actor, Client::query()->whereIn('site_id', $siteIds)->pluck('id'));
        $this->governance->scopeCanonicalClientMedicationRows($query, $siteIds)->whereIn('client_id', $clientIds);
        if (! $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)) {
            $this->governance->scopeWithoutControlledMedicationRows($query);
        }
        if (! $actor->canDo(MedicationErrorWorkflow::MANAGE) && ! $actor->canDo('medications.audit.view')) {
            $query->where(fn ($q) => $q->where('reported_by', $actor->id)
                ->orWhereHas('entries', fn ($entries) => $entries->where('kind', 'account')->where('actor_id', $actor->id)));
        }

        return $query;
    }
}
