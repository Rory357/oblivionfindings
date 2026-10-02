<?php

namespace App\Services\Medication\DoseSlots;

use App\Models\Client;
use App\Models\User;
use App\Services\Medication\MarLinkService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationScopeDecisionService;
use App\Services\UserSiteAccessService;

/**
 * Who a dose-slot read is for (P01 foundation C4).
 *
 * - People: the reader's Sites narrowed by the P02 person rule
 *   (ClientPolicy::viewMedications via MarLinkService), so no number counts a
 *   person the reader can't open ("no hidden-row counts").
 * - Controlled medicines (P09 Q6): totals include them for every reader;
 *   a row that names one needs medications.controlled.view.
 */
final class DoseSlotReaderScope
{
    /**
     * @param  list<int>|null  $clientIds  null = every person (internal use only)
     */
    private function __construct(
        public readonly ?array $clientIds,
        public readonly bool $canViewControlled,
    ) {}

    /**
     * An eMAR reader: the Sites the governance scope gives medications.view
     * (optionally one of them), then the people the reader may open.
     */
    public static function forMedicationReader(User $viewer, ?int $siteId = null): self
    {
        $siteIds = app(MedicationGovernanceScopeService::class)->readerSiteIds(
            $viewer,
            MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY,
            $siteId,
        );

        return self::forViewer($viewer, $siteId !== null ? [$siteId] : $siteIds);
    }

    /**
     * The people at these (already authorised) Sites the viewer may open.
     *
     * @param  array<int, int>  $siteIds
     */
    public static function forViewer(User $viewer, array $siteIds): self
    {
        $clientIds = $siteIds === []
            ? []
            : Client::query()->whereIn('site_id', $siteIds)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return new self(
            array_values(app(MarLinkService::class)->openableClientIds($viewer, $clientIds)),
            $viewer->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
        );
    }

    /**
     * A screen's own people (a worker's shift residents, a My Day site) narrowed
     * to the ones the viewer may open — or, for someone who records doses, the
     * people they support (assigned, at a Site they work at) or may record for
     * right now (a clocked-in shift covering them: the recording authority's
     * own rule). Neither needs medications.view.
     *
     * @param  array<int, int>  $clientIds
     */
    public static function forViewerClients(User $viewer, array $clientIds): self
    {
        $people = app(MarLinkService::class)->openableClientIds($viewer, $clientIds);
        if ($viewer->canDo('medications.administer.record') && $clientIds !== []) {
            $siteIds = app(UserSiteAccessService::class)->accessibleSiteIds(
                $viewer,
                MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS,
            );
            $assigned = $siteIds === [] ? [] : Client::query()
                ->whereIn('id', $clientIds)
                ->whereIn('site_id', $siteIds)
                ->whereHas('supportWorkers', fn ($users) => $users->whereKey($viewer->id))
                ->pluck('id')
                ->all();
            $people = array_merge(
                $people,
                $assigned,
                app(MedicationScopeDecisionService::class)->clientIdsWithCurrentAuthority($viewer, $clientIds, now()),
            );
        }

        return new self(
            array_values(array_unique(array_map('intval', $people))),
            $viewer->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
        );
    }

    /**
     * Internal jobs (alerts, rounds, the event log): every person, or the
     * given ones, with controlled medicines named.
     *
     * @param  array<int, int>|null  $clientIds
     */
    public static function internal(?array $clientIds = null): self
    {
        return new self(
            $clientIds === null ? null : array_values(array_map('intval', $clientIds)),
            true,
        );
    }
}
