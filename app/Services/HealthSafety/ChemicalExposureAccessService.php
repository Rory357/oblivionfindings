<?php

namespace App\Services\HealthSafety;

use App\Models\HazardousSubstance;
use App\Models\HsEvent;
use App\Models\Site;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Identifiable exposure evidence is private even when the chemical reference is readable. */
final class ChemicalExposureAccessService
{
    public const PERMISSIONS = ['hazards.view', 'hr.wellbeing.view', 'healthSafety.viewAllSites',
        'incidents.viewAny', 'incidents.viewAssigned', 'hr.cases.view', 'clients.viewAny', 'clients.viewAssigned',
        'clinical.accessAllSites', 'sites.viewAll', 'respite.viewAny'];

    public function withCurrentViewer(User $viewer, callable $operation): mixed
    {
        return HealthSafetyReadContext::within($viewer, self::PERMISSIONS,
            fn (HealthSafetyReadContext $context) => $this->withReadContext($context, $operation));
    }

    public function withReadContext(HealthSafetyReadContext $context, callable $operation): mixed
    {
        return $context->consumeViewer(self::PERMISSIONS, function ($current, $reads, $sites) use ($operation) {
            $result = $operation($current, $reads, $sites);
            $reads->assertActive();
            $this->detached($result);

            return $result;
        });
    }

    public function canRead(User $current): bool
    {
        return $current->canDo('hazards.view') && $current->canDo('hr.wellbeing.view');
    }

    public function assertRead(User $current, CurrentAuthorizationReads $reads): void
    {
        $reads->assertActive();
        abort_unless($this->canRead($current), 403);
    }

    public function assertSite(int $id, CurrentAuthorizationReads $reads, array $sites): void
    {
        $reads->assertActive();
        abort_unless($id > 0 && in_array($id, $sites, true), 404);
        abort_unless($reads->query(Site::query())->active()->notArchived()->whereNull('archived_at')->whereKey($id)->exists(), 404);
    }

    public function readableQuery(User $current, CurrentAuthorizationReads $reads, array $sites): Builder
    {
        $this->assertRead($current, $reads);

        // Legacy unknown-Site evidence cannot acquire a Site from today's worker placement.
        return $reads->query(SubstanceExposureRecord::query()->whereIn('site_id', $sites)
            ->whereHas('site', fn (Builder $site) => $site->active()->notArchived()->whereNull('archived_at'))
            ->whereHas('hazardousSubstance')->whereHas('user'));
    }

    public function assertExposure(SubstanceExposureRecord|int $source, User $current, CurrentAuthorizationReads $reads,
        array $sites, ?int $substanceId = null): SubstanceExposureRecord
    {
        $this->assertRead($current, $reads);
        $id = $this->id($source);
        $query = $this->readableQuery($current, $reads, $sites)->when($substanceId !== null,
            fn (Builder $query) => $query->where('hazardous_substance_id', $substanceId))->whereKey($id);
        $identity = (clone $query)->firstOrFail(ChemicalExposureSourceService::BODY_ADMISSION_FIELDS);
        $this->assertSite((int) $identity->site_id, $reads, $sites);
        app(ChemicalExposureSourceService::class)->admitExposureIdentityBeforeBody($identity, $current, $reads, $sites);
        $body = (clone $query)->where('user_id', $identity->user_id)->where('site_id', $identity->site_id)
            ->where('hazardous_substance_id', $identity->hazardous_substance_id)
            ->where('source_schema_version', $identity->source_schema_version)->where('source_revision', $identity->source_revision)
            ->where('related_incident_id', $identity->related_incident_id);
        if ($identity->original_context === null) { $body->whereNull('original_context'); }
        else { $body->whereRaw('SHA2(CAST(original_context AS CHAR), 256) = ?', [hash('sha256', $identity->getRawOriginal('original_context'))]); }
        $row = $body->firstOrFail();
        // Same active evidence, locked identity, exact tuple; no preflight handle survives this callback.
        app(ChemicalExposureSourceService::class)->admitExposureIdentityBeforeBody($row, $current, $reads, $sites);
        $this->assertSite((int) $row->site_id, $reads, $sites);
        // Historical worker identity is retained; current employment/fitness is not inferred.
        abort_unless($reads->query(User::query())->whereKey($row->user_id)->exists(), 404);

        return $row;
    }

    public function assertSubstance(int $id, User $current, CurrentAuthorizationReads $reads): void
    {
        $this->assertRead($current, $reads);
        abort_unless($id > 0 && $reads->query(HazardousSubstance::query())->whereKey($id)->exists(), 404);
    }

    /** Root derived consumers must compare this exact tuple before discovering private dependants. */
    public function sourceTuple(SubstanceExposureRecord|int $source, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $row = $this->assertExposure($source, $current, $reads, $sites);

        return ['source_type' => SubstanceExposureRecord::class, 'source_morph' => $row->getMorphClass(),
            'source_id' => (int) $row->id, 'event_category' => HsEvent::CATEGORY_EXPOSURE,
            'substance_id' => (int) $row->hazardous_substance_id, 'site_id' => (int) $row->site_id,
            'worker_id' => (int) $row->user_id, 'asset_id' => null,
            // There is no recorded Client tuple in the current exposure schema. No current incident fallback supplies it.
            'client_id' => null, 'client_binding' => $row->related_incident_id === null ? 'absent' : 'unknown',
            'attribution_state' => 'legacy_unknown'];
    }

    private function id(Model|int $source): int
    {
        if ($source instanceof Model && $source->getConnection() !== DB::connection()) {
            throw new LogicException('The exposure source belongs to a different physical connection.');
        }
        $id = $source instanceof Model ? (int) $source->id : $source;
        abort_unless($id > 0, 404);

        return $id;
    }

    private function detached(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $part) {
                $this->detached($part);
            }
        } elseif ($value !== null && ! is_scalar($value)) {
            throw new LogicException('Exposure reads may return detached scalar projections only.');
        }
    }
}
