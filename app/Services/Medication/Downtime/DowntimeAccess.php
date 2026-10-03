<?php

namespace App\Services\Medication\Downtime;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationDowntime;
use App\Models\MedicationPaperEntry;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\UserSiteAccessService;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class DowntimeAccess
{
    public function __construct(private readonly UserSiteAccessService $sites, private readonly MedicationRecordAccess $records) {}

    public function manages(User $actor): bool
    {
        return $actor->canDo('medications.view')
            && $actor->hasRole('admin', 'provider_manager', 'coordinator', 'clinical_lead', 'team_lead');
    }

    public function siteIds(User $actor, bool $pack = false): array
    {
        abort_unless($pack ? $actor->canDo('medications.reports.export') : ($this->manages($actor) || $actor->canDo('medications.administer.record')), 403);

        return $this->sites->accessibleSiteIds($actor, MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS);
    }

    public function downtime(User $actor, int $id): MedicationDowntime
    {
        $downtime = MedicationDowntime::query()->whereIn('site_id', $this->siteIds($actor))->findOrFail($id);
        // Ordinary staff open only downtimes with their own paper entries.
        abort_unless($this->manages($actor) || $downtime->entries()->where(fn ($q) => $q->where('given_by', $actor->id)->orWhere('witness_id', $actor->id))->exists(), 404);

        return $downtime;
    }

    public function order(User $actor, MedicationDowntime $downtime, int $id): ClientMedication
    {
        $order = ClientMedication::query()->whereHas('client', fn ($q) => $q->where('site_id', $downtime->site_id))->findOrFail($id);
        $this->records->assertReadable($actor, $order->client);
        abort_if($order->controlled_drug && ! $actor->canDo('medications.controlled.view'), 404);

        return $order;
    }

    public function entry(User $actor, MedicationDowntime $downtime, int $id): MedicationPaperEntry
    {
        $entry = $downtime->entries()->findOrFail($id);
        abort_if(($entry->snapshot['controlled'] ?? false) && ! $actor->canDo('medications.controlled.view'), 404);
        $this->order($actor, $downtime, (int) $entry->client_medication_id);
        abort_unless($this->manages($actor) || in_array((int) $actor->id, [(int) $entry->given_by, (int) $entry->witness_id], true), 404);

        return $entry;
    }

    public function clients(User $actor, int $siteId, bool $pack = false): array
    {
        abort_unless(in_array($siteId, $this->siteIds($actor, $pack), true), 404);
        $ids = Client::query()->where('site_id', $siteId)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $pack ? array_values(array_filter($ids, function (int $id) use ($actor): bool {
            try {
                $this->records->assertReportable($actor, Client::query()->find($id));

                return true;
            } catch (HttpExceptionInterface) {
                return false;
            }
        })) : $this->records->readableClientIds($actor, $ids);
    }
}
