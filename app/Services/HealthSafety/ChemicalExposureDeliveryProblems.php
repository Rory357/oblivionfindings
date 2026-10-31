<?php

namespace App\Services\HealthSafety;

use App\Models\ChemicalExposureSourceDelivery;
use App\Models\Site;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Exposures that haven't reached the H&S register (job 3, Stephan 26 Oct): a failed automatic delivery, or one still
 * waiting after 10 minutes. Shown only to exposure managers (hazards.manage plus exposure read) for exposures they can
 * read, on the exposure page and in /tasks; the H&S Overview shows only the count. Ids, Site and state only: no worker,
 * substance or clinical detail.
 */
final class ChemicalExposureDeliveryProblems
{
    public function __construct(private readonly ChemicalExposureAccessService $read) {}

    /** @return list<array{exposure_id: int, site_id: int, site_name: ?string, state: string, since: ?string}> */
    public function rows(User $viewer, ?HealthSafetyReadContext $context = null, ?int $exposureId = null): array
    {
        // Usually nothing is stuck: one indexed existence check, before any read context or exposure query.
        if (! ChemicalExposureSystemDeliveryService::stuckQuery()->when($exposureId !== null, fn ($q) => $q->where('exposure_id', $exposureId))->exists()) { return []; }
        $operation = fn (User $current, CurrentAuthorizationReads $reads, array $sites): array
            => $this->read($current, $reads, $sites, $exposureId);
        try {
            if ($context !== null) {
                $context->assertViewer($viewer);
                return $context->consumeViewer(ChemicalExposureCommandAccess::PERMISSIONS, $operation);
            }
            return HealthSafetyReadContext::within($viewer, ChemicalExposureCommandAccess::PERMISSIONS,
                fn (HealthSafetyReadContext $fresh): array => $fresh->consumeViewer(ChemicalExposureCommandAccess::PERMISSIONS, $operation));
        } catch (HttpExceptionInterface $denied) {
            if (! in_array($denied->getStatusCode(), [403, 404], true)) { throw $denied; }
            return [];
        }
    }

    /** The H&S Overview's count: null when the viewer isn't an exposure manager, so nothing is shown at all. */
    public function countFor(User $viewer, HealthSafetyReadContext $context): ?int
    {
        $manager = $context->consumeSourceViewer(ChemicalExposureCommandAccess::PERMISSIONS,
            fn (User $current): bool => $this->isManager($current));
        return $manager ? count($this->rows($viewer, $context)) : null;
    }

    /** The exposure page's notice for one exposure, or null. */
    public function forExposure(User $viewer, int $exposureId): ?array
    {
        $row = $this->rows($viewer, null, $exposureId)[0] ?? null;
        return $row === null ? null : ['state' => $row['state'], 'since' => $row['since']];
    }

    private function isManager(User $current): bool
    {
        return $current->canDo('hazards.manage') && $this->read->canRead($current);
    }

    private function read(User $current, CurrentAuthorizationReads $reads, array $sites, ?int $exposureId): array
    {
        if (! $this->isManager($current)) { return []; }
        $stuck = ChemicalExposureSystemDeliveryService::stuckQuery();
        $exposures = $this->read->readableQuery($current, $reads, $sites)
            ->whereIn('substance_exposure_records.id', (clone $stuck)->select('exposure_id'))
            ->when($exposureId !== null, fn ($q) => $q->whereKey($exposureId))
            ->orderByDesc('substance_exposure_records.id')->limit(300)->get(['id', 'site_id']);
        if ($exposures->isEmpty()) { return []; }
        $deliveries = $reads->query((clone $stuck)->whereIn('exposure_id', $exposures->pluck('id')->all()))
            ->get(['id', 'exposure_id', 'failed_attempts', 'needs_manager', 'created_at'])->groupBy('exposure_id');
        $siteNames = $reads->query(Site::query())->whereIn('id', $exposures->pluck('site_id')->unique()->all())->pluck('name', 'id');
        $rows = [];
        foreach ($exposures as $exposure) {
            $pending = $deliveries->get($exposure->id);
            if ($pending === null) { continue; }
            $state = $pending->contains(fn (ChemicalExposureSourceDelivery $row) => $row->needs_manager) ? 'needs_manager'
                : ($pending->contains(fn (ChemicalExposureSourceDelivery $row) => $row->failed_attempts > 0) ? 'failed' : 'overdue');
            $rows[] = ['exposure_id' => (int) $exposure->id, 'site_id' => (int) $exposure->site_id,
                'site_name' => $siteNames->get($exposure->site_id), 'state' => $state,
                'since' => $pending->min('created_at')?->toIso8601String()];
        }
        return $rows;
    }
}
