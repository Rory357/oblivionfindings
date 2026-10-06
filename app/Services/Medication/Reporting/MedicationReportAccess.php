<?php

namespace App\Services\Medication\Reporting;

use App\Models\Client;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\Gate;

final class MedicationReportAccess
{
    public function financeOnly(User $actor): bool
    {
        return $actor->hasRole('finance') && ! $actor->hasRole('admin', 'provider_manager', 'coordinator', 'clinical_lead', 'team_lead', 'auditor');
    }

    /** @return list<int> */
    public function siteIds(User $actor, ?int $siteId = null, ?int $clientId = null, string $report = 'doses', ?CurrentAuthorizationReads $reads = null): array
    {
        abort_unless($actor->canDo('medications.reports.view'), 403);
        abort_if($this->financeOnly($actor) && ($report !== 'stock' || $clientId !== null), 403);
        abort_if($report === 'controlled' && ! $actor->canDo('medications.controlled.view'), 403);
        if ($reads !== null) {
            $ids = app(UserSiteAccessService::class)->accessibleSiteIds($actor, MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS, $reads);
            abort_if($siteId !== null && ! in_array($siteId, $ids, true), 404);
            if ($siteId !== null) {
                $ids = [$siteId];
            }
        } else {
            $ids = app(MedicationGovernanceScopeService::class)->reportSiteIds($actor, $siteId, $clientId, $report === 'controlled');
        }
        if ($clientId !== null) {
            $query = Client::query()->whereIn('site_id', $ids);
            $client = ($reads ? $reads->query($query) : $query)->find($clientId);
            app(MedicationRecordAccess::class)->assertReportable($actor, $client);
            $ids = [(int) $client->site_id];
        }

        return $ids;
    }

    /** @return list<int> No hidden-person rows or counts. */
    public function clientIds(User $actor, array $siteIds): array
    {
        if ($this->financeOnly($actor)) {
            return [];
        }
        $clients = Client::query()->whereIn('site_id', $siteIds)->get();
        // Repeated release checks cannot use MarLinkService's request memo.
        // Evaluate the canonical person policy against the current model.
        return $clients->filter(fn ($client) => ! $actor->canDo('medications.view') || Gate::forUser($actor)->allows('viewMedications', $client))->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function canExport(User $actor, string $type): bool
    {
        if ($type === 'audit') {
            return ! $this->financeOnly($actor) && $actor->canDo('medications.audit.view') && $actor->canDo('medications.audit.export');
        }
        if ($type === 'stock' && $this->financeOnly($actor)) {
            return $actor->canDo('medications.reports.view');
        }

        return ! $this->financeOnly($actor) && $actor->canDo('medications.reports.view') && $actor->canDo('medications.reports.export')
            && (! in_array($type, ['controlled', 'cd_register'], true) || $actor->canDo('medications.controlled.view'));
    }
}
