<?php

namespace App\Services\Medication\Reporting;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\Site;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Support\Facades\DB;

/** Final buffered-file gate. The caller retains its exact permission and event contract. */
final class MedicationExportReleaseGuard
{
    public function run(User $actor, array $siteIds, array $includedClientIds, callable $authorise, callable $recheck, callable $record): void
    {
        $siteIds = array_values(array_unique(array_map('intval', $siteIds)));
        $includedClientIds = array_values(array_unique(array_map('intval', $includedClientIds)));
        abort_if($siteIds === [], 422, 'Choose an approved house before making an export.');
        DB::transaction(function () use ($actor, $siteIds, $includedClientIds, $authorise, $recheck, $record) {
            $current = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($actor, ['*']);
            CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($current, $siteIds, $includedClientIds, $authorise, $recheck, $record) {
                $sites = $reads->query(Site::query()->whereIn('id', $siteIds)->orderBy('id'))->pluck('id')->all();
                abort_if(array_diff($siteIds, $sites) !== [], 404);
                // Lock the original people even if one moved out during rendering,
                // plus the current population so newly included evidence is stable.
                $people = $reads->query(Client::query()->where(fn ($q) => $q->whereIn('id', $includedClientIds)->orWhereIn('site_id', $siteIds))->orderBy('id'))->pluck('id');
                $reads->query(ClientMedication::withTrashed()->whereIn('client_id', $people)->orderBy('id'))->get(['id', 'client_id', 'controlled_drug']);
                $authorise($current, $reads);
                // Rebuild the complete dataset and compare a canonical digest to
                // the buffered source, including all people and CD classification.
                $recheck($current, $reads);
                // This is the final write. Recorder heads must always be last.
                $record($current);
            });
        }, 5);
    }
}
