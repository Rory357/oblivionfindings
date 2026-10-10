<?php

namespace App\Services\Medication;

use App\Models\Client;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\Gate;

/**
 * Who sees a shift's medication card and its API summary (EA-166): the same
 * per-person rule as the person's record. A reader passes when
 * ClientPolicy::viewMedications allows the person (leads and medication
 * operations at the house, assigned workers, a clocked-in covering shift, an
 * active emergency access). A record-only frontline worker (administer.record
 * without medications.view) passes on the same assignment or covering-shift
 * terms. A relief worker rostered for someone they don't support sees the
 * card once they clock in, not before and not after.
 *
 * Called from one line in ShiftController::show so the workforce lanes'
 * copies of that controller rebase cleanly.
 */
final class ShiftMedicationCardAccess
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly MedicationScopeDecisionService $authority,
    ) {}

    public function canRead(User $viewer, ?Client $client): bool
    {
        if ($client === null || ! $client->exists) {
            return false;
        }
        // Callers often load a slim client (names only); the rule needs the house.
        if (! array_key_exists('site_id', $client->getAttributes())) {
            $client = Client::query()->find($client->getKey());
            if ($client === null) {
                return false;
            }
        }
        if (Gate::forUser($viewer)->allows('viewMedications', $client)) {
            return true;
        }
        if (! $viewer->canDo('medications.administer.record')) {
            return false;
        }

        $atHouse = $client->site_id !== null
            && in_array((int) $client->site_id, $this->siteAccess->accessibleSiteIds($viewer), true);
        if ($atHouse && $client->supportWorkers()->whereKey($viewer->id)->exists()) {
            return true;
        }

        return in_array(
            (int) $client->id,
            $this->authority->clientIdsWithCurrentAuthority($viewer, [(int) $client->id], now()),
            true,
        );
    }
}
